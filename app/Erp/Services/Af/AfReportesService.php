<?php

namespace App\Erp\Services\Af;

use App\Erp\Models\Ejercicio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reportes de Activos Fijos (SPEC 06 §6.4).
 *
 *   - listado(): inventario completo con valor origen, amort acum y residual
 *     a una fecha dada (default: hoy).
 *   - anexoBienesUso(): formato RT 9 — por categoría, saldo inicial + altas
 *     + bajas + amort del ejercicio + amort acum + saldo final.
 *   - altasBajas(): movimientos del ejercicio (ALTA + BAJA).
 *   - amortizacionesContableVsFiscal(): por bien y mes, las dos columnas
 *     (alimenta ajuste F.713 de SPEC 05).
 */
class AfReportesService
{
    /**
     * Inventario al corte de fecha.
     */
    public function listado(int $empresaId, ?string $fecha = null, array $filtros = []): array
    {
        $fecha = $fecha ?: now()->toDateString();

        $q = DB::table('erp_af_bienes as b')
            ->join('erp_af_categorias as c', 'c.id', '=', 'b.categoria_id')
            ->leftJoin('erp_centros_costo as cc', 'cc.id', '=', 'b.centro_costo_id')
            ->leftJoin('users as u', 'u.id', '=', 'b.responsable_user_id')
            ->where('b.empresa_id', $empresaId)
            ->whereNull('b.deleted_at')
            ->where('b.fecha_alta', '<=', $fecha)
            ->where(function ($q2) use ($fecha) {
                $q2->whereNull('b.fecha_baja')->orWhere('b.fecha_baja', '>', $fecha);
            });

        foreach (['categoria_id', 'centro_costo_id', 'responsable_user_id', 'estado'] as $k) {
            if (! empty($filtros[$k])) {
                $q->where("b.{$k}", $filtros[$k]);
            }
        }

        $rows = $q->orderBy('b.nro_inventario')
            ->select([
                'b.id', 'b.nro_inventario', 'b.descripcion', 'b.marca', 'b.modelo',
                'b.fecha_alta', 'b.valor_origen', 'b.estado',
                'c.codigo as categoria_codigo', 'c.nombre as categoria_nombre',
                'cc.codigo as cc_codigo', 'cc.nombre as cc_nombre',
                'u.name as responsable',
            ])->get();

        // Acompañamos con amort acum a la fecha de corte.
        $bienesIds = $rows->pluck('id')->all();
        $amortAcum = $this->amortAcumPorBien($bienesIds, $fecha);

        $filas = [];
        $totalOrigen = 0.0;
        $totalAmort = 0.0;
        foreach ($rows as $r) {
            $aa = (float) ($amortAcum[$r->id] ?? 0);
            $vo = (float) $r->valor_origen;
            $totalOrigen += $vo;
            $totalAmort += $aa;
            $filas[] = [
                'id' => (int) $r->id, 'nro_inventario' => $r->nro_inventario,
                'descripcion' => $r->descripcion,
                'marca' => $r->marca, 'modelo' => $r->modelo,
                'fecha_alta' => (string) $r->fecha_alta,
                'categoria' => $r->categoria_codigo.' '.$r->categoria_nombre,
                'centro_costo' => $r->cc_codigo,
                'responsable' => $r->responsable,
                'estado' => $r->estado,
                'valor_origen' => round($vo, 2),
                'amort_acum'   => round($aa, 2),
                'valor_residual' => round($vo - $aa, 2),
            ];
        }

        return [
            'fecha_corte' => $fecha,
            'cantidad' => count($filas),
            'totales' => [
                'valor_origen'   => round($totalOrigen, 2),
                'amort_acum'     => round($totalAmort, 2),
                'valor_residual' => round($totalOrigen - $totalAmort, 2),
            ],
            'filas' => $filas,
        ];
    }

