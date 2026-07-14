<?php

namespace App\Erp\Models\Sueldos;

use App\Erp\Models\Asiento;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Liquidacion extends Model
{
    protected $table = 'erp_emp_liquidaciones';

    protected $fillable = [
        'periodo', 'tipo', 'estado',
        'fecha_calculo', 'fecha_aprobacion', 'fecha_pago',
        'total_bruto', 'total_descuentos', 'total_neto',
        'total_formal', 'total_efectivo', 'total_mt',
        'empleados_count', 'asiento_id', 'hash_integridad',
        'calculado_por_id', 'aprobado_por_id',
        'liquidacion_origen_id', 'observaciones',
    ];

    protected $casts = [
        'fecha_calculo'    => 'datetime',
        'fecha_aprobacion' => 'datetime',
        'fecha_pago'       => 'datetime',
        'total_bruto'      => 'float',
        'total_descuentos' => 'float',
        'total_neto'       => 'float',
        'total_formal'     => 'float',
        'total_efectivo'   => 'float',
        'total_mt'         => 'float',
        'empleados_count'  => 'integer',
    ];

    public const TIPO_MENSUAL = 'MENSUAL';
    public const TIPO_SAC     = 'SAC';
    public const TIPO_AJUSTE  = 'AJUSTE';
    public const TIPO_FINAL   = 'FINAL';

    public const ESTADO_BORRADOR    = 'BORRADOR';
    public const ESTADO_CALCULADA   = 'CALCULADA';
    public const ESTADO_APROBADA    = 'APROBADA';
    public const ESTADO_PAGADA      = 'PAGADA';
    public const ESTADO_RECTIFICADA = 'RECTIFICADA';
    public const ESTADO_ANULADA     = 'ANULADA';

    public function items(): HasMany
    {
        return $this->hasMany(LiquidacionItem::class, 'liquidacion_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'liquidacion_id');
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_id');
    }

    public function calculador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculado_por_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'liquidacion_origen_id');
    }

    public function esEditable(): bool
    {
        return $this->estado === self::ESTADO_BORRADOR
            || $this->estado === self::ESTADO_CALCULADA;
    }
}
