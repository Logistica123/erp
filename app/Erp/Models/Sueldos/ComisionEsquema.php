<?php

namespace App\Erp\Models\Sueldos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComisionEsquema extends Model
{
    protected $table = 'erp_emp_comisiones_esquema';
    public $timestamps = false;

    protected $fillable = [
        'empleado_id', 'base',
        'porcentaje', 'importe_unitario', 'importe_fijo',
        'tope_mensual',
        'vigencia_desde', 'vigencia_hasta', 'observaciones',
    ];

    protected $casts = [
        'porcentaje'        => 'float',
        'importe_unitario'  => 'float',
        'importe_fijo'      => 'float',
        'tope_mensual'      => 'float',
        'vigencia_desde'    => 'date',
        'vigencia_hasta'    => 'date',
        'created_at'        => 'datetime',
    ];

    public const BASE_VENTAS_NETAS  = 'VENTAS_NETAS';
    public const BASE_COBRANZAS     = 'COBRANZAS';
    public const BASE_MARGEN        = 'MARGEN';
    public const BASE_UNIDADES      = 'UNIDADES';
    public const BASE_FIJO_MENSUAL  = 'FIJO_MENSUAL';

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }
}