    /**
     * Anexo de Bienes de Uso formato RT 9 (RN-83). Por cada categoría:
     *   - saldo_inicial:     suma valor_origen de bienes con fecha_alta < inicio
     *   - altas:             suma valor_origen de bienes con alta dentro del rango
     *   - bajas:             suma valor_origen de bienes dados de baja en rango
     *   - amort_acum_ini:    amort acumulada al inicio
     *   - amort_ejercicio:   amortización contable del ejercicio
     *   - amort_acum_fin:    amort acumulada al cierre
     *   - saldo_final:       valor origen al cierre − amort_acum_fin
     */
    public function anexoBienesUso(Ejercicio $ejercicio): array
    {
        $inicio = (string) $ejercicio->fecha_inicio;
        $cierre = (string) $ejercicio->fecha_cierre;

        $categorias = DB::table('erp_af_categorias')->orderBy('codigo')->get();

        $secciones = [];
        $totales = [
            'saldo_inicial' => 0.0, 'altas' => 0.0, 'bajas' => 0.0,
            'amort_acum_ini' => 0.0, 'amort_ejercicio' => 0.0,
            'amort_acum_fin' => 0.0, 'saldo_final' => 0.0,
        ];

        foreach ($categorias as $cat) {
            $bienesIni = DB::table('erp_af_bienes')
                ->where('empresa_id', $ejercicio->empresa_id)
                ->where('categoria_id', $cat->id)
                ->whereNull('deleted_at')
                ->where('fecha_alta', '<', $inicio)
                ->where(function ($q) use ($inicio) {
                    $q->whereNull('fecha_baja')->orWhere('fecha_baja', '>=', $inicio);
                })->sum('valor_origen');

            $altas = DB::table('erp_af_bienes')
                ->where('empresa_id', $ejercicio->empresa_id)
                ->where('categoria_id', $cat->id)
                ->whereNull('deleted_at')
                ->whereBetween('fecha_alta', [$inicio, $cierre])
                ->sum('valor_origen');

            $bajas = DB::table('erp_af_bienes')
                ->where('empresa_id', $ejercicio->empresa_id)
                ->where('categoria_id', $cat->id)
                ->whereNull('deleted_at')
                ->whereBetween('fecha_baja', [$inicio, $cierre])
                ->sum('valor_origen');

            $amortAcumIni = (float) DB::table('erp_af_amortizaciones as a')
                ->join('erp_af_bienes as b', 'b.id', '=', 'a.bien_id')
                ->where('b.empresa_id', $ejercicio->empresa_id)
                ->where('b.categoria_id', $cat->id)
                ->where(DB::raw('CONCAT(a.periodo_anio, LPAD(a.periodo_mes,2,0))'), '<',
                        Carbon::parse($inicio)->format('Ym'))
                ->sum('a.amort_contable_mes');

            $amortEjercicio = (float) DB::table('erp_af_amortizaciones as a')
                ->join('erp_af_bienes as b', 'b.id', '=', 'a.bien_id')
                ->where('b.empresa_id', $ejercicio->empresa_id)
                ->where('b.categoria_id', $cat->id)
                ->whereBetween(DB::raw('CONCAT(a.periodo_anio, LPAD(a.periodo_mes,2,0))'),
                    [Carbon::parse($inicio)->format('Ym'), Carbon::parse($cierre)->format('Ym')])
                ->sum('a.amort_contable_mes');

            $amortAcumFin = round($amortAcumIni + $amortEjercicio, 2);
            $saldoFinValor = round((float) $bienesIni + (float) $altas - (float) $bajas, 2);
            $saldoFinal = round($saldoFinValor - $amortAcumFin, 2);

            $fila = [
                'codigo' => $cat->codigo, 'nombre' => $cat->nombre,
                'saldo_inicial'   => round((float) $bienesIni, 2),
                'altas'           => round((float) $altas, 2),
                'bajas'           => round((float) $bajas, 2),
                'amort_acum_ini'  => round($amortAcumIni, 2),
                'amort_ejercicio' => round($amortEjercicio, 2),
                'amort_acum_fin'  => $amortAcumFin,
                'saldo_final'     => $saldoFinal,
            ];
            $secciones[] = $fila;
            foreach ($totales as $k => $_) {
                $totales[$k] += $fila[$k];
            }
        }
        foreach ($totales as $k => $v) {
            $totales[$k] = round($v, 2);
        }

        return [
            'ejercicio_id' => $ejercicio->id,
            'rango' => ['desde' => $inicio, 'hasta' => $cierre],
            'secciones' => $secciones,
            'totales' => $totales,
        ];
    }

