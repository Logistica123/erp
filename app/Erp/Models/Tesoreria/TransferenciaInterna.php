<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transferencia entre dos cuentas bancarias propias (SPEC 02 RN-20).
 * Genera 2 movimientos bancarios y 1 asiento contable.
 * Si monedas difieren usa tipo_cambio para la conversión.
 */
class TransferenciaInterna extends Model
{
    protected $table = 'erp_transferencias_internas';

    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_PARCIAL = 'PARCIAL';
    public const ESTADO_CONCILIADA = 'CONCILIADA';
    public const ESTADO_ANULADA = 'ANULADA';

    protected $fillable = [
        'empresa_id', 'numero', 'fecha',
        'cuenta_origen_id', 'cuenta_destino_id',
        'moneda_origen_id', 'moneda_destino_id',
        'importe_origen', 'importe_destino', 'tipo_cambio',
        'estado', 'movimiento_origen_id', 'movimiento_destino_id',
        'asiento_id', 'concepto', 'creado_por_user_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'importe_origen' => 'decimal:2',
        'importe_destino' => 'decimal:2',
        'tipo_cambio' => 'decimal:4',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cuentaOrigen(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_origen_id');
    }

    public function cuentaDestino(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_destino_id');
    }

    public function monedaOrigen(): BelongsTo
    {
        return $this->belongsTo(Moneda::class, 'moneda_origen_id');
    }

    public function monedaDestino(): BelongsTo
    {
        return $this->belongsTo(Moneda::class, 'moneda_destino_id');
    }

    public function movimientoOrigen(): BelongsTo
    {
        return $this->belongsTo(MovimientoBancario::class, 'movimiento_origen_id');
    }

    public function movimientoDestino(): BelongsTo
    {
        return $this->belongsTo(MovimientoBancario::class, 'movimiento_destino_id');
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }
}
