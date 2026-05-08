<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\SaldosClientesService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reportes Ventas/Compras (SPEC 03 §6.5):
 *   GET /reportes/libro-iva-compras
 *   GET /reportes/pendientes-control
 *   GET /reportes/antiguedad-saldos       (aging clientes)
 *   GET /reportes/antiguedad-proveedores  (aging proveedores)
 *   GET /reportes/fce-estados
 *
 * Libro IVA Ventas (ya existía) queda en LibroIvaController::ventas.
 */
class ReportesVentasComprasController extends Controller
{
    public function __construct(private readonly SaldosClientesService $saldos) {}

    public function libroIvaCompras(Request $request): JsonResponse
    {
        $data = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);
        $desde = $data['desde'] ?? date('Y-m-01');
        $hasta = $data['hasta'] ?? date('Y-m-t');

        $rows = DB::table('erp_facturas_compra as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->leftJoin('erp_condiciones_iva as ci', 'ci.id', '=', 'f.condicion_iva_id')
            ->leftJoin('erp_monedas as m', 'm.id', '=', 'f.moneda_id')
            ->where('f.empresa_id', 1)
            ->whereNull('f.deleted_at')
            ->whereBetween('f.fecha_emision', [$desde, $hasta])
            ->select([
                'f.id', 'f.fecha_emision', 'f.numero', 'f.punto_venta', 'f.cae', 'f.estado',
                'tc.codigo_interno as tipo_codigo', 'tc.letra', 'tc.clase', 'tc.signo',
                'a.cuit as cuit_emisor', 'a.nombre as razon_social',
                'ci.codigo_interno as cond_iva', 'm.codigo as moneda',
                'f.imp_neto_gravado', 'f.imp_no_gravado', 'f.imp_exento',
                'f.imp_iva', 'f.imp_percepciones', 'f.imp_tributos', 'f.imp_total',
            ])
            ->orderBy('f.fecha_emision')->orderBy('f.id')
            ->get();

        $totales = [
            'neto_gravado' => round((float) $rows->sum('imp_neto_gravado'), 2),
            'no_gravado' => round((float) $rows->sum('imp_no_gravado'), 2),
            'exento' => round((float) $rows->sum('imp_exento'), 2),
            'iva' => round((float) $rows->sum('imp_iva'), 2),
            'percepciones' => round((float) $rows->sum('imp_percepciones'), 2),
            'tributos' => round((float) $rows->sum('imp_tributos'), 2),
            'total' => round((float) $rows->sum('imp_total'), 2),
            'cantidad' => $rows->count(),
        ];

        return response()->json([
            'ok' => true,
            'data' => [
                'rango' => ['desde' => $desde, 'hasta' => $hasta],
                'comprobantes' => $rows,
                'totales' => $totales,
            ],
        ]);
    }

