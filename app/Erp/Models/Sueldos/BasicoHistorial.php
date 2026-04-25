<?php

namespace App\Erp\Models\Sueldos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BasicoHistorial extends Model
{
    protected $table = 'erp_emp_basicos_historial';
    public $timestamps = false;

    protected $fillable = [
        'empleado_id', 'basico_total',
        'vigencia_desde', 'vigencia_hasta',
        'motivo', 'aprobado_por_id', 'fecha_aprobacion',
        'observaciones',
    ];

    protected $casts = [
        'basico_total'     => 'float',
        'vigencia_desde'   => 'date',
        'vigencia_hasta'   => 'date',
        'fecha_aprobacion' => 'datetime',
        'created_at'       => 'datetime',
    ];

    public const MOTIVO_INGRESO            = 'INGRESO';
    public const MOTIVO_AUMENTO_PARITARIA  = 'AUMENTO_PARITARIA';
    public const MOTIVO_AUMENTO_GERENCIAL  = 'AUMENTO_GERENCIAL';
    public const MOTIVO_CORRECCION         = 'CORRECCION';
    public const MOTIVO_RECATEGORIZACION   = 'RECATEGORIZACION';

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por_id');
    }
}
