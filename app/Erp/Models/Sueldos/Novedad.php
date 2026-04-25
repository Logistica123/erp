<?php

namespace App\Erp\Models\Sueldos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Novedad extends Model
{
    protected $table = 'erp_emp_novedades';
    public $timestamps = false;

    protected $fillable = [
        'empleado_id', 'periodo', 'concepto_id',
        'cantidad', 'importe', 'observaciones',
        'creado_por_id',
    ];

    protected $casts = [
        'cantidad'   => 'float',
        'importe'    => 'float',
        'created_at' => 'datetime',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(Concepto::class, 'concepto_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_id');
    }
}
