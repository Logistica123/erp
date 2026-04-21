<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsientoPlantilla extends Model
{
    protected $table = 'erp_asientos_plantilla';

    protected $fillable = [
        'empresa_id', 'codigo', 'nombre', 'descripcion',
        'diario_id', 'json_definicion', 'activo',
    ];

    protected $casts = [
        'json_definicion' => 'array',
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function diario(): BelongsTo
    {
        return $this->belongsTo(Diario::class);
    }
}
