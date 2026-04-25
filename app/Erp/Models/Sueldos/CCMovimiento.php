<?php

namespace App\Erp\Models\Sueldos;

use App\Erp\Models\Asiento;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CCMovimiento extends Model
{
    protected $table = 'erp_emp_cc_movimientos';
    public $timestamps = false;

    protected $fillable = [
        'cc_id', 'fecha', 'tipo_mov', 'importe', 'saldo_posterior',
        'asiento_id', 'liquidacion_id', 'referencia',
        'observaciones', 'creado_por_id',
    ];

    protected $casts = [
        'fecha'           => 'date',
        'importe'         => 'float',
        'saldo_posterior' => 'float',
        'created_at'      => 'datetime',
    ];

    public const TIPO_CARGO              = 'CARGO';
    public const TIPO_PAGO               = 'PAGO';
    public const TIPO_DESCUENTO_LIQ      = 'DESCUENTO_LIQUIDACION';
    public const TIPO_AJUSTE             = 'AJUSTE';

    public function cc(): BelongsTo
    {
        return $this->belongsTo(CC::class, 'cc_id');
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_id');
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_id');
    }
}
