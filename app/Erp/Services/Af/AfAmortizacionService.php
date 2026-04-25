<?php

namespace App\Erp\Services\Af;

use App\Erp\Models\Af\AfAmortizacion;
use App\Erp\Models\Af\AfBien;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cálculo y persistencia de amortizaciones mensuales (SPEC 06 RN-73, RN-74).
 *
 * Por cada bien activo con alta anterior al mes de cierre, calcula:
 *   amort_contable_mes = base / vu_contable_meses  (lineal, 1ra cuota = mes
 *                        siguiente completo al de alta — §14.1).
 *   amort_fiscal_mes   = base / vu_fiscal_meses
 *
 * Persiste un registro por (bien, anio, mes). Idempotente: si ya hay registro
 * lo recalcula (útil cuando se corrigen datos del bien).
 *
 * El asiento contable consolidado (RN-74) lo crea el caller usando los
 * datos devueltos por `agruparPorAsiento()`. Mantenemos la generación de
 * asientos fuera del service para no acoplar el módulo AF al hard fence
 * de los triggers `sp_recalc_asiento` (decisión de testabilidad — H8 ya
 * documentó que insertar pares atomicamente desde PHP no es posible).
 */
class AfAmortizacionService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Genera amortizaciones del mes (anio/mes) para todos los bienes activos.
     *
     * @return array{
     *   bienes_amortizados:int, total_contable:float, total_fiscal:float,
     *   por_cuenta_cc: array<string, array{cuenta_gasto_id:int, cuenta_amort_id:int, cc_id:?int, total:float}>,
     *   amortizaciones: array<int, AfAmortizacion>
     * }
     */
    public function generar(int $anio, int $mes, User $usuario, bool $dryRun = false): array
    {
        if ($mes < 1 || $mes > 12) {
            throw new DomainException("AF_MES_INVALIDO: {$mes}");
        }

        $finMes = Carbon::create($anio, $mes, 1)->endOfMonth();

        // Bienes activos cuya fecha_alta dejó pasar al menos un mes completo
        // (RN-74: se amortiza desde el mes siguiente completo al de alta).
        // Equivale a fecha_alta < primer día del mes que se está cerrando.
        $bienes = AfBien::with('categoria')
            ->where('estado', '!=', 'BAJA')
            ->whereNull('deleted_at')
            ->where('fecha_alta', '<', Carbon::create($anio, $mes, 1)->startOfMonth())
            ->get();

        $resultados = [];
        $totales = [
            'bienes_amortizados' => 0,
            'total_contable' => 0.0,
            'total_fiscal' => 0.0,
            'por_cuenta_cc' => [],
            'amortizaciones' => [],
        ];

        foreach ($bienes as $bien) {
            $row = $this->calcularBien($bien, $anio, $mes, $finMes, $dryRun);
            if ($row === null) {
                continue;
            }

            $totales['bienes_amortizados']++;
            $totales['total_contable'] += $row->amort_contable_mes;
            $totales['total_fiscal']   += $row->amort_fiscal_mes;

            $key = sprintf(
                '%d|%d|%d',
                (int) $bien->categoria->cuenta_amort_ejercicio_id,
                (int) $bien->categoria->cuenta_amort_acum_id,
                (int) ($bien->centro_costo_id ?? 0)
            );
            if (! isset($totales['por_cuenta_cc'][$key])) {
                $totales['por_cuenta_cc'][$key] = [
                    'cuenta_gasto_id' => (int) $bien->categoria->cuenta_amort_ejercicio_id,
                    'cuenta_amort_id' => (int) $bien->categoria->cuenta_amort_acum_id,
                    'cc_id'           => $bien->centro_costo_id,
                    'total'           => 0.0,
                ];
            }
            $totales['por_cuenta_cc'][$key]['total'] += $row->amort_contable_mes;
            $totales['amortizaciones'][] = $row;
        }

        // Round.
        $totales['total_contable'] = round($totales['total_contable'], 2);
        $totales['total_fiscal']   = round($totales['total_fiscal'], 2);
        foreach ($totales['por_cuenta_cc'] as $k => $v) {
            $totales['por_cuenta_cc'][$k]['total'] = round($v['total'], 2);
        }

        if (! $dryRun) {
            $this->audit->logEvento(
                'af_amortizacion_mes',
                'af',
                "Amortización {$anio}/{$mes}: {$totales['bienes_amortizados']} bienes, contable={$totales['total_contable']}, fiscal={$totales['total_fiscal']} (user #{$usuario->id})",
                $bienes->first()?->empresa_id
            );
        }

        return $totales;
    }

    /**
     * Listado de amortizaciones del mes (lectura).
     */
    public function listar(int $anio, int $mes): \Illuminate\Support\Collection
    {
        return AfAmortizacion::with(['bien.categoria', 'bien.centroCosto'])
            ->where('periodo_anio', $anio)
            ->where('periodo_mes', $mes)
            ->orderBy('bien_id')
            ->get();
    }

    private function calcularBien(AfBien $bien, int $anio, int $mes, Carbon $finMes, bool $dryRun): ?AfAmortizacion
    {
        $base = $bien->baseAmort();
        $vuC = $bien->vuContable();
        $vuF = $bien->vuFiscal();
        if ($vuC <= 0 || $vuF <= 0 || $base <= 0) {
            return null;
        }

        // Determinar si todavía queda VU. Una vez agotada, no amortizar más.
        $previa = AfAmortizacion::where('bien_id', $bien->id)
            ->orderByDesc('periodo_anio')->orderByDesc('periodo_mes')
            ->first();

        $contableAcumPrev = $previa ? (float) $previa->amort_contable_acum : 0.0;
        $fiscalAcumPrev   = $previa ? (float) $previa->amort_fiscal_acum   : 0.0;

        $cuotaContable = round($base / $vuC, 2);
        $cuotaFiscal   = round($base / $vuF, 2);

        // Cap por agotamiento de VU.
        $cuotaContable = min($cuotaContable, max(0, $base - $contableAcumPrev));
        $cuotaFiscal   = min($cuotaFiscal,   max(0, $base - $fiscalAcumPrev));

        if ($cuotaContable <= 0 && $cuotaFiscal <= 0) {
            return null;
        }

        $contableAcum = round($contableAcumPrev + $cuotaContable, 2);
        $fiscalAcum   = round($fiscalAcumPrev + $cuotaFiscal, 2);

        $datos = [
            'bien_id'             => $bien->id,
            'periodo_anio'        => $anio,
            'periodo_mes'         => $mes,
            'base_amort_contable' => $base,
            'amort_contable_mes'  => $cuotaContable,
            'amort_contable_acum' => $contableAcum,
            'base_amort_fiscal'   => $base,
            'amort_fiscal_mes'    => $cuotaFiscal,
            'amort_fiscal_acum'   => $fiscalAcum,
            'generado_at'         => $dryRun ? null : now(),
        ];

        if ($dryRun) {
            return new AfAmortizacion($datos);
        }

        return AfAmortizacion::updateOrCreate(
            ['bien_id' => $bien->id, 'periodo_anio' => $anio, 'periodo_mes' => $mes],
            $datos
        );
    }
}
