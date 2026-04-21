<?php

namespace App\Erp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BalanceController
{
    /**
     * GET /api/erp/balance-sumas-saldos ?desde=&hasta=&nivel=
     *
     * Para cada cuenta imputable con movimientos en el rango:
     *   - saldo inicial
     *   - sumas debe / haber
     *   - saldo final
     *
     * Luego agrupa ascendiendo por la jerarquía de cuentas (nivel 3, 2, 1)
     * para dar totales por subrubro/rubro.
     */
    public function sumasSaldos(Request $request): JsonResponse
    {
        $data = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'nivel' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        $empresaId = $this->empresaIdFromRequest($request);
        $desde = $data['desde'] ?? null;
        $hasta = $data['hasta'] ?? null;
        $nivelMin = $data['nivel'] ?? 4;

        // Paso 1: saldos iniciales por cuenta imputable (movimientos antes de $desde)
        $saldoInicialQ = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('a.empresa_id', $empresaId)
            ->whereIn('a.estado', ['CONTABILIZADO', 'ANULADO'])
            ->select('m.cuenta_id', DB::raw('COALESCE(SUM(m.debe - m.haber), 0) AS saldo_inicial'))
            ->groupBy('m.cuenta_id');
        if ($desde) {
            $saldoInicialQ->where('a.fecha', '<', $desde);
        } else {
            // Sin "desde" el saldo inicial es 0 para todas.
            $saldoInicialQ->whereRaw('1 = 0');
        }

        // Paso 2: sumas de debe/haber del período
        $periodoQ = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('a.empresa_id', $empresaId)
            ->whereIn('a.estado', ['CONTABILIZADO', 'ANULADO'])
            ->select(
                'm.cuenta_id',
                DB::raw('COALESCE(SUM(m.debe), 0) AS debe'),
                DB::raw('COALESCE(SUM(m.haber), 0) AS haber')
            )
            ->groupBy('m.cuenta_id');
        if ($desde) {
            $periodoQ->where('a.fecha', '>=', $desde);
        }
        if ($hasta) {
            $periodoQ->where('a.fecha', '<=', $hasta);
        }

        // Paso 3: join con plan de cuentas
        $rows = DB::table('erp_cuentas_contables as c')
            ->where('c.empresa_id', $empresaId)
            ->where('c.imputable', true)
            ->leftJoinSub($saldoInicialQ, 'si', 'si.cuenta_id', '=', 'c.id')
            ->leftJoinSub($periodoQ, 'p', 'p.cuenta_id', '=', 'c.id')
            ->orderBy('c.codigo')
            ->select([
                'c.id',
                'c.codigo',
                'c.nombre',
                'c.nivel',
                'c.tipo',
                'c.moneda',
                DB::raw('COALESCE(si.saldo_inicial, 0) AS saldo_inicial'),
                DB::raw('COALESCE(p.debe, 0) AS debe'),
                DB::raw('COALESCE(p.haber, 0) AS haber'),
            ])
            ->get();

        // Filtro: solo cuentas con movimientos en período o saldo inicial ≠ 0
        $filas = $rows
            ->filter(fn ($r) => (float) $r->saldo_inicial != 0.0 || (float) $r->debe != 0.0 || (float) $r->haber != 0.0)
            ->map(fn ($r) => [
                'id' => $r->id,
                'codigo' => $r->codigo,
                'nombre' => $r->nombre,
                'nivel' => (int) $r->nivel,
                'tipo' => $r->tipo,
                'moneda' => $r->moneda,
                'saldo_inicial' => round((float) $r->saldo_inicial, 2),
                'debe' => round((float) $r->debe, 2),
                'haber' => round((float) $r->haber, 2),
                'saldo_final' => round((float) $r->saldo_inicial + (float) $r->debe - (float) $r->haber, 2),
            ])
            ->values();

        $totales = [
            'saldo_inicial' => round($filas->sum('saldo_inicial'), 2),
            'debe' => round($filas->sum('debe'), 2),
            'haber' => round($filas->sum('haber'), 2),
            'saldo_final' => round($filas->sum('saldo_final'), 2),
        ];

        return response()->json([
            'data' => [
                'rango' => ['desde' => $desde, 'hasta' => $hasta],
                'filas' => $filas,
                'totales' => $totales,
                'balanceado' => abs($totales['saldo_final']) < 0.01,
            ],
        ]);
    }

    private function empresaIdFromRequest(Request $request): int
    {
        $perfil = $request->user()->erpPerfil ?? null;

        return $perfil?->empresa_id ?? 1;
    }
}
