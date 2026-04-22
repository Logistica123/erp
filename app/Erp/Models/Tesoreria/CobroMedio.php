<?php

namespace App\Erp\Models\Tesoreria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medio con el que se cobró (efectivo en caja, transferencia a cuenta, eCheq).
 * Si medio = ECHEQ, echeq_id apunta al registro generado.
 * estado_acreditacion traquea el ciclo hasta que el movimiento bancario confirma.
 */
class CobroMedio extends Model
{
    protected $table = 'erp_cobro_medios';
    public $timestamps = false;

    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_ACREDITADO = 'ACREDITADO';
    public const ESTADO_RECHAZADO = 'RECHAZADO';

    protected $fillable = [
        'cobro_id', 'medio_pago_id',
        'cuenta_bancaria_id', 'caja_id', 'echeq_id',
        'importe', 'referencia',
        'movimiento_bancario_id', 'estado_acreditacion',
    ];

    protected $casts = [
        'importe' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class);
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class);
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function echeq(): BelongsTo
    {
        return $this->belongsTo(Echeq::class);
    }

    public function movimientoBancario(): BelongsTo
    {
        return $this->belongsTo(MovimientoBancario::class, 'movimiento_bancario_id');
    }
}
