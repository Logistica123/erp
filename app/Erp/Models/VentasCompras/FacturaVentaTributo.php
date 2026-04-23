<?php

namespace App\Erp\Models\VentasCompras;

use App\Erp\Models\Fiscal\TipoTributo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturaVentaTributo extends Model
{
    protected $table = 'erp_factura_venta_tributos';
    public $timestamps = false;

    protected $fillable = [
        'factura_id', 'tributo_id', 'base_imponible', 'alicuota', 'importe', 'descripcion',
    ];

    protected $casts = [
        'base_imponible' => 'decimal:2',
        'alicuota' => 'decimal:4',
        'importe' => 'decimal:2',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_id');
    }

    public function tributo(): BelongsTo
    {
        return $this->belongsTo(TipoTributo::class, 'tributo_id');
    }
}
