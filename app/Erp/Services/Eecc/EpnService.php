<?php

namespace App\Erp\Services\Eecc;

use App\Erp\Models\Ejercicio;
use Illuminate\Support\Facades\DB;

/**
 * Estado de Evolución del Patrimonio Neto (RN-61).
 *
 * Muestra el saldo inicial de cada cuenta PN al inicio del ejercicio,
 * los movimientos del ejercicio (aportes, revalúos, distribuciones,
 * resultado del ejercicio) y el saldo final.
 *
 * Lectura simple: por cada cuenta PN, debe-haber acumulados al inicio
 * y dentro del ejercicio. Saldo natural ACREEDOR.
 */
class EpnService
{
    public function calcular(Ejercicio $ejercicio): array
    {
        $inicio = (string) $ejercicio->fecha_inicio;
        $cierre = (string) $ejercicio->fecha_cierre;

        // Saldo inicial: movimientos contabilizados antes del inicio del ejercicio.
        $iniciales = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('a.fecha', '<', $inicio)
            ->where('c.tipo', 'PN')
            ->where('c.imputable', 1)
            ->groupBy('c.id', 'c.codigo', 'c.nombre')
            ->select([
                'c.id', 'c.codigo', 'c.nombre',
                DB::raw('SUM(m.haber - m.debe) AS saldo_inicial'),
            ])
            ->get()->keyBy('id');

        // Movimientos dentro del ejercicio.
        $delEjercicio = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('a.ejercicio_id', $ejercicio->id)
            ->where('c.tipo', 'PN')
            ->where('c.imputable', 1)
            ->groupBy('c.id', 'c.codigo', 'c.nombre')
            ->select([
                'c.id', 'c.codigo', 'c.nombre',
                DB::raw('SUM(m.debe) AS d, SUM(m.haber) AS h'),
            ])
            ->get()->keyBy('id');

        $cuentasUnion = collect($iniciales->keys())->merge($delEjercicio->keys())->unique();

        $filas = [];
        $totIni = 0.0; $totAumentos = 0.0; $totDisminuciones = 0.0; $totFinal = 0.0;

        foreach ($cuentasUnion as $cuentaId) {
            $iniRow = $iniciales->get($cuentaId);
            $movRow = $delEjercicio->get($cuentaId);

            $saldoIni = $iniRow ? round((float) $iniRow->saldo_inicial, 2) : 0.0;
            $debe  = $movRow ? (float) $movRow->d : 0.0;
            $haber = $movRow ? (float) $movRow->h : 0.0;

            // Para PN (acreedor): aumentos = haber, disminuciones = debe.
            $aumentos     = round($haber, 2);
            $disminuciones= round($debe, 2);
            $saldoFinal   = round($saldoIni + $aumentos - $disminuciones, 2);

            if (abs($saldoIni) < 0.01 && abs($aumentos) < 0.01 && abs($disminuciones) < 0.01) {
                continue;
            }

            $info = $iniRow ?: $movRow;
            $filas[] = [
                'cuenta_id'      => (int) $cuentaId,
                'codigo'         => $info->codigo,
                'nombre'         => $info->nombre,
                'saldo_inicial'  => $saldoIni,
                'aumentos'       => $aumentos,
                'disminuciones'  => $disminuciones,
                'saldo_final'    => $saldoFinal,
            ];
            $totIni += $saldoIni;
            $totAumentos += $aumentos;
            $totDisminuciones += $disminuciones;
            $totFinal += $saldoFinal;
        }

        usort($filas, fn ($a, $b) => $a['codigo'] <=> $b['codigo']);

        return [
            'ejercicio_id' => $ejercicio->id,
            'rango'        => ['desde' => $inicio, 'hasta' => $cierre],
            'filas'        => $filas,
            'totales'      => [
                'saldo_inicial' => round($totIni, 2),
                'aumentos'      => round($totAumentos, 2),
                'disminuciones' => round($totDisminuciones, 2),
                'saldo_final'   => round($totFinal, 2),
            ],
        ];
    }
}
