<?php

namespace App\Erp\Services\Reportes;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aging de cuentas por cobrar / pagar (RN-68).
 *
 * Cohortes según `fecha_vencimiento` de cada factura:
 *   - corriente : no vencida (vencimiento >= fecha de corte)
 *   - 1-30      : vencida 1..30 días
 *   - 31-60     : vencida 31..60 días
 *   - 61-90     : vencida 61..90 días
 *   - +90       : vencida > 90 días
 *
 * Saldo de la factura = imp_total − aplicaciones (cobros/OP) hasta la fecha
 * de corte. Si aplicación parcial, el saldo remanente entra a la cohorte
 * por vencimiento original.
 *
 * Devuelve resumen por cliente/proveedor + totales por cohorte.
 */
class AgingService
{
    private const ESTADOS_VTA = ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL'];
    private const ESTADOS_CPRA = ['CONTROLADA', 'PAGO_PARCIAL'];

    public function __construct(private readonly CtaCorrienteService $cc) {}

    /**
     * @param string $tipo  'clientes' o 'proveedores'
     * @return array{
     *   tipo:string, fecha_corte:string,
     *   por_auxiliar:array<int,array>,
     *   totales:array{corriente:float,rango_1_30:float,rango_31_60:float,rango_61_90:float,rango_91_plus:float,total:float}
     * }
     */
    public function calcular(int $empresaId, string $tipo, ?string $fecha = null): array
    {
        $fecha = $fecha ?: now()->toDateString();

        if ($tipo === 'clientes') {
            $tabla = 'erp_facturas_venta';
            $estados = self::ESTADOS_VTA;
        } elseif ($tipo === 'proveedores') {
            $tabla = 'erp_facturas_compra';
            $estados = self::ESTADOS_CPRA;
        } else {
            throw new \InvalidArgumentException("AGING_TIPO_INVALIDO: {$tipo}");
        }

        $rows = DB::table("{$tabla} as f")
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->join('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $empresaId)
            ->whereIn('f.estado', $estados)
            ->where('f.fecha_emision', '<=', $fecha)
            ->select([
                'a.id as auxiliar_id', 'a.nombre', 'a.cuit',
                'f.id as factura_id', 'f.fecha_emision', 'f.fecha_vencimiento',
                'f.imp_total', 't.signo',
            ])
            ->orderBy('a.nombre')
            ->get();

        // Aplicaciones por factura.
        $facIds = $rows->pluck('factura_id')->all();
        $aplicados = $tipo === 'clientes'
            ? $this->aplicCobros($facIds, $fecha)
            : $this->aplicPagos($facIds, $fecha);

        $hoy = Carbon::parse($fecha);
        $bucketsByAux = [];
        $totales = [
            'corriente' => 0.0, 'rango_1_30' => 0.0, 'rango_31_60' => 0.0,
            'rango_61_90' => 0.0, 'rango_91_plus' => 0.0, 'total' => 0.0,
        ];

        foreach ($rows as $r) {
            $signo = (int) $r->signo;
            $bruto = $signo * (float) $r->imp_total;
            $aplic = $signo * (float) ($aplicados[$r->factura_id] ?? 0);
            $saldo = $bruto - $aplic;
            if (abs($saldo) < 0.01) {
                continue;
            }

            $venc = $r->fecha_vencimiento ? Carbon::parse($r->fecha_vencimiento) : null;
            $diasVencido = $venc ? $hoy->diffInDays($venc, false) : 0;
            // diffInDays($venc, false): negativo si $venc en el pasado (vencido).
            $atraso = -$diasVencido;

            $bucket = match (true) {
                $atraso <= 0       => 'corriente',
                $atraso <= 30      => 'rango_1_30',
                $atraso <= 60      => 'rango_31_60',
                $atraso <= 90      => 'rango_61_90',
                default            => 'rango_91_plus',
            };

            $key = $r->auxiliar_id;
            $bucketsByAux[$key] ??= [
                'auxiliar_id' => (int) $r->auxiliar_id,
                'nombre' => $r->nombre, 'cuit' => $r->cuit,
                'corriente' => 0.0, 'rango_1_30' => 0.0, 'rango_31_60' => 0.0,
                'rango_61_90' => 0.0, 'rango_91_plus' => 0.0, 'total' => 0.0,
                'cantidad_facturas' => 0,
            ];
            $bucketsByAux[$key][$bucket] += $saldo;
            $bucketsByAux[$key]['total'] += $saldo;
            $bucketsByAux[$key]['cantidad_facturas']++;

            $totales[$bucket] += $saldo;
            $totales['total'] += $saldo;
        }

        // Round.
        $por = array_values(array_map(function ($r) {
            foreach (['corriente', 'rango_1_30', 'rango_31_60', 'rango_61_90', 'rango_91_plus', 'total'] as $k) {
                $r[$k] = round($r[$k], 2);
            }
            return $r;
        }, $bucketsByAux));
        foreach ($totales as $k => $v) {
            $totales[$k] = round($v, 2);
        }

        // Ordenar: vencidas más severas primero.
        usort($por, fn ($a, $b) => $b['rango_91_plus'] <=> $a['rango_91_plus']);

        return [
            'tipo' => $tipo,
            'fecha_corte' => $fecha,
            'por_auxiliar' => $por,
            'totales' => $totales,
        ];
    }

    private function aplicCobros(array $facturaIds, string $fecha): array
    {
        if (empty($facturaIds)) {
            return [];
        }
        return DB::table('erp_cobro_items as ci')
            ->join('erp_cobros as cb', 'cb.id', '=', 'ci.cobro_id')
            ->where('ci.tipo_item', 'FACTURA_VENTA')
            ->whereIn('ci.factura_id', $facturaIds)
            ->where('cb.fecha', '<=', $fecha)
            ->whereNotIn('cb.estado', ['ANULADO', 'RECHAZADO'])
            ->groupBy('ci.factura_id')
            ->select('ci.factura_id', DB::raw('SUM(ci.importe) AS aplicado'))
            ->get()
            ->pluck('aplicado', 'factura_id')->map(fn ($v) => (float) $v)->all();
    }

    private function aplicPagos(array $facturaIds, string $fecha): array
    {
        if (empty($facturaIds)) {
            return [];
        }
        return DB::table('erp_op_items as oi')
            ->join('erp_ordenes_pago as op', 'op.id', '=', 'oi.op_id')
            ->where('oi.tipo_item', 'FACTURA_COMPRA')
            ->whereIn('oi.comprobante_id', $facturaIds)
            ->where('op.fecha', '<=', $fecha)
            ->whereNotIn('op.estado', ['ANULADA', 'RECHAZADA'])
            ->groupBy('oi.comprobante_id')
            ->select('oi.comprobante_id as factura_id', DB::raw('SUM(oi.importe) AS aplicado'))
            ->get()
            ->pluck('aplicado', 'factura_id')->map(fn ($v) => (float) $v)->all();
    }
}
