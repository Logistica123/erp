<?php

namespace App\Erp\Services\Reportes;

use Illuminate\Support\Facades\DB;

/**
 * Libro Mayor de una cuenta contable: muestra movimientos cronológicos
 * con saldo corriente.
 *
 *   ?cuenta_id=...&desde=...&hasta=...&empresa_id=...
 *
 * Devuelve:
 *   - cabecera (cuenta + saldo inicial al inicio del rango)
 *   - movimientos: [{fecha, asiento_nro, glosa, debe, haber, saldo_corriente}]
 *   - totales (debe, haber, saldo_final)
 *
 * Saldo natural según `c.saldo_normal`: DEUDOR (saldo = D - H) o ACREEDOR
 * (saldo = H - D). Si no está seteado, asumimos según tipo: A/RN deudor, P/PN/RP acreedor.
 */
class MayorService
{
    /**
     * @return array{
     *   cuenta:array, saldo_inicial:float,
     *   movimientos:array<int,array>,
     *   totales:array{debe:float,haber:float,saldo_final:float}
     * }
     */
    public function calcular(int $empresaId, int $cuentaId, string $desde, string $hasta): array
    {
        $cuenta = DB::table('erp_cuentas_contables')
            ->where('id', $cuentaId)
            ->where('empresa_id', $empresaId)
            ->first();

        if (! $cuenta) {
            return ['cuenta' => null, 'saldo_inicial' => 0.0, 'movimientos' => [], 'totales' => [
                'debe' => 0.0, 'haber' => 0.0, 'saldo_final' => 0.0,
            ]];
        }

        $signo = $this->signoSaldo((string) $cuenta->tipo, (string) ($cuenta->saldo_normal ?? ''));

        // Saldo inicial: acumulado antes de `desde`.
        $iniRow = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('a.empresa_id', $empresaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('m.cuenta_id', $cuentaId)
            ->where('a.fecha', '<', $desde)
            ->select(DB::raw('COALESCE(SUM(m.debe),0) AS d, COALESCE(SUM(m.haber),0) AS h'))
            ->first();
        $saldoInicial = round($signo * ((float) $iniRow->d - (float) $iniRow->h), 2);

        // Movimientos del rango.
        $rows = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->leftJoin('erp_diarios as d', 'd.id', '=', 'a.diario_id')
            ->leftJoin('erp_auxiliares as ax', 'ax.id', '=', 'm.auxiliar_id')
            ->leftJoin('erp_centros_costo as cc', 'cc.id', '=', 'm.centro_costo_id')
            ->where('a.empresa_id', $empresaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('m.cuenta_id', $cuentaId)
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->orderBy('a.fecha')->orderBy('a.numero')->orderBy('m.linea')
            ->select([
                'a.fecha', 'a.numero', 'a.glosa', 'd.codigo as diario',
                'm.debe', 'm.haber', 'm.glosa as glosa_linea',
                'ax.nombre as auxiliar', 'cc.codigo as centro_costo',
            ])
            ->get();

        $saldoCorr = $saldoInicial;
        $totDebe = 0.0;
        $totHaber = 0.0;
        $movimientos = [];
        foreach ($rows as $r) {
            $debe = (float) $r->debe;
            $haber = (float) $r->haber;
            $totDebe += $debe;
            $totHaber += $haber;
            $saldoCorr += $signo * ($debe - $haber);
            $movimientos[] = [
                'fecha'         => (string) $r->fecha,
                'asiento_nro'   => (int) $r->numero,
                'diario'        => (string) ($r->diario ?? ''),
                'glosa'         => (string) ($r->glosa_linea ?? $r->glosa ?? ''),
                'auxiliar'      => $r->auxiliar,
                'centro_costo'  => $r->centro_costo,
                'debe'          => round($debe, 2),
                'haber'         => round($haber, 2),
                'saldo'         => round($saldoCorr, 2),
            ];
        }

        return [
            'cuenta' => [
                'id' => (int) $cuenta->id, 'codigo' => $cuenta->codigo,
                'nombre' => $cuenta->nombre, 'tipo' => $cuenta->tipo,
                'saldo_normal' => $cuenta->saldo_normal,
            ],
            'rango' => ['desde' => $desde, 'hasta' => $hasta],
            'saldo_inicial' => $saldoInicial,
            'movimientos' => $movimientos,
            'totales' => [
                'debe' => round($totDebe, 2),
                'haber' => round($totHaber, 2),
                'saldo_final' => round($saldoCorr, 2),
            ],
        ];
    }

    private function signoSaldo(string $tipo, string $saldoNormal): int
    {
        if ($saldoNormal === 'DEUDOR') {
            return 1;
        }
        if ($saldoNormal === 'ACREEDOR') {
            return -1;
        }
        // Fallback por tipo de cuenta.
        return in_array($tipo, ['A', 'RN'], true) ? 1 : -1;
    }
}
