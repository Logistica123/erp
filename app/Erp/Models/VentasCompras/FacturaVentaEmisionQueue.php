<?php

namespace App\Erp\Models\VentasCompras;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturaVentaEmisionQueue extends Model
{
    protected $table = 'erp_factura_venta_emision_queue';

    protected $fillable = [
        'factura_venta_id', 'idempotency_key', 'estado',
        'intento_actual', 'max_intentos', 'proximo_intento_at',
        'ultimo_error', 'locked_by_worker', 'locked_at', 'force_retry',
    ];

    protected $casts = [
        'proximo_intento_at' => 'datetime',
        'locked_at' => 'datetime',
        'force_retry' => 'boolean',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_venta_id');
    }
}
