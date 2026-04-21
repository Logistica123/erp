<?php

namespace App\Erp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Estados contables formato FACPCE (RT 8/9) — versión MVP.
 *
 *  - GET /api/erp/estados-contables/situacion-patrimonial ?al=YYYY-MM-DD
 *      Saldos de cuentas Activo / Pasivo / Patrimonio Neto a una fecha corte.
 *      Agrupa por rubro (nivel 1) y subrubro (nivel 2).
 *      Valida ecuación patrimonial: A = P + PN (con tolerancia 0.01).
 *
 *  - GET /api/erp/estados-contables/resultados ?desde=&hasta=
 *      Saldos de cuentas RP (ingresos) y RN (egresos) en el rango.
 *      Resultado neto = ingresos - egresos.
 *
 * Ambos endpoints usan los movimientos de asientos CONTABILIZADO + ANULADO
 * (las reversas compensan al original, ver bug-fix parte 7).
 */
class EstadosContablesController
{
    private const ESTADOS_VALIDOS = ['CONTABILIZADO', 'ANULADO'];

    public function situacionPatrimonial(Request $request): JsonResponse
    {
        $data = $request->validate([
            'al' => ['nullable', 'date'],
        ]);

        $empresaId = $this->empresaIdFromRequest($request);
        $al = $data['al'] ?? now()->toDateString();

        $filas = $this->saldosPorTipo($empresaId, ['A', 'P', 'PN'], hasta: $al);

        $activos = $this->agruparYFiltrar($filas, ['A']);
        $pasivos = $this->agruparYFiltrar($filas, ['P']);
        $patrimonio = $this->agruparYFiltrar($filas, ['PN']);

        $totalA = $this->totalSaldos($activos);
        $totalP = $this->totalSaldos($pasivos, invertirSigno: true); // P saldo normal H → mostrar positivo
        $totalPN = $this->totalSaldos($patrimonio, invertirSigno: true);

        $ecuacionCuadra = abs($totalA - ($totalP + $totalPN)) < 0.01;

        return response()->json([
            'data' => [
                'al' => $al,
                'activos' => $activos,
                'pasivos' => $pasivos,
                'patrimonio_neto' => $patrimonio,
                'totales' => [
                    'activo' => round($totalA, 2),
                    'pasivo' => round($totalP, 2),
                    'patrimonio_neto' => round($totalPN, 2),
                    'pasivo_mas_pn' => round($totalP + $totalPN, 2),
                    'diferencia' => round($totalA - ($totalP + $totalPN), 2),
                ],
                'ecuacion_cuadra' => $ecuacionCuadra,
            ],
        ]);
    }

    public function resultados(Request $request): JsonResponse
    {
        $data = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);

        $empresaId = $this->empresaIdFromRequest($request);

        $filas = $this->saldosPorTipo($empresaId, ['RP', 'RN'], desde: $data['desde'], hasta: $data['hasta']);

        $ingresos = $this->agruparYFiltrar($filas, ['RP']);
        $egresos = $this->agruparYFiltrar($filas, ['RN']);

        // RP saldo natural = creditos - debitos (positivo); nuestra función devuelve ya signado así por tipo.
        $totalIngresos = $this->totalSaldos($ingresos);
        $totalEgresos = $this->totalSaldos($egresos);
        $resultadoNeto = $totalIngresos - $totalEgresos;

