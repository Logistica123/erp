<?php

namespace App\Erp\Models\Integracion;

use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría de cada fila procesada por los reconciliadores (SPEC 07 §13).
 *
 * Una fila por registro DistriApp tocado en una corrida. Permite reproducir
 * qué se leyó en un momento dado, detectar errores recurrentes y alimentar
 * el bloque C del dashboard ("errores 24h", "última reconciliación").
 */
class IntegracionLog extends Model
{
    protected $table = 'erp_integracion_log';

    public $timestamps = false;

    protected $fillable = [
        'timestamp', 'flujo', 'distriapp_tabla', 'distriapp_id',
        'estado', 'mensaje', 'payload',
    ];

    protected $casts = [
        'timestamp'    => 'datetime',
        'distriapp_id' => 'integer',
        'payload'      => 'array',
    ];

    public const FLUJO_PAGO_MASIVO = 'PAGO_MASIVO';
    public const FLUJO_FACTURA = 'FACTURA';
    public const FLUJO_COBRO = 'COBRO';
    public const FLUJO_DASHBOARD = 'DASHBOARD';

    public const ESTADO_OK = 'OK';
    public const ESTADO_ERROR = 'ERROR';
    public const ESTADO_WARNING = 'WARNING';
    public const ESTADO_SKIPPED = 'SKIPPED';
}
