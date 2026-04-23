<?php

namespace App\Erp\Models\Impuestos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibroIvaVentasPeriodo extends Model
{
    protected $table = 'erp_libro_iva_ventas_periodo';

    protected $fillable = [
        'periodo_id',
        'neto_gravado_21', 'neto_gravado_10_5', 'neto_gravado_27', 'neto_gravado_5', 'neto_gravado_2_5',
        'neto_no_gravado', 'neto_exento',
        'iva_21', 'iva_10_5', 'iva_27', 'iva_5', 'iva_2_5',
        'percepciones_iibb_practicadas', 'otros_tributos',
        'total_facturado', 'cantidad_comprobantes',
        'archivo_f8001_path', 'archivo_f8001_hash', 'generado_at', 'generado_user_id',
    ];

    protected $casts = [
        'generado_at' => 'datetime',
    ];

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoFiscal::class, 'periodo_id');
    }

    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_user_id');
    }
}
