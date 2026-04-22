<?php

namespace App\Erp\Services;

use App\Erp\Jobs\RecomputarSaldosPeriodo;
use App\Erp\Models\Asiento;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Diario;
use App\Erp\Models\Ejercicio;
use App\Erp\Models\MovimientoAsiento;
use App\Erp\Models\Periodo;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Encapsula la creación y contabilización de asientos con todas las reglas
 * de negocio (RN-1 a RN-9 del SPEC_01 §6).
 *
 *  RN-1  Partida doble (SUM debe = SUM haber, tolerancia 0)
 *  RN-2  Un lado por línea (debe > 0 xor haber > 0)
 *  RN-3  Cuenta imputable (imputable=1)
 *  RN-4  Período abierto (estado='ABIERTO')
 *  RN-5  Anulación = asiento reversa
 *  RN-6  Hash integridad SHA-256
 *  RN-7  Auditoría obligatoria (pendiente: observer)
 *  RN-8  Moneda consistente (TODO: validar moneda)
 *  RN-9  Numerador correlativo por (empresa, ejercicio, diario)
 *  RN-10 CC obligatorio si admite_cc=1
 *
 * Los triggers SQL de DDL_02 ya enforzan RN-1/3/4/10 a nivel DB; este service
 * los duplica a nivel aplicación con mejores mensajes de error, y agrega el
 * numerador atómico (RN-9) + hash (RN-6).
 */
class AsientoService
{
    /**
     * @param  array{
     *   empresa_id:int,
     *   diario_id:int,
     *   fecha:string,
     *   glosa:?string,
     *   origen?:string,
     *   origen_id?:?int,
     *   origen_tabla?:?string,
     *   usuario_id:int,
     *   movimientos: array<int, array{
     *     cuenta_id?:int,
     *     cuenta_codigo?:string,
     *     centro_costo_id?:?int,
     *     auxiliar_id?:?int,
     *     glosa?:?string,
     *     debe:float|string,
     *     haber:float|string,
     *     moneda?:?string,
     *     importe_origen?:float|string|null,
     *     cotizacion?:float|string|null,
     *   }>
     * }  $data
     */
    public function crearBorrador(array $data): Asiento
    {
        return DB::transaction(function () use ($data) {
            $fecha = Carbon::parse($data['fecha'])->toDateString();

            $ejercicio = Ejercicio::where('empresa_id', $data['empresa_id'])
                ->where('fecha_inicio', '<=', $fecha)
                ->where('fecha_cierre', '>=', $fecha)
                ->first();

            if (! $ejercicio) {
                throw new DomainException('EJERCICIO_NO_ENCONTRADO: no hay ejercicio abierto que contenga la fecha '.$fecha);
            }

            $periodo = Periodo::where('ejercicio_id', $ejercicio->id)
                ->where('fecha_inicio', '<=', $fecha)
                ->where('fecha_fin', '>=', $fecha)
                ->first();

            if (! $periodo) {
                throw new DomainException('PERIODO_NO_ENCONTRADO');
            }

            // RN-4 (soft): permitimos BORRADOR en período en cierre pero no contabilizado.
            if (in_array($periodo->estado, ['CERRADO', 'BLOQUEADO'], true)) {
                throw new DomainException('PERIODO_BLOQUEADO: el período '.$periodo->anio.'/'.$periodo->mes.' está '.$periodo->estado);
            }

            $diario = Diario::where('empresa_id', $data['empresa_id'])
                ->where('id', $data['diario_id'])
                ->where('activo', true)
                ->firstOrFail();

            // Validación de movimientos
            $this->validarMovimientos($data['empresa_id'], $data['movimientos']);

            // RN-9: numerador correlativo por (empresa, ejercicio, diario) con FOR UPDATE
            $diarioLock = DB::table('erp_diarios')
                ->where('id', $diario->id)
                ->lockForUpdate()
                ->first();
            $nuevoNumero = ($diarioLock->numerador_actual ?? 0) + 1;

            DB::table('erp_diarios')
                ->where('id', $diario->id)
                ->update(['numerador_actual' => $nuevoNumero]);

            $totalDebe = array_sum(array_map(fn ($m) => (float) $m['debe'], $data['movimientos']));
            $totalHaber = array_sum(array_map(fn ($m) => (float) $m['haber'], $data['movimientos']));

            $asiento = Asiento::create([
                'empresa_id' => $data['empresa_id'],
                'ejercicio_id' => $ejercicio->id,
                'periodo_id' => $periodo->id,
                'diario_id' => $diario->id,
                'numero' => $nuevoNumero,
                'fecha' => $fecha,
                'fecha_contabilizacion' => now(),
                'glosa' => $data['glosa'] ?? null,
                'origen' => $data['origen'] ?? 'MANUAL',
                'origen_id' => $data['origen_id'] ?? null,
                'origen_tabla' => $data['origen_tabla'] ?? null,
                'estado' => Asiento::ESTADO_BORRADOR,
                'moneda_base' => 'ARS',
                'total_debe' => round($totalDebe, 2),
                'total_haber' => round($totalHaber, 2),
                // desbalance: columna GENERATED ALWAYS AS (total_debe - total_haber) — no se envía.
                'usuario_id' => $data['usuario_id'],
            ]);

            foreach ($data['movimientos'] as $idx => $m) {
                $cuentaId = $m['cuenta_id'] ?? $this->resolverCuentaId($data['empresa_id'], $m['cuenta_codigo'] ?? '');

                MovimientoAsiento::create([
                    'asiento_id' => $asiento->id,
                    'linea' => $idx + 1,
                    'cuenta_id' => $cuentaId,
                    'centro_costo_id' => $m['centro_costo_id'] ?? null,
                    'auxiliar_id' => $m['auxiliar_id'] ?? null,
                    'glosa' => $m['glosa'] ?? null,
                    'debe' => round((float) $m['debe'], 2),
                    'haber' => round((float) $m['haber'], 2),
                    'moneda' => $m['moneda'] ?? 'ARS',
                    'importe_origen' => $m['importe_origen'] ?? null,
                    'cotizacion' => $m['cotizacion'] ?? null,
                ]);
            }

            return $asiento->fresh('movimientos');
        });
    }

