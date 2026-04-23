<?php

namespace App\Erp\Models\Impuestos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PercepcionSufrida extends Model
{
    protected $table = 'erp_percepciones_sufridas';

    public $timestamps = false;

    protected $fillable = [
        'factura_compra_id', 'tipo', 'regimen',
        'base', 'alicuota', 'importe', 'periodo_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoFiscal::class, 'periodo_id');
    }
}
