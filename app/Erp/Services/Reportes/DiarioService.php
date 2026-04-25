<?php

namespace App\Erp\Services\Reportes;

use Illuminate\Support\Facades\DB;

/**
 * Libro Diario: lista cronológica de asientos con sus movimientos.
 */
class DiarioService
{
    /**
     * @return array{
     *   asientos:array<int,array{cabecera:array,movimientos:array<int,array>}>,
     *   totales:array{debe:float,haber:float,cantidad:int}
     * }
     */
    public function calcular(int $empresaId, string $desde, string $hasta, ?int $diarioId = null): array
    {
        $asientosQ = DB::table('erp_asientos as a')
            ->leftJoin('erp_diarios as d', 'd.id', '=', 'a.diario_id')
            ->where('a.empresa_id', $empresaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->whereBetween('a.fecha', [$desde, $hasta]);
        if ($diarioId) {
            $asientosQ->where('a.diario_id', $diarioId);
        }
        $asientos = $asientosQ
            ->orderBy('a.fecha')->orderBy('a.numero')
            ->select([
                'a.id', 'a.fecha', 'a.numero', 'a.glosa', 'a.origen',
                'a.total_debe', 'a.total_haber',
                'd.codigo as diario_codigo', 'd.nombre as diario_nombre',
            ])
            ->get();

        if ($asientos->isEmpty()) {
            return ['asientos' => [], 'totales' => ['debe' => 0.0, 'haber' => 0.0, 'cantidad' => 0]];
        }

        $movs = DB::table('erp_movimientos_asiento as m')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->leftJoin('erp_auxiliares as ax', 'ax.id', '=', 'm.auxiliar_id')
            ->leftJoin('erp_centros_costo as cc', 'cc.id', '=', 'm.centro_costo_id')
            ->whereIn('m.asiento_id', $asientos->pluck('id'))
            ->orderBy('m.asiento_id')->orderBy('m.linea')
            ->select([
                'm.asiento_id', 'm.linea', 'c.codigo as cuenta_codigo', 'c.nombre as cuenta_nombre',
                'm.debe', 'm.haber', 'm.glosa', 'ax.nombre as auxiliar', 'cc.codigo as centro_costo',
            ])
            ->get()
            ->groupBy('asiento_id');

        $out = [];
        $tDebe = 0.0;
        $tHaber = 0.0;
        foreach ($asientos as $a) {
            $tDebe += (float) $a->total_debe;
            $tHaber += (float) $a->total_haber;
            $out[] = [
                'cabecera' => [
                    'id' => (int) $a->id,
                    'fecha' => (string) $a->fecha,
                    'numero' => (int) $a->numero,
                    'diario' => (string) ($a->diario_codigo ?? ''),
                    'origen' => (string) $a->origen,
                    'glosa' => (string) ($a->glosa ?? ''),
                    'total_debe' => round((float) $a->total_debe, 2),
                    'total_haber' => round((float) $a->total_haber, 2),
                ],
                'movimientos' => collect($movs->get($a->id, []))->map(fn ($m) => [
                    'linea' => (int) $m->linea,
                    'cuenta' => $m->cuenta_codigo.' '.$m->cuenta_nombre,
                    'debe' => round((float) $m->debe, 2),
                    'haber' => round((float) $m->haber, 2),
                    'glosa' => (string) ($m->glosa ?? ''),
                    'auxiliar' => $m->auxiliar,
                    'centro_costo' => $m->centro_costo,
                ])->all(),
            ];
        }

        return [
            'rango' => ['desde' => $desde, 'hasta' => $hasta],
            'asientos' => $out,
            'totales' => [
                'debe' => round($tDebe, 2),
                'haber' => round($tHaber, 2),
                'cantidad' => $asientos->count(),
            ],
        ];
    }
}
