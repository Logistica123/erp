<?php

namespace App\Erp\Models\Tesoreria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v1.35 — Audit log inmutable (insert-only) de las órdenes de pago.
 * Cubre acciones de usuario + actualizaciones del sync DistriApp.
 */
class OrdenPagoAudit extends Model
{
    protected $table = 'erp_ordenes_pago_audit';
    public $timestamps = false;

    protected $fillable = [
        'op_id', 'accion', 'user_id', 'snapshot_antes', 'snapshot_despues',
        'motivo', 'created_at',
    ];

    protected $casts = [
        'snapshot_antes' => 'array',
        'snapshot_despues' => 'array',
        'created_at' => 'datetime',
    ];

    public function ordenPago(): BelongsTo
    {
        return $this->belongsTo(OrdenPago::class, 'op_id');
    }
}
