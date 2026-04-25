<?php

namespace App\Erp\Models\Sueldos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empleado extends Model
{
    protected $table = 'erp_emp_empleados';

    protected $fillable = [
        'legajo', 'cuil', 'cuit', 'apellido', 'nombre', 'dni',
        'fecha_nacimiento', 'fecha_ingreso', 'fecha_egreso',
        'categoria_id', 'convenio_id', 'regimen', 'jornada_formal_pct',
        'es_vendedor', 'paga_sac',
        'cbu', 'banco', 'alias_cbu',
        'email', 'telefono', 'domicilio',
        'activo', 'observaciones',
    ];

    protected $casts = [
        'fecha_nacimiento'   => 'date',
        'fecha_ingreso'      => 'date',
        'fecha_egreso'       => 'date',
        'jornada_formal_pct' => 'float',
        'es_vendedor'        => 'boolean',
        'paga_sac'           => 'boolean',
        'activo'             => 'boolean',
    ];

    public const REGIMEN_FORMAL_PURO    = 'FORMAL_PURO';
    public const REGIMEN_MIXTO          = 'MIXTO';
    public const REGIMEN_EFECTIVO_PURO  = 'EFECTIVO_PURO';
    public const REGIMEN_MONOTRIBUTISTA = 'MONOTRIBUTISTA';

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class, 'convenio_id');
    }

    public function basicos(): HasMany
    {
        return $this->hasMany(BasicoHistorial::class, 'empleado_id')
            ->orderByDesc('vigencia_desde');
    }

    public function basicoVigente(): ?BasicoHistorial
    {
        return BasicoHistorial::where('empleado_id', $this->id)
            ->whereNull('vigencia_hasta')
            ->orderByDesc('vigencia_desde')
            ->first();
    }

    public function composiciones(): HasMany
    {
        return $this->hasMany(ComposicionSueldo::class, 'empleado_id')
            ->orderByDesc('vigencia_desde');
    }

    public function composicionVigente(): ?ComposicionSueldo
    {
        return ComposicionSueldo::where('empleado_id', $this->id)
            ->whereNull('vigencia_hasta')
            ->orderByDesc('vigencia_desde')
            ->first();
    }

    public function comisiones(): HasMany
    {
        return $this->hasMany(ComisionEsquema::class, 'empleado_id')
            ->orderByDesc('vigencia_desde');
    }

    public function ccs(): HasMany
    {
        return $this->hasMany(CC::class, 'empleado_id');
    }
}
