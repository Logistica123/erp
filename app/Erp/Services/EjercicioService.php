<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Diario;
use App\Erp\Models\Ejercicio;
use App\Erp\Models\Periodo;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cierre de ejercicio fiscal con asiento automático de refundición.
 *
 *  - Reúne los saldos netos de las cuentas de resultado (tipo RP / RN) del
 *    ejercicio (asientos CONTABILIZADOS).
 *  - Genera un asiento en el diario CIE con:
 *      · DEBE   de cada cuenta RP (ingresos) por su saldo neto → las deja en 0
 *      · HABER  de cada cuenta RN (egresos) por su saldo neto → las deja en 0
 *      · Contrapartida en `3.3.02 Resultado del Ejercicio`:
 *          HABER si hay ganancia, DEBE si hay pérdida
 *  - Contabiliza el asiento (usando AsientoService → dispara SaldosService)
 *  - Cierra el último período del ejercicio si estaba abierto
 *  - Marca el ejercicio como CERRADO con hash de cierre
 *  - Audit log completo
 *
 * Precondición: el último período (el que contiene fecha_cierre del ejercicio)
 * debe estar ABIERTO para poder insertar el asiento ahí.
 *
 * Sobre admite_cc: si alguna cuenta RP/RN requiere CC, se usa el CC 'CENTRAL'
 * como fallback (es una convención para el asiento de refundición, no una
 * imputación analítica real).
 */
class EjercicioService
{
    private const CUENTA_RESULTADO_EJERCICIO = '3.3.02';
    private const DIARIO_CIERRE = 'CIE';
    private const CC_CIERRE = 'CENTRAL';

    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly PeriodoService $periodoService,
        private readonly SaldosService $saldosService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Cierra un ejercicio fiscal.
     *
     * @return array{ejercicio: Ejercicio, asiento_refundicion: Asiento, resultado: float}
     */
    public function reabrir(Ejercicio $ejercicio, User $usuario, string $motivo): Ejercicio
    {
        if ($ejercicio->estado !== 'CERRADO') {
            throw new DomainException('EJERCICIO_NO_CERRADO: estado actual '.$ejercicio->estado);
        }

        return DB::transaction(function () use ($ejercicio, $usuario, $motivo) {
            $ejercicio->update([
                'estado' => 'ABIERTO',
                'fecha_cierre_real' => null,
                'usuario_cierre_id' => null,
            ]);

            $this->audit->logEvento(
                accion: 'EJERCICIO_REABIERTO',
                modulo: 'ejercicios',
                descripcion: sprintf(
                    'Reapertura ejercicio %d por %s · motivo: %s',
                    $ejercicio->numero,
                    $usuario->name,
                    $motivo,
                ),
                empresaId: $ejercicio->empresa_id,
            );

            return $ejercicio->fresh();
        });
    }

