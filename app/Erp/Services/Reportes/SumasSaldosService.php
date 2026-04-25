<?php

namespace App\Erp\Services\Reportes;

use Illuminate\Support\Facades\DB;

/**
 * Sumas y Saldos: por cada cuenta imputable con movimiento en el rango,
 * devuelve sumas debe/haber del período + saldo deudor/acreedor.
 */
class SumasSaldosService
{
    /**
     * @return array{
     *   filas:array<int,array>,
     *   totales:array{debe:float,haber:float,saldo_deudor:float,saldo_acreedor:float}
     * }
     */
    public function calcular(int $empresaId, string $desde, string $hasta): array
    {
        $rows = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $empresaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->groupBy('c.id', 'c.codigo', 'c.nombre', 'c.tipo', 'c.saldo_normal')
            ->orderBy('c.codigo')
            ->select([
                'c.id', 'c.codigo', 'c.nombre', 'c.tipo', 'c.saldo_normal',
                DB::raw('SUM(m.debe) as debe'),
                DB::raw('SUM(m.haber) as haber'),
            ])
            ->get();

        $filas = [];
        $tDebe = 0.0;
        $tHaber = 0.0;
        $tSaldoD = 0.0;
        $tSaldoH = 0.0;

        foreach ($rows as $r) {
            $debe = (float) $r->debe;
            $haber = (float) $r->haber;
            $diff = $debe - $haber;
            $saldoD = $diff > 0 ? $diff : 0.0;
            $saldoH = $diff < 0 ? -$diff : 0.0;

            $tDebe += $debe;
            $tHaber += $haber;
            $tSaldoD += $saldoD;
            $tSaldoH += $saldoH;

            $filas[] = [
                'cuenta_id' => (int) $r->id,
                'codigo' => $r->codigo,
                'nombre' => $r->nombre,
                'tipo' => $r->tipo,
                'debe' => round($debe, 2),
                'haber' => round($haber, 2),
                'saldo_deudor' => round($saldoD, 2),
                'saldo_acreedor' => round($saldoH, 2),
            ];
        }

        return [
            'rango' => ['desde' => $desde, 'hasta' => $hasta],
            'filas' => $filas,
            'totales' => [
                'debe' => round($tDebe, 2),
                'haber' => round($tHaber, 2),
                'saldo_deudor' => round($tSaldoD, 2),
                'saldo_acreedor' => round($tSaldoH, 2),
            ],
        ];
    }
}
