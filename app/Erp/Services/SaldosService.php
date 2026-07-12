<?php

namespace App\Erp\Services;

use App\Erp\Models\CuentaContable;
use App\Erp\Models\Periodo;
use Illuminate\Support\Facades\DB;

/**
 * Mantiene la tabla materializada `erp_saldos_cuenta` con los saldos por
 * (cuenta, periodo), agregado desde `erp_movimientos_asiento` de asientos
 * CONTABILIZADOS.
 *
 * `saldo_final` es columna GENERATED; solo se escriben `saldo_inicial`,
 * `debitos`, `creditos`.
 *
 * Estrategia:
 *   - recomputarPeriodo($periodoId): barre todos los movimientos de cada
 *     cuenta en el período y hace UPSERT en erp_saldos_cuenta.
 *   - recomputarCuentaPeriodo($cuentaId, $periodoId): versión fine-grained
 *     para invocar desde eventos (después de contabilizar un asiento).
 *
 * Nota: por ahora NO se está encadenando saldo_inicial con el saldo_final
 * del período previo — cada período se computa solo con SUS movimientos.
 * Cuando se implemente Cierre de período, el saldo_inicial del mes N se
 * populará con el saldo_final del mes N-1.
 */
class SaldosService
{
    /**
     * Recomputa todos los saldos de un período completo.
     *
     * @return int  cantidad de filas escritas en erp_saldos_cuenta
     */
    public function recomputarPeriodo(int $periodoId): int
    {
        $periodo = Periodo::findOrFail($periodoId);
        $empresaId = $periodo->ejercicio->empresa_id;

        // Agregación: por cada (cuenta, periodo) sumo debe/haber del período.
        $agregados = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('a.empresa_id', $empresaId)
            ->whereIn('a.estado', ['CONTABILIZADO', 'ANULADO'])
            ->where('a.periodo_id', $periodoId)
            ->select([
                'm.cuenta_id',
                DB::raw('COALESCE(SUM(m.debe), 0) AS debitos'),
                DB::raw('COALESCE(SUM(m.haber), 0) AS creditos'),
            ])
            ->groupBy('m.cuenta_id')
            ->get();

        return DB::transaction(function () use ($agregados, $empresaId, $periodoId) {
            // Ceramos debitos/creditos de TODAS las cuentas del período pero preservamos saldo_inicial
            // (puede haber sido heredado del período previo cerrado).
            DB::table('erp_saldos_cuenta')
                ->where('empresa_id', $empresaId)
                ->where('periodo_id', $periodoId)
                ->update(['debitos' => 0, 'creditos' => 0, 'actualizado_en' => now()]);

            $count = 0;
            foreach ($agregados as $ag) {
                DB::table('erp_saldos_cuenta')->updateOrInsert(
                    [
                        'empresa_id' => $empresaId,
                        'cuenta_id' => $ag->cuenta_id,
                        'periodo_id' => $periodoId,
                    ],
                    [
                        'debitos' => $ag->debitos,
                        'creditos' => $ag->creditos,
                        'actualizado_en' => now(),
                    ]
                );
                $count++;
            }

            return $count;
        });
    }

    /**
     * Recomputa saldo de una cuenta específica en un período (fine-grained).
     */
    public function recomputarCuentaPeriodo(int $cuentaId, int $periodoId): void
    {
        $cuenta = CuentaContable::findOrFail($cuentaId);

        $agg = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('a.empresa_id', $cuenta->empresa_id)
            ->whereIn('a.estado', ['CONTABILIZADO', 'ANULADO'])
            ->where('a.periodo_id', $periodoId)
            ->where('m.cuenta_id', $cuentaId)
            ->selectRaw('COALESCE(SUM(m.debe), 0) AS debitos, COALESCE(SUM(m.haber), 0) AS creditos')
            ->first();

        // Auditoría 2026-07-12 #5 — NO tocar saldo_inicial: puede venir
        // encadenado del cierre del período anterior (propagarSaldos...).
        // recomputarPeriodo() ya lo preservaba; este camino fino lo pisaba
        // a 0. En el insert de fila nueva aplica el default 0 de la columna.
        DB::table('erp_saldos_cuenta')->updateOrInsert(
            [
                'empresa_id' => $cuenta->empresa_id,
                'cuenta_id' => $cuentaId,
                'periodo_id' => $periodoId,
            ],
            [
                'debitos' => (float) $agg->debitos,
                'creditos' => (float) $agg->creditos,
                'actualizado_en' => now(),
            ]
        );
    }

    /**
     * Recomputa todos los saldos de todos los períodos de la empresa.
     * Operación costosa — usar solo en rebuild manual.
     */
    public function recomputarTodo(int $empresaId): int
    {
        $periodos = Periodo::whereHas('ejercicio', fn ($q) => $q->where('empresa_id', $empresaId))
            ->pluck('id');

        $total = 0;
        foreach ($periodos as $periodoId) {
            $total += $this->recomputarPeriodo($periodoId);
        }

        return $total;
    }

    /**
     * Propaga saldo_final del período N como saldo_inicial del período N+1.
     * Solo aplica a cuentas patrimoniales (A, P, PN) — las cuentas de resultado (RP/RN)
     * se cierran al fin del ejercicio via refundición y arrancan en 0 el siguiente.
     *
     * Se invoca desde PeriodoService::cerrar() una vez que el período se selló.
     *
     * @return int filas actualizadas/insertadas en el período siguiente
     */
    public function propagarSaldosAlSiguientePeriodo(int $periodoCerradoId): int
    {
        $periodo = Periodo::findOrFail($periodoCerradoId);
        $empresaId = $periodo->ejercicio->empresa_id;

        // El período siguiente: mismo ejercicio con mes+1, o primer mes del ejercicio siguiente.
        $siguiente = Periodo::where('ejercicio_id', $periodo->ejercicio_id)
            ->where('anio', $periodo->anio)
            ->where('mes', $periodo->mes + 1)
            ->first();

        if (! $siguiente) {
            // Último período del ejercicio: el encadenado con el ejercicio siguiente
            // lo maneja el cierre de ejercicio (refundición deja patrimonio en 3.3.02).
            return 0;
        }

        if ($siguiente->estado !== 'ABIERTO') {
            // No pisamos saldos de un período ya cerrado.
            return 0;
        }

        // Saldos finales del período cerrado (solo cuentas patrimoniales)
        $saldosPatrimoniales = DB::table('erp_saldos_cuenta as s')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 's.cuenta_id')
            ->where('s.empresa_id', $empresaId)
            ->where('s.periodo_id', $periodoCerradoId)
            ->whereIn('c.tipo', ['A', 'P', 'PN'])
            ->select(['s.cuenta_id', 's.saldo_final'])
            ->get();

        $count = 0;
        foreach ($saldosPatrimoniales as $s) {
            if ((float) $s->saldo_final == 0) {
                continue;
            }

            DB::table('erp_saldos_cuenta')->updateOrInsert(
                [
                    'empresa_id' => $empresaId,
                    'cuenta_id' => $s->cuenta_id,
                    'periodo_id' => $siguiente->id,
                ],
                [
                    'saldo_inicial' => (float) $s->saldo_final,
                    // NO pisamos debitos/creditos existentes del período siguiente
                    'actualizado_en' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }
}
