<?php

namespace App\Erp\Models\VentasCompras;

use App\Erp\Models\Fiscal\AlicuotaIva;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturaVentaIva extends Model
{
    protected $table = 'erp_factura_venta_iva';
    public $timestamps = false;

    protected $fillable = ['factura_id', 'alicuota_iva_id', 'base_imponible', 'importe_iva'];

    protected $casts = [
        'base_imponible' => 'decimal:2',
        'importe_iva' => 'decimal:2',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_id');
    }

    public function alicuotaIva(): BelongsTo
    {
        return $this->belongsTo(AlicuotaIva::class);
    }
}
