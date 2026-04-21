<?php

namespace App\Erp\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Libro IVA Ventas — vista interna por período.
 * Lee erp_facturas_venta + joins. Totales por alícuota y grandes totales.
 * (Formato F.8001 AFIP para DDJJ queda fuera del alcance v1, ver SPEC 05 §2.1.)
 */
class LibroIvaController extends Controller
{
    public function ventas(Request $request): JsonResponse
    {
        $data = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);
        $desde = $data['desde'] ?? date('Y-m-01');
        $hasta = $data['hasta'] ?? date('Y-m-t');

        $rows = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_puntos_venta as pv', 'pv.id', '=', 'f.punto_venta_id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->leftJoin('erp_condiciones_iva as ci', 'ci.id', '=', 'f.condicion_iva_id')
            ->where('f.empresa_id', 1)
            ->where('f.estado', 'EMITIDA')
            ->whereBetween('f.fecha_emision', [$desde, $hasta])
            ->whereNull('f.deleted_at')
            ->select([
                'f.id', 'f.fecha_emision', 'f.numero', 'f.cae', 'f.doc_tipo_afip', 'f.doc_nro',
                'f.imp_neto_gravado', 'f.imp_no_gravado', 'f.imp_exento', 'f.imp_iva',
                'f.imp_tributos', 'f.imp_total', 'f.origen', 'f.asiento_id',
                'tc.codigo_interno as tipo_codigo', 'tc.letra', 'tc.signo as tipo_signo',
                'tc.clase as tipo_clase',
                'pv.numero as pto_vta',
                'a.nombre as cliente_nombre', 'a.cuit as cliente_cuit',
                'ci.codigo_interno as condicion_iva',
            ])
            ->orderBy('f.fecha_emision')->orderBy('f.id')
            ->get();

        // Totales por alícuota (de erp_factura_venta_iva)
        $facturaIds = $rows->pluck('id');
        $porAlicuota = [];
        if ($facturaIds->count()) {
            $porAlicuota = DB::table('erp_factura_venta_iva as v')
                ->join('erp_alicuotas_iva as ai', 'ai.id', '=', 'v.alicuota_iva_id')
                ->join('erp_facturas_venta as f', 'f.id', '=', 'v.factura_id')
                ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                ->whereIn('v.factura_id', $facturaIds)
                ->select(
                    'ai.codigo_interno', 'ai.nombre as alicuota_nombre', 'ai.tasa',
                    DB::raw('SUM(v.base_imponible * tc.signo) as base_total'),
                    DB::raw('SUM(v.importe_iva * tc.signo) as iva_total'),
                    DB::raw('COUNT(DISTINCT v.factura_id) as cant_cbtes'),
                )
                ->groupBy('ai.id', 'ai.codigo_interno', 'ai.nombre', 'ai.tasa')
                ->orderBy('ai.tasa')
                ->get();
        }

        // Grandes totales (aplicando signo — NC resta)
        $totales = $rows->reduce(function ($acc, $r) {
            $signo = (int) $r->tipo_signo ?: 1;
            $acc['cant'] += 1;
            $acc['neto'] += $signo * (float) $r->imp_neto_gravado;
            $acc['no_gravado'] += $signo * (float) $r->imp_no_gravado;
            $acc['exento'] += $signo * (float) $r->imp_exento;
            $acc['iva'] += $signo * (float) $r->imp_iva;
            $acc['tributos'] += $signo * (float) $r->imp_tributos;
            $acc['total'] += $signo * (float) $r->imp_total;
            return $acc;
        }, ['cant' => 0, 'neto' => 0, 'no_gravado' => 0, 'exento' => 0, 'iva' => 0, 'tributos' => 0, 'total' => 0]);

        // Totales por tipo de comprobante
        $porTipo = $rows->groupBy('tipo_codigo')->map(function ($grp, $codigo) {
            $signo = (int) $grp->first()->tipo_signo ?: 1;
            return [
                'tipo_codigo' => $codigo,
                'letra' => $grp->first()->letra,
                'cant' => $grp->count(),
                'neto' => $signo * $grp->sum(fn ($r) => (float) $r->imp_neto_gravado),
                'iva' => $signo * $grp->sum(fn ($r) => (float) $r->imp_iva),
                'total' => $signo * $grp->sum(fn ($r) => (float) $r->imp_total),
            ];
        })->values();

        return response()->json([
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'comprobantes' => $rows,
            'por_alicuota' => $porAlicuota,
            'por_tipo' => $porTipo,
            'totales' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $totales),
        ]);
    }
}
