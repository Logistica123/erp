<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Model
{
    protected $table = 'erp_empresas';

    protected $fillable = [
        'razon_social', 'nombre_fantasia', 'cuit',
        'condicion_iva', 'domicilio_fiscal',
        'iibb_nro', 'iibb_regimen', 'iibb_jurisdiccion_sede',
        'fecha_inicio_actividades', 'logo_path',
        'moneda_base', 'aplica_rt6', 'activo',
    ];

    protected $casts = [
        'fecha_inicio_actividades' => 'date',
        'aplica_rt6' => 'boolean',
        'activo' => 'boolean',
    ];

    public function ejercicios(): HasMany
    {
        return $this->hasMany(Ejercicio::class);
    }

    public function diarios(): HasMany
    {
        return $this->hasMany(Diario::class);
    }

    public function cuentasContables(): HasMany
    {
        return $this->hasMany(CuentaContable::class);
    }

    public function centrosCosto(): HasMany
    {
        return $this->hasMany(CentroCosto::class);
    }

    public function auxiliares(): HasMany
    {
        return $this->hasMany(Auxiliar::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Rol::class);
    }

    public function asientos(): HasMany
    {
        return $this->hasMany(Asiento::class);
    }
}