    /**
     * Listado de altas y bajas del ejercicio (ALTA + BAJA en erp_af_movimientos).
     */
    public function altasBajas(Ejercicio $ejercicio): array
    {
        $inicio = (string) $ejercicio->fecha_inicio;
        $cierre = (string) $ejercicio->fecha_cierre;

        $rows = DB::table('erp_af_movimientos as m')
            ->join('erp_af_bienes as b', 'b.id', '=', 'm.bien_id')
            ->join('erp_af_categorias as c', 'c.id', '=', 'b.categoria_id')
            ->where('b.empresa_id', $ejercicio->empresa_id)
            ->whereIn('m.tipo', ['ALTA', 'BAJA'])
            ->whereBetween('m.fecha', [$inicio, $cierre])
            ->orderBy('m.fecha')
            ->select([
                'm.id', 'm.tipo', 'm.fecha', 'm.importe', 'm.descripcion',
                'b.id as bien_id', 'b.nro_inventario', 'b.descripcion as bien_descripcion',
                'b.valor_origen',
                'c.codigo as categoria',
            ])->get();

        return ['ejercicio_id' => $ejercicio->id, 'movimientos' => $rows->all()];
    }

    /**
     * Amortizaciones contable + fiscal por bien para el ejercicio. Útil para
     * el ajuste fiscal de F.713 (SPEC 05 RN-55).
     */
    public function amortizacionesContableVsFiscal(Ejercicio $ejercicio): array
    {
        $rows = DB::table('erp_af_amortizaciones as a')
            ->join('erp_af_bienes as b', 'b.id', '=', 'a.bien_id')
            ->where('b.empresa_id', $ejercicio->empresa_id)
            ->whereBetween(DB::raw('CONCAT(a.periodo_anio, LPAD(a.periodo_mes,2,0))'),
                [
                    Carbon::parse($ejercicio->fecha_inicio)->format('Ym'),
                    Carbon::parse($ejercicio->fecha_cierre)->format('Ym'),
                ])
            ->groupBy('b.id', 'b.nro_inventario', 'b.descripcion')
            ->orderBy('b.nro_inventario')
            ->select([
                'b.id as bien_id', 'b.nro_inventario', 'b.descripcion',
                DB::raw('SUM(a.amort_contable_mes) AS contable'),
                DB::raw('SUM(a.amort_fiscal_mes)   AS fiscal'),
                DB::raw('SUM(a.diferencia_mes)     AS diferencia'),
            ])
            ->get();

        $totC = 0.0; $totF = 0.0;
        $filas = [];
        foreach ($rows as $r) {
            $totC += (float) $r->contable;
            $totF += (float) $r->fiscal;
            $filas[] = [
                'bien_id' => (int) $r->bien_id,
                'nro_inventario' => $r->nro_inventario,
                'descripcion' => $r->descripcion,
                'contable' => round((float) $r->contable, 2),
                'fiscal'   => round((float) $r->fiscal, 2),
                'diferencia' => round((float) $r->diferencia, 2),
            ];
        }

        return [
            'ejercicio_id' => $ejercicio->id,
            'filas' => $filas,
            'totales' => [
                'contable' => round($totC, 2),
                'fiscal'   => round($totF, 2),
                'diferencia' => round($totC - $totF, 2),
            ],
        ];
    }

    /** Devuelve [bien_id => amort_contable_acum] al corte. */
    private function amortAcumPorBien(array $bienesIds, string $fecha): array
    {
        if (empty($bienesIds)) {
            return [];
        }
        $cortePeriodo = Carbon::parse($fecha)->format('Ym');

        $rows = DB::table('erp_af_amortizaciones')
            ->whereIn('bien_id', $bienesIds)
            ->where(DB::raw('CONCAT(periodo_anio, LPAD(periodo_mes,2,0))'), '<=', $cortePeriodo)
            ->groupBy('bien_id')
            ->select('bien_id', DB::raw('SUM(amort_contable_mes) AS acum'))
            ->get();

        return $rows->pluck('acum', 'bien_id')->map(fn ($v) => (float) $v)->all();
    }
}
