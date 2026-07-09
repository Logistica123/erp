<?php

namespace App\Erp\Services\Reportes;

use Illuminate\Support\Facades\DB;

/**
 * Cuenta Corriente de cliente o proveedor.
 *
 * Saldo de cada factura = imp_total − sum(items_aplicados.importe).
 * Items aplicados:
 *   - clientes  : `erp_cobro_items` con tipo_item='FACTURA_VENTA' apuntando a la factura.
 *   - proveedores: `erp_op_items` con tipo_item='FACTURA_COMPRA' apuntando a la factura.
 *
 * Devuelve la lista de facturas con saldo > 0, ordenadas por fecha de
 * emisión, junto al saldo total de la cuenta corriente.
 */
class CtaCorrienteService
{
    /** Estados de factura venta que generan deuda comercial. */
    private const ESTADOS_VTA = ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL'];

    /** Estados de factura compra que generan obligación de pago. */
    private const ESTADOS_CPRA = ['CONTROLADA', 'PAGO_PARCIAL'];

    public function clientes(int $empresaId, int $clienteId, ?string $fecha = null): array
    {
        $fecha = $fecha ?: now()->toDateString();

        $facturas = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_puntos_venta as pv', 'pv.id', '=', 'f.punto_venta_id')
            ->where('f.empresa_id', $empresaId)
            ->where('f.auxiliar_id', $clienteId)
            ->where('f.fecha_emision', '<=', $fecha)
            ->whereIn('f.estado', self::ESTADOS_VTA)
            ->whereNull('f.deleted_at')
            ->select([
                'f.id', 'f.numero', 'f.fecha_emision', 'f.fecha_vencimiento',
                't.codigo_interno as tipo_codigo', 't.letra', 't.signo',
                'pv.numero as pto_vta', 'f.imp_total',
            ])
            ->orderBy('f.fecha_emision')->orderBy('f.id')
            ->get();

        $aplicados = $this->aplicacionesCobros($facturas->pluck('id')->all(), $fecha);
        return $this->armarRows($facturas, $aplicados);
    }

