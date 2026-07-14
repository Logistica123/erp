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
    /**
     * G-14 (P3 Matías): valor hora = básico / divisor, divisor CONFIG
     * (ERP_SUELDOS_DIVISOR_HORA, default 240 = (básico/30)/8 del Excel).
     */
    private function divisorValorHora(): float
    {
        $d = (float) config('erp.sueldos.divisor_valor_hora', 240);

        return $d > 0 ? $d : 240.0;
    }

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
        // G-07 (P1): el override por (liquidación, empleado) pisa el
        // default del maestro SOLO en esta liquidación.
        $override = DB::table('erp_emp_liquidacion_reparto_override')
            ->where('liquidacion_id', $liq->id)->where('empleado_id', $emp->id)->first();
        if ($override) {
            $compVig = (object) [
                'porc_formal' => (float) $override->porc_formal,
                'porc_efectivo' => (float) $override->porc_efectivo,
                'porc_mt' => (float) $override->porc_mt,
            ];
        }
        if (! $compVig) {
            return [];
        }

        $basicoTotal = (float) $basicoVig->basico_total;
        $valorHora   = $basicoTotal / $this->divisorValorHora();
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
        if ($liq->tipo === Liquidacion::TIPO_SAC) {
            // G-02 (LCT 121-123): mitad del MEJOR básico del semestre,
            // proporcional para ingresos dentro del semestre, paga_sac.
            if (! $emp->paga_sac) {
                return [];
            }
            $brutoBasico = $this->sacBruto($emp, $liq);
            if ($brutoBasico <= 0) {
                return [];
            }
        } else {
            $brutoBasico = round($basicoTotal * $diasTrabajados / self::DIAS_MES, 2);
        }

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
        // Camino A (P2, Bloque 2): por default NO se aplican — el recibo
        // formal AFIP lo liquida LIBER; el ERP refleja bolsillo.
        if (config('erp.sueldos.aplicar_descuentos_legales', false)
            && $emp->regimen !== Empleado::REGIMEN_MONOTRIBUTISTA && $brutoFormal > 0) {
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

    // ------------------------------------------------------------------
    // G-03 (Bloque 1 Sueldos) — cierre sellado con hash de integridad
    // ------------------------------------------------------------------

    /**
     * Aprueba la liquidación (CALCULADA → APROBADA) sellando el snapshot
     * con SHA-256 — equivalente al .bat del Excel que congela fórmulas a
     * valores, con verificación posterior (patrón RN-6 de asientos).
     */
    public function aprobar(Liquidacion $liq, int $userId): Liquidacion
    {
        if ($liq->estado !== Liquidacion::ESTADO_CALCULADA) {
            throw new DomainException('ESTADO_INVALIDO: para aprobar la liquidación debe estar CALCULADA (actual: '.$liq->estado.')');
        }

        // G-10 (spec §8): ningún empleado puede cerrar con neto negativo.
        $this->validarNetosNoNegativos($liq);

        $liq->update([
            'estado'           => Liquidacion::ESTADO_APROBADA,
            'fecha_aprobacion' => now(),
            'aprobado_por_id'  => $userId,
            'hash_integridad'  => $this->hashIntegridad($liq),
        ]);

        return $liq->fresh();
    }

    /** Recalcula el hash del snapshot y lo compara con el sellado. */
    public function verificarIntegridad(Liquidacion $liq): bool
    {
        if (! $liq->hash_integridad) {
            return false;
        }

        return hash_equals($liq->hash_integridad, $this->hashIntegridad($liq));
    }

    /**
     * SHA-256 canónico del snapshot: cabecera (período, tipo, totales) +
     * TODOS los ítems ordenados determinísticamente. Cualquier alta, baja
     * o modificación posterior de ítems (o de totales) cambia el hash.
     */
    private function hashIntegridad(Liquidacion $liq): string
    {
        $items = LiquidacionItem::where('liquidacion_id', $liq->id)
            ->orderBy('empleado_id')->orderBy('concepto_id')->orderBy('componente')->orderBy('id')
            ->get(['empleado_id', 'concepto_id', 'componente', 'cantidad', 'importe', 'base_calculo']);

        $payload = sprintf(
            '%s|%s|%.2f|%.2f|%.2f|%.2f|%.2f|%.2f|%d',
            $liq->periodo, $liq->tipo,
            (float) $liq->total_bruto, (float) $liq->total_descuentos, (float) $liq->total_neto,
            (float) $liq->total_formal, (float) $liq->total_efectivo, (float) $liq->total_mt,
            (int) $liq->empleados_count,
        );
        foreach ($items as $i) {
            $payload .= sprintf(
                '||%d|%d|%s|%.2f|%.2f|%.2f',
                $i->empleado_id, $i->concepto_id, $i->componente,
                (float) $i->cantidad, (float) $i->importe, (float) $i->base_calculo,
            );
        }

        return hash('sha256', $payload);
    }

    /**
     * G-10 — neto por empleado ≥ 0. Si algún descuento excede los haberes,
     * error explícito con el detalle (empleado, haberes, descuentos) para
     * que el tesorero sepa exactamente qué corregir antes de cerrar.
     */
    private function validarNetosNoNegativos(Liquidacion $liq): void
    {
        $filas = DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->join('erp_emp_empleados as e', 'e.id', '=', 'i.empleado_id')
            ->where('i.liquidacion_id', $liq->id)
            ->groupBy('i.empleado_id', 'e.legajo')
            ->selectRaw("e.legajo,
                SUM(CASE WHEN c.signo = 'HABER' THEN i.importe ELSE 0 END) haberes,
                SUM(CASE WHEN c.signo = 'DESCUENTO' THEN i.importe ELSE 0 END) descuentos")
            ->get();

        $negativos = [];
        foreach ($filas as $f) {
            $neto = round((float) $f->haberes - (float) $f->descuentos, 2);
            if ($neto < -0.009) {
                $negativos[] = sprintf('%s (haberes $%s, descuentos $%s, neto $%s)',
                    $f->legajo,
                    number_format((float) $f->haberes, 2, ',', '.'),
                    number_format((float) $f->descuentos, 2, ',', '.'),
                    number_format($neto, 2, ',', '.'));
            }
        }

        if ($negativos) {
            throw new DomainException(
                'NETO_NEGATIVO: no se puede aprobar — los descuentos exceden los haberes en: '
                .implode(' · ', $negativos)
                .'. Revisar cuotas de préstamo/descuentos o pausar el préstamo.'
            );
        }
    }

    /**
     * G-02 — SAC según LCT art. 121-123: mitad de la mejor remuneración
     * mensual devengada del semestre calendario. Se toma el básico
     * vigente al fin de cada mes del semestre (hasta el mes del período
     * SAC), acotado a los últimos N meses según config SAC_MESES (6
     * default; 3 si Sebastián pide replicar el Excel). Ingresos dentro
     * del semestre: proporcional (mejor/2 × meses_trabajados/6).
     */
    private function sacBruto(Empleado $emp, Liquidacion $liq): float
    {
        [$anio, $mes] = array_map('intval', explode('-', $liq->periodo));
        $mesesSemestre = $mes <= 6 ? range(1, 6) : range(7, 12);
        // Meses del semestre transcurridos hasta el período del SAC.
        $meses = array_values(array_filter($mesesSemestre, fn ($m) => $m <= $mes));
        // Ventana config: los últimos N (para el modo "3 meses" del Excel).
        $ventana = max(1, (int) config('erp.sueldos.sac_meses_calculo', 6));
        $meses = array_slice($meses, -$ventana);

        $mejor = 0.0;
        $trabajados = 0;
        foreach ($meses as $m) {
            $finMes = sprintf('%04d-%02d-%02d', $anio, $m, (int) date('t', strtotime(sprintf('%04d-%02d-01', $anio, $m))));
            $inicioMes = sprintf('%04d-%02d-01', $anio, $m);
            if ($emp->fecha_ingreso && $emp->fecha_ingreso->toDateString() > $finMes) {
                continue;
            }
            if ($emp->fecha_egreso && $emp->fecha_egreso->toDateString() < $inicioMes) {
                continue;
            }
            $trabajados++;
            $basico = $this->basicoVigente($emp->id, $finMes);
            if ($basico) {
                $mejor = max($mejor, (float) $basico->basico_total);
            }
        }

        if ($mejor <= 0 || $trabajados === 0) {
            return 0.0;
        }

        // Proporcionalidad sobre el semestre COMPLETO (6 meses), aunque la
        // ventana de cálculo sea menor.
        $proporcion = min(1.0, $trabajados / count($mesesSemestre));

        return round(($mejor / 2) * $proporcion, 2);
    }
}
