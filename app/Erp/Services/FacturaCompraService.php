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
     * ADDENDUM v1.9 — Calcula los campos de imputación para una factura de
     * compra a partir de `fecha_emision` y `fecha_imputacion` (opcional).
     *
     * Reglas (RN-FI-1, RN-FI-2):
     *   - `fecha_imputacion` default = `fecha_emision` si no se pasa.
     *   - `fecha_imputacion >= fecha_emision` (CHECK constraint + validación).
     *   - El período fiscal se busca por la `fecha_imputacion`.
     *   - Si el período está CERRADO/BLOQUEADO, requiere permiso
     *     `compras.imputar_periodo_cerrado`.
     *
     * @return array{fecha_imputacion:string, periodo_id:?int, imputacion_diferida:int}
     * @throws DomainException FECHA_IMPUTACION_INVALIDA | PERIODO_CERRADO_SIN_PERMISO
     */
    public function resolverImputacion(
        string $fechaEmision,
        ?string $fechaImputacion,
        User $usuario,
        int $empresaId = 1,
    ): array {
        $fechaImp = $fechaImputacion ?: $fechaEmision;

        if ($fechaImp < $fechaEmision) {
            throw new DomainException(
                'FECHA_IMPUTACION_INVALIDA: la imputación no puede ser anterior a la fecha del comprobante.'
            );
        }

        $periodo = DB::table('erp_periodos as p')
            ->join('erp_ejercicios as e', 'e.id', '=', 'p.ejercicio_id')
            ->where('e.empresa_id', $empresaId)
            ->whereDate('p.fecha_inicio', '<=', $fechaImp)
            ->whereDate('p.fecha_fin', '>=', $fechaImp)
            ->select('p.id', 'p.estado', 'p.anio', 'p.mes')
            ->first();

        if ($periodo && in_array($periodo->estado, ['CERRADO', 'BLOQUEADO'], true)) {
            $mes = sprintf('%02d/%d', $periodo->mes, $periodo->anio);
            $tienePermiso = DB::table('erp_rol_permiso as rp')
                ->join('erp_permisos as p', 'p.id', '=', 'rp.permiso_id')
                ->join('erp_usuario_rol as ur', 'ur.rol_id', '=', 'rp.rol_id')
                ->join('erp_usuario_perfil as up', 'up.id', '=', 'ur.usuario_perfil_id')
                ->where('up.user_id', $usuario->id)
                ->where('p.codigo', 'compras.imputar_periodo_cerrado')
                ->exists();

            if (! $tienePermiso) {
                throw new DomainException(
                    "PERIODO_CERRADO_SIN_PERMISO: el período {$mes} está cerrado. "
                    .'Para imputar facturas a períodos cerrados se requiere el permiso '
                    .'compras.imputar_periodo_cerrado. Elegí una fecha en un período abierto '
                    .'o solicitá autorización a un usuario con dicho permiso.'
                );
            }

            $this->audit->logEvento(
                accion: 'IMPUTAR_PERIODO_CERRADO',
                modulo: 'compras',
                descripcion: sprintf(
                    'Factura compra imputada a período cerrado %s por %s',
                    $mes, $usuario->name
                ),
                empresaId: $empresaId,
            );
        }

        return [
            'fecha_imputacion' => $fechaImp,
            'periodo_id' => $periodo?->id,
            'imputacion_diferida' => $fechaImp > $fechaEmision ? 1 : 0,
        ];
    }

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
     * Registra una NOTA DE CRÉDITO recibida del proveedor, asociada a una
     * factura de compra original (SPEC 03 RN-33).
     *
     * A diferencia de las NCs de venta (que el ERP emite), las NCs de compra
     * vienen del proveedor con número + CAE propio — el ERP solo las REGISTRA.
     *
     * @param  array{
     *   factura_original_id:int,
     *   tipo_comprobante_id:int,  // tipo NC compra (3=NC A, 8=NC B, 13=NC C)
     *   punto_venta:int, numero:int, cuit_emisor:string,
     *   fecha_emision:string, fecha_recepcion?:?string,
     *   cae?:?string, fecha_vto_cae?:?string,
     *   imp_neto_gravado:float, imp_no_gravado?:float, imp_exento?:float,
     *   imp_iva?:float, imp_tributos?:float, imp_total:float,
     *   observaciones?:?string, motivo?:?string,
     * }  $data
     */
    public function registrarNc(array $data, User $usuario): FacturaCompra
    {
        $original = FacturaCompra::findOrFail($data['factura_original_id']);

        if (in_array($original->estado, [self::ESTADO_ANULADA_POR_NC, self::ESTADO_RECHAZADA], true)) {
            throw new DomainException('FACTURA_NO_ANULABLE: original está '.$original->estado);
        }

        $ncPrevias = (float) DB::table('erp_factura_compra_asociadas')
            ->where('factura_original_id', $original->id)
            ->sum('importe_aplicado');
        $saldoPendiente = round((float) $original->imp_total - $ncPrevias, 2);
        $importe = round((float) $data['imp_total'], 2);

        if ($saldoPendiente <= 0.01) {
            throw new DomainException('FACTURA_SIN_SALDO: ya fue cancelada por NCs previas');
        }
        if ($importe > $saldoPendiente + 0.01) {
            throw new DomainException(sprintf(
                'RN-33: importe NC ($%.2f) supera saldo pendiente ($%.2f)',
                $importe,
                $saldoPendiente
            ));
        }

        return DB::transaction(function () use ($data, $original, $importe, $saldoPendiente, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $nc = FacturaCompra::create([
                'empresa_id' => $original->empresa_id,
                'tipo_comprobante_id' => $data['tipo_comprobante_id'],
                'punto_venta' => $data['punto_venta'],
                'numero' => $data['numero'],
                'cae' => $data['cae'] ?? null,
                'fecha_vto_cae' => $data['fecha_vto_cae'] ?? null,
                'fecha_emision' => $data['fecha_emision'],
                'fecha_recepcion' => $data['fecha_recepcion'] ?? now()->toDateString(),
                'auxiliar_id' => $original->auxiliar_id,
                'cuit_emisor' => $data['cuit_emisor'],
                'razon_social_emisor' => $original->razon_social_emisor,
                'condicion_iva_id' => $original->condicion_iva_id,
                'moneda_id' => $original->moneda_id,
                'cotizacion' => $original->cotizacion,
                'imp_neto_gravado' => $data['imp_neto_gravado'],
                'imp_no_gravado' => $data['imp_no_gravado'] ?? 0,
                'imp_exento' => $data['imp_exento'] ?? 0,
                'imp_iva' => $data['imp_iva'] ?? 0,
                'imp_tributos' => $data['imp_tributos'] ?? 0,
                'imp_percepciones' => 0,
                'imp_retenciones' => 0,
                'imp_total' => $importe,
                'origen' => 'MANUAL',
                'estado' => self::ESTADO_RECIBIDA,
                'constatacion_estado' => $data['cae'] ? 'PENDIENTE' : 'NO_APLICA',
                'observaciones' => 'NC de FC #'.$original->numero.(! empty($data['motivo']) ? ': '.$data['motivo'] : ''),
                'centro_costo_id' => $original->centro_costo_id,
                'created_by_user_id' => $usuario->id,
            ]);

            $tipoVinculo = abs($importe - $saldoPendiente) < 0.01 ? 'CANCELA' : 'PARCIAL';

            DB::table('erp_factura_compra_asociadas')->insert([
                'factura_id' => $nc->id,
                'factura_original_id' => $original->id,
                'tipo_vinculo' => $tipoVinculo,
                'importe_aplicado' => $importe,
            ]);

            if ($tipoVinculo === 'CANCELA') {
                $original->update(['estado' => self::ESTADO_ANULADA_POR_NC]);
            }

            $this->audit->logEvento(
                accion: 'FACTURA_COMPRA_NC_REGISTRADA',
                modulo: 'compras',
                descripcion: sprintf(
                    'NC recibida #%d vinculada a FC #%d · %s · importe $%s',
                    $nc->id, $original->id, $tipoVinculo,
                    number_format($importe, 2, ',', '.')
                ),
                empresaId: $original->empresa_id,
            );

            return $nc->fresh();
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
