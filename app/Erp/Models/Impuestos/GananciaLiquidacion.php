<?php

namespace App\Erp\Models\Impuestos;

use App\Erp\Models\Ejercicio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GananciaLiquidacion extends Model
{
    protected $table = 'erp_ganancias_liquidacion';

    protected $fillable = [
        'periodo_id', 'ejercicio_id',
        'resultado_contable', 'ajustes_fiscales_mas', 'ajustes_fiscales_menos',
        'resultado_impositivo', 'alicuota_escalonada', 'impuesto_determinado',
        'anticipos_computados', 'retenciones_sufridas', 'percepciones_sufridas',
        'saldo_a_pagar', 'saldo_a_favor',
        'ajusta_por_inflacion', 'ajuste_inflacion_importe',
        'archivo_f713_path', 'archivo_f713_hash', 'generado_at',
    ];

    protected $casts = [
        'alicuota_escalonada' => 'array',
        'ajusta_por_inflacion'=> 'bool',
        'generado_at'         => 'datetime',
    ];

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoFiscal::class, 'periodo_id');
    }

    public function ejercicio(): BelongsTo
    {
        return $this->belongsTo(Ejercicio::class);
    }

    public function anticipos(): HasMany
    {
        return $this->hasMany(GananciaAnticipo::class, 'liquidacion_origen_id');
    }
}
