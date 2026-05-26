<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v1.35 — Catálogo de tipos de orden de pago (PROV, SUEL_ADM, ALQ, etc).
 * El tipo DIST se asigna automáticamente a las OP sincronizadas de DistriApp.
 */
class OrdenPagoTipo extends Model
{
    protected $table = 'erp_ordenes_pago_tipos';
    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'codigo', 'nombre', 'cuenta_contable_default_id',
        'activo', 'orden', 'created_at',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
        'created_at' => 'datetime',
    ];

    public function cuentaContableDefault(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_default_id');
    }
}
