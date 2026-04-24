<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\GananciaLiquidacion;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Calcula la liquidación anual de Ganancias F.713 (RN-55, RN-56).
 *
 *   Resultado contable  = saldo de la cuenta 3.3.02 Resultado del Ejercicio
 *                         (o Net(Ingresos RP − Egresos RN) si el ejercicio
 *                         no fue cerrado aún con refundición).
 *   Resultado impositivo = resultado_contable + ajustes_mas − ajustes_menos
 *   Impuesto determinado = escala_art73(resultado_impositivo)
 *     La escala (RN-56) es progresiva con cuota fija por tramo:
 *       impuesto = cuota_fija + (base - limite_inferior) * alicuota_marginal
 *
 * Ajuste por inflación (RT 6): si `erp_ejercicios.ajusta_por_inflacion=1`,
 * el caller pasa `ajuste_inflacion_importe` que se resta del impositivo.
 *
 * Ajustes fiscales se pasan como array a `agregarAjuste` (también persisten
 * en un campo JSON opcional dentro de `alicuota_escalonada`).
 */
class GananciasCalculator
{
    private const CUENTA_RESULTADO_EJERCICIO = '3.3.02';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Calcula y persiste la liquidación. Si el período no es editable, error.
     *
     * @param array{
     *   ajuste_inflacion?: float,
     *   retenciones_sufridas?: float,
     *   percepciones_sufridas?: float,
     *   anticipos_computados?: float,
     * } $contexto
     */
    public function calcular(PeriodoFiscal $periodo, Ejercicio $ejercicio, User $usuario, array $contexto = []): GananciaLiquidacion
    {
        if ($periodo->impuesto !== 'GAN_ANUAL') {
            throw new DomainException('GAN_PERIODO_INVALIDO: requerí período GAN_ANUAL');
        }
        if (! $periodo->esEditable()) {
            throw new DomainException("GAN_PERIODO_NO_EDITABLE: estado {$periodo->estado}");
        }
        if ($periodo->ejercicio_id !== $ejercicio->id) {
            throw new DomainException('GAN_EJERCICIO_MISMATCH: el periodo debe apuntar al ejercicio pasado');
        }

        return DB::transaction(function () use ($periodo, $ejercicio, $usuario, $contexto) {
            $resultadoContable = $this->resultadoContable($ejercicio);

            // Preservar ajustes existentes si ya había una liquidación calculada.
            $previa = GananciaLiquidacion::where('ejercicio_id', $ejercicio->id)->first();
            $ajustesMas   = $previa ? (float) $previa->ajustes_fiscales_mas   : 0.0;
            $ajustesMenos = $previa ? (float) $previa->ajustes_fiscales_menos : 0.0;
            $detalleAjustes = $previa && is_array($previa->alicuota_escalonada)
                ? ($previa->alicuota_escalonada['ajustes'] ?? [])
                : [];

            $ajusteInflacion = (float) ($contexto['ajuste_inflacion'] ?? ($previa->ajuste_inflacion_importe ?? 0));
            $resultadoImpositivo = round($resultadoContable + $ajustesMas - $ajustesMenos - $ajusteInflacion, 2);

            $breakdown = $this->aplicarEscala($resultadoImpositivo, $ejercicio->fecha_cierre);
            $impuestoDeterminado = round($breakdown['impuesto'], 2);

            $retSuf  = (float) ($contexto['retenciones_sufridas'] ?? ($previa->retenciones_sufridas ?? 0));
            $percSuf = (float) ($contexto['percepciones_sufridas'] ?? ($previa->percepciones_sufridas ?? 0));
            $anticipos = (float) ($contexto['anticipos_computados'] ?? ($previa->anticipos_computados ?? 0));

            $neto = round($impuestoDeterminado - $retSuf - $percSuf - $anticipos, 2);
            $saldoAPagar = max(0, $neto);
            $saldoAFavor = max(0, -$neto);

            $meta = [
                'breakdown_tramos' => $breakdown['tramos'],
                'ajustes'          => $detalleAjustes,
            ];

            $liq = GananciaLiquidacion::updateOrCreate(
                ['ejercicio_id' => $ejercicio->id],
                [
                    'periodo_id'             => $periodo->id,
                    'resultado_contable'     => $resultadoContable,
                    'ajustes_fiscales_mas'   => $ajustesMas,
                    'ajustes_fiscales_menos' => $ajustesMenos,
                    'resultado_impositivo'   => $resultadoImpositivo,
                    'alicuota_escalonada'    => $meta,
                    'impuesto_determinado'   => $impuestoDeterminado,
                    'anticipos_computados'   => $anticipos,
                    'retenciones_sufridas'   => $retSuf,
                    'percepciones_sufridas'  => $percSuf,
                    'saldo_a_pagar'          => $saldoAPagar,
                    'saldo_a_favor'          => $saldoAFavor,
                    'ajusta_por_inflacion'   => (bool) $ejercicio->ajusta_por_inflacion,
                    'ajuste_inflacion_importe' => $ajusteInflacion,
                ]
            );

            $this->audit->log('calcular_ganancias', $periodo, null, [
                'contable' => $resultadoContable, 'impositivo' => $resultadoImpositivo,
                'determinado' => $impuestoDeterminado, 'a_pagar' => $saldoAPagar,
            ], "F.713 calc ejercicio #{$ejercicio->id} (user #{$usuario->id})");

            return $liq->fresh();
        });
    }

