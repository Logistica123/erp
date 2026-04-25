<?php

namespace App\Erp\Services\Sueldos;

use App\Erp\Models\Sueldos\Ausencia;
use App\Erp\Models\Sueldos\Concepto;
use App\Erp\Models\Sueldos\Empleado;
use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Models\Sueldos\LiquidacionItem;
use App\Erp\Models\Sueldos\Novedad;
use App\Erp\Models\Sueldos\Prestamo;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Motor de cálculo de la liquidación mensual (SPEC 08 §9).
 *
 * Algoritmo por empleado:
 *   1. Resolver básico vigente y composición vigente al cierre del período.
 *   2. Calcular bruto base ajustado por días trabajados (descuenta faltas
 *      injustificadas y suspensiones).
 *   3. Sumar haberes adicionales de novedades (HE, comisiones, aumentos,
 *      vacaciones, presentismo, viático, bonos).
 *   4. Si tipo=SAC, calcular SAC = básico_total / 2 (RN-104 — aprox. v1).
 *   5. Aplicar descuentos legales (jub 11%, OS 3%, INSSJP 3%, sindicato 2.5%)
 *      sólo sobre la base FORMAL.
 *   6. Aplicar descuentos internos (cuota préstamo, adelantos, combustible,
 *      póliza, sanción).
 *   7. Descomponer cada concepto según afecta_formal/efectivo/mt y los
 *      porcentajes vigentes del empleado.
 *   8. Persistir items en transacción + actualizar totales de la cabecera.
 */
class LiquidacionService
{
    /** Horas mensuales estándar usadas para valor_hora. */
    private const HORAS_MES = 200.0;

    /** Días para básico diario. */
    private const DIAS_MES = 30.0;

    public function calcular(Liquidacion $liq, ?int $userId = null): Liquidacion
    {
        if ($liq->estado === Liquidacion::ESTADO_APROBADA || $liq->estado === Liquidacion::ESTADO_PAGADA) {
            throw new DomainException('LIQUIDACION_CERRADA: estado '.$liq->estado.' es inmutable. Crear rectificativa.');
        }

        [$anio, $mes] = explode('-', $liq->periodo);
        $cierre = sprintf('%04d-%02d-%02d', (int) $anio, (int) $mes, (int) date('t', strtotime("$anio-$mes-01")));

        $empleados = Empleado::with(['composiciones', 'basicos'])
            ->where('activo', 1)
            ->where(function ($q) use ($cierre) {
                $q->whereNull('fecha_egreso')
                  ->orWhere('fecha_egreso', '>=', $cierre);
            })
            ->where('fecha_ingreso', '<=', $cierre)
            ->get();

        $conceptosByCodigo = Concepto::where('activo', 1)->get()->keyBy('codigo');

        DB::transaction(function () use ($liq, $empleados, $conceptosByCodigo, $cierre, $userId) {
            // Limpiar items previos (re-cálculo idempotente).
            LiquidacionItem::where('liquidacion_id', $liq->id)->delete();

            $totales = [
                'bruto' => 0.0, 'descuentos' => 0.0, 'neto' => 0.0,
                'formal' => 0.0, 'efectivo' => 0.0, 'mt' => 0.0,
            ];
            $count = 0;

            foreach ($empleados as $emp) {
                $itemsEmp = $this->calcularEmpleado($emp, $liq, $cierre, $conceptosByCodigo);
                if (empty($itemsEmp)) {
                    continue;
                }
                LiquidacionItem::insert($itemsEmp);
                $count++;

                foreach ($itemsEmp as $it) {
                    $signo = $conceptosByCodigo->firstWhere('id', $it['concepto_id'])?->signo ?? 'HABER';
                    $importe = (float) $it['importe'];
                    if ($signo === Concepto::SIGNO_HABER) {
                        $totales['bruto'] += $importe;
                    } else {
                        $totales['descuentos'] += $importe;
                    }
                    if ($it['componente'] === LiquidacionItem::COMPONENTE_FORMAL)   $totales['formal']   += ($signo === 'HABER' ? $importe : -$importe);
                    if ($it['componente'] === LiquidacionItem::COMPONENTE_EFECTIVO) $totales['efectivo'] += ($signo === 'HABER' ? $importe : -$importe);
                    if ($it['componente'] === LiquidacionItem::COMPONENTE_MT)       $totales['mt']       += ($signo === 'HABER' ? $importe : -$importe);
                }
            }

            $totales['neto'] = $totales['bruto'] - $totales['descuentos'];

            $liq->update([
                'estado'           => Liquidacion::ESTADO_CALCULADA,
                'fecha_calculo'    => now(),
                'calculado_por_id' => $userId,
                'total_bruto'      => round($totales['bruto'], 2),
                'total_descuentos' => round($totales['descuentos'], 2),
                'total_neto'       => round($totales['neto'], 2),
                'total_formal'     => round($totales['formal'], 2),
                'total_efectivo'   => round($totales['efectivo'], 2),
                'total_mt'         => round($totales['mt'], 2),
                'empleados_count'  => $count,
            ]);
        });

        return $liq->fresh();
    }

