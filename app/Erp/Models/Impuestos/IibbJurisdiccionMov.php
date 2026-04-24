<?php

namespace App\Erp\Models\Impuestos;

use App\Erp\Models\Empresa;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\FacturaVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IibbJurisdiccionMov extends Model
{
    protected $table = 'erp_iibb_jurisdiccion_mov';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'fecha', 'jurisdiccion', 'tipo',
        'importe', 'origen',
        'factura_venta_id', 'factura_compra_id', 'descripcion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'created_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function facturaVenta(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_venta_id');
    }

    public function facturaCompra(): BelongsTo
    {
        return $this->belongsTo(FacturaCompra::class, 'factura_compra_id');
    }
}
