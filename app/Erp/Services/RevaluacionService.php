<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\Cotizacion;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Diario;
use App\Erp\Models\Periodo;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Revaluación mensual de saldos USD (SPEC_01 RN-11).
 *
 * Al cerrar un período, para cada cuenta con moneda='USD' y saldo no cero al
 * último día del período, genera un asiento en diario AJU con fecha = último
 * día del período que ajusta el saldo en ARS al valor del día según la
 * cotización OFICIAL (valor_referencia). Contrapartida:
 *   · 4.2.04 "Diferencias de Cambio Positivas" (ganancia) — etiqueta DIF-CAMBIO+
 *   · 5.4.03 "Diferencias de Cambio Negativas" (pérdida) — etiqueta DIF-CAMBIO-
 *
 * El asiento tiene `origen='REVALUACION'` y `origen_id=periodo_id` para que sea
 * trazable y no se vuelva a generar sobre el mismo período (idempotencia).
 */
class RevaluacionService
{
    public function __construct(
        private readonly AsientoService $asientos,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ejecuta la revaluación para un período. Devuelve el asiento generado
     * (o null si no hubo diferencias que ajustar).
     */
    public function ejecutar(Periodo $periodo, User $usuario): ?Asiento
    {
        if ($periodo->estado !== 'ABIERTO') {
            throw new DomainException('PERIODO_NO_ABIERTO: solo se revalúa sobre períodos ABIERTOS (actual: '.$periodo->estado.')');
        }

        // Idempotencia: si ya hay un asiento REVALUACION para el período, no duplicar.
        $existente = Asiento::where('periodo_id', $periodo->id)
            ->where('origen', 'REVALUACION')
            ->where('origen_id', $periodo->id)
            ->first();

        if ($existente) {
            throw new DomainException('REVALUACION_YA_GENERADA: asiento existente id='.$existente->id);
        }

        $fechaCierre = Carbon::parse($periodo->fecha_fin);
        $empresaId = $periodo->ejercicio->empresa_id;

        $cotizacion = $this->cotizacionUsdReferencia($empresaId, $fechaCierre);
        if ($cotizacion === null) {
            throw new DomainException('SIN_COTIZACION: no hay cotización USD OFICIAL para '.$fechaCierre->toDateString());
        }

        $cuentasUsd = CuentaContable::where('empresa_id', $empresaId)
            ->where('moneda', 'USD')
            ->where('imputable', true)
            ->get();

        $diario = Diario::where('empresa_id', $empresaId)->where('codigo', 'AJU')->firstOrFail();

        $cuentaGanancia = CuentaContable::where('empresa_id', $empresaId)->where('codigo', '4.2.04')->firstOrFail();
        $cuentaPerdida = CuentaContable::where('empresa_id', $empresaId)->where('codigo', '5.4.03')->firstOrFail();

        $movimientos = [];
        $totalAjuste = 0.0;

        foreach ($cuentasUsd as $cuenta) {
            $ajuste = $this->calcularAjuste($cuenta, $fechaCierre, $cotizacion);
            if (abs($ajuste['delta_ars']) < 0.01) {
                continue;
            }

            $delta = round($ajuste['delta_ars'], 2);
            $totalAjuste += abs($delta);

            // Criterio (acorde a la naturaleza de la cuenta, no al signo del delta):
            //   ACTIVO (A) + delta > 0  → gana: DEBE cuenta, HABER 4.2.04
            //   ACTIVO (A) + delta < 0  → pierde: DEBE 5.4.03, HABER cuenta
            //   PASIVO (P) + delta > 0  → pierde: DEBE 5.4.03, HABER cuenta
            //   PASIVO (P) + delta < 0  → gana: DEBE cuenta, HABER 4.2.04
            $esActivo = $cuenta->tipo === 'A';
            $esGanancia = ($esActivo && $delta > 0) || (! $esActivo && $delta < 0);

            $importe = abs($delta);
            if ($esGanancia) {
                $movimientos[] = [
                    'cuenta_id' => $cuenta->id,
                    'glosa' => 'Revaluación '.$cuenta->codigo.' · saldo USD '.number_format($ajuste['saldo_usd'], 2).' @ '.$cotizacion,
                    'debe' => $delta > 0 ? $importe : 0,
                    'haber' => $delta < 0 ? $importe : 0,
                ];
                $movimientos[] = [
                    'cuenta_id' => $cuentaGanancia->id,
                    'glosa' => 'Contrapartida rev. '.$cuenta->codigo,
                    'debe' => $delta < 0 ? $importe : 0,
                    'haber' => $delta > 0 ? $importe : 0,
                ];
            } else {
                $movimientos[] = [
                    'cuenta_id' => $cuentaPerdida->id,
                    'glosa' => 'Contrapartida rev. '.$cuenta->codigo,
                    'debe' => $delta > 0 ? $importe : 0,
                    'haber' => $delta < 0 ? $importe : 0,
                ];
                $movimientos[] = [
                    'cuenta_id' => $cuenta->id,
                    'glosa' => 'Revaluación '.$cuenta->codigo.' · saldo USD '.number_format($ajuste['saldo_usd'], 2).' @ '.$cotizacion,
                    'debe' => $delta < 0 ? $importe : 0,
                    'haber' => $delta > 0 ? $importe : 0,
                ];
            }
        }

        if (empty($movimientos)) {
            return null;
        }

        $asiento = $this->asientos->crearBorrador([
            'empresa_id' => $empresaId,
            'diario_id' => $diario->id,
            'fecha' => $fechaCierre->toDateString(),
            'glosa' => sprintf('Revaluación USD período %02d/%d · cotización %s', $periodo->mes, $periodo->anio, $cotizacion),
            'origen' => 'REVALUACION',
            'origen_id' => $periodo->id,
            'origen_tabla' => 'erp_periodos',
            'usuario_id' => $usuario->id,
            'movimientos' => $movimientos,
        ]);

        $asiento = $this->asientos->contabilizar($asiento);

        $this->audit->logEvento(
            accion: 'REVALUACION_EJECUTADA',
            modulo: 'contabilidad',
            descripcion: sprintf(
                'Revaluación USD período %02d/%d · asiento %d · cotización %s · ajuste total $%s',
                $periodo->mes, $periodo->anio, $asiento->id, $cotizacion, number_format($totalAjuste, 2, ',', '.')
            ),
            empresaId: $empresaId,
        );

        return $asiento;
    }

    private function cotizacionUsdReferencia(int $empresaId, Carbon $fecha): ?float
    {
        // Busca la cotización OFICIAL del día; si no hay, la más reciente anterior.
        $cot = Cotizacion::query()
            ->whereHas('moneda', fn ($q) => $q->where('codigo', 'USD'))
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'OFICIAL')
            ->where('fecha', '<=', $fecha->toDateString())
            ->orderByDesc('fecha')
            ->first();

        return $cot ? (float) $cot->valor_referencia : null;
    }

