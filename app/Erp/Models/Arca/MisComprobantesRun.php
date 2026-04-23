<?php

namespace App\Erp\Models\Arca;

use App\Erp\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MisComprobantesRun extends Model
{
    protected $table = 'erp_mis_comprobantes_runs';
    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'tipo', 'fecha_desde', 'fecha_hasta', 'estado',
        'total_rows', 'nuevos', 'existentes', 'diff_json',
        'error_detail', 'arca_run_id', 'iniciado_at', 'finalizado_at',
    ];

    protected $casts = [
        'fecha_desde' => 'date',
        'fecha_hasta' => 'date',
        'iniciado_at' => 'datetime',
        'finalizado_at' => 'datetime',
        'diff_json' => 'array',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
