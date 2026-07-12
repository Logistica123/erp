<?php

namespace App\Erp\Services\Cierres;

use App\Erp\Models\Asiento;
use App\Erp\Services\AsientoService;
use App\Erp\Models\Cierres\AjusteRetroactivo;
use App\Erp\Models\Cierres\DiaContable;
use App\Erp\Models\Periodo;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Workflow de cierre diario (anexo Cierres Diarios §6).
 *
 * Estados:
 *   ABIERTO    — registro creado.
 *   EN_PROCESO — import en curso o esperando decisión del usuario.
 *   CERRADO    — sellado. Movs estampados. Saldos snapshot.
 *   REAPERTO   — caso edge (super_admin solamente).
 *
 * Reglas de negocio cubiertas:
 *   RN-CD-1: bloquea sellar día N si N-1 no está cerrado (huecos).
 *   RN-CD-2: saldos_apertura(N) = saldos_cierre(N-1).
 *   RN-CD-3: saldos_cierre(N) = apertura + movs del día por cuenta.
 *   RN-CD-5: pendientes del día cerrado quedan estampados pero siguen
 *            siendo conciliables (siempre que no cambien saldos).
 *   RN-CD-6: ajuste retroactivo no toca día cerrado, genera asiento
 *            forward con fecha hoy y log en erp_ajustes_retroactivos.
 */
class CerrarDiaService
{
    public function __construct(
        private readonly DetectorTransferenciasInternasService $detector,
    ) {}

    /**
     * Inicia el cierre del día — crea/actualiza la fila DiaContable,
     * recopila saldos de apertura, corre el detector de transferencias.
     * NO sella todavía (eso lo hace sellar()). Devuelve resumen para que
     * la UI muestre el modal de pre-sellado.
     */
    public function iniciar(Carbon $fecha, int $empresaId): DiaContable
    {
        $this->validarHuecos($fecha, $empresaId);

        $dia = DiaContable::firstOrNew(
            ['empresa_id' => $empresaId, 'fecha' => $fecha->toDateString()]
        );

        if ($dia->exists && $dia->estado === DiaContable::ESTADO_CERRADO) {
            throw new DomainException('DIA_YA_CERRADO: '.$fecha->format('d/m/Y').' ya está cerrado. Para corregir: ajuste retroactivo o reapertura (super_admin).');
        }

        // Resolver saldos_apertura: del día N-1 cerrado, o NULL/manual si es el primer día.
        $aperturaPrev = $this->saldosAperturaDesdeNMenosUno($fecha, $empresaId);

        if (! $dia->exists) {
            $dia->fill([
                'estado'          => DiaContable::ESTADO_EN_PROCESO,
                'saldos_apertura' => $aperturaPrev,
            ])->save();
        } elseif ($dia->estado === DiaContable::ESTADO_ABIERTO) {
            $dia->update([
                'estado'          => DiaContable::ESTADO_EN_PROCESO,
                'saldos_apertura' => $aperturaPrev ?? $dia->saldos_apertura,
            ]);
        }

        // Detectar transferencias internas cross-banco (RN-CD-8).
        $this->detector->detectarYConciliar($fecha, $empresaId);

        $this->actualizarMetricas($dia);

        return $dia->fresh();
    }

    /**
     * Sella el día — calcula saldos_cierre, estampa movs con
     * dia_contable_id, actualiza estado a CERRADO.
     */
    public function sellar(Carbon $fecha, int $empresaId, User $usuario, bool $confirmarPendientes = true): DiaContable
    {
        $dia = DiaContable::where('empresa_id', $empresaId)
            ->where('fecha', $fecha->toDateString())->first();
        if (! $dia) {
            throw new DomainException('DIA_NO_INICIADO: hay que iniciar el cierre antes de sellar');
        }
        if ($dia->estado === DiaContable::ESTADO_CERRADO) {
            throw new DomainException('DIA_YA_CERRADO');
        }

        $this->validarHuecos($fecha, $empresaId);

        // Recalcular metrics + saldos_cierre antes del sellado.
        $this->actualizarMetricas($dia);

        $tienePendientes = $dia->total_pendientes > 0;
        if ($tienePendientes && ! $confirmarPendientes) {
            throw new DomainException(
                'PENDIENTES_REVISAR: '.$dia->total_pendientes.' movimientos sin etiquetar. '.
                'Pasar confirmarPendientes=true para sellar igual.'
            );
        }

        $saldosCierre = $this->calcularSaldosCierre($dia);

        DB::transaction(function () use ($dia, $usuario, $saldosCierre) {
            $dia->update([
                'estado'        => DiaContable::ESTADO_CERRADO,
                'saldos_cierre' => $saldosCierre,
                'cerrado_por'   => $usuario->id,
                'cerrado_at'    => now(),
            ]);

            // Estampar todos los movs de este día con dia_contable_id (RN-CD-5).
            $cuentasIds = CuentaBancaria::where('empresa_id', $dia->empresa_id)->pluck('id');
            MovimientoBancario::whereIn('cuenta_bancaria_id', $cuentasIds)
                ->whereDate('fecha', $dia->fecha->toDateString())
                ->whereNull('dia_contable_id')
                ->update(['dia_contable_id' => $dia->id, 'updated_at' => now()]);
        });

        return $dia->fresh();
    }

