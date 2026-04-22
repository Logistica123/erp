<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detalle de qué paga una OP: factura de compra, adelanto, reintegro, etc.
 */
class OpItem extends Model
{
    protected $table = 'erp_op_items';

    public const TIPO_FACTURA_COMPRA = 'FACTURA_COMPRA';
    public const TIPO_ADELANTO = 'ADELANTO';
    public const TIPO_REINTEGRO = 'REINTEGRO';
    public const TIPO_RETENCION = 'RETENCION';
    public const TIPO_OTRO = 'OTRO';

    protected $fillable = [
        'op_id', 'orden', 'tipo_item', 'comprobante_id',
        'cuenta_contable_id', 'concepto', 'importe',
    ];

    protected $casts = [
        'importe' => 'decimal:2',
        'orden' => 'integer',
    ];

    public function ordenPago(): BelongsTo
    {
        return $this->belongsTo(OrdenPago::class, 'op_id');
    }

    public function cuentaContable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class);
    }
}
