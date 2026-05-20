<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v1.27 Sprint A — Configuración por cuenta bancaria de las cuentas
 * contables a usar para los tipos automáticos (COMISION, IMPUESTO,
 * INTERES_GANADO). El operador concilia un movimiento de esos tipos en 1
 * click y el sistema usa las cuentas configuradas acá.
 */
class BancoConfig extends Model
{
    protected $table = 'erp_banco_config';

    public $timestamps = true;
    const UPDATED_AT = 'updated_at';
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'cuenta_bancaria_id',
        'cuenta_gastos_bancarios_id',
        'cuenta_imp_debito_credito_id',
        'cuenta_intereses_ganados_id',
        'observaciones',
    ];

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class);
    }

    public function cuentaGastosBancarios(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_gastos_bancarios_id');
    }

    public function cuentaImpDebitoCredito(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_imp_debito_credito_id');
    }

    public function cuentaInteresesGanados(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_intereses_ganados_id');
    }
}
