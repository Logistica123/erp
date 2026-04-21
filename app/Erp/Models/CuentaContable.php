<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuentaContable extends Model
{
    protected $table = 'erp_cuentas_contables';

    protected $fillable = [
        'empresa_id', 'codigo', 'codigo_padre_id', 'nivel', 'nombre',
        'tipo', 'rubro_ec', 'imputable', 'moneda',
        'admite_cc', 'admite_auxiliar', 'tipo_auxiliar',
        'etiqueta_cierre', 'saldo_normal', 'regularizadora',
        'notas', 'activo',
    ];

    protected $casts = [
        'nivel' => 'integer',
        'imputable' => 'boolean',
        'admite_cc' => 'boolean',
        'admite_auxiliar' => 'boolean',
        'regularizadora' => 'boolean',
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'codigo_padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(CuentaContable::class, 'codigo_padre_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoAsiento::class, 'cuenta_id');
    }

    public function saldos(): HasMany
    {
        return $this->hasMany(SaldoCuenta::class, 'cuenta_id');
    }

    public function mapeos(): HasMany
    {
        return $this->hasMany(MapeoEtiquetaCuenta::class, 'cuenta_id');
    }
}
