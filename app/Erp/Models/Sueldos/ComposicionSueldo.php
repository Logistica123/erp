<?php

namespace App\Erp\Models\Sueldos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComposicionSueldo extends Model
{
    protected $table = 'erp_emp_composicion_sueldo';
    public $timestamps = false;

    protected $fillable = [
        'empleado_id', 'porc_formal', 'porc_efectivo', 'porc_mt',
        'vigencia_desde', 'vigencia_hasta', 'observaciones',
    ];

    protected $casts = [
        'porc_formal'    => 'float',
        'porc_efectivo'  => 'float',
        'porc_mt'        => 'float',
        'vigencia_desde' => 'date',
        'vigencia_hasta' => 'date',
        'created_at'     => 'datetime',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }
}