    /**
     * Agrega un ajuste fiscal (MAS o MENOS) y recalcula la liquidación.
     *
     * @param array{tipo:string, concepto:string, importe:float, descripcion?:string} $ajuste
     */
    public function agregarAjuste(GananciaLiquidacion $liq, array $ajuste, User $usuario): GananciaLiquidacion
    {
        if (! in_array($ajuste['tipo'], ['MAS', 'MENOS'], true)) {
            throw new DomainException('GAN_AJUSTE_TIPO_INVALIDO');
        }
        if (! isset($ajuste['importe']) || $ajuste['importe'] < 0) {
            throw new DomainException('GAN_AJUSTE_IMPORTE_INVALIDO');
        }

        return DB::transaction(function () use ($liq, $ajuste, $usuario) {
            $meta = is_array($liq->alicuota_escalonada) ? $liq->alicuota_escalonada : [];
            $meta['ajustes'][] = [
                'tipo'        => $ajuste['tipo'],
                'concepto'    => $ajuste['concepto'],
                'importe'     => (float) $ajuste['importe'],
                'descripcion' => $ajuste['descripcion'] ?? null,
                'agregado_at' => now()->toDateTimeString(),
                'user_id'     => $usuario->id,
            ];

            if ($ajuste['tipo'] === 'MAS') {
                $liq->ajustes_fiscales_mas = (float) $liq->ajustes_fiscales_mas + (float) $ajuste['importe'];
            } else {
                $liq->ajustes_fiscales_menos = (float) $liq->ajustes_fiscales_menos + (float) $ajuste['importe'];
            }

            // Recalcular impositivo e impuesto con la nueva suma.
            $ejercicio = $liq->ejercicio;
            $resultadoImpositivo = round(
                (float) $liq->resultado_contable
                + (float) $liq->ajustes_fiscales_mas
                - (float) $liq->ajustes_fiscales_menos
                - (float) $liq->ajuste_inflacion_importe,
                2
            );
            $breakdown = $this->aplicarEscala($resultadoImpositivo, $ejercicio->fecha_cierre);
            $meta['breakdown_tramos'] = $breakdown['tramos'];

            $liq->resultado_impositivo  = $resultadoImpositivo;
            $liq->impuesto_determinado  = round($breakdown['impuesto'], 2);
            $liq->alicuota_escalonada   = $meta;

            $neto = round(
                $liq->impuesto_determinado - $liq->retenciones_sufridas
                - $liq->percepciones_sufridas - $liq->anticipos_computados,
                2
            );
            $liq->saldo_a_pagar = max(0, $neto);
            $liq->saldo_a_favor = max(0, -$neto);
            $liq->save();

            $this->audit->log('gan_ajuste', $liq, null, $ajuste,
                "Ajuste {$ajuste['tipo']} {$ajuste['concepto']}={$ajuste['importe']} (user #{$usuario->id})");

            return $liq->fresh();
        });
    }

