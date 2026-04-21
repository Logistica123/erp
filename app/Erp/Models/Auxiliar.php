<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Auxiliar extends Model
{
    protected $table = 'erp_auxiliares';

    protected $fillable = [
        'empresa_id', 'tipo', 'tabla_ref', 'id_ref',
        'codigo', 'nombre', 'cuit', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoAsiento::class, 'auxiliar_id');
    }
}