    /**
     * Calcula el ajuste de una cuenta USD entre su saldo en ARS ya registrado
     * y lo que resulta de revaluar su saldo en USD a la cotización actual.
     *
     * @return array{saldo_usd:float, saldo_ars_actual:float, saldo_ars_revaluado:float, delta_ars:float}
     */
    private function calcularAjuste(CuentaContable $cuenta, Carbon $hasta, float $cotizacion): array
    {
        $row = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('a.empresa_id', $cuenta->empresa_id)
            ->where('a.estado', Asiento::ESTADO_CONTABILIZADO)
            ->where('m.cuenta_id', $cuenta->id)
            ->where('a.fecha', '<=', $hasta->toDateString())
            ->selectRaw('
                COALESCE(SUM(CASE WHEN m.moneda = "USD" THEN m.importe_origen * (CASE WHEN m.debe > 0 THEN 1 ELSE -1 END) ELSE 0 END), 0) AS saldo_usd,
                COALESCE(SUM(m.debe - m.haber), 0) AS saldo_ars
            ')
            ->first();

        $saldoUsd = (float) ($row->saldo_usd ?? 0);
        $saldoArsActual = (float) ($row->saldo_ars ?? 0);
        $saldoArsRevaluado = round($saldoUsd * $cotizacion, 2);
        $delta = round($saldoArsRevaluado - $saldoArsActual, 2);

        return [
            'saldo_usd' => $saldoUsd,
            'saldo_ars_actual' => $saldoArsActual,
            'saldo_ars_revaluado' => $saldoArsRevaluado,
            'delta_ars' => $delta,
        ];
    }
}
