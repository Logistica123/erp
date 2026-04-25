<?php

namespace App\Erp\Models\Sueldos;

use App\Erp\Models\Asiento;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prestamo extends Model
{
    protected $table = 'erp_emp_prestamos';

    protected $fillable = [
        'empleado_id', 'fecha_otorgamiento', 'capital',
        'cuotas_total', 'cuotas_pagadas', 'cuota_mensual',
        'saldo_capital', 'primera_cuota_periodo',
        'estado', 'asiento_alta_id', 'aprobado_por_id', 'observaciones',
    ];

    protected $casts = [
        'fecha_otorgamiento' => 'date',
        'capital'            => 'float',
        'cuotas_total'       => 'integer',
        'cuotas_pagadas'     => 'integer',
        'cuota_mensual'      => 'float',
        'saldo_capital'      => 'float',
    ];

    public const ESTADO_VIGENTE      = 'VIGENTE';
    public const ESTADO_CANCELADO    = 'CANCELADO';
    public const ESTADO_REFINANCIADO = 'REFINANCIADO';
    public const ESTADO_BAJA         = 'BAJA';

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function asientoAlta(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_alta_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por_id');
    }
}
