<?php

namespace App\Erp\Models\Impuestos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IibbCmDeclaracion extends Model
{
    protected $table = 'erp_iibb_cm_declaracion';

    protected $fillable = [
        'periodo_id', 'tipo', 'jurisdiccion',
        'base_imponible', 'coeficiente', 'base_atribuida',
        'alicuota', 'impuesto_determinado',
        'percepciones_sufridas', 'retenciones_sufridas',
        'saldo_anterior', 'importe_a_pagar',
        'archivo_sifere_path', 'generado_at',
    ];

    protected $casts = [
        'generado_at' => 'datetime',
    ];

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoFiscal::class, 'periodo_id');
    }
}
