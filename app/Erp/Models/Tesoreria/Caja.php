<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\CuentaContable;
use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Caja extends Model
{
    protected $table = 'erp_cajas';

    protected $fillable = [
        'empresa_id', 'codigo', 'nombre', 'cuenta_contable_id',
        'moneda_id', 'responsable_user_id', 'saldo_actual', 'activo',
    ];

    protected $casts = [
        'saldo_actual' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cuentaContable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_id');
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_user_id');
    }
}