        return response()->json([
            'data' => [
                'rango' => ['desde' => $data['desde'], 'hasta' => $data['hasta']],
                'ingresos' => $ingresos,
                'egresos' => $egresos,
                'totales' => [
                    'ingresos' => round($totalIngresos, 2),
                    'egresos' => round($totalEgresos, 2),
                    'resultado_neto' => round($resultadoNeto, 2),
                    'margen_porcentual' => $totalIngresos > 0
                        ? round(($resultadoNeto / $totalIngresos) * 100, 2)
                        : null,
                ],
                'tipo_resultado' => $resultadoNeto >= 0 ? 'GANANCIA' : 'PERDIDA',
            ],
        ]);
    }

    /**
     * Devuelve saldos por cuenta imputable del tipo indicado, con movimientos en el rango.
     * Retorna el saldo ya signado según la naturaleza de la cuenta:
     *   - A: debitos - creditos   (positivo = activo)
     *   - P/PN: creditos - debitos (positivo = pasivo/patrimonio)
     *   - RP (ingresos): creditos - debitos (positivo = ingreso)
     *   - RN (egresos): debitos - creditos (positivo = egreso)
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function saldosPorTipo(int $empresaId, array $tipos, ?string $desde = null, ?string $hasta = null): \Illuminate\Support\Collection
    {
        $movQuery = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('a.empresa_id', $empresaId)
            ->whereIn('a.estado', self::ESTADOS_VALIDOS)
            ->groupBy('m.cuenta_id')
            ->select(
                'm.cuenta_id',
                DB::raw('COALESCE(SUM(m.debe), 0) AS debitos'),
                DB::raw('COALESCE(SUM(m.haber), 0) AS creditos'),
            );

        if ($desde) {
            $movQuery->where('a.fecha', '>=', $desde);
        }
        if ($hasta) {
            $movQuery->where('a.fecha', '<=', $hasta);
        }

        return DB::table('erp_cuentas_contables as c')
            ->leftJoinSub($movQuery, 'mov', 'mov.cuenta_id', '=', 'c.id')
            ->where('c.empresa_id', $empresaId)
            ->whereIn('c.tipo', $tipos)
            ->where('c.imputable', true)
            ->orderBy('c.codigo')
            ->select([
                'c.id',
                'c.codigo',
                'c.nombre',
                'c.tipo',
                'c.nivel',
                DB::raw('COALESCE(mov.debitos, 0) AS debitos'),
                DB::raw('COALESCE(mov.creditos, 0) AS creditos'),
            ])
            ->get()
            ->map(function ($row) {
                $debitos = (float) $row->debitos;
                $creditos = (float) $row->creditos;
                $row->saldo = in_array($row->tipo, ['A', 'RN'], true)
                    ? ($debitos - $creditos)
                    : ($creditos - $debitos);
                return $row;
            });
    }

    /**
     * Agrupa las filas por los 2 primeros niveles de la jerarquía del código:
     *   "1.1.1.01" → rubro "1", subrubro "1.1"
     * y filtra por tipos.
     */
    private function agruparYFiltrar(\Illuminate\Support\Collection $filas, array $tipos): array
    {
        $filtered = $filas->filter(fn ($r) => in_array($r->tipo, $tipos, true))
            ->filter(fn ($r) => abs($r->saldo) >= 0.01);

        if ($filtered->isEmpty()) {
            return [];
        }

        $empresaId = null;
        $rubroNombres = DB::table('erp_cuentas_contables')
            ->whereIn('nivel', [1, 2])
            ->whereIn('codigo', $filtered->map(fn ($r) => [
                $this->cortarNivel($r->codigo, 1),
                $this->cortarNivel($r->codigo, 2),
            ])->flatten()->unique()->values())
            ->pluck('nombre', 'codigo');

        $grupos = [];
        foreach ($filtered as $f) {
            $rubroCodigo = $this->cortarNivel($f->codigo, 1);
            $subrubroCodigo = $this->cortarNivel($f->codigo, 2);

            $grupos[$rubroCodigo] ??= [
                'codigo' => $rubroCodigo,
                'nombre' => $rubroNombres[$rubroCodigo] ?? $rubroCodigo,
                'subrubros' => [],
                'total' => 0.0,
            ];

            $grupos[$rubroCodigo]['subrubros'][$subrubroCodigo] ??= [
                'codigo' => $subrubroCodigo,
                'nombre' => $rubroNombres[$subrubroCodigo] ?? $subrubroCodigo,
                'cuentas' => [],
                'total' => 0.0,
            ];

            $grupos[$rubroCodigo]['subrubros'][$subrubroCodigo]['cuentas'][] = [
                'id' => $f->id,
                'codigo' => $f->codigo,
                'nombre' => $f->nombre,
                'saldo' => round($f->saldo, 2),
            ];
            $grupos[$rubroCodigo]['subrubros'][$subrubroCodigo]['total'] += $f->saldo;
            $grupos[$rubroCodigo]['total'] += $f->saldo;
        }

        // Convertir subrubros de objeto indexado a array ordenado
        return array_values(array_map(function ($rubro) {
            $rubro['total'] = round($rubro['total'], 2);
            $rubro['subrubros'] = array_values(array_map(function ($sub) {
                $sub['total'] = round($sub['total'], 2);
                return $sub;
            }, $rubro['subrubros']));
            return $rubro;
        }, $grupos));
    }

    private function cortarNivel(string $codigo, int $nivel): string
    {
        $parts = explode('.', $codigo);
        return implode('.', array_slice($parts, 0, $nivel));
    }

    private function totalSaldos(array $rubros, bool $invertirSigno = false): float
    {
        $total = array_sum(array_column($rubros, 'total'));
        return $invertirSigno ? $total : $total;
    }

    private function empresaIdFromRequest(Request $request): int
    {
        $perfil = $request->user()->erpPerfil ?? null;

        return $perfil?->empresa_id ?? 1;
    }
}