    public function cerrar(Ejercicio $ejercicio, User $usuario): array
    {
        if ($ejercicio->estado !== 'ABIERTO') {
            throw new DomainException('EJERCICIO_NO_ABIERTO: estado actual '.$ejercicio->estado);
        }

        // Identificar el último período (contiene fecha_cierre del ejercicio)
        $fechaCierreEjercicio = $ejercicio->fecha_cierre->toDateString();
        $ultimoPeriodo = Periodo::where('ejercicio_id', $ejercicio->id)
            ->where('fecha_inicio', '<=', $fechaCierreEjercicio)
            ->where('fecha_fin', '>=', $fechaCierreEjercicio)
            ->first();

        if (! $ultimoPeriodo) {
            throw new DomainException('ULTIMO_PERIODO_NO_ENCONTRADO');
        }

        if ($ultimoPeriodo->estado !== 'ABIERTO') {
            throw new DomainException(sprintf(
                'ULTIMO_PERIODO_CERRADO: el período %02d/%d debe estar ABIERTO para recibir el asiento de refundición. Reabrilo antes de cerrar el ejercicio.',
                $ultimoPeriodo->mes,
                $ultimoPeriodo->anio,
            ));
        }

        // Validar no haya borradores en el último período (refundición va a quedar en CONTABILIZADO y se cierra el período)
        $borradores = Asiento::where('periodo_id', $ultimoPeriodo->id)
            ->where('estado', Asiento::ESTADO_BORRADOR)
            ->count();
        if ($borradores > 0) {
            throw new DomainException(sprintf(
                'BORRADORES_PENDIENTES: el período %02d/%d tiene %d asiento(s) en BORRADOR.',
                $ultimoPeriodo->mes,
                $ultimoPeriodo->anio,
                $borradores,
            ));
        }

        return DB::transaction(function () use ($ejercicio, $ultimoPeriodo, $usuario, $fechaCierreEjercicio) {
            // 1. Armar saldos netos de cuentas de resultado del ejercicio
            $saldos = $this->calcularSaldosResultado($ejercicio);

            if ($saldos->isEmpty()) {
                throw new DomainException('SIN_MOVIMIENTOS: el ejercicio no tiene movimientos en cuentas de resultado.');
            }

            // 2. Construir movimientos del asiento
            $movimientos = $this->construirMovimientos($saldos, $ejercicio->empresa_id);
            $resultadoNeto = $this->calcularResultadoNeto($saldos);

            // 3. Crear asiento BORRADOR
            $diarioCierre = Diario::where('empresa_id', $ejercicio->empresa_id)
                ->where('codigo', self::DIARIO_CIERRE)
                ->firstOrFail();

            $asiento = $this->asientoService->crearBorrador([
                'empresa_id' => $ejercicio->empresa_id,
                'diario_id' => $diarioCierre->id,
                'fecha' => $fechaCierreEjercicio,
                'glosa' => sprintf(
                    'Asiento de refundición — cierre ejercicio %d · resultado %s $%s',
                    $ejercicio->numero,
                    $resultadoNeto >= 0 ? 'positivo' : 'negativo',
                    number_format(abs($resultadoNeto), 2, ',', '.'),
                ),
                'origen' => 'CIERRE',
                'origen_id' => $ejercicio->id,
                'origen_tabla' => 'erp_ejercicios',
                'usuario_id' => $usuario->id,
                'movimientos' => $movimientos,
            ]);

            // 4. Contabilizar
            $asiento = $this->asientoService->contabilizar($asiento);

            // 5. Cerrar el último período
            $this->periodoService->cerrar($ultimoPeriodo->fresh(), $usuario);

            // 6. Computar hash de cierre del ejercicio (snapshot de saldos PN + A + P)
            $hashCierre = $this->computarHashCierre($ejercicio);

            // 7. Marcar ejercicio CERRADO
            $ejercicio->update([
                'estado' => 'CERRADO',
                'fecha_cierre_real' => now(),
                'usuario_cierre_id' => $usuario->id,
            ]);

            // 8. Audit log
            $this->audit->logEvento(
                accion: 'EJERCICIO_CERRADO',
                modulo: 'ejercicios',
                descripcion: sprintf(
                    'Cierre ejercicio %d · resultado neto $%s · asiento refundición #%d · hash=%s',
                    $ejercicio->numero,
                    number_format($resultadoNeto, 2, ',', '.'),
                    $asiento->id,
                    substr($hashCierre, 0, 16),
                ),
                empresaId: $ejercicio->empresa_id,
            );

            return [
                'ejercicio' => $ejercicio->fresh(),
                'asiento_refundicion' => $asiento,
                'resultado' => $resultadoNeto,
            ];
        });
    }

    /**
     * Devuelve saldos netos por cuenta de resultado del ejercicio,
     * filtrando las que quedaron en saldo 0.
     *
     * @return \Illuminate\Support\Collection<int, object{id:int,codigo:string,nombre:string,tipo:string,admite_cc:int,admite_auxiliar:int,debitos:float,creditos:float,saldo:float}>
     */
    private function calcularSaldosResultado(Ejercicio $ejercicio): \Illuminate\Support\Collection
    {
        return DB::table('erp_cuentas_contables as c')
            ->leftJoinSub(
                DB::table('erp_movimientos_asiento as m')
                    ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
                    ->where('a.empresa_id', $ejercicio->empresa_id)
                    ->where('a.ejercicio_id', $ejercicio->id)
                    ->whereIn('a.estado', ['CONTABILIZADO', 'ANULADO'])
                    ->groupBy('m.cuenta_id')
                    ->select(
                        'm.cuenta_id',
                        DB::raw('COALESCE(SUM(m.debe), 0) AS debitos'),
                        DB::raw('COALESCE(SUM(m.haber), 0) AS creditos'),
                    ),
                'mov',
                'mov.cuenta_id',
                '=',
                'c.id'
            )
            ->where('c.empresa_id', $ejercicio->empresa_id)
            ->whereIn('c.tipo', ['RP', 'RN'])
            ->where('c.imputable', true)
            ->select([
                'c.id',
                'c.codigo',
                'c.nombre',
                'c.tipo',
                'c.admite_cc',
                'c.admite_auxiliar',
                DB::raw('COALESCE(mov.debitos, 0) AS debitos'),
                DB::raw('COALESCE(mov.creditos, 0) AS creditos'),
            ])
            ->get()
            ->map(function ($row) {
                $debitos = (float) $row->debitos;
                $creditos = (float) $row->creditos;
                // Para RP el saldo "contable" natural es creditos - debitos (saldo H)
                // Para RN es debitos - creditos (saldo D)
                $row->saldo = $row->tipo === 'RP' ? ($creditos - $debitos) : ($debitos - $creditos);
                return $row;
            })
            ->filter(fn ($r) => abs($r->saldo) >= 0.01);
    }

