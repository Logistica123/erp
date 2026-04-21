<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentroCosto extends Model
{
    protected $table = 'erp_centros_costo';

    protected $fillable = [
        'empresa_id', 'codigo', 'nombre', 'tipo',
        'padre_id', 'ref_externa', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(CentroCosto::class, 'padre_id');
    }
}