    /**
     * Facturas pendientes del "tilde" (RN-31 gate).
     * Compras RECIBIDAS sin control y ventas EMITIDAS sin control.
     */
    public function pendientesControl(): JsonResponse
    {
        $compras = DB::table('erp_facturas_compra as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->where('f.empresa_id', 1)
            ->whereIn('f.estado', ['RECIBIDA', 'OBSERVADA'])
            ->whereNull('f.deleted_at')
            ->select([
                'f.id', 'f.fecha_emision', 'f.numero', 'f.imp_total', 'f.estado',
                'tc.codigo_interno as tipo', 'tc.letra',
                'a.nombre as proveedor', 'a.cuit',
            ])
            ->orderBy('f.fecha_emision')->orderBy('f.id')
            ->limit(500)->get();

        $ventas = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->where('f.empresa_id', 1)
            ->where('f.estado', 'EMITIDA')
            ->whereNull('f.deleted_at')
            ->select([
                'f.id', 'f.fecha_emision', 'f.numero', 'f.imp_total', 'f.estado',
                'tc.codigo_interno as tipo', 'tc.letra',
                'a.nombre as cliente', 'a.cuit',
            ])
            ->orderBy('f.fecha_emision')->orderBy('f.id')
            ->limit(500)->get();

        return response()->json([
            'ok' => true,
            'data' => [
                'compras' => [
                    'items' => $compras,
                    'cantidad' => $compras->count(),
                    'importe_total' => round((float) $compras->sum('imp_total'), 2),
                ],
                'ventas' => [
                    'items' => $ventas,
                    'cantidad' => $ventas->count(),
                    'importe_total' => round((float) $ventas->sum('imp_total'), 2),
                ],
            ],
        ]);
    }

    public function antiguedadSaldos(Request $request): JsonResponse
    {
        $hoy = Carbon::today();
        $items = [];
        foreach ($this->saldos->saldosPorCliente($hoy) as $row) {
            $aging = $this->saldos->aging($row['auxiliar_id'], $hoy);
            $items[] = array_merge($row, $aging, ['cantidad_facturas' => 1]);
        }
        usort($items, fn ($a, $b) => $b['saldo'] <=> $a['saldo']);

        $tot = fn ($k) => round(array_sum(array_column($items, $k)), 2);
        return response()->json([
            'ok' => true,
            'data' => [
                'tipo' => 'clientes',
                'items' => $items,
                'totales' => [
                    'rango_0_30'    => $tot('rango_0_30'),
                    'rango_31_60'   => $tot('rango_31_60'),
                    'rango_61_90'   => $tot('rango_61_90'),
                    'rango_91_plus' => $tot('rango_91_plus'),
                    'total'         => $tot('saldo'),
                ],
            ],
        ]);
    }

    public function antiguedadProveedores(Request $request): JsonResponse
    {
        return $this->antiguedad('compra');
    }

    private function antiguedad(string $tipo): JsonResponse
    {
        $hoy = Carbon::today();
        $tabla = $tipo === 'venta' ? 'erp_facturas_venta' : 'erp_facturas_compra';
        $estados = $tipo === 'venta'
            ? ['CONTROLADA', 'COBRO_PARCIAL']
            : ['CONTROLADA', 'PAGO_PARCIAL'];

        // Addendum v1.12 Sprint C — incluir tc.signo para que las NC resten
        // en el total y los buckets en lugar de sumar (bug pre-existente).
        $rows = DB::table("{$tabla} as f")
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->join('erp_monedas as m', 'm.id', '=', 'f.moneda_id')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->whereIn('f.estado', $estados)
            ->whereNull('f.deleted_at')
            ->where('f.empresa_id', 1)
            ->select([
                'a.id as auxiliar_id', 'a.nombre', 'a.cuit',
                'f.id', 'f.fecha_emision', 'f.fecha_vencimiento',
                'f.imp_total', 'm.codigo as moneda', 'tc.signo',
            ])
            ->get();

        $buckets = [];
        foreach ($rows as $r) {
            $k = $r->auxiliar_id;
            $buckets[$k] ??= [
                'auxiliar_id' => $r->auxiliar_id,
                'nombre' => $r->nombre,
                'cuit' => $r->cuit,
                'moneda' => $r->moneda,
                'rango_0_30' => 0, 'rango_31_60' => 0, 'rango_61_90' => 0, 'rango_91_plus' => 0,
                'total' => 0, 'cantidad_facturas' => 0,
            ];
            $ref = $r->fecha_vencimiento ?? $r->fecha_emision;
            $dias = $ref ? $hoy->diffInDays(Carbon::parse($ref), false) * -1 : 0;

            $col = match (true) {
                $dias <= 30 => 'rango_0_30',
                $dias <= 60 => 'rango_31_60',
                $dias <= 90 => 'rango_61_90',
                default => 'rango_91_plus',
            };
            // tc.signo: +1 para FACTURA/ND, -1 para NC (definido en seed
            // 03_ventas_compras). El total respeta la convención contable.
            $importeFirmado = (float) $r->imp_total * (int) ($r->signo ?? 1);
            $buckets[$k][$col] += $importeFirmado;
            $buckets[$k]['total'] += $importeFirmado;
            $buckets[$k]['cantidad_facturas']++;
        }

        $items = array_values($buckets);
        usort($items, fn ($a, $b) => $b['total'] <=> $a['total']);

        return response()->json([
            'ok' => true,
            'data' => [
                'tipo' => $tipo === 'venta' ? 'clientes' : 'proveedores',
                'items' => $items,
                'totales' => [
                    'rango_0_30' => round(array_sum(array_column($items, 'rango_0_30')), 2),
                    'rango_31_60' => round(array_sum(array_column($items, 'rango_31_60')), 2),
                    'rango_61_90' => round(array_sum(array_column($items, 'rango_61_90')), 2),
                    'rango_91_plus' => round(array_sum(array_column($items, 'rango_91_plus')), 2),
                    'total' => round(array_sum(array_column($items, 'total')), 2),
                ],
            ],
        ]);
    }

    public function fceEstados(Request $request): JsonResponse
    {
        $rows = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->where('f.empresa_id', 1)
            ->where('f.es_fce', true)
            ->whereNull('f.deleted_at')
            ->select([
                'f.id', 'f.fecha_emision', 'f.numero', 'f.imp_total',
                'f.estado', 'f.estado_fce',
                'tc.letra', 'tc.codigo_interno as tipo',
                'a.nombre as cliente', 'a.cuit',
            ])
            ->orderByDesc('f.fecha_emision')
            ->limit(500)->get();

        $byEstadoFce = $rows->groupBy('estado_fce')->map(function ($grupo) {
            return [
                'cantidad' => $grupo->count(),
                'importe_total' => round((float) $grupo->sum('imp_total'), 2),
            ];
        });

        return response()->json([
            'ok' => true,
            'data' => [
                'facturas' => $rows,
                'resumen_por_estado_fce' => $byEstadoFce,
            ],
        ]);
    }
}
