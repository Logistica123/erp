<?php

namespace App\Erp\Services\Sueldos;

use App\Erp\Models\Sueldos\Empleado;
use App\Erp\Models\Sueldos\Liquidacion;
use Illuminate\Support\Facades\DB;

/**
 * Reportes del módulo Sueldos (SPEC 08 §10).
 *
 * Las series ocultan componente=EFECTIVO según el flag $verEfectivos
 * (RN-112: dato sensible).
 */
class ReportesSueldosService
{
    /**
     * Resumen completo de una liquidación: totales por empleado y por concepto.
     */
    public function resumenLiquidacion(int $liquidacionId, bool $verEfectivos): array
    {
        $liq = Liquidacion::findOrFail($liquidacionId);

        $q = DB::table('erp_emp_liquidaciones_items as li')
            ->join('erp_emp_empleados as e', 'e.id', '=', 'li.empleado_id')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'li.concepto_id')
            ->where('li.liquidacion_id', $liquidacionId);
        if (! $verEfectivos) {
            $q->where('li.componente', '!=', 'EFECTIVO');
        }

        $rows = $q->select(
            'li.empleado_id', 'e.legajo', 'e.apellido', 'e.nombre', 'e.regimen',
            'li.concepto_id', 'c.codigo as concepto_codigo', 'c.nombre as concepto_nombre',
            'c.signo', 'li.componente',
            DB::raw('SUM(li.importe) AS importe'),
        )
        ->groupBy('li.empleado_id', 'e.legajo', 'e.apellido', 'e.nombre', 'e.regimen',
                  'li.concepto_id', 'c.codigo', 'c.nombre', 'c.signo', 'li.componente')
        ->get();

        $porEmpleado = [];
        foreach ($rows as $r) {
            $key = $r->empleado_id;
            $porEmpleado[$key] ??= [
                'empleado_id' => $r->empleado_id,
                'legajo'      => $r->legajo,
                'nombre_completo' => trim($r->apellido.', '.$r->nombre),
                'regimen'     => $r->regimen,
                'haberes'     => 0.0,
                'descuentos'  => 0.0,
                'neto'        => 0.0,
                'formal'      => 0.0,
                'efectivo'    => 0.0,
                'mt'          => 0.0,
                'detalle'     => [],
            ];
            $imp = (float) $r->importe;
            $signoMul = $r->signo === 'HABER' ? 1 : -1;
            if ($r->signo === 'HABER') $porEmpleado[$key]['haberes']    += $imp;
            else                       $porEmpleado[$key]['descuentos'] += $imp;
            $porEmpleado[$key][strtolower($r->componente)] += $imp * $signoMul;
            $porEmpleado[$key]['detalle'][] = [
                'concepto_codigo' => $r->concepto_codigo,
                'concepto'        => $r->concepto_nombre,
                'componente'      => $r->componente,
                'signo'           => $r->signo,
                'importe'         => round($imp, 2),
            ];
        }
        foreach ($porEmpleado as &$e) {
            $e['neto'] = round($e['haberes'] - $e['descuentos'], 2);
            foreach (['haberes','descuentos','formal','efectivo','mt'] as $k) {
                $e[$k] = round($e[$k], 2);
            }
        }