    /**
     * Arma los movimientos de refundición: una línea por cuenta de resultado
     * (invertida para cerrarla) + contrapartida en `3.3.02 Resultado del Ejercicio`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function construirMovimientos(\Illuminate\Support\Collection $saldos, int $empresaId): array
    {
        $ccCentralId = DB::table('erp_centros_costo')
            ->where('empresa_id', $empresaId)
            ->where('codigo', self::CC_CIERRE)
            ->value('id');

        $cuentaResultadoPn = CuentaContable::where('empresa_id', $empresaId)
            ->where('codigo', self::CUENTA_RESULTADO_EJERCICIO)
            ->firstOrFail();

        $movs = [];
        $sumaRpNeto = 0.0;
        $sumaRnNeto = 0.0;

        foreach ($saldos as $s) {
            $cc = $s->admite_cc ? $ccCentralId : null;
            if ($s->tipo === 'RP') {
                // Ingreso con saldo positivo → DEBITAR para cerrar
                $movs[] = [
                    'cuenta_id' => $s->id,
                    'centro_costo_id' => $cc,
                    'auxiliar_id' => null,
                    'glosa' => 'Refundición cuenta '.$s->codigo,
                    'debe' => round($s->saldo, 2),
                    'haber' => 0,
                ];
                $sumaRpNeto += $s->saldo;
            } else { // RN
                $movs[] = [
                    'cuenta_id' => $s->id,
                    'centro_costo_id' => $cc,
                    'auxiliar_id' => null,
                    'glosa' => 'Refundición cuenta '.$s->codigo,
                    'debe' => 0,
                    'haber' => round($s->saldo, 2),
                ];
                $sumaRnNeto += $s->saldo;
            }
        }

        $resultadoNeto = round($sumaRpNeto - $sumaRnNeto, 2);

        // Contrapartida en 3.3.02 Resultado del Ejercicio
        if ($resultadoNeto > 0) {
            // Ganancia: crédito en PN (aumenta el resultado)
            $movs[] = [
                'cuenta_id' => $cuentaResultadoPn->id,
                'centro_costo_id' => $cuentaResultadoPn->admite_cc ? $ccCentralId : null,
                'auxiliar_id' => null,
                'glosa' => 'Resultado del ejercicio (ganancia)',
                'debe' => 0,
                'haber' => $resultadoNeto,
            ];
        } else {
            // Pérdida: débito en PN (reduce el resultado)
            $movs[] = [
                'cuenta_id' => $cuentaResultadoPn->id,
                'centro_costo_id' => $cuentaResultadoPn->admite_cc ? $ccCentralId : null,
                'auxiliar_id' => null,
                'glosa' => 'Resultado del ejercicio (pérdida)',
                'debe' => abs($resultadoNeto),
                'haber' => 0,
            ];
        }

        return $movs;
    }

    private function calcularResultadoNeto(\Illuminate\Support\Collection $saldos): float
    {
        $ingresos = $saldos->where('tipo', 'RP')->sum('saldo');
        $egresos = $saldos->where('tipo', 'RN')->sum('saldo');

        return round((float) $ingresos - (float) $egresos, 2);
    }

    private function computarHashCierre(Ejercicio $ejercicio): string
    {
        $snapshot = DB::table('erp_saldos_cuenta as s')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 's.cuenta_id')
            ->join('erp_periodos as p', 'p.id', '=', 's.periodo_id')
            ->where('s.empresa_id', $ejercicio->empresa_id)
            ->where('p.ejercicio_id', $ejercicio->id)
            ->orderBy('c.codigo')
            ->orderBy('p.mes')
            ->get(['c.codigo', 's.periodo_id', 's.debitos', 's.creditos', 's.saldo_final'])
            ->toArray();

        return hash('sha256', json_encode([
            'ejercicio_id' => $ejercicio->id,
            'saldos' => $snapshot,
            'cerrado_en' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }
}
