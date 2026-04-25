<?php

namespace App\Erp\Services\Reportes;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Comparativo período vs período (RN-69).
 *
 * Soporta los reportes de saldos agregados por cuenta:
 *   - resultado : cuentas tipo RP (ingresos) y RN (egresos)
 *   - balance   : cuentas tipo A (activo), P (pasivo), PN (patrimonio)
 *
 * Cada período se especifica como YYYY-MM (último día del mes) o YYYY (cierre
 * 31/12) o YYYY-MM-DD explícito.
 *
 * Devuelve filas cuenta × período con variación absoluta y % entre el
 * primer período (base) y los siguientes.
 */
class ComparativoService
{
    /**
     * @param array<string> $periodos lista de identificadores YYYY-MM | YYYY | YYYY-MM-DD
     * @param string $reporte 'resultado'|'balance'
     */
    public function calcular(int $empresaId, string $reporte, array $periodos): array
    {
        if (count($periodos) < 2) {
            throw new DomainException('COMPARATIVO_MIN_2_PERIODOS');
        }

        $tipos = match ($reporte) {
            'resultado' => ['RP', 'RN'],
            'balance'   => ['A', 'P', 'PN'],
            default     => throw new DomainException("COMPARATIVO_REPORTE_INVALIDO: {$reporte}"),
        };

        $rangos = array_map(fn ($p) => $this->resolverRango($p, $reporte), $periodos);

        // Obtener saldos por cuenta para cada período en paralelo.
        $saldosPorPeriodo = [];
        $cuentasUnion = [];
        foreach ($rangos as $idx => $rango) {
            $rows = $this->saldosCuentas($empresaId, $tipos, $rango['desde'], $rango['hasta']);
            $saldosPorPeriodo[$idx] = $rows;
            foreach ($rows as $cuentaId => $info) {
                $cuentasUnion[$cuentaId] = [
                    'codigo' => $info['codigo'], 'nombre' => $info['nombre'], 'tipo' => $info['tipo'],
                ];
            }
        }
        ksort($cuentasUnion);

        $filas = [];
        foreach ($cuentasUnion as $cuentaId => $info) {
            $valoresPeriodos = [];
            foreach ($periodos as $idx => $p) {
                $valoresPeriodos[$p] = $saldosPorPeriodo[$idx][$cuentaId]['saldo'] ?? 0.0;
            }

            $base = $valoresPeriodos[$periodos[0]];
            $variaciones = [];
            for ($i = 1; $i < count($periodos); $i++) {
                $valor = $valoresPeriodos[$periodos[$i]];
                $absol = round($valor - $base, 2);
                $pct = $base != 0.0 ? round(($valor - $base) / abs($base) * 100, 2) : null;
                $variaciones[$periodos[$i]] = ['absoluto' => $absol, 'porcentual' => $pct];
            }

            $filas[] = [
                'cuenta_id' => $cuentaId,
                'codigo' => $info['codigo'], 'nombre' => $info['nombre'], 'tipo' => $info['tipo'],
                'valores' => array_map(fn ($v) => round($v, 2), $valoresPeriodos),
                'variaciones_vs_base' => $variaciones,
            ];
        }

        return [
            'reporte' => $reporte,
            'periodos' => $periodos,
            'rangos' => array_combine($periodos, $rangos),
            'filas' => $filas,
        ];
    }

    /**
     * Saldo por cuenta (signo natural según tipo) en el rango.
     *
     * @return array<int, array{codigo:string, nombre:string, tipo:string, saldo:float}>
     */
    private function saldosCuentas(int $empresaId, array $tipos, string $desde, string $hasta): array
    {
        $rows = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $empresaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->whereIn('c.tipo', $tipos)
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->groupBy('c.id', 'c.codigo', 'c.nombre', 'c.tipo')
            ->select([
                'c.id', 'c.codigo', 'c.nombre', 'c.tipo',
                DB::raw('SUM(m.debe) AS d'),
                DB::raw('SUM(m.haber) AS h'),
            ])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            // Signo natural: A/RN deudor (D-H), P/PN/RP acreedor (H-D).
            $saldo = in_array($r->tipo, ['A', 'RN'], true)
                ? ((float) $r->d) - ((float) $r->h)
                : ((float) $r->h) - ((float) $r->d);

            $out[(int) $r->id] = [
                'codigo' => $r->codigo, 'nombre' => $r->nombre, 'tipo' => $r->tipo,
                'saldo' => $saldo,
            ];
        }
        return $out;
    }

    /**
     * Resuelve un identifier en {desde, hasta}. Para 'resultado' es el rango
     * del mes/año; para 'balance' es siempre desde el inicio del ejercicio
     * (acumulado) hasta la fecha de corte.
     */
    private function resolverRango(string $periodo, string $reporte): array
    {
        // YYYY-MM-DD explícito
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodo)) {
            return ['desde' => $periodo, 'hasta' => $periodo];
        }
        // YYYY-MM
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodo, $m)) {
            $anio = (int) $m[1];
            $mes  = (int) $m[2];
            $desde = $reporte === 'balance'
                ? sprintf('%04d-01-01', $anio)
                : sprintf('%04d-%02d-01', $anio, $mes);
            $hasta = date('Y-m-t', strtotime("{$anio}-{$mes}-01"));
            return ['desde' => $desde, 'hasta' => $hasta];
        }
        // YYYY
        if (preg_match('/^\d{4}$/', $periodo)) {
            $anio = (int) $periodo;
            return [
                'desde' => sprintf('%04d-01-01', $anio),
                'hasta' => sprintf('%04d-12-31', $anio),
            ];
        }
        throw new DomainException("COMPARATIVO_PERIODO_INVALIDO: {$periodo}");
    }
}
