<?php

namespace App\Erp\Models\Sueldos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportLiber extends Model
{
    protected $table = 'erp_emp_export_liber';
    public $timestamps = false;

    protected $fillable = [
        'liquidacion_id', 'periodo', 'fecha_export',
        'generado_por_id', 'total_exportado', 'empleados_count',
        'archivo_path', 'hash_sha256',
        'enviado_a_liber', 'fecha_envio', 'observaciones',
    ];

    protected $casts = [
        'fecha_export'    => 'datetime',
        'total_exportado' => 'float',
        'empleados_count' => 'integer',
        'enviado_a_liber' => 'boolean',
        'fecha_envio'     => 'datetime',
    ];

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }

    public function generador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por_id');
    }
}