    /**
     * Ajuste retroactivo (RN-CD-6).
     * Crea asiento forward con fecha actual y registra en erp_ajustes_retroactivos.
     * El día original NO se modifica.
     *
     * @param array{cuenta_debe_id:int, cuenta_haber_id:int, importe:float, glosa?:?string} $asientoSimple
     */
    public function ajusteRetroactivo(
        Carbon $fechaDiaAfectado, int $empresaId, string $motivo,
        array $asientoSimple, User $usuario, ?int $movimientoOrigenId = null
    ): AjusteRetroactivo {
        $dia = DiaContable::where('empresa_id', $empresaId)
            ->where('fecha', $fechaDiaAfectado->toDateString())->first();
        if (! $dia || $dia->estado !== DiaContable::ESTADO_CERRADO) {
            throw new DomainException('DIA_NO_CERRADO: solo se ajusta retroactivo sobre días CERRADOS.');
        }
        if (mb_strlen(trim($motivo)) < 5) {
            throw new DomainException('MOTIVO_REQUERIDO: mínimo 5 caracteres.');
        }

        $cuentaDebeId  = (int) $asientoSimple['cuenta_debe_id'];
        $cuentaHaberId = (int) $asientoSimple['cuenta_haber_id'];
        $importe = round((float) $asientoSimple['importe'], 2);
        if ($importe <= 0) {
            throw new DomainException('IMPORTE_INVALIDO');
        }

        $hoy = now();
        $glosa = sprintf('Ajuste retroactivo del %s · %s',
            $fechaDiaAfectado->format('d/m/Y'),
            $asientoSimple['glosa'] ?? $motivo
        );

        return DB::transaction(function () use ($cuentaDebeId, $cuentaHaberId, $importe, $glosa, $hoy, $fechaDiaAfectado, $empresaId, $usuario, $motivo, $movimientoOrigenId) {
            $asiento = $this->crearAsientoForward($cuentaDebeId, $cuentaHaberId, $importe, $glosa, $empresaId, $usuario, $hoy);

            return AjusteRetroactivo::create([
                'empresa_id'           => $empresaId,
                'fecha_dia_afectado'   => $fechaDiaAfectado->toDateString(),
                'fecha_asiento_ajuste' => $hoy->toDateString(),
                'asiento_ajuste_id'    => $asiento->id,
                'motivo'               => trim($motivo),
                'iniciado_por'         => $usuario->id,
                'iniciado_at'          => $hoy,
                'movimiento_origen_id' => $movimientoOrigenId,
            ]);
        });
    }

    // -------------------------------------------------------------------- helpers

    /**
     * RN-CD-1: bloquea si hay días anteriores sin cerrar entre el último
     * cerrado y el día solicitado.
     */
    private function validarHuecos(Carbon $fecha, int $empresaId): void
    {
        $ultimo = DiaContable::where('empresa_id', $empresaId)
            ->where('estado', DiaContable::ESTADO_CERRADO)
            ->where('fecha', '<', $fecha->toDateString())
            ->orderByDesc('fecha')->first();

        if (! $ultimo) {
            return; // Primer cierre histórico — el saldo apertura debe ser manual.
        }

        $diff = $ultimo->fecha->copy()->startOfDay()->diffInDays($fecha->copy()->startOfDay());
        if ($diff > 1) {
            $faltantes = [];
            $cursor = $ultimo->fecha->copy()->addDay();
            while ($cursor->lt($fecha)) {
                $faltantes[] = $cursor->format('d/m/Y');
                $cursor->addDay();
            }
            throw new DomainException(sprintf(
                'HUECOS_NO_PERMITIDOS: no podés cerrar el %s. Faltan: %s. Cerrá esos primero.',
                $fecha->format('d/m/Y'),
                implode(', ', $faltantes)
            ));
        }
    }

    /**
     * RN-CD-2: saldos_apertura(N) = saldos_cierre(N-1).
     */
    private function saldosAperturaDesdeNMenosUno(Carbon $fecha, int $empresaId): ?array
    {
        $diaAnterior = DiaContable::where('empresa_id', $empresaId)
            ->where('estado', DiaContable::ESTADO_CERRADO)
            ->where('fecha', '<', $fecha->toDateString())
            ->orderByDesc('fecha')->first();
        return $diaAnterior?->saldos_cierre;
    }

