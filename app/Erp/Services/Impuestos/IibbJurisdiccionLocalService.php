<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\IibbCmDeclaracion;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Calculadora de IIBB para jurisdicciones locales (ARCIBA CABA y ARBA PBA).
 *
 * Usa la misma tabla `erp_iibb_cm_declaracion` con `tipo='CM03'` y filtra
 * solo la jurisdicción correspondiente. A diferencia de CM03, no aplica
 * coeficiente (coef=1.0) — se usa directo el total de ingresos atribuidos
 * a esa jurisdicción.
 *
 * Esto permite reusar los generadores y reportes sin duplicar estructuras.
 *
 * Reglas:
 *   - CABA (901): `alicuota_default` del catálogo; restamos percepciones+retenciones CABA.
 *   - PBA  (902): idem con sus valores.
 *
 * Al usar este service para períodos IIBB_CABA / IIBB_PBA, se filtra por
 * `jurisdiccion` en vez de todas.
 */
class IibbJurisdiccionLocalService
{
    /** Mapping período → jurisdicción SIFERE. */
    private const MAP = [
        'IIBB_CABA' => '901',
        'IIBB_PBA'  => '902',
    ];

    public function __construct(
        private readonly IibbAtribucionService $atribucion,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{jurisdiccion: string, base: float, alicuota: float,
     *   impuesto: float, a_pagar: float}
     */
    public function calcular(PeriodoFiscal $periodo, User $usuario): array
    {
        $jur = self::MAP[$periodo->impuesto] ?? null;
        if ($jur === null) {
            throw new DomainException(
                "IIBB_LOCAL_PERIODO_INVALIDO: {$periodo->impuesto} no es jurisdicción local soportada"
            );
        }
        if (! $periodo->esEditable()) {
            throw new DomainException("IIBB_LOCAL_PERIODO_NO_EDITABLE: estado {$periodo->estado}");
        }

        $mesInicio = sprintf('%04d-%02d-01', $periodo->anio, $periodo->mes);
        $mesFin    = date('Y-m-d', strtotime("{$mesInicio} +1 month -1 day"));
        $this->atribucion->recalcularRango($periodo->empresa_id, $mesInicio, $mesFin, $usuario);
        $totales = $this->atribucion->totalesPorJurisdiccion($periodo->empresa_id, $mesInicio, $mesFin);

        $base = (float) ($totales[$jur]['ingresos'] ?? 0);
        $alicuota = (float) (DB::table('erp_iibb_jurisdicciones')
            ->where('codigo', $jur)->value('alicuota_default') ?? 0);
        $impuesto = round($base * $alicuota, 2);

        return DB::transaction(function () use ($periodo, $jur, $base, $alicuota, $impuesto, $usuario) {
            IibbCmDeclaracion::where('periodo_id', $periodo->id)
                ->where('jurisdiccion', $jur)->delete();

            $saldoAnt = $this->saldoAnteriorLocal($periodo, $jur);
            $aPagar = max(0, round($impuesto - $saldoAnt, 2));

            IibbCmDeclaracion::create([
                'periodo_id'     => $periodo->id,
                'tipo'           => 'CM03',   // reuso de tabla
                'jurisdiccion'   => $jur,
                'base_imponible' => $base,
                'coeficiente'    => 1.0,
                'base_atribuida' => $base,
                'alicuota'       => $alicuota,
                'impuesto_determinado' => $impuesto,
                'percepciones_sufridas'=> 0,
                'retenciones_sufridas' => 0,
                'saldo_anterior'       => $saldoAnt,
                'importe_a_pagar'      => $aPagar,
            ]);

            $this->audit->log("calcular_{$periodo->impuesto}", $periodo, null, [
                'jurisdiccion' => $jur, 'base' => $base, 'alicuota' => $alicuota,
                'impuesto' => $impuesto, 'a_pagar' => $aPagar,
            ], "{$periodo->impuesto} calc {$periodo->anio}/{$periodo->mes}");

            return [
                'jurisdiccion' => $jur, 'base' => $base, 'alicuota' => $alicuota,
                'impuesto' => $impuesto, 'saldo_anterior' => $saldoAnt, 'a_pagar' => $aPagar,
            ];
        });
    }

    private function saldoAnteriorLocal(PeriodoFiscal $periodo, string $jurisdiccion): float
    {
        $ultimoPeriodoId = DB::table('erp_periodos_fiscales')
            ->where('empresa_id', $periodo->empresa_id)
            ->where('impuesto', $periodo->impuesto)
            ->whereIn('estado', ['PRESENTADO', 'CERRADO'])
            ->whereRaw('(anio*100 + mes) < ?', [$periodo->anio * 100 + $periodo->mes])
            ->orderByDesc('anio')->orderByDesc('mes')
            ->value('id');

        if (! $ultimoPeriodoId) {
            return 0.0;
        }

        $row = DB::table('erp_iibb_cm_declaracion')
            ->where('periodo_id', $ultimoPeriodoId)
            ->where('jurisdiccion', $jurisdiccion)
            ->select(DB::raw('SUM(impuesto_determinado - importe_a_pagar) AS saldo'))
            ->first();

        return max(0, (float) ($row->saldo ?? 0));
    }
}
