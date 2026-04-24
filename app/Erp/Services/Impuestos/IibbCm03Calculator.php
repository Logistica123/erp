<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\IibbCmDeclaracion;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Calcula la DDJJ IIBB Convenio Multilateral CM03 mensual (RN-52).
 *
 * Para cada jurisdicción con coeficiente VIGENTE:
 *   base_atribuida        = base_imponible_total_mes × coeficiente
 *   impuesto_determinado  = base_atribuida × alícuota_jurisdicción
 *   importe_a_pagar       = max(0, impuesto_determinado − percepciones − retenciones − saldo_anterior)
 *
 * La base_imponible_total del mes son los ingresos atribuibles (ventas del
 * período netas). `IibbAtribucionService::baseImponibleCm03` suma todos
 * los ingresos del mes desde `erp_iibb_jurisdiccion_mov` (ingresos).
 *
 * Idempotente: reemplaza filas de `erp_iibb_cm_declaracion` con mismo
 * (periodo_id, jurisdiccion).
 */
class IibbCm03Calculator
{
    public function __construct(
        private readonly IibbCm05Calculator $cm05,
        private readonly IibbAtribucionService $atribucion,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{por_jurisdiccion: array<string, array>, total_determinado: float, total_a_pagar: float}
     */
    public function calcular(PeriodoFiscal $periodo, User $usuario): array
    {
        if ($periodo->impuesto !== 'IIBB_CM') {
            throw new DomainException('CM03_PERIODO_INVALIDO: requerí período IIBB_CM');
        }
        if (! $periodo->esEditable()) {
            throw new DomainException("CM03_PERIODO_NO_EDITABLE: estado {$periodo->estado}");
        }

        $coefs = $this->cm05->vigentes($periodo->anio);
        if (empty($coefs)) {
            throw new DomainException("CM03_SIN_COEFICIENTES: no hay coeficientes VIGENTES para año {$periodo->anio} (aprobar CM05 primero)");
        }

        $mesInicio = sprintf('%04d-%02d-01', $periodo->anio, $periodo->mes);
        $mesFin    = date('Y-m-d', strtotime("{$mesInicio} +1 month -1 day"));
        $this->atribucion->recalcularRango($periodo->empresa_id, $mesInicio, $mesFin, $usuario);

        $totales = $this->atribucion->totalesPorJurisdiccion($periodo->empresa_id, $mesInicio, $mesFin);
        $baseMes = array_sum(array_column($totales, 'ingresos'));

        if ($baseMes <= 0) {
            throw new DomainException('CM03_SIN_INGRESOS: no hay ingresos en el mes');
        }

        $saldoAnt = $this->saldoAnterior($periodo);

        return DB::transaction(function () use ($periodo, $usuario, $coefs, $baseMes, $saldoAnt) {
            // Limpiar declaración previa del período.
            IibbCmDeclaracion::where('periodo_id', $periodo->id)
                ->where('tipo', 'CM03')
                ->delete();

            $alicuotas = DB::table('erp_iibb_jurisdicciones')->pluck('alicuota_default', 'codigo');

            $porJur = [];
            $totalDeterminado = 0.0;
            $totalAPagar = 0.0;
            $primero = true;

            foreach ($coefs as $jur => $coef) {
                $baseAtribuida = round($baseMes * $coef, 2);
                $alicuota = (float) ($alicuotas[$jur] ?? 0);
                $impDeterminado = round($baseAtribuida * $alicuota, 2);

                // Saldo anterior se imputa a la primera jurisdicción por convención simple.
                $saldoAntJur = $primero ? $saldoAnt : 0.0;
                $primero = false;

                $aPagar = max(0, round($impDeterminado - $saldoAntJur, 2));
                $totalDeterminado += $impDeterminado;
                $totalAPagar += $aPagar;

                $decl = IibbCmDeclaracion::create([
                    'periodo_id'     => $periodo->id,
                    'tipo'           => 'CM03',
                    'jurisdiccion'   => $jur,
                    'base_imponible' => $baseMes,
                    'coeficiente'    => $coef,
                    'base_atribuida' => $baseAtribuida,
                    'alicuota'       => $alicuota,
                    'impuesto_determinado' => $impDeterminado,
                    'percepciones_sufridas'=> 0,   // H3 sobre compras — se puede enriquecer luego
                    'retenciones_sufridas' => 0,
                    'saldo_anterior'       => $saldoAntJur,
                    'importe_a_pagar'      => $aPagar,
                ]);

                $porJur[$jur] = $decl->toArray();
            }

            $this->audit->log('calcular_cm03', $periodo, null, [
                'base_mes' => $baseMes, 'total_determinado' => $totalDeterminado,
                'total_a_pagar' => $totalAPagar, 'jurisdicciones' => count($porJur),
            ], "CM03 calc {$periodo->anio}/{$periodo->mes} (user #{$usuario->id})");

            return [
                'base_mes' => $baseMes,
                'por_jurisdiccion' => $porJur,
                'total_determinado' => round($totalDeterminado, 2),
                'total_a_pagar' => round($totalAPagar, 2),
            ];
        });
    }

    private function saldoAnterior(PeriodoFiscal $periodo): float
    {
        // Tomamos el último período CM03 PRESENTADO/CERRADO anterior y sumamos
        // su saldo remanente (determinado - pagado). Subconsulta para elegir el
        // periodo_id más reciente, luego agregado.
        $ultimoPeriodoId = DB::table('erp_periodos_fiscales')
            ->where('empresa_id', $periodo->empresa_id)
            ->where('impuesto', 'IIBB_CM')
            ->whereIn('estado', ['PRESENTADO', 'CERRADO'])
            ->whereRaw('(anio*100 + mes) < ?', [$periodo->anio * 100 + $periodo->mes])
            ->orderByDesc('anio')->orderByDesc('mes')
            ->value('id');

        if (! $ultimoPeriodoId) {
            return 0.0;
        }

        $row = DB::table('erp_iibb_cm_declaracion')
            ->where('periodo_id', $ultimoPeriodoId)
            ->where('tipo', 'CM03')
            ->select(DB::raw('SUM(impuesto_determinado - importe_a_pagar) AS saldo'))
            ->first();

        return max(0, (float) ($row->saldo ?? 0));
    }
}
