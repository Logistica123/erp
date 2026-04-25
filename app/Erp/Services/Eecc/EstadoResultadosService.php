<?php

namespace App\Erp\Services\Eecc;

use App\Erp\Models\Ejercicio;
use Illuminate\Support\Facades\DB;

/**
 * Estado de Resultados — agrupa por rubro las cuentas RP (ingresos) y RN
 * (egresos) del ejercicio. Devuelve el resultado neto antes y después de
 * impuestos a las ganancias.
 *
 * Ingresos (RP): saldo natural ACREEDOR (H − D).
 * Egresos  (RN): saldo natural DEUDOR (D − H).
 */
class EstadoResultadosService
{
    public function calcular(Ejercicio $ejercicio): array
    {
        $rows = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.ejercicio_id', $ejercicio->id)
            ->where('a.estado', 'CONTABILIZADO')
            ->whereIn('c.tipo', ['RP', 'RN'])
            ->where('c.imputable', 1)
            ->groupBy('c.id', 'c.codigo', 'c.nombre', 'c.tipo', 'c.rubro_ec')
            ->orderBy('c.codigo')
            ->select([
                'c.id', 'c.codigo', 'c.nombre', 'c.tipo', 'c.rubro_ec',
                DB::raw("CASE c.tipo WHEN 'RP' THEN SUM(m.haber - m.debe) ELSE SUM(m.debe - m.haber) END AS saldo"),
            ])
            ->get();

        $ingresos = ['rubros' => [], 'total' => 0.0];
        $egresos  = ['rubros' => [], 'total' => 0.0];
        $rubrosI = []; $rubrosE = [];

        foreach ($rows as $r) {
            $saldo = (float) $r->saldo;
            if (abs($saldo) < 0.01) {
                continue;
            }
            $rubroKey = (string) ($r->rubro_ec ?: 'Sin rubro');
            $bag = $r->tipo === 'RP' ? 'I' : 'E';

            if ($bag === 'I') {
                $rubrosI[$rubroKey] ??= ['rubro' => $rubroKey, 'cuentas' => [], 'total' => 0.0];
                $rubrosI[$rubroKey]['cuentas'][] = [
                    'codigo' => $r->codigo, 'nombre' => $r->nombre, 'saldo' => round($saldo, 2),
                ];
                $rubrosI[$rubroKey]['total'] += $saldo;
            } else {
                $rubrosE[$rubroKey] ??= ['rubro' => $rubroKey, 'cuentas' => [], 'total' => 0.0];
                $rubrosE[$rubroKey]['cuentas'][] = [
                    'codigo' => $r->codigo, 'nombre' => $r->nombre, 'saldo' => round($saldo, 2),
                ];
                $rubrosE[$rubroKey]['total'] += $saldo;
            }
        }

        foreach ($rubrosI as $r) {
            $r['total'] = round($r['total'], 2);
            $ingresos['rubros'][] = $r;
            $ingresos['total'] += $r['total'];
        }
        foreach ($rubrosE as $r) {
            $r['total'] = round($r['total'], 2);
            $egresos['rubros'][] = $r;
            $egresos['total'] += $r['total'];
        }
        $ingresos['total'] = round($ingresos['total'], 2);
        $egresos['total']  = round($egresos['total'], 2);

        $resultadoBruto = round($ingresos['total'] - $egresos['total'], 2);

        // Impuesto a las ganancias del ejercicio (si fue calculado en H5).
        $impuestoGan = (float) DB::table('erp_ganancias_liquidacion')
            ->where('ejercicio_id', $ejercicio->id)
            ->value('impuesto_determinado') ?? 0.0;

        return [
            'ejercicio_id'         => $ejercicio->id,
            'rango'                => [
                'desde' => (string) $ejercicio->fecha_inicio,
                'hasta' => (string) $ejercicio->fecha_cierre,
            ],
            'ingresos'             => $ingresos,
            'egresos'              => $egresos,
            'resultado_bruto'      => $resultadoBruto,
            'impuesto_ganancias'   => round($impuestoGan, 2),
            'resultado_ejercicio'  => round($resultadoBruto - $impuestoGan, 2),
        ];
    }
}
