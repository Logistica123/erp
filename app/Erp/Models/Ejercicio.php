<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ejercicio extends Model
{
    protected $table = 'erp_ejercicios';

    protected $fillable = [
        'empresa_id', 'numero', 'nombre',
        'fecha_inicio', 'fecha_cierre', 'estado',
        'fecha_cierre_real', 'usuario_cierre_id',
        'ajusta_por_inflacion', 'indice_cierre',  // SPEC 05 H1 ALTERs
    ];

    protected $casts = [
        'numero' => 'integer',
        'fecha_inicio' => 'date',
        'fecha_cierre' => 'date',
        'fecha_cierre_real' => 'datetime',
        'ajusta_por_inflacion' => 'bool',
        'indice_cierre' => 'float',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function periodos(): HasMany
    {
        return $this->hasMany(Periodo::class);
    }
}