    /**
     * Resultado contable del ejercicio:
     *   - Si 3.3.02 tiene saldo (ejercicio cerrado con refundición), lo devuelve.
     *   - Si no, calcula neto de cuentas RP - RN.
     */
    private function resultadoContable(Ejercicio $ejercicio): float
    {
        $saldoRE = (float) (DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.ejercicio_id', $ejercicio->id)
            ->whereIn('a.estado', ['CONTABILIZADO'])
            ->where('c.codigo', self::CUENTA_RESULTADO_EJERCICIO)
            ->select(DB::raw('COALESCE(SUM(m.haber - m.debe), 0) AS saldo'))
            ->value('saldo'));

        if (abs($saldoRE) > 0.01) {
            // Ganancia (saldo H > 0) o pérdida (saldo H < 0).
            return round($saldoRE, 2);
        }

        // Fallback: sumar RP - RN del ejercicio.
        $rows = DB::table('erp_cuentas_contables as c')
            ->leftJoin('erp_movimientos_asiento as m', 'm.cuenta_id', '=', 'c.id')
            ->leftJoin('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('c.empresa_id', $ejercicio->empresa_id)
            ->whereIn('c.tipo', ['RP', 'RN'])
            ->where(function ($q) use ($ejercicio) {
                $q->whereNull('a.id')->orWhere(function ($q2) use ($ejercicio) {
                    $q2->where('a.ejercicio_id', $ejercicio->id)
                       ->whereIn('a.estado', ['CONTABILIZADO']);
                });
            })
            ->select([
                'c.tipo',
                DB::raw('COALESCE(SUM(m.debe), 0) AS debitos'),
                DB::raw('COALESCE(SUM(m.haber), 0) AS creditos'),
            ])
            ->groupBy('c.tipo')
            ->get();

        $ingresos = 0.0;
        $gastos = 0.0;
        foreach ($rows as $r) {
            if ($r->tipo === 'RP') {
                $ingresos += ((float) $r->creditos) - ((float) $r->debitos);
            } else {
                $gastos += ((float) $r->debitos) - ((float) $r->creditos);
            }
        }
        return round($ingresos - $gastos, 2);
    }

    /**
     * Aplica escala art 73 LIG vigente en la fecha de cierre del ejercicio.
     *
     * @return array{impuesto:float, tramos:array<int, array>}
     */
    public function aplicarEscala(float $base, $fechaCierre): array
    {
        if ($base <= 0) {
            return ['impuesto' => 0.0, 'tramos' => []];
        }

        $fecha = $fechaCierre instanceof \DateTimeInterface
            ? $fechaCierre->format('Y-m-d')
            : (string) $fechaCierre;

        $tramos = DB::table('erp_ganancias_escala')
            ->where('vigente_desde', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $fecha);
            })
            ->orderBy('tramo')
            ->get();

        if ($tramos->isEmpty()) {
            throw new DomainException("GAN_ESCALA_NO_ENCONTRADA: sin escala vigente al {$fecha}");
        }

        foreach ($tramos as $t) {
            $li = (float) $t->limite_inferior;
            $ls = $t->limite_superior !== null ? (float) $t->limite_superior : INF;
            if ($base >= $li && $base < $ls) {
                $impuesto = (float) $t->cuota_fija + ($base - $li) * (float) $t->alicuota_marginal;
                return [
                    'impuesto' => $impuesto,
                    'tramos' => [[
                        'tramo' => (int) $t->tramo,
                        'base' => $base,
                        'limite_inferior' => $li,
                        'limite_superior' => is_finite($ls) ? $ls : null,
                        'cuota_fija' => (float) $t->cuota_fija,
                        'alicuota_marginal' => (float) $t->alicuota_marginal,
                        'impuesto' => round($impuesto, 2),
                    ]],
                ];
            }
        }

        // Caso base > último tramo (limite_superior NULL). Tomamos el último.
        $ultimo = $tramos->last();
        $li = (float) $ultimo->limite_inferior;
        $impuesto = (float) $ultimo->cuota_fija + ($base - $li) * (float) $ultimo->alicuota_marginal;
        return [
            'impuesto' => $impuesto,
            'tramos' => [[
                'tramo' => (int) $ultimo->tramo,
                'base' => $base,
                'limite_inferior' => $li,
                'limite_superior' => null,
                'cuota_fija' => (float) $ultimo->cuota_fija,
                'alicuota_marginal' => (float) $ultimo->alicuota_marginal,
                'impuesto' => round($impuesto, 2),
            ]],
        ];
    }
}
