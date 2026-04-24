<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\IibbCoeficiente;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula coeficientes unificados del Convenio Multilateral (CM05 anual,
 * RN-53).
 *
 * Insumo: los 12 meses del ejercicio anterior — por default `abril…marzo`
 * del año anterior al que vigente (configurable vía `base_calendar`).
 *
 * Fórmula:
 *   coef_jur = 0.5 × (ingresos_jur / ingresos_total)
 *            + 0.5 × (gastos_jur / gastos_total)
 *
 * Resultado: filas `erp_iibb_coeficientes` con estado DRAFT hasta que el
 * período CM05 pasa a APROBADO, donde pasan a VIGENTE. Al aprobar, las
 * filas VIGENTE del año anterior se mantienen (coexisten por año_vigencia
 * distinto).
 *
 * Si el usuario quiere ajustar manualmente una atribución, lo hace
 * editando los movimientos en `erp_iibb_jurisdiccion_mov` (o agregando
 * filas origen='AJUSTE') y re-corriendo el cálculo.
 */
class IibbCm05Calculator
{
    public function __construct(
        private readonly IibbAtribucionService $atribucion,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Calcula coeficientes para el año `anio_vigencia` a partir de los 12
     * meses anteriores. Persiste filas DRAFT en `erp_iibb_coeficientes`.
     *
     * @param string $baseCalendar Rango base: 'abril_marzo' (Convenio) o
     *                             'enero_diciembre' (calendario).
     *
     * @return array{coeficientes: array<string, float>, ingresos_total: float, gastos_total: float}
     */
    public function calcular(
        PeriodoFiscal $periodo,
        User $usuario,
        string $baseCalendar = 'abril_marzo',
    ): array {
        if ($periodo->impuesto !== 'IIBB_CM') {
            throw new DomainException('CM05_PERIODO_INVALIDO: requerí período IIBB_CM');
        }
        if (! $periodo->esEditable()) {
            throw new DomainException("CM05_PERIODO_NO_EDITABLE: estado {$periodo->estado}");
        }

        [$desde, $hasta] = $this->rangoBase($periodo->anio, $baseCalendar);

        return DB::transaction(function () use ($periodo, $usuario, $desde, $hasta) {
            // Asegurar que la atribución esté actualizada.
            $this->atribucion->recalcularRango($periodo->empresa_id, $desde, $hasta, $usuario);
            $totales = $this->atribucion->totalesPorJurisdiccion($periodo->empresa_id, $desde, $hasta);

            $ingresosTotal = array_sum(array_column($totales, 'ingresos'));
            $gastosTotal   = array_sum(array_column($totales, 'gastos'));

            if ($ingresosTotal <= 0 && $gastosTotal <= 0) {
                throw new DomainException('CM05_SIN_DATOS: no hay ingresos ni gastos en el rango base');
            }

            // Eliminar coeficientes DRAFT previos del mismo año — los VIGENTES no se tocan.
            DB::table('erp_iibb_coeficientes')
                ->where('anio_vigencia', $periodo->anio)
                ->where('estado', 'DRAFT')
                ->delete();

            $coefs = [];
            foreach ($totales as $jur => $t) {
                $share_i = $ingresosTotal > 0 ? ((float) $t['ingresos'] / $ingresosTotal) : 0;
                $share_g = $gastosTotal   > 0 ? ((float) $t['gastos']   / $gastosTotal)   : 0;
                $coef = round(0.5 * $share_i + 0.5 * $share_g, 8);

                IibbCoeficiente::create([
                    'anio_vigencia' => $periodo->anio,
                    'jurisdiccion'  => $jur,
                    'coeficiente'   => $coef,
                    'origen'        => 'CM05',
                    'estado'        => 'DRAFT',
                ]);
                $coefs[$jur] = $coef;
            }

            $this->audit->log('calcular_cm05', $periodo, null, [
                'anio' => $periodo->anio,
                'rango' => "$desde..$hasta",
                'ingresos_total' => $ingresosTotal,
                'gastos_total' => $gastosTotal,
                'coeficientes' => $coefs,
            ], "CM05 calculado para año {$periodo->anio} (user #{$usuario->id})");

            return [
                'coeficientes' => $coefs,
                'ingresos_total' => $ingresosTotal,
                'gastos_total' => $gastosTotal,
                'base_rango' => ['desde' => $desde, 'hasta' => $hasta],
            ];
        });
    }

    /**
     * Ajusta manualmente un coeficiente DRAFT (casos borderline que LIBER
     * pide corregir antes de aprobar).
     */
    public function ajustarManual(int $anioVigencia, string $jurisdiccion, float $coeficiente, User $usuario): IibbCoeficiente
    {
        $row = IibbCoeficiente::where('anio_vigencia', $anioVigencia)
            ->where('jurisdiccion', $jurisdiccion)
            ->first();

        if (! $row) {
            $row = new IibbCoeficiente([
                'anio_vigencia' => $anioVigencia,
                'jurisdiccion'  => $jurisdiccion,
                'coeficiente'   => $coeficiente,
                'origen'        => 'MANUAL',
                'estado'        => 'DRAFT',
            ]);
            $row->save();
        } else {
            if ($row->estado === 'VIGENTE') {
                throw new DomainException('CM05_COEF_VIGENTE: no se puede editar VIGENTE, requiere rectificativa');
            }
            $row->update(['coeficiente' => $coeficiente, 'origen' => 'MANUAL']);
        }

        $this->audit->log('ajustar_cm05_coef', $row, null, $row->toArray(),
            "Ajuste manual coef {$anioVigencia}/{$jurisdiccion} → {$coeficiente} (user #{$usuario->id})");

        return $row->fresh();
    }

    /**
     * Marca los coeficientes DRAFT de un año como VIGENTE (usado al aprobar
     * el período CM05).
     */
    public function aprobar(int $anioVigencia, User $usuario): int
    {
        return DB::transaction(function () use ($anioVigencia, $usuario) {
            $n = IibbCoeficiente::where('anio_vigencia', $anioVigencia)
                ->where('estado', 'DRAFT')
                ->update([
                    'estado' => 'VIGENTE',
                    'aprobado_at' => now(),
                    'aprobado_user_id' => $usuario->id,
                ]);

            $this->audit->logEvento(
                'aprobar_cm05',
                'impuestos',
                "CM05 año {$anioVigencia} aprobado — {$n} coeficientes pasan a VIGENTE (user #{$usuario->id})",
            );

            return $n;
        });
    }

    /**
     * Devuelve coeficientes vigentes para un año (se usan en CM03 mensual).
     *
     * @return array<string, float>
     */
    public function vigentes(int $anio): array
    {
        $rows = IibbCoeficiente::where('anio_vigencia', $anio)
            ->where('estado', 'VIGENTE')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->jurisdiccion] = (float) $r->coeficiente;
        }
        return $out;
    }

    /**
     * Rango base para el cálculo CM05. Por default, abril-marzo del año
     * anterior (convención Convenio Multilateral). Alternativa:
     * enero-diciembre del año anterior (para empresas con ejercicio = año calendario).
     */
    private function rangoBase(int $anioVigencia, string $baseCalendar): array
    {
        if ($baseCalendar === 'enero_diciembre') {
            return [
                sprintf('%04d-01-01', $anioVigencia - 1),
                sprintf('%04d-12-31', $anioVigencia - 1),
            ];
        }
        // abril_marzo (default)
        return [
            sprintf('%04d-04-01', $anioVigencia - 1),
            sprintf('%04d-03-31', $anioVigencia),
        ];
    }
}
