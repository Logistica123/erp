<?php

namespace App\Erp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reportes operativos de Tesorería (SPEC 02 §6.9).
 *
 *   GET /api/erp/reportes/saldos              saldo actual consolidado
 *   GET /api/erp/reportes/flujo-caja          ingresos/egresos/saldo día a día
 *   GET /api/erp/reportes/pendientes-conciliar  movimientos bancarios pendientes
 *   GET /api/erp/reportes/echeq-en-cartera     eCheq a depositar/acreditar
 *
 * Usa las vistas SQL ya creadas en DDL_03 (v_tesoreria_saldos,
 * v_mov_bancarios_pendientes, v_echeq_en_cartera).
 */
class ReportesTesoreriaController
{
    public function saldos(): JsonResponse
    {
        $rows = DB::table('v_tesoreria_saldos')->orderBy('cuenta_id')->get();

        $totalesPorMoneda = $rows->groupBy('moneda')->map(function ($grupo) {
            return [
                'cantidad_cuentas' => $grupo->count(),
                'saldo_total' => round((float) $grupo->sum('saldo_actual'), 2),
            ];
        });

        return response()->json([
            'ok' => true,
            'data' => [
                'cuentas' => $rows,
                'totales_por_moneda' => $totalesPorMoneda,
                'generado_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Flujo de caja histórico: agrupa débitos/créditos por día a partir de
     * movimientos bancarios CONCILIADOS y conceptos de caja.
     * Intervalo default: últimos 90 días.
     */
    public function flujoCaja(Request $request): JsonResponse
    {
        $data = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'cuenta_bancaria_id' => ['nullable', 'integer'],
        ]);

        $hasta = Carbon::parse($data['hasta'] ?? now()->toDateString());
        $desde = Carbon::parse($data['desde'] ?? $hasta->copy()->subDays(90)->toDateString());

        $query = DB::table('erp_movimientos_bancarios as m')
            ->join('erp_cuentas_bancarias as cb', 'cb.id', '=', 'm.cuenta_bancaria_id')
            ->where('m.estado', 'CONCILIADO')
            ->whereBetween('m.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when($data['cuenta_bancaria_id'] ?? null, fn ($q, $v) => $q->where('m.cuenta_bancaria_id', $v))
            ->groupBy('m.fecha')
            ->orderBy('m.fecha')
            ->selectRaw('
                m.fecha AS fecha,
                SUM(m.credito) AS ingresos,
                SUM(m.debito) AS egresos,
                SUM(m.credito - m.debito) AS flujo_neto
            ');

        $rows = $query->get();

        // Calcular saldo corrido desde el saldo inicial acumulado antes del rango.
        $saldoInicial = (float) DB::table('erp_movimientos_bancarios as m')
            ->join('erp_cuentas_bancarias as cb', 'cb.id', '=', 'm.cuenta_bancaria_id')
            ->where('m.estado', 'CONCILIADO')
            ->where('m.fecha', '<', $desde->toDateString())
            ->when($data['cuenta_bancaria_id'] ?? null, fn ($q, $v) => $q->where('m.cuenta_bancaria_id', $v))
            ->selectRaw('COALESCE(SUM(m.credito - m.debito), 0) AS saldo')
            ->value('saldo');

        $saldo = $saldoInicial;
        $dias = $rows->map(function ($r) use (&$saldo) {
            $saldo = round($saldo + (float) $r->flujo_neto, 2);

            return [
                'fecha' => $r->fecha,
                'ingresos' => round((float) $r->ingresos, 2),
                'egresos' => round((float) $r->egresos, 2),
                'flujo_neto' => round((float) $r->flujo_neto, 2),
                'saldo_corrido' => $saldo,
            ];
        });

        return response()->json([
            'ok' => true,
            'data' => [
                'rango' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
                'saldo_inicial' => round($saldoInicial, 2),
                'dias' => $dias,
                'totales' => [
                    'ingresos' => round((float) $rows->sum('ingresos'), 2),
                    'egresos' => round((float) $rows->sum('egresos'), 2),
                    'flujo_neto' => round((float) $rows->sum('flujo_neto'), 2),
                ],
            ],
        ]);
    }

    public function pendientesConciliar(Request $request): JsonResponse
    {
        $cb = $request->integer('cuenta_bancaria_id');

        $query = DB::table('v_mov_bancarios_pendientes');
        if ($cb) {
            $query->whereIn('id', DB::table('erp_movimientos_bancarios')->where('cuenta_bancaria_id', $cb)->pluck('id'));
        }

        $rows = $query->limit(500)->get();

        $resumen = [
            'total' => $rows->count(),
            'pendientes' => $rows->where('estado', 'PENDIENTE')->count(),
            'etiquetados' => $rows->where('estado', 'ETIQUETADO')->count(),
            'suma_debitos' => round((float) $rows->sum('debito'), 2),
            'suma_creditos' => round((float) $rows->sum('credito'), 2),
        ];

        return response()->json([
            'ok' => true,
            'data' => ['movimientos' => $rows, 'resumen' => $resumen],
        ]);
    }

    public function echeqEnCartera(Request $request): JsonResponse
    {
        $query = DB::table('v_echeq_en_cartera')->orderBy('fecha_pago');
        $rows = $query->get();

        $resumen = [
            'total' => $rows->count(),
            'suma_importe' => round((float) $rows->sum('importe'), 2),
            'vencen_proximos_7d' => $rows->where('dias_a_vencimiento', '<=', 7)->where('dias_a_vencimiento', '>=', 0)->count(),
            'vencidos' => $rows->where('dias_a_vencimiento', '<', 0)->count(),
        ];

        return response()->json([
            'ok' => true,
            'data' => ['echeq' => $rows, 'resumen' => $resumen],
        ]);
    }
}
