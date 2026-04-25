<?php

namespace App\Erp\Models\Sueldos;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CC extends Model
{
    protected $table = 'erp_emp_cc';

    protected $fillable = [
        'empleado_id', 'tipo', 'cuenta_contable_id',
        'saldo_actual', 'limite_credito', 'activa',
    ];

    protected $casts = [
        'saldo_actual'   => 'float',
        'limite_credito' => 'float',
        'activa'         => 'boolean',
    ];

    public const TIPO_PRESTAMO    = 'PRESTAMO';
    public const TIPO_ADELANTO    = 'ADELANTO';
    public const TIPO_COMBUSTIBLE = 'COMBUSTIBLE';
    public const TIPO_POLIZA      = 'POLIZA';
    public const TIPO_SANCION     = 'SANCION';
    public const TIPO_OTRO        = 'OTRO';

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(CCMovimiento::class, 'cc_id')->orderByDesc('fecha');
    }
}