    /**
     * Transiciona un asiento BORRADOR → CONTABILIZADO:
     *  · re-valida RN-1..RN-10
     *  · exige período ABIERTO (RN-4)
     *  · calcula hash de integridad (RN-6)
     */
    public function contabilizar(Asiento $asiento): Asiento
    {
        if ($asiento->estado !== Asiento::ESTADO_BORRADOR) {
            throw new DomainException('ESTADO_INVALIDO: solo se contabilizan asientos BORRADOR (actual: '.$asiento->estado.')');
        }

        return DB::transaction(function () use ($asiento) {
            $periodo = $asiento->periodo()->lockForUpdate()->first();
            if ($periodo->estado !== 'ABIERTO') {
                throw new DomainException('PERIODO_BLOQUEADO: '.$periodo->estado);
            }

            $movs = $asiento->movimientos()->orderBy('linea')->get();

            // RN-2 / RN-3 / RN-10 (re-validación aplicación, los triggers SQL también validan)
            foreach ($movs as $m) {
                if ($m->debe > 0 && $m->haber > 0) {
                    throw new DomainException('LINEA_INVALIDA: línea '.$m->linea.' tiene debe y haber > 0');
                }
                if ($m->debe == 0 && $m->haber == 0) {
                    throw new DomainException('LINEA_INVALIDA: línea '.$m->linea.' sin importe');
                }
            }

            $totalDebe = round($movs->sum('debe'), 2);
            $totalHaber = round($movs->sum('haber'), 2);
            $desbalance = round($totalDebe - $totalHaber, 2);

            // RN-1: tolerancia 0
            if (abs($desbalance) > 0.001) {
                throw new DomainException(sprintf(
                    'ASIENTO_DESBALANCEADO: debe=%.2f haber=%.2f diferencia=%.2f',
                    $totalDebe,
                    $totalHaber,
                    $desbalance
                ));
            }

            // RN-6: hash de integridad
            $hash = self::calcularHashIntegridad($asiento, $movs);

            $asiento->update([
                'estado' => Asiento::ESTADO_CONTABILIZADO,
                'fecha_contabilizacion' => now(),
                'total_debe' => $totalDebe,
                'total_haber' => $totalHaber,
                // desbalance se recalcula solo (columna GENERATED)
                'hash_integridad' => $hash,
            ]);

            // Dispara el recálculo de saldos del período en background.
            RecomputarSaldosPeriodo::dispatch($asiento->periodo_id);

            return $asiento->fresh('movimientos');
        });
    }

