<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\CuentaContable;
use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CuentaBancaria extends Model
{
    use SoftDeletes;

    protected $table = 'erp_cuentas_bancarias';

    protected $fillable = [
        'empresa_id', 'banco_id', 'cuenta_contable_id', 'moneda_id',
        'codigo', 'nombre', 'tipo',
        'numero_cuenta', 'cbu', 'cvu', 'alias_cbu',
        'saldo_actual', 'saldo_moneda_origen', 'fecha_ultimo_movimiento',
        'activo',
    ];

    protected $casts = [
        'saldo_actual' => 'decimal:2',
        'saldo_moneda_origen' => 'decimal:2',
        'fecha_ultimo_movimiento' => 'date',
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function cuentaContable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoBancario::class);
    }
}
