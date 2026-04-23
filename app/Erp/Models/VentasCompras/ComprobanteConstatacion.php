<?php

namespace App\Erp\Models\VentasCompras;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComprobanteConstatacion extends Model
{
    protected $table = 'erp_comprobante_constatacion';
    public $timestamps = false;

    protected $fillable = [
        'factura_compra_id', 'resultado', 'fecha_consulta',
        'datos_afip', 'arca_request_id', 'consultada_by_user_id',
    ];

    protected $casts = [
        'fecha_consulta' => 'datetime',
        'datos_afip' => 'array',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaCompra::class, 'factura_compra_id');
    }
}