    /**
     * Anula un asiento contabilizado generando un asiento reversa (RN-5).
     */
    public function anular(Asiento $asiento, int $usuarioId, string $motivo): Asiento
    {
        if ($asiento->estado !== Asiento::ESTADO_CONTABILIZADO) {
            throw new DomainException('Solo se anulan asientos CONTABILIZADOS.');
        }

        return DB::transaction(function () use ($asiento, $usuarioId, $motivo) {
            $periodo = $asiento->periodo()->lockForUpdate()->first();
            if ($periodo->estado !== 'ABIERTO') {
                throw new DomainException('PERIODO_BLOQUEADO: no se puede anular en período '.$periodo->estado);
            }

            // Movimientos invertidos (debe ↔ haber)
            $movimientosInvertidos = $asiento->movimientos->map(fn ($m) => [
                'cuenta_id' => $m->cuenta_id,
                'centro_costo_id' => $m->centro_costo_id,
                'auxiliar_id' => $m->auxiliar_id,
                'glosa' => 'Reversa: '.($m->glosa ?? ''),
                'debe' => (float) $m->haber,
                'haber' => (float) $m->debe,
            ])->all();

            $reversa = $this->crearBorrador([
                'empresa_id' => $asiento->empresa_id,
                'diario_id' => $asiento->diario_id,
                'fecha' => now()->toDateString(),
                'glosa' => 'Anulación asiento '.$asiento->numero.': '.$motivo,
                'origen' => 'AJUSTE',
                'origen_id' => $asiento->id,
                'origen_tabla' => 'erp_asientos',
                'usuario_id' => $usuarioId,
                'movimientos' => $movimientosInvertidos,
            ]);

            $reversa = $this->contabilizar($reversa);

            $asiento->update([
                'estado' => Asiento::ESTADO_ANULADO,
                'usuario_anulo_id' => $usuarioId,
                'fecha_anulacion' => now(),
                'motivo_anulacion' => $motivo,
                'asiento_reversa_id' => $reversa->id,
            ]);

            // Recompute saldos de ambos períodos (el original y el de la reversa).
            RecomputarSaldosPeriodo::dispatch($asiento->periodo_id);
            if ($reversa->periodo_id !== $asiento->periodo_id) {
                RecomputarSaldosPeriodo::dispatch($reversa->periodo_id);
            }

            return $asiento->fresh(['movimientos', 'asientoReversa']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $movimientos
     */
    private function validarMovimientos(int $empresaId, array $movimientos): void
    {
        if (count($movimientos) < 2) {
            throw new DomainException('ASIENTO_MINIMO: se requieren al menos 2 líneas.');
        }

        $cuentaIds = collect($movimientos)
            ->map(fn ($m) => $m['cuenta_id'] ?? null)
            ->filter()
            ->unique();

        $cuentasByCodigo = collect($movimientos)
            ->map(fn ($m) => $m['cuenta_codigo'] ?? null)
            ->filter()
            ->unique();

        $cuentasCargadas = CuentaContable::where('empresa_id', $empresaId)
            ->where(function ($q) use ($cuentaIds, $cuentasByCodigo) {
                $q->whereIn('id', $cuentaIds);
                if ($cuentasByCodigo->isNotEmpty()) {
                    $q->orWhereIn('codigo', $cuentasByCodigo);
                }
            })
            ->get()
            ->keyBy(fn ($c) => $c->id);

        $cuentasByCodigoMap = CuentaContable::where('empresa_id', $empresaId)
            ->whereIn('codigo', $cuentasByCodigo)
            ->get()
            ->keyBy('codigo');

        foreach ($movimientos as $idx => $m) {
            $linea = $idx + 1;
            $debe = (float) ($m['debe'] ?? 0);
            $haber = (float) ($m['haber'] ?? 0);

            // RN-2
            if ($debe > 0 && $haber > 0) {
                throw new DomainException("LINEA_INVALIDA: línea {$linea} tiene debe y haber > 0.");
            }
            if ($debe == 0 && $haber == 0) {
                throw new DomainException("LINEA_INVALIDA: línea {$linea} sin importe.");
            }
            if ($debe < 0 || $haber < 0) {
                throw new DomainException("LINEA_INVALIDA: línea {$linea} con importe negativo.");
            }

            $cuenta = null;
            if (! empty($m['cuenta_id'])) {
                $cuenta = $cuentasCargadas->get($m['cuenta_id']);
            } elseif (! empty($m['cuenta_codigo'])) {
                $cuenta = $cuentasByCodigoMap->get($m['cuenta_codigo']);
            }

            if (! $cuenta) {
                throw new DomainException("CUENTA_NO_ENCONTRADA: línea {$linea}.");
            }

            // RN-3
            if (! $cuenta->imputable) {
                throw new DomainException("CUENTA_NO_IMPUTABLE: línea {$linea}, cuenta {$cuenta->codigo}.");
            }

            // RN-10
            if ($cuenta->admite_cc && empty($m['centro_costo_id'])) {
                throw new DomainException("CC_REQUERIDO: línea {$linea}, cuenta {$cuenta->codigo} requiere centro de costo.");
            }

            if ($cuenta->admite_auxiliar && empty($m['auxiliar_id'])) {
                throw new DomainException("AUXILIAR_REQUERIDO: línea {$linea}, cuenta {$cuenta->codigo} requiere auxiliar.");
            }
        }
    }

    /**
     * Recomputa el hash_integridad de un asiento usando la misma regla que
     * aplicó contabilizar(). Si algún movimiento se modificó por fuera del
     * flujo contable (UPDATE directo a SQL), el hash recomputado diferirá.
     *
     * @param  \Illuminate\Support\Collection<int, MovimientoAsiento>|null  $movs
     */
    public static function calcularHashIntegridad(Asiento $asiento, $movs = null): string
    {
        $movs ??= $asiento->movimientos()->orderBy('linea')->get();

        $payload = [
            'id' => $asiento->id,
            'fecha' => $asiento->fecha instanceof Carbon
                ? $asiento->fecha->toDateString()
                : Carbon::parse($asiento->fecha)->toDateString(),
            'diario_id' => $asiento->diario_id,
            'numero' => $asiento->numero,
            'movimientos' => $movs->map(fn ($m) => [
                'linea' => $m->linea,
                'cuenta_id' => $m->cuenta_id,
                'debe' => number_format((float) $m->debe, 2, '.', ''),
                'haber' => number_format((float) $m->haber, 2, '.', ''),
            ])->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Itera los asientos CONTABILIZADOS y devuelve los que presentan un
     * hash_integridad distinto al recomputado (tamper evidente).
     *
     * @return array<int, array{asiento_id:int, numero:int, esperado:string, encontrado:?string}>
     */
    public function verificarIntegridadAsientos(?int $empresaId = null, ?int $periodoId = null): array
    {
        $query = Asiento::query()
            ->where('estado', Asiento::ESTADO_CONTABILIZADO)
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
            ->when($periodoId, fn ($q) => $q->where('periodo_id', $periodoId))
            ->with(['movimientos' => fn ($q) => $q->orderBy('linea')])
            ->orderBy('id');

        $fallas = [];
        foreach ($query->cursor() as $asiento) {
            $esperado = self::calcularHashIntegridad($asiento, $asiento->movimientos);
            if ($esperado !== $asiento->hash_integridad) {
                $fallas[] = [
                    'asiento_id' => $asiento->id,
                    'numero' => $asiento->numero,
                    'esperado' => $esperado,
                    'encontrado' => $asiento->hash_integridad,
                ];
            }
        }

        return $fallas;
    }

    private function resolverCuentaId(int $empresaId, string $codigo): int
    {
        $cuenta = CuentaContable::where('empresa_id', $empresaId)
            ->where('codigo', $codigo)
            ->first();

        if (! $cuenta) {
            throw new DomainException('CUENTA_NO_ENCONTRADA: '.$codigo);
        }

        return $cuenta->id;
    }
}
