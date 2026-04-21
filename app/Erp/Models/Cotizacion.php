<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cotizacion extends Model
{
    protected $table = 'erp_cotizaciones';

    protected $fillable = [
        'empresa_id', 'moneda_id', 'fecha', 'tipo',
        'valor_compra', 'valor_venta', 'valor_referencia',
        'fuente', 'notas',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valor_compra' => 'decimal:4',
        'valor_venta' => 'decimal:4',
        'valor_referencia' => 'decimal:4',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }
}
