<?php

namespace App\Erp\Services\Eecc;

use App\Erp\Models\Ejercicio;
use Illuminate\Support\Facades\DB;

/**
 * Estado de Flujo de Efectivo — método indirecto (RN-60).
 *
 * Estructura:
 *   1. Caja inicial (saldo de cuentas Caja+Bancos al inicio del ejercicio).
 *   2. Resultado del ejercicio (toma `erp_ganancias_liquidacion.resultado_contable`
 *      si existe, sino RP - RN).
 *   3. Conciliaciones (ajustes que no son flujo):
 *      - amortizaciones y depreciaciones (cuentas con etiqueta_cierre 'AMORT' o
 *        rubro 'Amortizaciones' por convención del seed)
 *      - variación de créditos por ventas (rubro 'Créditos por ventas')
 *      - variación de bienes de cambio (rubro 'Bienes de cambio')
 *      - variación de deudas comerciales (rubro 'Deudas comerciales')
 *   4. Actividades operativas / inversión / financiación.
 *   5. Caja final = caja inicial + variación.
 *
 * Esta implementación produce la cifra agregada por sección. El detalle
 * por cuenta queda como anexo informativo dentro del paquete EECC.
 */
class FlujoEfectivoService
{
    /** Códigos de cuenta considerados Caja y Equivalentes. */
    private const CAJA_PREFIJOS = ['1.1.1', '1.1.2'];

    public function calcular(Ejercicio $ejercicio): array
    {
        $inicio = (string) $ejercicio->fecha_inicio;
        $cierre = (string) $ejercicio->fecha_cierre;

        $cajaIni = $this->saldoCajaAl($ejercicio, $inicio, '<');
        $cajaFin = $this->saldoCajaAl($ejercicio, $cierre, '<=');
        $variacionCaja = round($cajaFin - $cajaIni, 2);

        // Resultado contable (preferir liquidación de Ganancias si existe).
        $resultadoContable = (float) DB::table('erp_ganancias_liquidacion')
            ->where('ejercicio_id', $ejercicio->id)
            ->value('resultado_contable') ?? 0.0;
        if ($resultadoContable === 0.0) {
            $resultadoContable = $this->resultadoNeto($ejercicio);
        }

        // Variación de rubros operativos (D - H del ejercicio para cuentas A/P específicas).
        $varCreditos = $this->variacionRubro($ejercicio, ['Créditos por ventas', 'Otros créditos']);
        $varBienes   = $this->variacionRubro($ejercicio, ['Bienes de cambio']);
        $varDeudas   = $this->variacionRubroPasivo($ejercicio, ['Deudas comerciales', 'Cargas fiscales', 'Remuneraciones a pagar']);

        // Amortizaciones (no movimiento de caja): sumamos saldos H del ejercicio
        // de cuentas amortizaciones acumuladas (regularizadoras de A).
        $amortizaciones = (float) DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.ejercicio_id', $ejercicio->id)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('c.regularizadora', 1)
            ->select(DB::raw('COALESCE(SUM(m.haber - m.debe), 0) AS s'))
            ->value('s');

        // Resultado operativo conciliado:
        //   Resultado + Amortizaciones - Δcréditos - Δbienes + Δdeudas
        $operativas = round(
            $resultadoContable + $amortizaciones - $varCreditos - $varBienes + $varDeudas,
            2
        );

        // Inversión y financiación quedan placeholder (se requiere detección
        // por etiquetas adicionales en el plan; el seed actual no las marca).
        $inversion    = 0.0;
        $financiacion = 0.0;

        $totalFlujo = round($operativas + $inversion + $financiacion, 2);

        return [
            'ejercicio_id' => $ejercicio->id,
            'rango'        => ['desde' => $inicio, 'hasta' => $cierre],
            'caja_inicial' => round($cajaIni, 2),
            'caja_final'   => round($cajaFin, 2),
            'variacion_caja' => $variacionCaja,
            'metodo'        => 'INDIRECTO',
            'actividades_operativas' => [
                'resultado_contable'   => round($resultadoContable, 2),
                'amortizaciones'       => round($amortizaciones, 2),
                'var_creditos'         => round($varCreditos, 2),
                'var_bienes_cambio'    => round($varBienes, 2),
                'var_deudas'           => round($varDeudas, 2),
                'flujo'                => $operativas,
            ],
            'actividades_inversion'    => ['flujo' => $inversion],
            'actividades_financiacion' => ['flujo' => $financiacion],
            'flujo_total'              => $totalFlujo,
            'reconciliacion'           => [
                'flujo_total'   => $totalFlujo,
                'variacion_caja'=> $variacionCaja,
                'cierra'        => abs($totalFlujo - $variacionCaja) <= 1.0,
                'diferencia'    => round($totalFlujo - $variacionCaja, 2),
            ],
        ];
    }

    private function saldoCajaAl(Ejercicio $ejercicio, string $fecha, string $op): float
    {
        $q = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('a.fecha', $op, $fecha)
            ->where('c.tipo', 'A')
            ->where('c.imputable', 1)
            ->where(function ($qq) {
                foreach (self::CAJA_PREFIJOS as $p) {
                    $qq->orWhere('c.codigo', 'LIKE', $p.'%');
                }
            });

        return (float) $q->select(DB::raw('COALESCE(SUM(m.debe - m.haber), 0) AS s'))->value('s');
    }

    private function resultadoNeto(Ejercicio $ejercicio): float
    {
        $row = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.ejercicio_id', $ejercicio->id)
            ->where('a.estado', 'CONTABILIZADO')
            ->whereIn('c.tipo', ['RP', 'RN'])
            ->select(DB::raw(
                "SUM(CASE c.tipo WHEN 'RP' THEN m.haber - m.debe ELSE -(m.debe - m.haber) END) AS r"
            ))
            ->first();

        return round((float) ($row->r ?? 0), 2);
    }

    private function variacionRubro(Ejercicio $ejercicio, array $rubros): float
    {
        $row = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.ejercicio_id', $ejercicio->id)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('c.tipo', 'A')
            ->whereIn('c.rubro_ec', $rubros)
            ->select(DB::raw('SUM(m.debe - m.haber) AS v'))
            ->first();

        return round((float) ($row->v ?? 0), 2);
    }

    private function variacionRubroPasivo(Ejercicio $ejercicio, array $rubros): float
    {
        $row = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.ejercicio_id', $ejercicio->id)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('c.tipo', 'P')
            ->whereIn('c.rubro_ec', $rubros)
            ->select(DB::raw('SUM(m.haber - m.debe) AS v'))
            ->first();

        return round((float) ($row->v ?? 0), 2);
    }
}
