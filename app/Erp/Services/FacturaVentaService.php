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
     * Emite una NOTA DE CRÉDITO que cancela (o cancela parcialmente) la factura
     * original. SPEC 03 RN-33: importe ≤ saldo pendiente.
     *
     * Flujo (en modo MANUAL — sin emisión WSFE):
     *  1) Valida estado factura original: EMITIDA | CONTROLADA | COBRO_PARCIAL.
     *  2) Calcula importe: null → total - NCs previas (cancela saldo).
     *  3) Crea FacturaVenta tipo NC (3/8/13 según letra A/B/C), origen MANUAL,
     *     estado PREPARADA (sin CAE todavía — emisión via SPEC 03 §6.6).
     *  4) Registra en erp_factura_venta_asociadas; trigger trg_fva_validar_bi
     *     valida RN-33 a nivel DB.
     *  5) Si la NC cancela el saldo remanente, factura original → ANULADA_POR_NC.
     *
     * El asiento reversa lo genera el endpoint /controlar de la NC vía
     * ContabilizadorFacturas (detecta cbte_signo < 0).
     */
    public function anular(FacturaVenta $factura, string $motivo, ?float $importe, User $usuario): FacturaVenta
    {
        if (in_array($factura->estado, [self::ESTADO_ANULADA_POR_NC, self::ESTADO_RECHAZADA, self::ESTADO_COBRADA], true)) {
            throw new DomainException('FACTURA_NO_ANULABLE: estado '.$factura->estado);
        }
        if (! in_array($factura->estado, [self::ESTADO_EMITIDA, self::ESTADO_CONTROLADA, self::ESTADO_COBRO_PARCIAL], true)) {
            throw new DomainException('FACTURA_ESTADO_INVALIDO: solo se anula desde EMITIDA/CONTROLADA/COBRO_PARCIAL (actual: '.$factura->estado.')');
        }

        // Saldo pendiente = total - sum(NCs previas)
        $ncPrevias = (float) DB::table('erp_factura_venta_asociadas')
            ->where('factura_original_id', $factura->id)
            ->sum('importe_aplicado');
        $saldoPendiente = round((float) $factura->imp_total - $ncPrevias, 2);

        if ($saldoPendiente <= 0.01) {
            throw new DomainException('FACTURA_SIN_SALDO: ya fue cancelada por NCs previas');
        }

        $importe = $importe !== null ? round((float) $importe, 2) : $saldoPendiente;
        if ($importe <= 0) {
            throw new DomainException('IMPORTE_INVALIDO: debe ser > 0');
        }
        if ($importe > $saldoPendiente + 0.01) {
            throw new DomainException(sprintf(
                'RN-33: importe NC ($%.2f) supera saldo pendiente ($%.2f)',
                $importe,
                $saldoPendiente
            ));
        }

        return DB::transaction(function () use ($factura, $motivo, $importe, $saldoPendiente, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $nc = $this->crearNotaCredito($factura, $motivo, $importe, $usuario);
            $tipoVinculo = abs($importe - $saldoPendiente) < 0.01 ? 'CANCELA' : 'PARCIAL';

            DB::table('erp_factura_venta_asociadas')->insert([
                'factura_id' => $nc->id,
                'factura_original_id' => $factura->id,
                'tipo_vinculo' => $tipoVinculo,
                'importe_aplicado' => $importe,
            ]);

            // Si cancela saldo total → factura original ANULADA_POR_NC.
            if ($tipoVinculo === 'CANCELA') {
                $factura->update(['estado' => self::ESTADO_ANULADA_POR_NC]);
            }

            $this->audit->logEvento(
                accion: 'FACTURA_VENTA_ANULADA',
                modulo: 'ventas',
                descripcion: sprintf(
                    'Factura venta #%d anulada con NC #%d · %s · importe $%s · motivo: %s',
                    $factura->id, $nc->id, $tipoVinculo,
                    number_format($importe, 2, ',', '.'),
                    $motivo
                ),
                empresaId: $factura->empresa_id,
            );

            return $nc->fresh();
        });
    }

    /**
     * Crea la FacturaVenta tipo NC. Deriva el código AFIP por la letra de la
     * original: A→3, B→8, C→13, M→21. La NC se crea en estado PREPARADA
     * (sin CAE) cuando modo emisión = MANUAL; la emisión WSFE queda para
     * el endpoint /facturas-venta/{id}/emitir (SPEC 03 §6.6).
     */
    private function crearNotaCredito(FacturaVenta $factura, string $motivo, float $importe, User $usuario): FacturaVenta
    {
        $tipoOriginal = DB::table('erp_tipos_comprobante')->where('id', $factura->tipo_comprobante_id)->first();
        if (! $tipoOriginal) {
            throw new DomainException('TIPO_COMPROBANTE_NO_ENCONTRADO');
        }

        $ncCodigoAfip = match ($tipoOriginal->letra) {
            'A' => 3,
            'B' => 8,
            'C' => 13,
            'M' => 21,
            default => throw new DomainException('LETRA_NO_SOPORTADA: '.$tipoOriginal->letra),
        };
        $ncTipoId = DB::table('erp_tipos_comprobante')
            ->where('codigo_afip', $ncCodigoAfip)
            ->where('clase', 'NOTA_CREDITO')
            ->value('id');
        if (! $ncTipoId) {
            throw new DomainException('TIPO_NC_NO_CONFIGURADO: buscar en seed erp_tipos_comprobante codigo_afip='.$ncCodigoAfip);
        }

        // Proporción iva/neto igual a la factura original para mantener equivalencia.
        $ratio = $factura->imp_total > 0 ? $importe / (float) $factura->imp_total : 0;
        $netoGravado = round((float) $factura->imp_neto_gravado * $ratio, 2);
        $iva = round((float) $factura->imp_iva * $ratio, 2);
        $noGrav = round((float) $factura->imp_no_gravado * $ratio, 2);
        $exento = round((float) $factura->imp_exento * $ratio, 2);
        // Ajuste de redondeo para que sumen exactamente el importe
        $calc = round($netoGravado + $iva + $noGrav + $exento, 2);
        if (abs($calc - $importe) > 0.01) {
            $netoGravado = round($netoGravado + ($importe - $calc), 2);
        }

        $ultimo = DB::table('erp_facturas_venta')
            ->where('empresa_id', $factura->empresa_id)
            ->where('tipo_comprobante_id', $ncTipoId)
            ->where('punto_venta_id', $factura->punto_venta_id)
            ->max('numero') ?? 0;

        $nc = FacturaVenta::create([
            'empresa_id' => $factura->empresa_id,
            'tipo_comprobante_id' => $ncTipoId,
            'punto_venta_id' => $factura->punto_venta_id,
            'numero' => $ultimo + 1,
            'fecha_emision' => now()->toDateString(),
            'auxiliar_id' => $factura->auxiliar_id,
            'condicion_iva_id' => $factura->condicion_iva_id,
            'doc_tipo_afip' => $factura->doc_tipo_afip,
            'doc_nro' => $factura->doc_nro,
            'moneda_id' => $factura->moneda_id,
            'cotizacion' => $factura->cotizacion,
            'concepto_afip' => $factura->concepto_afip,
            'imp_neto_gravado' => $netoGravado,
            'imp_no_gravado' => $noGrav,
            'imp_exento' => $exento,
            'imp_iva' => $iva,
            'imp_tributos' => 0,
            'imp_total' => $importe,
            'origen' => 'MANUAL',
            'estado' => self::ESTADO_PREPARADA,
            'observaciones' => 'NC por anulación de FV #'.$factura->numero.': '.$motivo,
            'created_by_user_id' => $usuario->id,
        ]);

        // Replica el IVA por alícuota desde la factura original, proporcional.
        $ivaOriginales = DB::table('erp_factura_venta_iva')->where('factura_id', $factura->id)->get();
        foreach ($ivaOriginales as $v) {
            DB::table('erp_factura_venta_iva')->insert([
                'factura_id' => $nc->id,
                'alicuota_iva_id' => $v->alicuota_iva_id,
                'base_imponible' => round((float) $v->base_imponible * $ratio, 2),
                'importe_iva' => round((float) $v->importe_iva * $ratio, 2),
            ]);
        }

        // Item único con el motivo (simplificación: no detallamos línea por línea)
        DB::table('erp_factura_venta_items')->insert([
            'factura_id' => $nc->id,
            'nro_linea' => 1,
            'descripcion' => 'Anulación FV #'.$factura->numero.': '.$motivo,
            'cantidad' => 1,
            'precio_unitario' => $importe,
            'alicuota_iva_id' => DB::table('erp_factura_venta_iva')->where('factura_id', $factura->id)->value('alicuota_iva_id')
                ?? DB::table('erp_alicuotas_iva')->where('codigo_interno', '21')->value('id'),
            'imp_neto' => round($netoGravado + $noGrav + $exento, 2),
            'imp_iva' => $iva,
        ]);

        return $nc;
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
