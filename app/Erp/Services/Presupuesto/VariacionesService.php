<?php

namespace App\Erp\Services\Presupuesto;

use App\Erp\Models\Presupuesto\Presupuesto;
use Illuminate\Support\Facades\DB;

/**
 * Variación Real vs Presupuesto en tiempo real (SPEC 06 RN-87).
 *
 * No usamos vista SQL persistida — la query se calcula on-the-fly
 * cruzando `erp_movimientos_asiento` con `erp_presupuesto_items`.
 *
 * Real por (cuenta × CC × mes) =
 *   - Para cuentas RN (egresos): SUM(debe − haber) en asientos CONTABILIZADOS
 *     del mes filtrados por cuenta y CC.
 *   - Para cuentas RP (ingresos): SUM(haber − debe).
 *   - Otras (A/P/PN): SUM(debe − haber) si es deudora natural,
 *     SUM(haber − debe) si es acreedora.
 */
class VariacionesService
{
    /**
     * @param array{anio?:int, cuenta_id?:int, centro_costo_id?:int, mes?:int} $filtros
     */
    public function detalle(Presupuesto $p, array $filtros = []): array
    {
        $anio = (int) ($filtros['anio'] ?? $p->ejercicio->fecha_inicio->format('Y'));

        // Items del presupuesto (filtros opcionales).
        $itemsQ = DB::table('erp_presupuesto_items as pi')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'pi.cuenta_id')
            ->leftJoin('erp_centros_costo as cc', 'cc.id', '=', 'pi.centro_costo_id')
            ->where('pi.presupuesto_id', $p->id);

        if (! empty($filtros['cuenta_id']))      $itemsQ->where('pi.cuenta_id', (int) $filtros['cuenta_id']);
        if (! empty($filtros['centro_costo_id'])) $itemsQ->where('pi.centro_costo_id', (int) $filtros['centro_costo_id']);
        if (! empty($filtros['mes']))            $itemsQ->where('pi.mes', (int) $filtros['mes']);

        $items = $itemsQ
            ->orderBy('c.codigo')->orderBy('pi.mes')
            ->select([
                'pi.id', 'pi.cuenta_id', 'pi.centro_costo_id', 'pi.mes', 'pi.importe',
                'c.codigo as cuenta_codigo', 'c.nombre as cuenta_nombre', 'c.tipo as cuenta_tipo',
                'cc.codigo as cc_codigo', 'cc.nombre as cc_nombre',
            ])->get();

        // Real por (cuenta × CC × mes) del ejercicio.
        $real = $this->realPorClave($p, $anio, $filtros);

        $filas = [];
        $totPresup = 0.0;
        $totReal = 0.0;
        foreach ($items as $i) {
            $key = $this->key((int) $i->cuenta_id, $i->centro_costo_id ? (int) $i->centro_costo_id : null, (int) $i->mes);
            $r = (float) ($real[$key] ?? 0);
            $presup = (float) $i->importe;
            $varAbs = round($r - $presup, 2);
            $varPct = $presup != 0 ? round(($r - $presup) / abs($presup) * 100, 2) : null;
            $totPresup += $presup;
            $totReal += $r;

            $filas[] = [
                'item_id' => (int) $i->id,
                'cuenta'  => $i->cuenta_codigo.' '.$i->cuenta_nombre,
                'cuenta_tipo' => $i->cuenta_tipo,
                'centro_costo' => $i->cc_codigo,
                'mes' => (int) $i->mes,
                'presupuesto' => round($presup, 2),
                'real' => round($r, 2),
                'variacion_abs' => $varAbs,
                'variacion_pct' => $varPct,
            ];
        }

