<?php

namespace App\Erp\Models\Integracion;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Índice invertido DistriApp → ERP (SPEC 07 §5).
 *
 * Cada fila vincula un registro de DistriApp (identificado por (tipo,
 * distriapp_tabla, distriapp_id)) con la entidad ERP que lo cubrió
 * (asiento, factura_venta, cobro). La UK garantiza idempotencia: si el
 * reconciliador corre dos veces, el segundo INSERT falla.
 */
class DistriappRef extends Model
{
    protected $table = 'erp_distriapp_ref';

    public const UPDATED_AT = null;
    public const CREATED_AT = 'fecha_conciliacion';

    protected $fillable = [
        'tipo', 'distriapp_tabla', 'distriapp_id',
        'erp_entidad', 'erp_entidad_id',
        'fecha_conciliacion', 'usuario_id', 'notas',
    ];

    protected $casts = [
        'distriapp_id'       => 'integer',
        'erp_entidad_id'     => 'integer',
        'fecha_conciliacion' => 'datetime',
    ];

    public const TIPO_PAGO_OP = 'PAGO_OP';
    public const TIPO_PAGO_DETALLE = 'PAGO_DETALLE';
    public const TIPO_FACTURA = 'FACTURA';
    public const TIPO_COBRO = 'COBRO';

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
