<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoBancario extends Model
{
    protected $table = 'erp_movimientos_bancarios';

    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_ETIQUETADO = 'ETIQUETADO';
    public const ESTADO_CONCILIADO = 'CONCILIADO';
    public const ESTADO_IGNORADO = 'IGNORADO';
    // v1.45 — estados de imputación automática con extractor CUIT.
    public const ESTADO_MATCH_AUTO = 'MATCH_AUTO';
    public const ESTADO_CONFIRMADO = 'CONFIRMADO';
    public const ESTADO_REVERTIDO = 'REVERTIDO';
    public const ESTADO_CONCILIADO_MANUAL = 'CONCILIADO_MANUAL';

    protected $fillable = [
        'extracto_id', 'cuenta_bancaria_id',
        'fecha', 'fecha_valor', 'concepto', 'comprobante_banco',
        'debito', 'credito', 'saldo',
        'estado', 'etiqueta_sugerida',
        'cuenta_contable_propuesta_id', 'asiento_id',
        'motivo_ignorado_id', 'observacion', 'hash_linea',
        'cuit_contraparte', 'nombre_contraparte', 'persona_id', 'cliente_id',
        'cuenta_propia_id', 'referencia_externa', 'regla_aplicada_id',
        'confianza_match', 'dia_contable_id',
        // v1.27 Sprint A
        'tipo_operativo', 'monto_conciliado',
        // v1.45 — imputación automática.
        'factura_imputada_id', 'factura_imputada_tipo', 'imputacion_confianza',
        'cuit_extractado', 'auxiliar_resuelto_id',
        // v1.48 — diferencias + pendientes de facturar + transferencias internas.
        'motivo_diferencia_id', 'pendiente_factura_complementaria', 'distribuidor_pendiente_id',
        'monto_pendiente_facturar', 'nc_complementaria_id', 'observaciones_pendiente',
        'es_transferencia_interna', 'mov_espejo_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_valor' => 'date',
        'debito' => 'decimal:2',
        'credito' => 'decimal:2',
        'saldo' => 'decimal:2',
        'confianza_match' => 'integer',
    ];

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class);
    }

    public function extracto(): BelongsTo
    {
        return $this->belongsTo(ExtractoBancario::class, 'extracto_id');
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    public function cuentaContablePropuesta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_propuesta_id');
    }

    public function cuentaPropia(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_propia_id');
    }

    public function reglaAplicada(): BelongsTo
    {
        return $this->belongsTo(ConciliacionRegla::class, 'regla_aplicada_id');
    }

    /** Importe firmado: positivo crédito, negativo débito. */
    public function importeFirmado(): float
    {
        return (float) $this->credito - (float) $this->debito;
    }

    public function esCredito(): bool
    {
        return (float) $this->credito > 0;
    }

    public function esDebito(): bool
    {
        return (float) $this->debito > 0;
    }
}