    /**
     * Calcula los ítems de un empleado para la liquidación dada.
     * @return array<int, array<string, mixed>> Filas listas para insert masivo.
     */
    private function calcularEmpleado(Empleado $emp, Liquidacion $liq, string $cierre, $conceptosByCodigo): array
    {
        $basicoVig = $this->basicoVigente($emp->id, $cierre);
        if (! $basicoVig) {
            return [];
        }
        $compVig = $this->composicionVigente($emp->id, $cierre);
        if (! $compVig) {
            return [];
        }

        $basicoTotal = (float) $basicoVig->basico_total;
        $valorHora   = $basicoTotal / self::HORAS_MES;
        $basicoDiario = $basicoTotal / self::DIAS_MES;

        $items = [];
        $now = now();

        // Días no trabajados (faltas injustificadas + suspensiones) → ajusta básico.
        $diasFaltados = (int) Ausencia::where('empleado_id', $emp->id)
            ->whereIn('tipo', ['FALTA_INJUSTIFICADA', 'SUSPENSION'])
            ->where('fecha_desde', '<=', $cierre)
            ->where('fecha_hasta', '>=', substr($liq->periodo, 0, 7).'-01')
            ->sum('dias_habiles');

        $diasTrabajados = max(0, (int) self::DIAS_MES - $diasFaltados);
        $brutoBasico = $liq->tipo === Liquidacion::TIPO_SAC
            ? $basicoTotal / 2  // RN-104 v1: SAC = básico/2.
            : round($basicoTotal * $diasTrabajados / self::DIAS_MES, 2);

        $codBasico = $liq->tipo === Liquidacion::TIPO_SAC ? 'SAC' : 'BASICO';
        $items = array_merge($items, $this->descomponer(
            $conceptosByCodigo[$codBasico] ?? null,
            $emp, $compVig, $brutoBasico,
            $liq->id, $now,
            cantidad: $liq->tipo === Liquidacion::TIPO_SAC ? 1 : $diasTrabajados,
            base: $basicoTotal
        ));

        // Presentismo (8.5% sobre básico, sólo formal).
        if ($emp->regimen !== Empleado::REGIMEN_MONOTRIBUTISTA && $liq->tipo === Liquidacion::TIPO_MENSUAL) {
            $presentismo = round($brutoBasico * 0.085, 2);
            $items = array_merge($items, $this->descomponer(
                $conceptosByCodigo['PRESENTISMO'] ?? null,
                $emp, $compVig, $presentismo,
                $liq->id, $now,
                base: $brutoBasico
            ));
        }

        // Novedades del período (HE, comisiones, ajustes, descuentos manuales).
        $novedades = Novedad::where('empleado_id', $emp->id)
            ->where('periodo', $liq->periodo)
            ->get();

        foreach ($novedades as $nov) {
            $concepto = $conceptosByCodigo->firstWhere('id', $nov->concepto_id);
            if (! $concepto) {
                continue;
            }
            $importe = $this->resolverImporteNovedad($nov, $concepto, $valorHora, $basicoDiario);
            if ($importe == 0.0) {
                continue;
            }
            $items = array_merge($items, $this->descomponer(
                $concepto, $emp, $compVig, $importe,
                $liq->id, $now,
                cantidad: (float) $nov->cantidad,
                unitario: $importe / max(1, (float) $nov->cantidad ?: 1),
                base: $basicoTotal
            ));
        }

        // Vacaciones gozadas (de ausencias tipo VACACIONES).
        if ($liq->tipo === Liquidacion::TIPO_MENSUAL) {
            $diasVacaciones = (int) Ausencia::where('empleado_id', $emp->id)
                ->where('tipo', 'VACACIONES')
                ->where('paga', 1)
                ->where('fecha_desde', '<=', $cierre)
                ->where('fecha_hasta', '>=', substr($liq->periodo, 0, 7).'-01')
                ->sum('dias_habiles');
            if ($diasVacaciones > 0) {
                $importeVac = round($basicoDiario * $diasVacaciones, 2);
                $items = array_merge($items, $this->descomponer(
                    $conceptosByCodigo['VACACIONES'] ?? null,
                    $emp, $compVig, $importeVac,
                    $liq->id, $now,
                    cantidad: $diasVacaciones, unitario: $basicoDiario,
                ));
            }
        }

        // Bruto FORMAL acumulado (para descuentos legales).
        $brutoFormal = 0.0;
        foreach ($items as $it) {
            if ($it['componente'] === LiquidacionItem::COMPONENTE_FORMAL) {
                $signo = $conceptosByCodigo->firstWhere('id', $it['concepto_id'])?->signo;
                if ($signo === Concepto::SIGNO_HABER) {
                    $brutoFormal += (float) $it['importe'];
                }
            }
        }

        // Descuentos legales — sólo sobre FORMAL, sólo para no-monotributistas.
        if ($emp->regimen !== Empleado::REGIMEN_MONOTRIBUTISTA && $brutoFormal > 0) {
            $legales = [
                'JUB_11'    => 0.11,
                'OS_3'      => 0.03,
                'LEY_19032' => 0.03,
                'SINDICATO' => 0.025,
            ];
            foreach ($legales as $cod => $pct) {
                $impDesc = round($brutoFormal * $pct, 2);
                $items[] = $this->itemRow(
                    $conceptosByCodigo[$cod] ?? null,
                    $emp, LiquidacionItem::COMPONENTE_FORMAL,
                    1, null, $impDesc, $brutoFormal, $liq->id, $now
                );
            }
        }

        // Descuentos internos: cuotas préstamo + saldos CC del empleado.
        $items = array_merge($items, $this->descuentosInternos($emp, $compVig, $liq, $conceptosByCodigo, $now));

        // Filtrar items vacíos / nulos.
        return array_values(array_filter($items, fn ($it) => $it !== null && abs((float) $it['importe']) > 0.005));
    }