        return [
            'liquidacion' => $liq->only(['id', 'periodo', 'tipo', 'estado', 'total_bruto', 'total_neto', 'total_formal', 'total_efectivo', 'total_mt']),
            'empleados'   => array_values($porEmpleado),
        ];
    }

    /**
     * Histórico por empleado: una fila por liquidación participada en el rango.
     */
    public function historicoEmpleado(int $empleadoId, string $desde, string $hasta, bool $verEfectivos): array
    {
        $emp = Empleado::with(['categoria.convenio'])->findOrFail($empleadoId);

        $q = DB::table('erp_emp_liquidaciones_items as li')
            ->join('erp_emp_liquidaciones as l', 'l.id', '=', 'li.liquidacion_id')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'li.concepto_id')
            ->where('li.empleado_id', $empleadoId)
            ->whereBetween('l.periodo', [$desde, $hasta]);
        if (! $verEfectivos) {
            $q->where('li.componente', '!=', 'EFECTIVO');
        }

        $rows = $q->select(
            'l.id as liquidacion_id', 'l.periodo', 'l.tipo', 'l.estado',
            'li.componente', 'c.signo',
            DB::raw('SUM(li.importe) AS importe'),
        )
        ->groupBy('l.id', 'l.periodo', 'l.tipo', 'l.estado', 'li.componente', 'c.signo')
        ->orderBy('l.periodo')->orderBy('l.id')
        ->get();

        $porLiq = [];
        foreach ($rows as $r) {
            $k = $r->liquidacion_id;
            $porLiq[$k] ??= [
                'liquidacion_id' => $r->liquidacion_id,
                'periodo'        => $r->periodo,
                'tipo'           => $r->tipo,
                'estado'         => $r->estado,
                'haberes'        => 0.0,
                'descuentos'     => 0.0,
                'neto'           => 0.0,
                'formal'         => 0.0,
                'efectivo'       => 0.0,
                'mt'             => 0.0,
            ];
            $imp = (float) $r->importe;
            $signoMul = $r->signo === 'HABER' ? 1 : -1;
            if ($r->signo === 'HABER') $porLiq[$k]['haberes']    += $imp;
            else                       $porLiq[$k]['descuentos'] += $imp;
            $porLiq[$k][strtolower($r->componente)] += $imp * $signoMul;
        }
        foreach ($porLiq as &$row) {
            $row['neto'] = round($row['haberes'] - $row['descuentos'], 2);
            foreach (['haberes','descuentos','formal','efectivo','mt'] as $k) {
                $row[$k] = round($row[$k], 2);
            }
        }

        return [
            'empleado' => [
                'id' => $emp->id,
                'legajo' => $emp->legajo,
                'nombre_completo' => trim($emp->apellido.', '.$emp->nombre),
                'cuil' => $emp->cuil,
                'regimen' => $emp->regimen,
                'categoria' => $emp->categoria?->nombre,
                'convenio' => $emp->categoria?->convenio?->nombre ?? $emp->convenio?->nombre,
            ],
            'rango' => ['desde' => $desde, 'hasta' => $hasta],
            'liquidaciones' => array_values($porLiq),
        ];
    }

    /**
     * Costo laboral anual: matriz mes × empleado con totales por componente.
     * Solo considera liquidaciones APROBADA / PAGADA.
     */
    public function costoLaboralAnual(int $anio, bool $verEfectivos): array
    {
        $q = DB::table('erp_emp_liquidaciones_items as li')
            ->join('erp_emp_liquidaciones as l', 'l.id', '=', 'li.liquidacion_id')
            ->join('erp_emp_empleados as e', 'e.id', '=', 'li.empleado_id')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'li.concepto_id')
            ->whereIn('l.estado', [Liquidacion::ESTADO_APROBADA, Liquidacion::ESTADO_PAGADA])
            ->whereRaw('SUBSTRING(l.periodo, 1, 4) = ?', [(string) $anio]);
        if (! $verEfectivos) {
            $q->where('li.componente', '!=', 'EFECTIVO');
        }

        $rows = $q->select(
            'e.id as empleado_id', 'e.legajo',
            DB::raw('CONCAT(e.apellido, ", ", e.nombre) as nombre_completo'),
            'l.periodo',
            'li.componente', 'c.signo',
            DB::raw('SUM(li.importe) AS importe'),
        )
        ->groupBy('e.id', 'e.legajo', 'e.apellido', 'e.nombre', 'l.periodo', 'li.componente', 'c.signo')
        ->get();

        $porEmpleado = [];
        $totalesMes = [];
        foreach ($rows as $r) {
            $eid = $r->empleado_id;
            $mes = (int) substr((string) $r->periodo, 5, 2);
            $imp = (float) $r->importe * ($r->signo === 'HABER' ? 1 : -1);

            $porEmpleado[$eid] ??= [
                'empleado_id'     => $r->empleado_id,
                'legajo'          => $r->legajo,
                'nombre_completo' => $r->nombre_completo,
                'meses'           => array_fill(1, 12, ['formal' => 0.0, 'efectivo' => 0.0, 'mt' => 0.0, 'total' => 0.0]),
                'total_anual'     => 0.0,
            ];
            $porEmpleado[$eid]['meses'][$mes][strtolower($r->componente)] += $imp;
            $porEmpleado[$eid]['meses'][$mes]['total']                    += $imp;
            $porEmpleado[$eid]['total_anual']                              += $imp;

            $totalesMes[$mes] ??= ['formal' => 0.0, 'efectivo' => 0.0, 'mt' => 0.0, 'total' => 0.0];
            $totalesMes[$mes][strtolower($r->componente)] += $imp;
            $totalesMes[$mes]['total']                    += $imp;
        }
        foreach ($porEmpleado as &$e) {
            foreach ($e['meses'] as &$m) {
                foreach ($m as $k => $v) $m[$k] = round($v, 2);
            }
            $e['total_anual'] = round($e['total_anual'], 2);
        }
        foreach ($totalesMes as $mes => &$row) {
            foreach ($row as $k => $v) $row[$k] = round($v, 2);
        }
        ksort($totalesMes);

        return [
            'anio'        => $anio,
            'empleados'   => array_values($porEmpleado),
            'totales_mes' => $totalesMes,
        ];
    }

    /**
     * CCs activas del empleado con saldo y último movimiento.
     */
    public function ccEmpleado(int $empleadoId): array
    {
        $emp = Empleado::findOrFail($empleadoId);

        $rows = DB::table('erp_emp_cc as cc')
            ->leftJoin('erp_cuentas_contables as cta', 'cta.id', '=', 'cc.cuenta_contable_id')
            ->leftJoin(DB::raw('(SELECT cc_id, MAX(fecha) AS ultima_fecha FROM erp_emp_cc_movimientos GROUP BY cc_id) AS um'), 'um.cc_id', '=', 'cc.id')
            ->where('cc.empleado_id', $empleadoId)
            ->select(
                'cc.id', 'cc.tipo', 'cc.saldo_actual', 'cc.limite_credito', 'cc.activa',
                'cta.codigo as cuenta_codigo', 'cta.nombre as cuenta_nombre',
                'um.ultima_fecha',
            )
            ->orderBy('cc.tipo')
            ->get();

        return [
            'empleado' => ['id' => $emp->id, 'legajo' => $emp->legajo, 'nombre_completo' => trim($emp->apellido.', '.$emp->nombre)],
            'cuentas'  => $rows,
        ];
    }

    /**
     * G-06 — Dashboard "TOTALES POR MES" del Excel: por cada mes del año
     * (liquidaciones APROBADA/PAGADA), haberes, descuentos, neto y
     * desglose por componente + variación vs mes anterior + acumulado.
     */
    public function dashboardAnual(int $anio, bool $verEfectivos = true): array
    {
        $filas = DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->join('erp_emp_liquidaciones as l', 'l.id', '=', 'i.liquidacion_id')
            ->whereIn('l.estado', ['APROBADA', 'PAGADA'])
            ->where('l.periodo', 'like', $anio.'-%')
            ->groupBy('l.periodo', 'l.tipo')
            ->selectRaw("l.periodo, l.tipo,
                SUM(CASE WHEN c.signo='HABER' THEN i.importe ELSE 0 END) haberes,
                SUM(CASE WHEN c.signo='DESCUENTO' THEN i.importe ELSE 0 END) descuentos,
                SUM(CASE WHEN c.signo='HABER' THEN i.importe ELSE -i.importe END) neto,
                SUM(CASE WHEN i.componente='FORMAL'   THEN (CASE WHEN c.signo='HABER' THEN i.importe ELSE -i.importe END) ELSE 0 END) formal,
                SUM(CASE WHEN i.componente='EFECTIVO' THEN (CASE WHEN c.signo='HABER' THEN i.importe ELSE -i.importe END) ELSE 0 END) efectivo,
                SUM(CASE WHEN i.componente='MT'       THEN (CASE WHEN c.signo='HABER' THEN i.importe ELSE -i.importe END) ELSE 0 END) mt")
            ->orderBy('l.periodo')
            ->get();

        $meses = [];
        $acum = ['haberes' => 0.0, 'descuentos' => 0.0, 'neto' => 0.0, 'formal' => 0.0, 'efectivo' => 0.0, 'mt' => 0.0];
        $netoAnterior = null;
        foreach ($filas as $f) {
            $fila = [
                'periodo' => $f->periodo,
                'tipo' => $f->tipo,
                'haberes' => round((float) $f->haberes, 2),
                'descuentos' => round((float) $f->descuentos, 2),
                'neto' => round((float) $f->neto, 2),
                'formal' => round((float) $f->formal, 2),
                'efectivo' => $verEfectivos ? round((float) $f->efectivo, 2) : null,
                'mt' => round((float) $f->mt, 2),
            ];
            // Variación vs mes anterior (solo entre MENSUALES).
            if ($f->tipo === 'MENSUAL') {
                $fila['variacion_neto_pct'] = ($netoAnterior !== null && $netoAnterior > 0)
                    ? round(((float) $f->neto - $netoAnterior) / $netoAnterior * 100, 2)
                    : null;
                $netoAnterior = (float) $f->neto;
            } else {
                $fila['variacion_neto_pct'] = null;
            }
            foreach (['haberes', 'descuentos', 'neto', 'formal', 'mt'] as $k) {
                $acum[$k] += (float) $fila[$k];
            }
            $acum['efectivo'] += (float) ($f->efectivo ?? 0);
            $meses[] = $fila;
        }

        return [
            'anio' => $anio,
            'meses' => $meses,
            'acumulado' => array_map(fn ($v) => round($v, 2), $verEfectivos ? $acum : array_merge($acum, ['efectivo' => null])),
        ];
    }
}
