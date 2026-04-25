<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\BpParticipacion;
use App\Erp\Models\Impuestos\EmpresaSocio;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Calcula la liquidación BP F.2000 — Bienes Personales Participaciones (RN-58).
 *
 * La sociedad paga por sus socios sobre el valor patrimonial proporcional
 * (VPP) al 31/12 del ejercicio. Pasos:
 *
 *   1. Patrimonio neto contable al cierre = saldo de cuentas tipo PN
 *      (suma de saldos H, ya que su saldo natural es ACREEDOR).
 *   2. Si `erp_ejercicios.ajusta_por_inflacion=1`, el caller pasa
 *      `pn_ajustado_override` con el valor reexpresado por RT 6.
 *   3. Distribución por socio: PN × % participación / 100.
 *   4. Impuesto por socio: VPP × alícuota BP_PARTICIPACIONES vigente.
 *   5. Total = suma de impuestos por socio.
 *
 * Idempotente por `ejercicio_id`. Recálculo seguro.
 *
 * Validación: la suma de %participación de socios activos al cierre debe
 * ser 100%. Si no, error explicando la discrepancia.
 */
class BpCalculator
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array{pn_ajustado_override?: float} $contexto
     */
    public function calcular(PeriodoFiscal $periodo, Ejercicio $ejercicio, User $usuario, array $contexto = []): BpParticipacion
    {
        if ($periodo->impuesto !== 'BP_PART') {
            throw new DomainException('BP_PERIODO_INVALIDO: requerí período BP_PART');
        }
        if (! $periodo->esEditable()) {
            throw new DomainException("BP_PERIODO_NO_EDITABLE: estado {$periodo->estado}");
        }
        if ($periodo->ejercicio_id !== $ejercicio->id) {
            throw new DomainException('BP_EJERCICIO_MISMATCH');
        }

        return DB::transaction(function () use ($periodo, $ejercicio, $usuario, $contexto) {
            $socios = $this->sociosActivos($ejercicio);
            $this->validarParticipacion($socios);

            $pnContable = $this->patrimonioNetoContable($ejercicio);
            $pnAjustado = isset($contexto['pn_ajustado_override'])
                ? (float) $contexto['pn_ajustado_override']
                : $pnContable;

            if ($pnAjustado <= 0) {
                throw new DomainException("BP_PN_NO_POSITIVO: PN ajustado={$pnAjustado}, no se computa BP");
            }

            $alicuota = $this->alicuotaVigente($ejercicio->fecha_cierre);

            $socios_detalle = [];
            $impuestoTotal = 0.0;

            foreach ($socios as $s) {
                $vpp = round($pnAjustado * ((float) $s->porcentaje_participacion) / 100, 2);
                $imp = round($vpp * $alicuota, 2);
                $impuestoTotal += $imp;
                $socios_detalle[] = [
                    'cuit'                    => $s->cuit,
                    'nombre'                  => $s->nombre,
                    'tipo'                    => $s->tipo,
                    'porcentaje_participacion'=> (float) $s->porcentaje_participacion,
                    'vpp'                     => $vpp,
                    'impuesto'                => $imp,
                ];
            }

            $bp = BpParticipacion::updateOrCreate(
                ['ejercicio_id' => $ejercicio->id],
                [
                    'periodo_id'               => $periodo->id,
                    'patrimonio_neto_ajustado' => $pnAjustado,
                    'alicuota'                 => $alicuota,
                    'impuesto_total'           => round($impuestoTotal, 2),
                    'socios_detalle'           => $socios_detalle,
                ]
            );

            $this->audit->log('calcular_bp', $periodo, null, [
                'pn_contable' => $pnContable, 'pn_ajustado' => $pnAjustado,
                'alicuota' => $alicuota, 'impuesto_total' => $impuestoTotal,
                'socios' => count($socios_detalle),
            ], "BP F.2000 calc ejercicio #{$ejercicio->id} (user #{$usuario->id})");

            return $bp->fresh();
        });
    }

    /**
     * Patrimonio neto contable: suma de saldos H − D de cuentas PN imputables
     * del ejercicio. Las cuentas PN tienen saldo natural ACREEDOR.
     */
    public function patrimonioNetoContable(Ejercicio $ejercicio): float
    {
        $row = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('a.empresa_id', $ejercicio->empresa_id)
            ->where('a.ejercicio_id', $ejercicio->id)
            ->where('a.estado', 'CONTABILIZADO')
            ->where('c.tipo', 'PN')
            ->where('c.imputable', 1)
            ->select(DB::raw('COALESCE(SUM(m.haber - m.debe), 0) AS pn'))
            ->value('pn');

        return round((float) $row, 2);
    }

    /**
     * Alícuota BP PARTICIPACIONES vigente para una fecha dada.
     */
    public function alicuotaVigente($fechaCierre): float
    {
        $fecha = $fechaCierre instanceof \DateTimeInterface
            ? $fechaCierre->format('Y-m-d')
            : (string) $fechaCierre;

        $row = DB::table('erp_bp_alicuotas')
            ->where('tipo', 'PARTICIPACIONES')
            ->where('vigente_desde', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $fecha);
            })
            ->orderByDesc('vigente_desde')
            ->first(['alicuota']);

        if (! $row) {
            throw new DomainException("BP_ALICUOTA_NO_ENCONTRADA: sin alícuota vigente al {$fecha}");
        }

        return (float) $row->alicuota;
    }

    /**
     * Socios activos al cierre del ejercicio: alta <= fecha_cierre y
     * (sin baja o baja > fecha_cierre).
     */
    private function sociosActivos(Ejercicio $ejercicio): \Illuminate\Support\Collection
    {
        $cierre = $ejercicio->fecha_cierre instanceof \DateTimeInterface
            ? $ejercicio->fecha_cierre->format('Y-m-d')
            : (string) $ejercicio->fecha_cierre;

        return EmpresaSocio::query()
            ->where('empresa_id', $ejercicio->empresa_id)
            ->where('activo', 1)
            ->where('fecha_alta', '<=', $cierre)
            ->where(function ($q) use ($cierre) {
                $q->whereNull('fecha_baja')->orWhere('fecha_baja', '>', $cierre);
            })
            ->orderBy('cuit')
            ->get();
    }

    /**
     * Valida que la suma de % participación = 100 (con tolerancia de 0.01%).
     */
    private function validarParticipacion(\Illuminate\Support\Collection $socios): void
    {
        if ($socios->isEmpty()) {
            throw new DomainException('BP_SIN_SOCIOS: no hay socios activos al cierre — cargá socios primero');
        }
        $suma = (float) $socios->sum('porcentaje_participacion');
        if (abs($suma - 100.0) > 0.01) {
            throw new DomainException(sprintf(
                'BP_PARTICIPACION_INVALIDA: suma de %% participaciones = %.4f%%, esperado 100%%',
                $suma
            ));
        }
    }
}
