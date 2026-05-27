<?php

namespace App\Erp\Models\VentasCompras;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v1.51 — Reparto de base imponible IIBB por jurisdicción de una factura de
 * venta. La suma de `base_imponible` de todas las filas de una factura debe
 * igualar su neto gravado (lo valida el controlador).
 */
class FacturaVentaJurisdiccion extends Model
{
    protected $table = 'erp_factura_venta_jurisdicciones';

    protected $fillable = [
        'factura_venta_id', 'jurisdiccion_codigo', 'base_imponible',
    ];

    protected $casts = [
        'base_imponible' => 'decimal:2',
    ];

    public function facturaVenta(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_venta_id');
    }
}
