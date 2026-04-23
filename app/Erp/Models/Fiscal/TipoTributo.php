<?php

namespace App\Erp\Models\Fiscal;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TipoTributo extends Model
{
    protected $table = 'erp_tipos_tributo';
    public $timestamps = false;

    protected $fillable = [
        'codigo_afip', 'codigo_interno', 'nombre', 'jurisdiccion',
        'es_retencion', 'cuenta_contable_id', 'activo',
    ];

    protected $casts = [
        'es_retencion' => 'boolean',
        'activo' => 'boolean',
    ];

    public function cuentaContable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class);
    }
}
