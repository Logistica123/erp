<?php

namespace App\Erp\Models\Impuestos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IvaDdjj extends Model
{
    protected $table = 'erp_iva_ddjj';

    protected $fillable = [
        'periodo_id',
        'debito_fiscal', 'credito_fiscal', 'saldo_tecnico',
        'saldo_libre_disp_anterior', 'retenciones_sufridas',
        'percepciones_sufridas', 'pagos_a_cuenta',
        'saldo_libre_disp_final', 'importe_a_pagar',
        'archivo_f2002_path', 'archivo_f2002_hash', 'generado_at',
        'volante_pago_id',
    ];

    protected $casts = [
        'generado_at' => 'datetime',
    ];

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoFiscal::class, 'periodo_id');
    }
}
