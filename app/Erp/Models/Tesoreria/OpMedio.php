<?php

namespace App\Erp\Models\Tesoreria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medio con el que se paga una OP (transferencia, MP, etc.).
 * Una misma OP puede combinar varios medios (RN-27 balance).
 */
class OpMedio extends Model
{
    protected $table = 'erp_op_medios';
    public $timestamps = false;

    protected $fillable = [
        'op_id', 'medio_pago_id', 'cuenta_bancaria_id',
        'importe', 'referencia',
    ];

    protected $casts = [
        'importe' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function ordenPago(): BelongsTo
    {
        return $this->belongsTo(OrdenPago::class, 'op_id');
    }

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class);
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class);
    }
}
