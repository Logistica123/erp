<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * eCheq recibido (SPEC 02 §4.1, RN-18, RN-19).
 * Flujo: EN_CARTERA → DEPOSITADO → ACREDITADO | RECHAZADO | ANULADO.
 */
class Echeq extends Model
{
    protected $table = 'erp_echeq';

    public const ESTADO_EN_CARTERA = 'EN_CARTERA';
    public const ESTADO_DEPOSITADO = 'DEPOSITADO';
    public const ESTADO_ACREDITADO = 'ACREDITADO';
    public const ESTADO_RECHAZADO = 'RECHAZADO';
    public const ESTADO_ENDOSADO = 'ENDOSADO';
    public const ESTADO_ANULADO = 'ANULADO';

    protected $fillable = [
        'empresa_id', 'numero', 'cuit_librador', 'razon_social_librador',
        'banco_origen', 'cbu_origen', 'importe', 'moneda_id',
        'fecha_emision', 'fecha_pago', 'estado',
        'cobro_id', 'deposito_cuenta_id', 'fecha_deposito',
        'movimiento_bancario_id', 'fecha_acreditacion',
        'motivo_rechazo', 'observaciones',
    ];

    protected $casts = [
        'importe' => 'decimal:2',
        'fecha_emision' => 'date',
        'fecha_pago' => 'date',
        'fecha_deposito' => 'date',
        'fecha_acreditacion' => 'date',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    public function cuentaDeposito(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'deposito_cuenta_id');
    }

    public function movimientoBancario(): BelongsTo
    {
        return $this->belongsTo(MovimientoBancario::class, 'movimiento_bancario_id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(EcheqMovimiento::class, 'echeq_id')->orderBy('fecha');
    }
}
