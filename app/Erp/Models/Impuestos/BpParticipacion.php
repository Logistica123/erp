<?php

namespace App\Erp\Models\Impuestos;

use App\Erp\Models\Ejercicio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BpParticipacion extends Model
{
    protected $table = 'erp_bp_participaciones';

    protected $fillable = [
        'periodo_id', 'ejercicio_id',
        'patrimonio_neto_ajustado', 'alicuota', 'impuesto_total',
        'socios_detalle',
        'archivo_f2000_path', 'archivo_f2000_hash', 'generado_at',
    ];

    protected $casts = [
        'socios_detalle' => 'array',
        'generado_at'    => 'datetime',
    ];

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoFiscal::class, 'periodo_id');
    }

    public function ejercicio(): BelongsTo
    {
        return $this->belongsTo(Ejercicio::class);
    }
}
