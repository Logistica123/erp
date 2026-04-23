<?php

namespace App\Erp\Models\Arca;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\FacturaVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibroIvaDetalle extends Model
{
    protected $table = 'erp_libro_iva_detalle';
    public $timestamps = false;

    protected $fillable = [
        'importacion_id', 'nro_fila',
        'fecha_cbte', 'tipo_cbte_afip', 'punto_venta', 'numero_desde', 'numero_hasta',
        'cuit_contraparte', 'razon_social',
        'imp_neto_gravado', 'imp_no_gravado', 'imp_exento', 'imp_iva', 'imp_total',
        'cae', 'raw_row_json',
        'estado_matching', 'factura_venta_id', 'factura_compra_id', 'conflicto_detalle',
    ];

    protected $casts = [
        'fecha_cbte' => 'date',
        'imp_neto_gravado' => 'decimal:2',
        'imp_no_gravado' => 'decimal:2',
        'imp_exento' => 'decimal:2',
        'imp_iva' => 'decimal:2',
        'imp_total' => 'decimal:2',
        'raw_row_json' => 'array',
    ];

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(LibroIvaImportacion::class, 'importacion_id');
    }

    public function facturaVenta(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class);
    }

    public function facturaCompra(): BelongsTo
    {
        return $this->belongsTo(FacturaCompra::class);
    }
}
