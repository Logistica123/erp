<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\VentasCompras\FacturaVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v1.32 — Comprobante imputado dentro de un recibo. Un recibo puede tener N
 * facturas imputadas. El snapshot (total_factura, fecha_factura, numero_factura)
 * se congela al momento de crear el recibo para que el PDF histórico se
 * mantenga consistente.
 */
class ReciboComprobanteImputado extends Model
{
    protected $table = 'erp_recibos_comprobantes_imputados';
    public $timestamps = false;

    protected $fillable = [
        'recibo_id', 'factura_venta_id', 'monto_imputado',
        'total_factura', 'fecha_factura', 'numero_factura_snapshot', 'created_at',
    ];

    protected $casts = [
        'monto_imputado' => 'decimal:2',
        'total_factura' => 'decimal:2',
        'fecha_factura' => 'date',
        'created_at' => 'datetime',
    ];

    public function recibo(): BelongsTo
    {
        return $this->belongsTo(Recibo::class);
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_venta_id');
    }
}
