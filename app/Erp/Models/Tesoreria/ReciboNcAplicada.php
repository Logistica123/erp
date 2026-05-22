<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\VentasCompras\FacturaVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v1.31 — NC aplicada dentro de un recibo. Cada fila representa la imputación
 * de una NC del cliente a la factura cobrada por el recibo.
 */
class ReciboNcAplicada extends Model
{
    protected $table = 'erp_recibos_nc_aplicadas';
    public $timestamps = false;

    protected $fillable = [
        'recibo_id', 'nc_factura_id', 'monto_aplicado', 'automatica', 'created_at',
    ];

    protected $casts = [
        'monto_aplicado' => 'decimal:2',
        'automatica' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function recibo(): BelongsTo
    {
        return $this->belongsTo(Recibo::class);
    }

    public function nc(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'nc_factura_id');
    }
}
