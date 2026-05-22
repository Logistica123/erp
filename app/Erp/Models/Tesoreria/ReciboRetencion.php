<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v1.31 — Retención recibida del cliente dentro de un recibo. Tipos AFIP:
 * Ganancias, IVA, IIBB (jurisdicción + alícuota), SUSS, Otro.
 */
class ReciboRetencion extends Model
{
    protected $table = 'erp_recibos_retenciones';
    public $timestamps = false;

    public const TIPO_GANANCIAS = 'GANANCIAS';
    public const TIPO_IVA = 'IVA';
    public const TIPO_IIBB = 'IIBB';
    public const TIPO_SUSS = 'SUSS';
    public const TIPO_OTRO = 'OTRO';

    protected $fillable = [
        'recibo_id', 'tipo', 'jurisdiccion_codigo', 'numero_certificado',
        'alicuota', 'base_imponible', 'monto', 'cuenta_contable_id', 'created_at',
    ];

    protected $casts = [
        'alicuota' => 'decimal:2',
        'base_imponible' => 'decimal:2',
        'monto' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function recibo(): BelongsTo
    {
        return $this->belongsTo(Recibo::class);
    }

    public function cuentaContable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class);
    }
}
