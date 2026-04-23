<?php

namespace App\Erp\Models\VentasCompras;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturaVentaCae extends Model
{
    protected $table = 'erp_factura_venta_cae';

    public const UPDATED_AT = null;

    protected $fillable = [
        'factura_venta_id', 'cae', 'fecha_vto_cae', 'resultado',
        'observaciones_afip', 'errores_afip', 'arca_request_id',
        'idempotency_key', 'reintentos', 'emitida_at',
    ];

    protected $casts = [
        'fecha_vto_cae' => 'date',
        'emitida_at' => 'datetime',
        'observaciones_afip' => 'array',
        'errores_afip' => 'array',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_venta_id');
    }
}