    public function proveedores(int $empresaId, int $proveedorId, ?string $fecha = null): array
    {
        $fecha = $fecha ?: now()->toDateString();

        $facturas = DB::table('erp_facturas_compra as f')
            ->join('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $empresaId)
            ->where('f.auxiliar_id', $proveedorId)
            ->where('f.fecha_emision', '<=', $fecha)
            ->whereIn('f.estado', self::ESTADOS_CPRA)
            ->select([
                'f.id', 'f.numero', 'f.fecha_emision', 'f.fecha_vencimiento',
                't.codigo_interno as tipo_codigo', 't.letra', 't.signo',
                'f.punto_venta as pto_vta', 'f.imp_total',
            ])
            ->orderBy('f.fecha_emision')->orderBy('f.id')
            ->get();

        $aplicados = $this->aplicacionesPagos($facturas->pluck('id')->all(), $fecha);
        return $this->armarRows($facturas, $aplicados);
    }

    private function armarRows($facturas, array $aplicados): array
    {
        $rows = [];
        $saldoTotal = 0.0;
        foreach ($facturas as $f) {
            $aplic = (float) ($aplicados[$f->id] ?? 0);
            $signo = (int) $f->signo;
            $bruto = $signo * (float) $f->imp_total;
            $saldo = $bruto - ($signo * $aplic);

            if (abs($saldo) < 0.01) {
                continue;
            }
            $saldoTotal += $saldo;
            $rows[] = [
                'factura_id' => (int) $f->id,
                'tipo' => $f->letra.' '.$f->tipo_codigo,
                'pto_vta' => (int) $f->pto_vta,
                'numero' => (int) $f->numero,
                'fecha_emision' => (string) $f->fecha_emision,
                'fecha_vencimiento' => $f->fecha_vencimiento,
                'imp_total' => round($bruto, 2),
                'aplicado' => round($signo * $aplic, 2),
                'saldo' => round($saldo, 2),
            ];
        }

        return [
            'facturas' => $rows,
            'totales' => [
                'cantidad' => count($rows),
                'saldo' => round($saldoTotal, 2),
            ],
        ];
    }

    private function aplicacionesCobros(array $facturaIds, string $fecha): array
    {
        if (empty($facturaIds)) {
            return [];
        }
        $aplic = [];

        // v1.32 — Cobros vía recibos (multi-comprobante), recibos NO anulados.
        // El monto_imputado ya incluye la parte cubierta por NC (compensación),
        // así que las NC aplicadas A la factura NO se suman de nuevo acá.
        $conRecibo = [];
        $rec = DB::table('erp_recibos_comprobantes_imputados as rci')
            ->join('erp_recibos as r', 'r.id', '=', 'rci.recibo_id')
            ->whereIn('rci.factura_venta_id', $facturaIds)
            ->where('r.estado', '!=', 'ANULADO')
            ->where('r.fecha_emision', '<=', $fecha)
            ->groupBy('rci.factura_venta_id')
            ->select('rci.factura_venta_id as fid', DB::raw('SUM(rci.monto_imputado) AS m'))
            ->get();
        foreach ($rec as $x) {
            $aplic[$x->fid] = (float) $x->m;
            $conRecibo[$x->fid] = true;
        }

        // Cobros legacy (erp_cobro_items), solo para facturas sin recibo (no
        // doble contar las que ya se cobraron por el modelo nuevo).
        $cob = DB::table('erp_cobro_items as ci')
            ->join('erp_cobros as cb', 'cb.id', '=', 'ci.cobro_id')
            ->where('ci.tipo_item', 'FACTURA_VENTA')
            ->whereIn('ci.factura_id', $facturaIds)
            ->where('cb.fecha', '<=', $fecha)
            ->whereNotIn('cb.estado', ['ANULADO', 'RECHAZADO'])
            ->groupBy('ci.factura_id')
            ->select('ci.factura_id as fid', DB::raw('SUM(ci.importe) AS m'))
            ->get();
        foreach ($cob as $x) {
            if (! isset($conRecibo[$x->fid])) {
                $aplic[$x->fid] = ($aplic[$x->fid] ?? 0) + (float) $x->m;
            }
        }

        // NC consumidas: cada NC reduce su PROPIO saldo por lo que se imputó de
        // ella a facturas (erp_imputaciones_nc.nc_id). Sin esto, las NC ya
        // aplicadas seguían figurando como pendientes en la cuenta corriente.
        $nc = DB::table('erp_imputaciones_nc')
            ->whereIn('nc_id', $facturaIds)
            ->where('fecha_imputacion', '<=', $fecha)
            ->groupBy('nc_id')
            ->select('nc_id as fid', DB::raw('SUM(importe) AS m'))
            ->get();
        foreach ($nc as $x) {
            $aplic[$x->fid] = ($aplic[$x->fid] ?? 0) + (float) $x->m;
        }

        return $aplic;
    }

    private function aplicacionesPagos(array $facturaIds, string $fecha): array
    {
        if (empty($facturaIds)) {
            return [];
        }
        $rows = DB::table('erp_op_items as oi')
            ->join('erp_ordenes_pago as op', 'op.id', '=', 'oi.op_id')
            ->where('oi.tipo_item', 'FACTURA_COMPRA')
            ->whereIn('oi.comprobante_id', $facturaIds)
            ->where('op.fecha', '<=', $fecha)
            ->whereNotIn('op.estado', ['ANULADA', 'RECHAZADA'])
            ->groupBy('oi.comprobante_id')
            ->select('oi.comprobante_id as factura_id', DB::raw('SUM(oi.importe) AS aplicado'))
            ->get();
        return $rows->pluck('aplicado', 'factura_id')->map(fn ($v) => (float) $v)->all();
    }
}