    private function resolverImporteNovedad(Novedad $nov, Concepto $c, float $valorHora, float $basicoDiario): float
    {
        if ($nov->importe !== null) {
            return (float) $nov->importe;
        }
        $cantidad = (float) ($nov->cantidad ?: 0);
        return match ($c->codigo) {
            'HE_50'      => round($valorHora * 1.5 * $cantidad, 2),
            'HE_100'     => round($valorHora * 2.0 * $cantidad, 2),
            'HORAS_DESC' => round($valorHora * $cantidad, 2),
            'FALTA_DIA'  => round($basicoDiario * $cantidad, 2),
            default      => 0.0,
        };
    }

    /**
     * Aplica descuentos internos: cuotas de préstamos vigentes y saldos CC.
     */
    private function descuentosInternos(Empleado $emp, $compVig, Liquidacion $liq, $conceptosByCodigo, $now): array
    {
        $items = [];

        // Cuotas de préstamos vigentes cuyo primera_cuota_periodo <= período actual.
        $prestamos = Prestamo::where('empleado_id', $emp->id)
            ->where('estado', Prestamo::ESTADO_VIGENTE)
            ->where('primera_cuota_periodo', '<=', $liq->periodo)
            ->where('cuotas_pagadas', '<', DB::raw('cuotas_total'))
            ->get();

        foreach ($prestamos as $p) {
            $items = array_merge($items, $this->descomponer(
                $conceptosByCodigo['PRESTAMO_CUOTA'] ?? null,
                $emp, $compVig, (float) $p->cuota_mensual,
                $liq->id, $now,
                obs: 'Préstamo #'.$p->id.' cuota '.($p->cuotas_pagadas + 1).'/'.$p->cuotas_total,
            ));
        }

        // CC con saldo > 0 (otros tipos).
        $ccs = $emp->ccs()->where('saldo_actual', '>', 0)->where('activa', 1)->get();
        foreach ($ccs as $cc) {
            $codConcepto = match ($cc->tipo) {
                'ADELANTO'    => 'ADELANTO',
                'COMBUSTIBLE' => 'COMBUSTIBLE',
                'POLIZA'      => 'POLIZA',
                'SANCION'     => 'SANCION',
                default       => null,
            };
            if (! $codConcepto) {
                continue;
            }
            $items = array_merge($items, $this->descomponer(
                $conceptosByCodigo[$codConcepto] ?? null,
                $emp, $compVig, (float) $cc->saldo_actual,
                $liq->id, $now,
                obs: 'CC '.$cc->tipo.' saldo a descontar',
            ));
        }

        return $items;
    }

