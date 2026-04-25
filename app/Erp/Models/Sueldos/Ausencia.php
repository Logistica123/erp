<?php

namespace App\Erp\Models\Sueldos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ausencia extends Model
{
    protected $table = 'erp_emp_ausencias';

    protected $fillable = [
        'empleado_id', 'tipo', 'fecha_desde', 'fecha_hasta',
        'dias_habiles', 'paga', 'observaciones', 'adjunto_path',
    ];

    protected $casts = [
        'fecha_desde'  => 'date',
        'fecha_hasta'  => 'date',
        'dias_habiles' => 'integer',
        'paga'         => 'boolean',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }
}
