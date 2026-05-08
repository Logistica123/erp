<?php

namespace App\Erp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.14 — endpoints de reports analíticos sobre los nuevos campos
 * (centro_costo_id, periodo_trabajado_texto, jurisdiccion_codigo).
 *
 *   GET /api/erp/reportes/ventas-por-cliente
 *   GET /api/erp/reportes/gastos-por-cliente
 *   GET /api/erp/reportes/margen-por-cliente
 *   GET /api/erp/reportes/ventas-por-jurisdiccion
 *   GET /api/erp/reportes/gastos-por-jurisdiccion
 *
 * Filtros comunes:
 *   - desde, hasta: rango de fecha_emision (formato YYYY-MM-DD).
 *   - periodo_trabajado: filtro exacto sobre periodo_trabajado_texto (ej. "2026-03").
 *   - jurisdiccion: código IIBB (901-924).
 *
 * Solo cuenta facturas no eliminadas (`deleted_at IS NULL`). Para compras
 * se excluyen además las `no_tomada=1` (no impactan contablemente).
 */
class ReportesV14Controller
{
    public function ventasPorCliente(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        [$desde, $hasta, $periodoTrab, $juris] = $this->filtros($request);

        $rows = DB::table('erp_facturas_venta as fv')
            ->leftJoin('erp_centros_costo as cc', 'cc.id', '=', 'fv.centro_costo_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'fv.auxiliar_id')
            ->where('fv.empresa_id', $empresaId)
            ->whereNull('fv.deleted_at')
            ->when($desde, fn ($q) => $q->where('fv.fecha_emision', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('fv.fecha_emision', '<=', $hasta))
            ->when($periodoTrab, fn ($q) => $q->where('fv.periodo_trabajado_texto', $periodoTrab))
            ->when($juris, fn ($q) => $q->where('fv.jurisdiccion_codigo', $juris))
            ->select(
                'cc.id as cc_id',
                'cc.codigo as cc_codigo',
                DB::raw('COALESCE(cc.nombre, a.nombre, "—") as nombre'),
                DB::raw('COUNT(*) as facturas'),
                DB::raw('SUM(fv.imp_neto_gravado) as neto'),
                DB::raw('SUM(fv.imp_iva) as iva'),
                DB::raw('SUM(fv.imp_total) as total'),
            )
            ->groupBy('cc.id', 'cc.codigo', 'cc.nombre', 'a.nombre')
            ->orderByDesc('total')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function gastosPorCliente(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        [$desde, $hasta, $periodoTrab, $juris] = $this->filtros($request);

        $rows = DB::table('erp_facturas_compra as fc')
            ->leftJoin('erp_centros_costo as cc', 'cc.id', '=', 'fc.centro_costo_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'fc.cliente_auxiliar_id')
            ->where('fc.empresa_id', $empresaId)
            ->whereNull('fc.deleted_at')
            ->where('fc.no_tomada', 0)
            ->when($desde, fn ($q) => $q->where('fc.fecha_imputacion', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('fc.fecha_imputacion', '<=', $hasta))
            ->when($periodoTrab, fn ($q) => $q->where('fc.periodo_trabajado_texto', $periodoTrab))
            ->when($juris, fn ($q) => $q->where('fc.jurisdiccion_codigo', $juris))
            ->select(
                'cc.id as cc_id',
                'cc.codigo as cc_codigo',
                DB::raw('COALESCE(cc.nombre, a.nombre, "—") as nombre'),
                DB::raw('COUNT(*) as facturas'),
                DB::raw('SUM(fc.imp_neto_gravado) as neto'),
                DB::raw('SUM(fc.imp_iva) as iva'),
                DB::raw('SUM(fc.imp_total) as total'),
            )
            ->groupBy('cc.id', 'cc.codigo', 'cc.nombre', 'a.nombre')
            ->orderByDesc('total')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /**
     * Margen por cliente = ventas (neto) - gastos imputados a ese cliente (neto).
     * Devuelve un row por CC con ventas, gastos, margen y % margen.
     */
    public function margenPorCliente(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        [$desde, $hasta, $periodoTrab, $juris] = $this->filtros($request);
        $top = (int) ($request->query('top', 50));

        $ventas = DB::table('erp_facturas_venta as fv')
            ->where('fv.empresa_id', $empresaId)
            ->whereNull('fv.deleted_at')
            ->when($desde, fn ($q) => $q->where('fv.fecha_emision', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('fv.fecha_emision', '<=', $hasta))
            ->when($periodoTrab, fn ($q) => $q->where('fv.periodo_trabajado_texto', $periodoTrab))
            ->when($juris, fn ($q) => $q->where('fv.jurisdiccion_codigo', $juris))
            ->whereNotNull('fv.centro_costo_id')
            ->groupBy('fv.centro_costo_id')
            ->select('fv.centro_costo_id as cc_id', DB::raw('SUM(fv.imp_neto_gravado) as ventas'))
            ->get()
            ->keyBy('cc_id');

        $gastos = DB::table('erp_facturas_compra as fc')
            ->where('fc.empresa_id', $empresaId)
            ->whereNull('fc.deleted_at')
            ->where('fc.no_tomada', 0)
            ->when($desde, fn ($q) => $q->where('fc.fecha_imputacion', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('fc.fecha_imputacion', '<=', $hasta))
            ->when($periodoTrab, fn ($q) => $q->where('fc.periodo_trabajado_texto', $periodoTrab))
            ->when($juris, fn ($q) => $q->where('fc.jurisdiccion_codigo', $juris))
            ->whereNotNull('fc.centro_costo_id')
            ->groupBy('fc.centro_costo_id')
            ->select('fc.centro_costo_id as cc_id', DB::raw('SUM(fc.imp_neto_gravado) as gastos'))
            ->get()
            ->keyBy('cc_id');

        $ccIds = collect($ventas->keys())->merge($gastos->keys())->unique()->values();
        $ccs = DB::table('erp_centros_costo')->whereIn('id', $ccIds)->get(['id', 'codigo', 'nombre'])->keyBy('id');

        $rows = $ccIds->map(function ($id) use ($ventas, $gastos, $ccs) {
            $v = (float) ($ventas[$id]->ventas ?? 0);
            $g = (float) ($gastos[$id]->gastos ?? 0);
            $cc = $ccs[$id] ?? null;
            $margen = $v - $g;
            return [
                'cc_id' => (int) $id,
                'cc_codigo' => $cc?->codigo,
                'nombre' => $cc?->nombre ?? '—',
                'ventas' => round($v, 2),
                'gastos' => round($g, 2),
                'margen' => round($margen, 2),
                'pct_margen' => $v > 0 ? round(($margen / $v) * 100, 2) : null,
            ];
        })
        ->sortByDesc('margen')
        ->take($top)
        ->values();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function ventasPorJurisdiccion(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        [$desde, $hasta, $periodoTrab,] = $this->filtros($request);

        $rows = DB::table('erp_facturas_venta as fv')
            ->leftJoin('erp_iibb_jurisdicciones as j', 'j.codigo', '=', 'fv.jurisdiccion_codigo')
            ->where('fv.empresa_id', $empresaId)
            ->whereNull('fv.deleted_at')
            ->when($desde, fn ($q) => $q->where('fv.fecha_emision', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('fv.fecha_emision', '<=', $hasta))
            ->when($periodoTrab, fn ($q) => $q->where('fv.periodo_trabajado_texto', $periodoTrab))
            ->select(
                'fv.jurisdiccion_codigo as codigo',
                DB::raw('COALESCE(j.nombre, "Sin jurisdicción") as nombre'),
                DB::raw('COUNT(*) as facturas'),
                DB::raw('SUM(fv.imp_neto_gravado) as neto'),
                DB::raw('SUM(fv.imp_iva) as iva'),
                DB::raw('SUM(fv.imp_total) as total'),
            )
            ->groupBy('fv.jurisdiccion_codigo', 'j.nombre')
            ->orderByDesc('total')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function gastosPorJurisdiccion(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        [$desde, $hasta, $periodoTrab,] = $this->filtros($request);

        $rows = DB::table('erp_facturas_compra as fc')
            ->leftJoin('erp_iibb_jurisdicciones as j', 'j.codigo', '=', 'fc.jurisdiccion_codigo')
            ->where('fc.empresa_id', $empresaId)
            ->whereNull('fc.deleted_at')
            ->where('fc.no_tomada', 0)
            ->when($desde, fn ($q) => $q->where('fc.fecha_imputacion', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('fc.fecha_imputacion', '<=', $hasta))
            ->when($periodoTrab, fn ($q) => $q->where('fc.periodo_trabajado_texto', $periodoTrab))
            ->select(
                'fc.jurisdiccion_codigo as codigo',
                DB::raw('COALESCE(j.nombre, "Sin jurisdicción") as nombre'),
                DB::raw('COUNT(*) as facturas'),
                DB::raw('SUM(fc.imp_neto_gravado) as neto'),
                DB::raw('SUM(fc.imp_iva) as iva'),
                DB::raw('SUM(fc.imp_total) as total'),
            )
            ->groupBy('fc.jurisdiccion_codigo', 'j.nombre')
            ->orderByDesc('total')
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /**
     * @return array{0:?string,1:?string,2:?string,3:?string} desde, hasta, periodo_trabajado, jurisdiccion
     */
    private function filtros(Request $request): array
    {
        return [
            $this->dateOrNull($request->query('desde')),
            $this->dateOrNull($request->query('hasta')),
            $request->query('periodo_trabajado') ?: null,
            $request->query('jurisdiccion') ?: null,
        ];
    }

    private function dateOrNull(?string $v): ?string
    {
        if (! $v) return null;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
