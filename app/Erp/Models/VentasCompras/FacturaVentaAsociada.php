<?php

namespace App\Erp\Models\VentasCompras;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturaVentaAsociada extends Model
{
    protected $table = 'erp_factura_venta_asociadas';
    public $timestamps = false;

    protected $fillable = [
        'factura_id', 'factura_original_id', 'tipo_vinculo', 'importe_aplicado',
    ];

    protected $casts = ['importe_aplicado' => 'decimal:2'];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_id');
    }

    public function facturaOriginal(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_original_id');
    }
}
