<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\Asiento;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arqueo diario de caja (SPEC 02 RN-22, RN-23).
 * diferencia es columna generated = saldo_fisico - saldo_teorico.
 * Si diferencia != 0 se genera asiento de sobrante/faltante (RN-23).
 */
class ArqueoCaja extends Model
{
    protected $table = 'erp_arqueos_caja';
    public $timestamps = false;

    protected $fillable = [
        'caja_id', 'fecha',
        'saldo_teorico', 'saldo_fisico',
        'motivo', 'asiento_ajuste_id', 'realizado_por_user_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'saldo_teorico' => 'decimal:2',
        'saldo_fisico' => 'decimal:2',
        'diferencia' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function asientoAjuste(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_ajuste_id');
    }

    public function realizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'realizado_por_user_id');
    }
}
