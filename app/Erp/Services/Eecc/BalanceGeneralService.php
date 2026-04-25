<?php

namespace App\Erp\Services\Eecc;

use App\Erp\Models\Ejercicio;
use Illuminate\Support\Facades\DB;

/**
 * Balance General — agrupa cuentas A / P / PN del ejercicio en rubros y
 * verifica que A = P + PN (con tolerancia 0,01).
 *
 * El saldo de cada cuenta toma el acumulado al cierre del ejercicio:
 *   A:  D - H  (deudor)
 *   P:  H - D  (acreedor)
 *   PN: H - D  (acreedor)
 */
class BalanceGeneralService
{
    /**
     * @return array{
     *   ejercicio_id:int,
     *   activo:array{rubros:array, total:float},
     *   pasivo:array{rubros:array, total:float},
     *   patrimonio:array{rubros:array, total:float},
     *   verificacion:array{cierra:bool, diferencia:float}
     * }
     */
    public function calcular(Ejercicio $ejercicio): array
    {
        $hasta = $ejercicio->fecha_cierre instanceof \DateTimeInterface
            ? $ejercicio->fecha_cierre->format('Y-m-d')
            : (string) $ejercicio->fecha_cierre;

        $rows = $this->saldosCuentasPatrimoniales($ejercicio, $hasta);

        $activo = ['rubros' => [], 'total' => 0.0];
        $pasivo = ['rubros' => [], 'total' => 0.0];
        $patrimonio = ['rubros' => [], 'total' => 0.0];

        $rubros = ['activo' => [], 'pasivo' => [], 'patrimonio' => []];

        foreach ($rows as $r) {
            $tipo = $r->tipo;
            $saldo = (float) $r->saldo;
            if (abs($saldo) < 0.01) {
                continue;
            }

            $rubroKey = (string) ($r->rubro_ec ?: 'Sin rubro');

            $bucket = match ($tipo) {
                'A'  => 'activo',
                'P'  => 'pasivo',
                'PN' => 'patrimonio',
                default => null,
            };
            if ($bucket === null) {
                continue;
            }
            $rubros[$bucket][$rubroKey] ??= ['rubro' => $rubroKey, 'cuentas' => [], 'total' => 0.0];
            $rubros[$bucket][$rubroKey]['cuentas'][] = [
                'codigo' => $r->codigo, 'nombre' => $r->nombre,
                'saldo'  => round($saldo, 2),
            ];
            $rubros[$bucket][$rubroKey]['total'] += $saldo;
        }

        $activo['rubros']     = $this->finalizarRubros($rubros['activo'], $activo['total']);
        $pasivo['rubros']     = $this->finalizarRubros($rubros['pasivo'], $pasivo['total']);
        $patrimonio['rubros'] = $this->finalizarRubros($rubros['patrimonio'], $patrimonio['total']);

        $diff = round($activo['total'] - ($pasivo['total'] + $patrimonio['total']), 2);

        return [
            'ejercicio_id' => $ejercicio->id,
            'fecha_corte'  => $hasta,
            'activo'       => $activo,
            'pasivo'       => $pasivo,
            'patrimonio'   => $patrimonio,
            'verificacion' => [
                'cierra'     => abs($diff) <= 0.01,
                'diferencia' => $diff,
            ],
        ];
    }

    private function saldosCuentasPatrimoniales(Ejercicio $ejercicio, string $hasta)
    {
        return DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('a.fecha', '<=', $hasta)
            ->whereIn('c.tipo', ['A', 'P', 'PN'])
            ->where('c.imputable', 1)
            ->groupBy('c.id', 'c.codigo', 'c.nombre', 'c.tipo', 'c.rubro_ec')
            ->orderBy('c.codigo')
            ->select([
                'c.id', 'c.codigo', 'c.nombre', 'c.tipo', 'c.rubro_ec',
                DB::raw("CASE c.tipo WHEN 'A' THEN SUM(m.debe - m.haber) ELSE SUM(m.haber - m.debe) END AS saldo"),
            ])
            ->get();
    }

    private function finalizarRubros(array $rubros, float &$totalRef): array
    {
        $out = [];
        foreach ($rubros as $r) {
            $r['total'] = round($r['total'], 2);
            $totalRef += $r['total'];
            $out[] = $r;
        }
        $totalRef = round($totalRef, 2);
        return $out;
    }
}
