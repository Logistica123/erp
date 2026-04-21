<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Periodo extends Model
{
    protected $table = 'erp_periodos';

    protected $fillable = [
        'ejercicio_id', 'anio', 'mes',
        'fecha_inicio', 'fecha_fin', 'estado',
        'fecha_cierre', 'usuario_cierre_id',
        'cierre_iva', 'cierre_iibb',
    ];

    protected $casts = [
        'anio' => 'integer',
        'mes' => 'integer',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'fecha_cierre' => 'datetime',
        'cierre_iva' => 'boolean',
        'cierre_iibb' => 'boolean',
    ];

    public function ejercicio(): BelongsTo
    {
        return $this->belongsTo(Ejercicio::class);
    }

    public function asientos(): HasMany
    {
        return $this->hasMany(Asiento::class);
    }

    public function estaCerrado(): bool
    {
        return $this->estado === 'CERRADO' || $this->estado === 'BLOQUEADO';
    }
}
