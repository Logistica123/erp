<?php

namespace App\Erp\Models\Arca;

use App\Erp\Models\Fiscal\CondicionIva;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PadronCache extends Model
{
    protected $table = 'erp_padron_cache';
    public $timestamps = false;
    public $incrementing = false;

    protected $primaryKey = 'cuit';
    protected $keyType = 'string';

    protected $fillable = [
        'cuit', 'alcance', 'razon_social', 'condicion_iva_afip', 'condicion_iva_id',
        'estado_cuit', 'domicilio_fiscal', 'actividades', 'impuestos',
        'datos_raw', 'consultado_at', 'ttl_dias',
    ];

    protected $casts = [
        'domicilio_fiscal' => 'array',
        'actividades' => 'array',
        'impuestos' => 'array',
        'datos_raw' => 'array',
        'consultado_at' => 'datetime',
    ];

    public function condicionIva(): BelongsTo
    {
        return $this->belongsTo(CondicionIva::class);
    }
}
