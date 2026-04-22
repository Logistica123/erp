<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facturas / NDs / señas que cancela un cobro.
 */
class CobroItem extends Model
{
    protected $table = 'erp_cobro_items';
    public $timestamps = false;

    public const TIPO_FACTURA_VENTA = 'FACTURA_VENTA';
    public const TIPO_NOTA_DEBITO = 'NOTA_DEBITO';
    public const TIPO_SEÑA = 'SEÑA';
    public const TIPO_OTRO = 'OTRO';

    protected $fillable = [
        'cobro_id', 'tipo_item', 'factura_id',
        'cuenta_contable_id', 'concepto', 'importe',
    ];

    protected $casts = [
        'importe' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    public function cuentaContable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class);
    }
}
