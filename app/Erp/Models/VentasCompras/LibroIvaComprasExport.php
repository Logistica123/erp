<?php

namespace App\Erp\Models\VentasCompras;

use App\Erp\Models\Empresa;
use App\Erp\Models\Periodo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADDENDUM v1.11 — tracking de cada generación F.8001.
 *
 * Re-generar el mismo período crea un nuevo registro (no actualiza el viejo).
 * Eso permite mantener trazabilidad de TXT generados antes y después de
 * correcciones de datos. El campo `enviado_afip` se marca manualmente cuando
 * Matías sube los TXT a AFIP.
 */
class LibroIvaComprasExport extends Model
{
    protected $table = 'erp_libros_iva_compras_export';
    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'periodo_id',
        'archivo_cbte_path', 'archivo_alicuotas_path',
        'archivo_cbte_hash', 'archivo_alicuotas_hash',
        'filas_cbte', 'filas_alicuotas',
        'total_neto', 'total_iva', 'total_facturas',
        'generado_por', 'generado_at',
        'enviado_afip', 'enviado_at', 'enviado_por',
        'observaciones',
    ];

    protected $casts = [
        'enviado_afip' => 'boolean',
        'generado_at' => 'datetime',
        'enviado_at' => 'datetime',
        'total_neto' => 'decimal:2',
        'total_iva' => 'decimal:2',
        'total_facturas' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
