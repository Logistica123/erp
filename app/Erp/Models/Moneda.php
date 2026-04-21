<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Moneda extends Model
{
    protected $table = 'erp_monedas';
    public $timestamps = false;

    protected $fillable = [
        'codigo', 'nombre', 'simbolo', 'decimales', 'es_base', 'activa',
    ];

    protected $casts = [
        'decimales' => 'integer',
        'es_base' => 'boolean',
        'activa' => 'boolean',
    ];

    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class);
    }
}
