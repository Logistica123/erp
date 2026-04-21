<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\CuentaContable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LibroMayorController
{
    /**
     * GET /api/erp/libro-mayor
     *   ?cuenta_id=  (requerido)
     *   ?desde=YYYY-MM-DD
     *   ?hasta=YYYY-MM-DD
     *
     * Devuelve:
     *   - cuenta:        info de la cuenta seleccionada
     *   - saldo_inicial: suma acumulada antes de ?desde
     *   - movimientos:   asientos contabilizados en el rango, con saldo corrido
     *   - totales:       debe/haber del período + saldo final
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cuenta_id' => ['required', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        $empresaId = $this->empresaIdFromRequest($request);
        $cuenta = CuentaContable::where('empresa_id', $empresaId)->findOrFail($data['cuenta_id']);

        $desde = $data['desde'] ?? null;
        $hasta = $data['hasta'] ?? null;

        // Saldo inicial: suma de (debe-haber) de todos los movimientos CONTABILIZADOS
        // anteriores a $desde. Si no hay $desde, saldo_inicial = 0.
        $saldoInicial = 0.0;
        if ($desde) {
            $saldoInicial = (float) DB::table('erp_movimientos_asiento as m')
                ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
                ->where('a.empresa_id', $empresaId)
                ->whereIn('a.estado', ['CONTABILIZADO', 'ANULADO'])
                ->where('m.cuenta_id', $cuenta->id)
                ->where('a.fecha', '<', $desde)
                ->selectRaw('COALESCE(SUM(m.debe - m.haber), 0) AS saldo')
                ->value('saldo');
        }

        // Movimientos del período (incluye ANULADOS para que sumen con su reversa a cero)
        $query = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_diarios as d', 'd.id', '=', 'a.diario_id')
            ->leftJoin('erp_auxiliares as ax', 'ax.id', '=', 'm.auxiliar_id')
            ->leftJoin('erp_centros_costo as cc', 'cc.id', '=', 'm.centro_costo_id')
            ->where('a.empresa_id', $empresaId)
            ->whereIn('a.estado', ['CONTABILIZADO', 'ANULADO'])
            ->where('m.cuenta_id', $cuenta->id)
            ->orderBy('a.fecha')
            ->orderBy('a.numero')
            ->orderBy('m.linea')
            ->select([
                'm.id',
                'a.fecha',
                'd.codigo as diario',
                'a.numero',
                'a.glosa as asiento_glosa',
                'm.glosa as linea_glosa',
                'm.debe',
                'm.haber',
                'ax.nombre as auxiliar',
                'cc.codigo as centro_costo',
                'a.id as asiento_id',
            ]);

        if ($desde) {
            $query->where('a.fecha', '>=', $desde);
        }
        if ($hasta) {
            $query->where('a.fecha', '<=', $hasta);
        }

        $movs = $query->get();

        // Calcular saldo corrido en PHP (evita problemas de portabilidad entre DB engines)
        $saldo = $saldoInicial;
        $totalDebe = 0.0;
        $totalHaber = 0.0;
        $movimientos = $movs->map(function ($m) use (&$saldo, &$totalDebe, &$totalHaber) {
            $debe = (float) $m->debe;
            $haber = (float) $m->haber;
            $saldo += $debe - $haber;
            $totalDebe += $debe;
            $totalHaber += $haber;

            return [
                'id' => $m->id,
                'fecha' => $m->fecha,
                'diario' => $m->diario,
                'numero' => (int) $m->numero,
                'glosa' => $m->linea_glosa ?? $m->asiento_glosa,
                'auxiliar' => $m->auxiliar,
                'centro_costo' => $m->centro_costo,
                'debe' => round($debe, 2),
                'haber' => round($haber, 2),
                'saldo' => round($saldo, 2),
                'asiento_id' => (int) $m->asiento_id,
            ];
        })->all();

        return response()->json([
            'data' => [
                'cuenta' => [
                    'id' => $cuenta->id,
                    'codigo' => $cuenta->codigo,
                    'nombre' => $cuenta->nombre,
                    'moneda' => $cuenta->moneda,
                    'saldo_normal' => $cuenta->saldo_normal,
                ],
                'rango' => ['desde' => $desde, 'hasta' => $hasta],
                'saldo_inicial' => round($saldoInicial, 2),
                'movimientos' => $movimientos,
                'totales' => [
                    'debe' => round($totalDebe, 2),
                    'haber' => round($totalHaber, 2),
                    'saldo_final' => round($saldoInicial + $totalDebe - $totalHaber, 2),
                ],
            ],
        ]);
    }

    private function empresaIdFromRequest(Request $request): int
    {
        $perfil = $request->user()->erpPerfil ?? null;

        return $perfil?->empresa_id ?? 1;
    }
}
