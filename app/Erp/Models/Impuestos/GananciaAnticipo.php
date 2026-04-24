<?php

namespace App\Erp\Models\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Tesoreria\OrdenPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GananciaAnticipo extends Model
{
    protected $table = 'erp_ganancias_anticipos';

    protected $fillable = [
        'ejercicio_id', 'liquidacion_origen_id', 'nro_anticipo',
        'fecha_vencimiento', 'base_calculo', 'porcentaje', 'importe',
        'estado', 'fecha_pago', 'orden_pago_id', 'observaciones',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
        'fecha_pago'        => 'date',
    ];

    public function ejercicio(): BelongsTo
    {
        return $this->belongsTo(Ejercicio::class);
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(GananciaLiquidacion::class, 'liquidacion_origen_id');
    }

    public function ordenPago(): BelongsTo
    {
        return $this->belongsTo(OrdenPago::class, 'orden_pago_id');
    }
}
