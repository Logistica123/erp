<?php

namespace App\Erp\Services;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Services\Integracion\ContabilizadorFacturas;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Flujo de estados de factura de compra (SPEC 03 §4.7):
 *   RECIBIDA → CONTROLADA → PAGO_PARCIAL → PAGADA
 *            ↘ OBSERVADA (con motivo, puede volver a RECIBIDA al resolver)
 *            ↘ RECHAZADA (terminal)
 *            ↘ ANULADA_POR_NC
 *
 * controlar(): RN-31 — el tilde que habilita el pago. Dispara asiento
 *   contable automático (DEBE Gasto + IVA CF + Ret sufridas / HABER Proveedor)
 *   vía ContabilizadorFacturas (RN-34).
 */
class FacturaCompraService
{
    public const ESTADO_RECIBIDA = 'RECIBIDA';
    public const ESTADO_CONTROLADA = 'CONTROLADA';
    public const ESTADO_OBSERVADA = 'OBSERVADA';
    public const ESTADO_PAGO_PARCIAL = 'PAGO_PARCIAL';
    public const ESTADO_PAGADA = 'PAGADA';
    public const ESTADO_ANULADA_POR_NC = 'ANULADA_POR_NC';
    public const ESTADO_RECHAZADA = 'RECHAZADA';

    public function __construct(
        private readonly ContabilizadorFacturas $contabilizador,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * RECIBIDA | OBSERVADA → CONTROLADA. Genera asiento automático RN-34.
     * Habilita el pago vía OP (RN-31 gate en OrdenPagoService).
     */
    public function controlar(FacturaCompra $factura, User $usuario): FacturaCompra
    {
        if ($factura->estado === self::ESTADO_CONTROLADA) {
            throw new DomainException('FACTURA_YA_CONTROLADA');
        }
        if (! in_array($factura->estado, [self::ESTADO_RECIBIDA, self::ESTADO_OBSERVADA], true)) {
            throw new DomainException('FACTURA_ESTADO_INVALIDO: solo se controla desde RECIBIDA u OBSERVADA (actual: '.$factura->estado.')');
        }

        return DB::transaction(function () use ($factura, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $asiento = $this->contabilizador->contabilizarCompra($factura->id, $factura->empresa_id, $usuario->id);

            $factura->update([
                'estado' => self::ESTADO_CONTROLADA,
                'asiento_id' => $asiento->id,
                'controlada_by_user_id' => $usuario->id,
                'controlada_at' => now(),
            ]);

            $this->audit->logEvento(
                accion: 'FACTURA_COMPRA_CONTROLADA',
                modulo: 'compras',
                descripcion: sprintf(
                    'Factura compra #%d (tipo=%d nro=%d) controlada por %s · asiento %d',
                    $factura->id, $factura->tipo_comprobante_id, $factura->numero, $usuario->name, $asiento->id
                ),
                empresaId: $factura->empresa_id,
            );

            return $factura->fresh();
        });
    }

    /**
     * Marca como OBSERVADA. Puede volver a RECIBIDA al resolver la observación.
     */
    public function observar(FacturaCompra $factura, string $motivo, User $usuario): FacturaCompra
    {
        if (! in_array($factura->estado, [self::ESTADO_RECIBIDA, self::ESTADO_OBSERVADA], true)) {
            throw new DomainException('FACTURA_ESTADO_INVALIDO: solo se observa desde RECIBIDA u OBSERVADA (actual: '.$factura->estado.')');
        }

        return DB::transaction(function () use ($factura, $motivo, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $factura->update([
                'estado' => self::ESTADO_OBSERVADA,
                'motivo_observacion' => $motivo,
            ]);

            $this->audit->logEvento(
                accion: 'FACTURA_COMPRA_OBSERVADA',
                modulo: 'compras',
                descripcion: sprintf('Factura compra #%d observada: %s', $factura->id, $motivo),
                empresaId: $factura->empresa_id,
            );

            return $factura->fresh();
        });
    }

    /**
     * Rechaza la factura (no corresponde, FCE errónea, etc.). Terminal.
     */
    public function rechazar(FacturaCompra $factura, string $motivo, User $usuario): FacturaCompra
    {
        if (in_array($factura->estado, [self::ESTADO_PAGADA, self::ESTADO_PAGO_PARCIAL], true)) {
            throw new DomainException('FACTURA_CON_PAGOS: contraasentar antes de rechazar');
        }
        if ($factura->estado === self::ESTADO_RECHAZADA) {
            throw new DomainException('FACTURA_YA_RECHAZADA');
        }

        return DB::transaction(function () use ($factura, $motivo, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $factura->update([
                'estado' => self::ESTADO_RECHAZADA,
                'motivo_observacion' => $motivo,
            ]);

            $this->audit->logEvento(
                accion: 'FACTURA_COMPRA_RECHAZADA',
                modulo: 'compras',
                descripcion: sprintf('Factura compra #%d rechazada: %s', $factura->id, $motivo),
                empresaId: $factura->empresa_id,
            );

            return $factura->fresh();
        });
    }

    /**
     * Recalcula estado basado en OPs asociadas.
     * Invocable desde OrdenPagoService al registrar/anular pagos.
     */
    public function reescalarPorPagos(FacturaCompra $factura): FacturaCompra
    {
        if (in_array($factura->estado, [self::ESTADO_RECHAZADA, self::ESTADO_ANULADA_POR_NC], true)) {
            return $factura;
        }

        $pagado = (float) DB::table('erp_op_items as oi')
            ->join('erp_ordenes_pago as op', 'op.id', '=', 'oi.op_id')
            ->where('oi.comprobante_id', $factura->id)
            ->where('op.estado', 'PAGADA')
            ->sum('oi.importe');

        $total = (float) $factura->imp_total;
        $nuevo = match (true) {
            $pagado <= 0 => self::ESTADO_CONTROLADA,
            $pagado >= $total - 0.01 => self::ESTADO_PAGADA,
            default => self::ESTADO_PAGO_PARCIAL,
        };

        if ($nuevo !== $factura->estado) {
            $factura->update(['estado' => $nuevo]);
        }

        return $factura->fresh();
    }
}
