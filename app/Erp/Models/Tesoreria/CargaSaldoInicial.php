<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\CuentaContable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v1.52 — Carga de saldo inicial de Cajas y Bancos (trazabilidad).
 * Inmutable: si hay error se revierte (asiento espejo) y se crea otra.
 */
class CargaSaldoInicial extends Model
{
    protected $table = 'erp_cargas_saldo_inicial';

    protected $fillable = [
        'empresa_id', 'cuenta_contable_destino_id', 'cuenta_contable_contrapartida_id',
        'cuenta_bancaria_id', 'caja_id', 'monto', 'fecha',
        'motivo_tipo', 'motivo_observacion', 'asiento_id', 'estado',
        'asiento_reversa_id', 'motivo_reversa', 'revertido_at', 'revertido_by',
        'created_by',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'revertido_at' => 'datetime',
    ];

    public function cuentaDestino(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_destino_id');
    }

    public function cuentaContrapartida(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_contrapartida_id');
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_id');
    }

    public function asientoReversa(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_reversa_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revertidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revertido_by');
    }
}
