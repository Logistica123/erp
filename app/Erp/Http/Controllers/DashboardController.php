<?php

namespace App\Erp\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KPIs y stats agregados para el dashboard. Todo read-only.
 */
class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $empresaId = 1;
        $hoy = Carbon::today();
        $inicioMes = $hoy->copy()->startOfMonth()->toDateString();
        $finMes = $hoy->copy()->endOfMonth()->toDateString();

        // Facturas mes (signadas: NC resta) — todos los estados activos.
        $mesTotales = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $empresaId)
            ->whereIn('f.estado', ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL', 'COBRADA'])
            ->whereBetween('f.fecha_emision', [$inicioMes, $finMes])
            ->whereNull('f.deleted_at')
            ->selectRaw('
                COUNT(*) as cant,
                COALESCE(SUM(f.imp_total * tc.signo), 0) as total,
                COALESCE(SUM(f.imp_neto_gravado * tc.signo), 0) as neto,
                COALESCE(SUM(f.imp_iva * tc.signo), 0) as iva
            ')
            ->first();

        // Saldo a cobrar (con IVA, a hoy) — alineado con Aging clientes:
        // facturas + NC en estados pendientes (CONTROLADA / COBRO_PARCIAL),
        // neteando con tc.signo (FACTURA=+1, NC=-1). EMITIDA queda fuera porque
        // todavía no transicionó por contabilidad.
        $pendientes = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $empresaId)
            ->whereIn('f.estado', ['CONTROLADA', 'COBRO_PARCIAL'])
            ->whereIn('tc.clase', ['FACTURA', 'NOTA_CREDITO'])
            ->whereNull('f.deleted_at')
            ->selectRaw('
                SUM(CASE WHEN tc.clase = "FACTURA" THEN 1 ELSE 0 END) as cant,
                COALESCE(SUM(f.imp_total * tc.signo), 0) as total
            ')
            ->first();

        // Evolución últimos 6 meses (facturado neto + total)
        $seisMeses = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $hoy->copy()->subMonths($i);
            $ini = $m->copy()->startOfMonth()->toDateString();
            $fin = $m->copy()->endOfMonth()->toDateString();
            $row = DB::table('erp_facturas_venta as f')
                ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                ->where('f.empresa_id', $empresaId)
                ->whereIn('f.estado', ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL', 'COBRADA'])
                ->whereBetween('f.fecha_emision', [$ini, $fin])
                ->whereNull('f.deleted_at')
                ->selectRaw('COUNT(*) as cant, COALESCE(SUM(f.imp_total * tc.signo), 0) as total')
                ->first();
            $seisMeses[] = [
                'label' => mb_strtolower($m->locale('es')->isoFormat('MMM')),
                'anio' => $m->year,
                'mes' => $m->month,
                'cant' => (int) $row->cant,
                'total' => (float) $row->total,
                'actual' => $i === 0,
            ];
        }

        // Últimas 5 facturas
        $ultimasFacturas = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_puntos_venta as pv', 'pv.id', '=', 'f.punto_venta_id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->where('f.empresa_id', $empresaId)
            ->whereNull('f.deleted_at')
            ->select(
                'f.id', 'f.fecha_emision', 'f.numero', 'f.imp_total', 'f.estado', 'f.origen',
                'tc.codigo_interno as tipo_codigo', 'tc.letra',
                'pv.numero as pto_vta',
                'a.nombre as cliente_nombre',
            )
            ->orderByDesc('f.fecha_emision')->orderByDesc('f.id')
            ->limit(5)
            ->get();

        // Últimos 5 asientos contabilizados
        $ultimosAsientos = DB::table('erp_asientos as a')
            ->join('erp_diarios as d', 'd.id', '=', 'a.diario_id')
            ->where('a.empresa_id', $empresaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->select('a.id', 'a.numero', 'a.fecha', 'a.glosa', 'a.total_debe', 'd.codigo as diario')
            ->orderByDesc('a.fecha')->orderByDesc('a.id')
            ->limit(5)
            ->get();

        // Contadores de integración
        $clientes = DB::table('erp_auxiliares')->where('empresa_id', $empresaId)
            ->where('tipo', 'Cliente')->where('activo', 1)->count();
        $distribuidores = DB::table('erp_auxiliares')->where('empresa_id', $empresaId)
            ->where('tipo', 'Distribuidor')->where('activo', 1)->count();
        $asientosTotal = DB::table('erp_asientos')->where('empresa_id', $empresaId)
            ->where('estado', 'CONTABILIZADO')->count();

        // Período abierto actual
        $periodo = DB::table('erp_periodos as p')
            ->join('erp_ejercicios as e', 'e.id', '=', 'p.ejercicio_id')
            ->where('e.empresa_id', $empresaId)
            ->where('p.estado', 'ABIERTO')
            ->orderBy('p.anio')->orderBy('p.mes')
            ->select('p.id', 'p.anio', 'p.mes', 'p.estado')
            ->first();

        return response()->json([
            'fecha' => $hoy->toDateString(),
            'periodo_actual' => $periodo,
            'mes' => [
                'inicio' => $inicioMes,
                'fin' => $finMes,
                'facturas' => (int) $mesTotales->cant,
                'facturado_total' => (float) $mesTotales->total,
                'facturado_neto' => (float) $mesTotales->neto,
                'iva_df' => (float) $mesTotales->iva,
            ],
            'por_cobrar' => [
                'cant' => (int) $pendientes->cant,
                'total' => (float) $pendientes->total,
            ],
            'contadores' => [
                'clientes' => $clientes,
                'distribuidores' => $distribuidores,
                'asientos_contabilizados' => $asientosTotal,
            ],
            'evolucion_6m' => $seisMeses,
            'ultimas_facturas' => $ultimasFacturas,
            'ultimos_asientos' => $ultimosAsientos,
        ]);
    }
}
