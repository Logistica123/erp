<?php

namespace App\Erp\Services;

use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Services\Integracion\ContabilizadorFacturas;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Flujo de estados de factura de venta (SPEC 03 §4.7):
 *   EMITIDA → CONTROLADA → COBRO_PARCIAL → COBRADA
 *                        ↘ ANULADA_POR_NC
 *
 * Estados iniciales posibles (al crear):
 *   PREPARADA (pre-emisión WSFE), EMITIDA (con CAE o importada), RECHAZADA.
 *
 * controlar(): RN-34 — al marcar CONTROLADA, genera asiento contable automático
 *   (DEBE Deudores / HABER Ventas + IVA DF) vía ContabilizadorFacturas.
 *   La factura queda habilitada para asociarse a un Cobro en Tesorería (RN-31).
 */
class FacturaVentaService
{
    public const ESTADO_PREPARADA = 'PREPARADA';
    public const ESTADO_EMITIDA = 'EMITIDA';
    public const ESTADO_CONTROLADA = 'CONTROLADA';
    public const ESTADO_COBRO_PARCIAL = 'COBRO_PARCIAL';
    public const ESTADO_COBRADA = 'COBRADA';
    public const ESTADO_ANULADA_POR_NC = 'ANULADA_POR_NC';
    public const ESTADO_RECHAZADA = 'RECHAZADA';
    public const ESTADO_EMISION_FALLIDA = 'EMISION_FALLIDA';

    public function __construct(
        private readonly ContabilizadorFacturas $contabilizador,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * EMITIDA → CONTROLADA.
     * Dispara asiento contable automático (RN-34) si aún no lo tiene.
     */
    public function controlar(FacturaVenta $factura, User $usuario): FacturaVenta
    {
        if ($factura->estado === self::ESTADO_CONTROLADA) {
            throw new DomainException('FACTURA_YA_CONTROLADA');
        }
        if (! in_array($factura->estado, [self::ESTADO_EMITIDA, self::ESTADO_PREPARADA], true)) {
            throw new DomainException('FACTURA_ESTADO_INVALIDO: solo se controla desde EMITIDA (actual: '.$factura->estado.')');
        }
        if ($factura->estado === self::ESTADO_PREPARADA && ! $factura->cae) {
            throw new DomainException('FACTURA_SIN_CAE: falta CAE antes de controlar');
        }

        return DB::transaction(function () use ($factura, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            // RN-34 asiento automático
            $asiento = $this->contabilizador->contabilizarVenta($factura->id, $factura->empresa_id, $usuario->id);

            $factura->update([
                'estado' => self::ESTADO_CONTROLADA,
                'asiento_id' => $asiento->id,
            ]);

            $this->audit->logEvento(
                accion: 'FACTURA_VENTA_CONTROLADA',
                modulo: 'ventas',
                descripcion: sprintf(
                    'Factura venta #%d (tipo=%d PV=%d nro=%d) controlada por %s · asiento %d',
                    $factura->id, $factura->tipo_comprobante_id, $factura->punto_venta_id ?? 0,
                    $factura->numero, $usuario->name, $asiento->id
                ),
                empresaId: $factura->empresa_id,
            );

            return $factura->fresh();
        });
    }

    /**
     * Rechaza una factura (error de carga u observada por cliente vía FCE).
     * Requiere motivo.
     */
    public function rechazar(FacturaVenta $factura, string $motivo, User $usuario): FacturaVenta
    {
        if ($factura->estado === self::ESTADO_COBRADA || $factura->estado === self::ESTADO_COBRO_PARCIAL) {
            throw new DomainException('FACTURA_CON_COBROS: no se rechaza una factura con cobros registrados');
        }
        if ($factura->estado === self::ESTADO_RECHAZADA) {
            throw new DomainException('FACTURA_YA_RECHAZADA');
        }

        return DB::transaction(function () use ($factura, $motivo, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $factura->update([
                'estado' => self::ESTADO_RECHAZADA,
                'observaciones' => trim(($factura->observaciones ?? '').' · RECHAZADA: '.$motivo),
            ]);

            $this->audit->logEvento(
                accion: 'FACTURA_VENTA_RECHAZADA',
                modulo: 'ventas',
                descripcion: sprintf('Factura venta #%d rechazada: %s', $factura->id, $motivo),
                empresaId: $factura->empresa_id,
            );

            return $factura->fresh();
        });
    }

    /**
     * Recalcula estado basado en cobros asociados.
     * Llamable desde CobroService al crear/anular un cobro item.
     */
    public function reescalarPorCobros(FacturaVenta $factura): FacturaVenta
    {
        if (in_array($factura->estado, [self::ESTADO_RECHAZADA, self::ESTADO_ANULADA_POR_NC], true)) {
            return $factura;
        }

        $cobrado = (float) DB::table('erp_cobro_items as ci')
            ->join('erp_cobros as c', 'c.id', '=', 'ci.cobro_id')
            ->where('ci.factura_id', $factura->id)
            ->where('c.estado', '!=', 'ANULADO')
            ->sum('ci.importe');

        $total = (float) $factura->imp_total;
        $nuevo = match (true) {
            $cobrado <= 0 => self::ESTADO_CONTROLADA, // sin cobros; vuelve a controlada
            $cobrado >= $total - 0.01 => self::ESTADO_COBRADA,
            default => self::ESTADO_COBRO_PARCIAL,
        };

        if ($nuevo !== $factura->estado) {
            $factura->update(['estado' => $nuevo]);
        }

        return $factura->fresh();
    }
}
