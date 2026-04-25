<?php

namespace App\Erp\Models\Sueldos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidacionItem extends Model
{
    protected $table = 'erp_emp_liquidaciones_items';
    public $timestamps = false;

    protected $fillable = [
        'liquidacion_id', 'empleado_id', 'concepto_id',
        'componente', 'cantidad', 'importe_unitario', 'importe',
        'base_calculo', 'observaciones',
    ];

    protected $casts = [
        'cantidad'         => 'float',
        'importe_unitario' => 'float',
        'importe'          => 'float',
        'base_calculo'     => 'float',
    ];

    public const COMPONENTE_FORMAL   = 'FORMAL';
    public const COMPONENTE_EFECTIVO = 'EFECTIVO';
    public const COMPONENTE_MT       = 'MT';

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(Concepto::class, 'concepto_id');
    }
}