        return [
            'presupuesto_id' => $p->id, 'anio' => $anio,
            'filas' => $filas,
            'totales' => [
                'presupuesto' => round($totPresup, 2),
                'real' => round($totReal, 2),
                'variacion_abs' => round($totReal - $totPresup, 2),
            ],
        ];
    }

    /** Resumen agregado por cuenta o por CC (parámetro `por`). */
    public function resumen(Presupuesto $p, string $por = 'cuenta', array $filtros = []): array
    {
        $detalle = $this->detalle($p, $filtros);
        $grupos = [];
        foreach ($detalle['filas'] as $f) {
            $clave = $por === 'cc' ? ($f['centro_costo'] ?? 'Sin CC') : ($f['cuenta'] ?? 'Sin cuenta');
            $grupos[$clave] ??= ['clave' => $clave, 'presupuesto' => 0.0, 'real' => 0.0];
            $grupos[$clave]['presupuesto'] += $f['presupuesto'];
            $grupos[$clave]['real']        += $f['real'];
        }
        $out = [];
        foreach ($grupos as $g) {
            $g['presupuesto'] = round($g['presupuesto'], 2);
            $g['real']        = round($g['real'], 2);
            $g['variacion_abs'] = round($g['real'] - $g['presupuesto'], 2);
            $g['variacion_pct'] = $g['presupuesto'] != 0
                ? round(($g['real'] - $g['presupuesto']) / abs($g['presupuesto']) * 100, 2)
                : null;
            $out[] = $g;
        }
        usort($out, fn ($a, $b) => $a['clave'] <=> $b['clave']);

        return [
            'presupuesto_id' => $p->id,
            'agrupado_por' => $por,
            'filas' => $out,
            'totales' => $detalle['totales'],
        ];
    }

    /** Ejecución acumulada al mes actual: % de presupuesto consumido por cuenta. */
    public function ejecucion(Presupuesto $p, ?int $hastaMes = null): array
    {
        $hastaMes = $hastaMes ?: (int) now()->format('m');
        $detalle = $this->detalle($p, ['mes' => null]);

        // Acumular por cuenta hasta hastaMes inclusive.
        $porCuenta = [];
        foreach ($detalle['filas'] as $f) {
            if ((int) $f['mes'] > $hastaMes) {
                continue;
            }
            $k = $f['cuenta'];
            $porCuenta[$k] ??= ['cuenta' => $k, 'presupuesto_acum' => 0.0, 'real_acum' => 0.0];
            $porCuenta[$k]['presupuesto_acum'] += $f['presupuesto'];
            $porCuenta[$k]['real_acum']        += $f['real'];
        }

        $out = [];
        foreach ($porCuenta as $r) {
            $r['presupuesto_acum'] = round($r['presupuesto_acum'], 2);
            $r['real_acum'] = round($r['real_acum'], 2);
            $r['ejecucion_pct'] = $r['presupuesto_acum'] != 0
                ? round($r['real_acum'] / $r['presupuesto_acum'] * 100, 2)
                : null;
            $r['semaforo'] = $this->semaforo($r['ejecucion_pct']);
            $out[] = $r;
        }
        usort($out, fn ($a, $b) => $a['cuenta'] <=> $b['cuenta']);

        return [
            'presupuesto_id' => $p->id,
            'hasta_mes' => $hastaMes,
            'filas' => $out,
        ];
    }

    private function realPorClave(Presupuesto $p, int $anio, array $filtros): array
    {
        $q = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $p->empresa_id)
            ->where('a.estado', 'CONTABILIZADO')
            ->whereYear('a.fecha', $anio)
            ->groupBy('m.cuenta_id', 'm.centro_costo_id', DB::raw('MONTH(a.fecha)'), 'c.tipo')
            ->select([
                'm.cuenta_id', 'm.centro_costo_id',
                DB::raw('MONTH(a.fecha) as mes'),
                'c.tipo',
                DB::raw("SUM(CASE c.tipo
                    WHEN 'RN' THEN m.debe - m.haber
                    WHEN 'RP' THEN m.haber - m.debe
                    WHEN 'A'  THEN m.debe - m.haber
                    WHEN 'P'  THEN m.haber - m.debe
                    WHEN 'PN' THEN m.haber - m.debe
                    ELSE m.debe - m.haber END) AS importe"),
            ]);

        if (! empty($filtros['cuenta_id']))      $q->where('m.cuenta_id', (int) $filtros['cuenta_id']);
        if (! empty($filtros['centro_costo_id'])) $q->where('m.centro_costo_id', (int) $filtros['centro_costo_id']);
        if (! empty($filtros['mes']))            $q->whereRaw('MONTH(a.fecha) = ?', [(int) $filtros['mes']]);

        $rows = $q->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$this->key((int) $r->cuenta_id, $r->centro_costo_id ? (int) $r->centro_costo_id : null, (int) $r->mes)] = (float) $r->importe;
        }
        return $out;
    }

    private function key(int $cuentaId, ?int $ccId, int $mes): string
    {
        return $cuentaId.'|'.($ccId ?? 0).'|'.$mes;
    }

    private function semaforo(?float $pct): string
    {
        if ($pct === null) {
            return 'sin_dato';
        }
        if ($pct < 95) return 'verde';
        if ($pct <= 110) return 'amarillo';
        return 'rojo';
    }
}
