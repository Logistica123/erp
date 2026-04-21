<?php

namespace App\Erp\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints read-only de facturas de venta contra erp_facturas_venta.
 * Joins con tipos_comprobante, puntos_venta, auxiliares, monedas y asiento.
 */
class FacturasVentaController extends Controller
{
    public function index(Request $request)
    {
        $q = DB::table('erp_facturas_venta as f')
            ->leftJoin('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->leftJoin('erp_puntos_venta as pv', 'pv.id', '=', 'f.punto_venta_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->leftJoin('erp_monedas as m', 'm.id', '=', 'f.moneda_id')
            ->leftJoin('erp_asientos as asi', 'asi.id', '=', 'f.asiento_id')
            ->where('f.empresa_id', 1)
            ->whereNull('f.deleted_at')
            ->select([
                'f.id', 'f.numero', 'f.cae', 'f.fecha_vto_cae', 'f.fecha_emision',
                'f.imp_neto_gravado', 'f.imp_iva', 'f.imp_total', 'f.origen', 'f.estado',
                'f.es_fce', 'f.created_at',
                'tc.codigo_interno as tipo_codigo', 'tc.nombre as tipo_nombre', 'tc.letra',
                'tc.clase as tipo_clase', 'tc.signo as tipo_signo',
                'pv.numero as pto_vta',
                'a.id as cliente_id', 'a.nombre as cliente_nombre', 'a.cuit as cliente_cuit',
                'm.codigo as moneda',
                'f.asiento_id', 'asi.numero as asiento_numero', 'asi.estado as asiento_estado',
            ]);

        if ($desde = $request->query('desde')) {
            $q->where('f.fecha_emision', '>=', $desde);
        }
        if ($hasta = $request->query('hasta')) {
            $q->where('f.fecha_emision', '<=', $hasta);
        }
        if ($estado = $request->query('estado')) {
            $q->where('f.estado', $estado);
        }
        if ($origen = $request->query('origen')) {
            $q->where('f.origen', $origen);
        }

        $data = $q->orderByDesc('f.fecha_emision')->orderByDesc('f.id')->limit(200)->get();

        return response()->json(['data' => $data]);
    }

    public function show(int $id)
    {
        $factura = DB::table('erp_facturas_venta as f')
            ->leftJoin('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->leftJoin('erp_puntos_venta as pv', 'pv.id', '=', 'f.punto_venta_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->leftJoin('erp_condiciones_iva as ci', 'ci.id', '=', 'f.condicion_iva_id')
            ->leftJoin('erp_monedas as m', 'm.id', '=', 'f.moneda_id')
            ->leftJoin('erp_asientos as asi', 'asi.id', '=', 'f.asiento_id')
            ->where('f.empresa_id', 1)
            ->where('f.id', $id)
            ->select([
                'f.*',
                'tc.codigo_interno as tipo_codigo', 'tc.nombre as tipo_nombre', 'tc.letra',
                'tc.clase as tipo_clase', 'tc.signo as tipo_signo',
                'pv.numero as pto_vta',
                'a.nombre as cliente_nombre', 'a.cuit as cliente_cuit', 'a.tipo as cliente_tipo',
                'ci.codigo_interno as condicion_iva_codigo', 'ci.nombre as condicion_iva_nombre',
                'm.codigo as moneda',
                'asi.numero as asiento_numero', 'asi.estado as asiento_estado', 'asi.fecha as asiento_fecha',
            ])
            ->first();

        if (!$factura) {
            return response()->json(['message' => 'Factura no encontrada'], 404);
        }

        $items = DB::table('erp_factura_venta_items as i')
            ->leftJoin('erp_alicuotas_iva as ai', 'ai.id', '=', 'i.alicuota_iva_id')
            ->where('i.factura_id', $id)
            ->select('i.*', 'ai.nombre as alicuota_nombre', 'ai.tasa as alicuota_tasa')
            ->orderBy('i.nro_linea')
            ->get();

        $iva = DB::table('erp_factura_venta_iva as v')
            ->leftJoin('erp_alicuotas_iva as ai', 'ai.id', '=', 'v.alicuota_iva_id')
            ->where('v.factura_id', $id)
            ->select('v.*', 'ai.nombre as alicuota_nombre', 'ai.tasa as alicuota_tasa')
            ->get();

        $asientoMovs = null;
        if ($factura->asiento_id) {
            $asientoMovs = DB::table('erp_movimientos_asiento as m')
                ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
                ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'm.auxiliar_id')
                ->where('m.asiento_id', $factura->asiento_id)
                ->select('m.linea', 'c.codigo', 'c.nombre as cuenta_nombre',
                    'm.debe', 'm.haber', 'a.nombre as auxiliar_nombre')
                ->orderBy('m.linea')
                ->get();
        }

        return response()->json([
            'factura' => $factura,
            'items' => $items,
            'iva' => $iva,
            'asiento_movimientos' => $asientoMovs,
        ]);
    }
}
