<?php

namespace App\Erp\Models\VentasCompras;

use App\Erp\Models\CentroCosto;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Fiscal\AlicuotaIva;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturaVentaItem extends Model
{
    protected $table = 'erp_factura_venta_items';
    public $timestamps = false;

    protected $fillable = [
        'factura_id', 'nro_linea', 'concepto', 'codigo_articulo',
        'cantidad', 'unidad_medida', 'precio_unitario', 'descuento_pct',
        'alicuota_iva_id', 'imp_neto', 'imp_iva',
        'cuenta_contable_id', 'centro_costo_id',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'precio_unitario' => 'decimal:4',
        'descuento_pct' => 'decimal:2',
        'imp_neto' => 'decimal:2',
        'imp_iva' => 'decimal:2',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_id');
    }

    public function alicuotaIva(): BelongsTo
    {
        return $this->belongsTo(AlicuotaIva::class);
    }

    public function cuentaContable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class);
    }

    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class);
    }
}
