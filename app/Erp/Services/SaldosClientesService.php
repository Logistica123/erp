<?php

namespace App\Erp\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth para saldos de clientes (RN-1 partida doble).
 *
 * Patrón: el saldo deudor real de un cliente sale de la cuenta contable
 * `1.1.4.01 Deudores por Ventas` filtrando por `auxiliar_id`. Eso suma
 * automáticamente facturas (debe), NC (haber), cobros (haber) y retenciones
 * (haber) — porque cada uno generó su asiento contabilizado vía servicio.
 *
 * Usado por:
 *   - DashboardController                     → saldoTotal(now())
 *   - ReportesVentasComprasController::aging  → aging($cliente, now())
 *   - CCPage frontend                         → saldoCliente($id, fecha)
 *   - FacturacionPage frontend                → facturadoBruto($desde, $hasta)
 */
class SaldosClientesService
{
    private const CUENTA_DEUDORES = '1.1.4.01';

    /**
     * Saldo deudor consolidado de TODOS los clientes a fecha de corte.
     * Resultado: SUM(debe - haber) sobre `1.1.4.01` para asientos
     * CONTABILIZADO con fecha <= corte.
     */
    public function saldoTotal(Carbon $fechaCorte, int $empresaId = 1): float
    {
        $row = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $empresaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('a.fecha', '<=', $fechaCorte->toDateString())
            ->where('c.codigo', self::CUENTA_DEUDORES)
            ->selectRaw('COALESCE(SUM(m.debe - m.haber), 0) as saldo')
            ->first();
        return round((float) ($row?->saldo ?? 0), 2);
    }

    /**
     * Saldo individual de un cliente (por auxiliar_id) a fecha de corte.
     */
    public function saldoCliente(int $auxiliarId, Carbon $fechaCorte, int $empresaId = 1): float
    {
        $row = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $empresaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('a.fecha', '<=', $fechaCorte->toDateString())
            ->where('c.codigo', self::CUENTA_DEUDORES)
            ->where('m.auxiliar_id', $auxiliarId)
            ->selectRaw('COALESCE(SUM(m.debe - m.haber), 0) as saldo')
            ->first();
        return round((float) ($row?->saldo ?? 0), 2);
    }

    /**
     * Aging por buckets para un cliente. Usa la fecha de cada movimiento
     * deudor pendiente como referencia de antigüedad.
     *
     * Buckets: 0-30 / 31-60 / 61-90 / 91+ días desde la fecha del asiento.
     *
     * @return array{rango_0_30:float, rango_31_60:float, rango_61_90:float, rango_91_plus:float, total:float}
     */
    public function aging(int $auxiliarId, Carbon $fechaCorte, int $empresaId = 1): array
    {
        // Tomamos cada línea (debe - haber) sobre Deudores y la clasificamos
        // por antigüedad. Es una aproximación: para precisión perfecta habría
        // que aplicar pagos a facturas individuales (FIFO o por referencia).
        // Esta versión es buena para dashboard y aceptable contablemente.
        $rows = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $empresaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('a.fecha', '<=', $fechaCorte->toDateString())
            ->where('c.codigo', self::CUENTA_DEUDORES)
            ->where('m.auxiliar_id', $auxiliarId)
            ->select('a.fecha', DB::raw('(m.debe - m.haber) as importe'))
            ->get();

        $buckets = ['rango_0_30' => 0.0, 'rango_31_60' => 0.0, 'rango_61_90' => 0.0, 'rango_91_plus' => 0.0];
        foreach ($rows as $r) {
            $dias = $fechaCorte->diffInDays(Carbon::parse($r->fecha));
            $bucket = match (true) {
                $dias <= 30 => 'rango_0_30',
                $dias <= 60 => 'rango_31_60',
                $dias <= 90 => 'rango_61_90',
                default => 'rango_91_plus',
            };
            $buckets[$bucket] += (float) $r->importe;
        }

        $total = array_sum($buckets);
        return [
            'rango_0_30'    => round($buckets['rango_0_30'], 2),
            'rango_31_60'   => round($buckets['rango_31_60'], 2),
            'rango_61_90'   => round($buckets['rango_61_90'], 2),
            'rango_91_plus' => round($buckets['rango_91_plus'], 2),
            'total'         => round($total, 2),
        ];
    }

    /**
     * Facturado bruto en un rango de fechas (sin restar cobros ni NC).
     * Para reportes fiscales / KPI "Total facturado del período".
     *
     * @return array{neto:float, iva:float, total:float, cantidad:int}
     */
    public function facturadoBruto(Carbon $desde, Carbon $hasta, int $empresaId = 1): array
    {
        $row = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $empresaId)
            ->whereIn('f.estado', ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL', 'COBRADA'])
            ->whereBetween('f.fecha_emision', [$desde->toDateString(), $hasta->toDateString()])
            ->whereNull('f.deleted_at')
            ->selectRaw('
                COUNT(*) as cantidad,
                COALESCE(SUM(f.imp_neto_gravado * tc.signo), 0) as neto,
                COALESCE(SUM(f.imp_iva * tc.signo), 0) as iva,
                COALESCE(SUM(f.imp_total * tc.signo), 0) as total
            ')
            ->first();

        return [
            'neto'     => round((float) ($row->neto ?? 0), 2),
            'iva'      => round((float) ($row->iva ?? 0), 2),
            'total'    => round((float) ($row->total ?? 0), 2),
            'cantidad' => (int) ($row->cantidad ?? 0),
        ];
    }

    /**
     * Saldos por cliente (todos) a fecha de corte. Útil para Aging
     * consolidado y para CC Clientes listado.
     *
     * @return array<int, array{auxiliar_id:int, nombre:string, cuit:?string, saldo:float}>
     */
    public function saldosPorCliente(Carbon $fechaCorte, int $empresaId = 1): array
    {
        $rows = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->join('erp_auxiliares as aux', 'aux.id', '=', 'm.auxiliar_id')
            ->where('a.empresa_id', $empresaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('a.fecha', '<=', $fechaCorte->toDateString())
            ->where('c.codigo', self::CUENTA_DEUDORES)
            ->whereNotNull('m.auxiliar_id')
            ->groupBy('aux.id', 'aux.nombre', 'aux.cuit')
            ->selectRaw('aux.id as auxiliar_id, aux.nombre, aux.cuit, ROUND(SUM(m.debe - m.haber), 2) as saldo')
            ->havingRaw('ABS(SUM(m.debe - m.haber)) > 0.01')
            ->orderByDesc('saldo')
            ->get();

        return $rows->map(fn ($r) => [
            'auxiliar_id' => (int) $r->auxiliar_id,
            'nombre'      => (string) $r->nombre,
            'cuit'        => $r->cuit,
            'saldo'       => (float) $r->saldo,
        ])->all();
    }
}