    /**
     * RN-CD-3: saldos_cierre(N) por cuenta = apertura(N) + suma(creditos) - suma(debitos).
     */
    private function calcularSaldosCierre(DiaContable $dia): array
    {
        $apertura = $dia->saldos_apertura ?? [];
        $cuentasIds = CuentaBancaria::where('empresa_id', $dia->empresa_id)->pluck('id');

        $delta = DB::table('erp_movimientos_bancarios')
            ->whereIn('cuenta_bancaria_id', $cuentasIds)
            ->whereDate('fecha', $dia->fecha->toDateString())
            ->select('cuenta_bancaria_id', DB::raw('SUM(credito - debito) AS delta'))
            ->groupBy('cuenta_bancaria_id')
            ->get()
            ->keyBy('cuenta_bancaria_id');

        $cierre = [];
        foreach ($cuentasIds as $cid) {
            $sa = (float) ($apertura[(string) $cid] ?? $apertura[$cid] ?? 0);
            $d  = (float) ($delta[$cid]->delta ?? 0);
            $cierre[(string) $cid] = round($sa + $d, 2);
        }
        return $cierre;
    }

    private function actualizarMetricas(DiaContable $dia): void
    {
        $cuentasIds = CuentaBancaria::where('empresa_id', $dia->empresa_id)->pluck('id');

        $counts = DB::table('erp_movimientos_bancarios')
            ->whereIn('cuenta_bancaria_id', $cuentasIds)
            ->whereDate('fecha', $dia->fecha->toDateString())
            ->select('estado', DB::raw('COUNT(*) AS n'))
            ->groupBy('estado')
            ->pluck('n', 'estado');

        $total = (int) array_sum($counts->all());
        $dia->update([
            'total_movimientos' => $total,
            'total_conciliados' => (int) ($counts['CONCILIADO'] ?? 0),
            'total_pendientes'  => (int) (($counts['PENDIENTE'] ?? 0) + ($counts['ETIQUETADO'] ?? 0)),
            'total_ignorados'   => (int) ($counts['IGNORADO'] ?? 0),
        ]);
    }

    private function crearAsientoForward(int $cuentaDebeId, int $cuentaHaberId, float $importe, string $glosa, int $empresaId, User $usuario, Carbon $hoy): Asiento
    {
        // Auditoría 2026-07-12 #6 — antes se creaba por INSERT/UPDATE directo:
        // sin hash de integridad (fallaba verificarIntegridadAsientos), con
        // numeración por regex sobre el último id (colisionable) y diario_id=8
        // literal. Ahora pasa por AsientoService (numerador RN-9 con lock,
        // hash RN-6, validaciones RN-1/3/10) y el diario se resuelve por código.
        $diarioId = DB::table('erp_diarios')->where('empresa_id', $empresaId)
            ->where('codigo', 'AJ')->where('activo', 1)->value('id')
            ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)
                ->where('codigo', 'GEN')->where('activo', 1)->value('id');
        if (! $diarioId) {
            throw new DomainException('DIARIO_AJ_INEXISTENTE: no hay diario AJ ni GEN activo para la empresa '.$empresaId);
        }

        $req = $this->cuentasQueRequierenCC([$cuentaDebeId, $cuentaHaberId]);
        $cc  = $req !== [] ? $this->ccDefault($empresaId) : null;

        $asientoSvc = app(AsientoService::class);
        $asiento = $asientoSvc->crearBorrador([
            'empresa_id' => $empresaId,
            'diario_id' => (int) $diarioId,
            'fecha' => $hoy->toDateString(),
            'glosa' => $glosa,
            'origen' => 'AJUSTE',
            'usuario_id' => $usuario->id,
            'movimientos' => [
                ['cuenta_id' => $cuentaDebeId,
                 'centro_costo_id' => in_array($cuentaDebeId, $req, true) ? $cc : null,
                 'debe' => $importe, 'haber' => 0, 'glosa' => $glosa],
                ['cuenta_id' => $cuentaHaberId,
                 'centro_costo_id' => in_array($cuentaHaberId, $req, true) ? $cc : null,
                 'debe' => 0, 'haber' => $importe, 'glosa' => $glosa],
            ],
        ]);

        return $asientoSvc->contabilizar($asiento);
    }

    private function ccDefault(int $empresaId = 1): int
    {
        $id = DB::table('erp_centros_costo')->where('empresa_id', $empresaId)->where('activo', 1)
            ->where('codigo', 'GENERAL')->value('id')
            ?? DB::table('erp_centros_costo')->where('empresa_id', $empresaId)->where('activo', 1)
                ->orderBy('id')->value('id');
        if (! $id) throw new DomainException('CC_DEFAULT_NO_ENCONTRADO');
        return (int) $id;
    }

    /** @param int[] $cuentaIds @return int[] */
    private function cuentasQueRequierenCC(array $cuentaIds): array
    {
        if (empty($cuentaIds)) return [];
        return DB::table('erp_cuentas_contables')
            ->whereIn('id', $cuentaIds)->where('admite_cc', 1)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

}