    /**
     * Descompone un importe en items según composición y flags afecta_*.
     * Devuelve hasta 3 items (uno por componente activo).
     */
    private function descomponer(
        ?Concepto $c, Empleado $emp, $compVig, float $importeTotal,
        int $liquidacionId, $now,
        ?float $cantidad = null, ?float $unitario = null, ?float $base = null, ?string $obs = null
    ): array {
        if (! $c || $importeTotal == 0.0) {
            return [];
        }

        $items = [];

        if ($c->afecta_formal && $compVig->porc_formal > 0) {
            $imp = round($importeTotal * (float) $compVig->porc_formal / 100, 2);
            if (abs($imp) > 0.005) {
                $items[] = $this->itemRow($c, $emp, LiquidacionItem::COMPONENTE_FORMAL, $cantidad, $unitario, $imp, $base, $liquidacionId, $now, $obs);
            }
        }
        if ($c->afecta_efectivo && $compVig->porc_efectivo > 0) {
            $imp = round($importeTotal * (float) $compVig->porc_efectivo / 100, 2);
            if (abs($imp) > 0.005) {
                $items[] = $this->itemRow($c, $emp, LiquidacionItem::COMPONENTE_EFECTIVO, $cantidad, $unitario, $imp, $base, $liquidacionId, $now, $obs);
            }
        }
        if ($c->afecta_mt && $compVig->porc_mt > 0) {
            $imp = round($importeTotal * (float) $compVig->porc_mt / 100, 2);
            if (abs($imp) > 0.005) {
                $items[] = $this->itemRow($c, $emp, LiquidacionItem::COMPONENTE_MT, $cantidad, $unitario, $imp, $base, $liquidacionId, $now, $obs);
            }
        }

        return $items;
    }

    private function itemRow(
        ?Concepto $c, Empleado $emp, string $componente,
        ?float $cantidad, ?float $unitario, float $importe, ?float $base,
        int $liquidacionId, $now, ?string $obs = null
    ): ?array {
        if (! $c) {
            return null;
        }
        return [
            'liquidacion_id'   => $liquidacionId,
            'empleado_id'      => $emp->id,
            'concepto_id'      => $c->id,
            'componente'       => $componente,
            'cantidad'         => $cantidad ?? 0,
            'importe_unitario' => $unitario,
            'importe'          => $importe,
            'base_calculo'     => $base,
            'observaciones'    => $obs,
        ];
    }

    private function basicoVigente(int $empleadoId, string $fechaCorte)
    {
        return DB::table('erp_emp_basicos_historial')
            ->where('empleado_id', $empleadoId)
            ->where('vigencia_desde', '<=', $fechaCorte)
            ->where(function ($q) use ($fechaCorte) {
                $q->whereNull('vigencia_hasta')->orWhere('vigencia_hasta', '>=', $fechaCorte);
            })
            ->orderByDesc('vigencia_desde')
            ->first();
    }

    private function composicionVigente(int $empleadoId, string $fechaCorte)
    {
        return DB::table('erp_emp_composicion_sueldo')
            ->where('empleado_id', $empleadoId)
            ->where('vigencia_desde', '<=', $fechaCorte)
            ->where(function ($q) use ($fechaCorte) {
                $q->whereNull('vigencia_hasta')->orWhere('vigencia_hasta', '>=', $fechaCorte);
            })
            ->orderByDesc('vigencia_desde')
            ->first();
    }
}
