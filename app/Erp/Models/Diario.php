<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Diario extends Model
{
    protected $table = 'erp_diarios';
    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'codigo', 'nombre', 'descripcion',
        'tipo', 'numerador_actual', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function asientos(): HasMany
    {
        return $this->hasMany(Asiento::class);
    }
}
