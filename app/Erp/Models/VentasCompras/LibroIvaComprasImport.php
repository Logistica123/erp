<?php

namespace App\Erp\Models\VentasCompras;

use App\Erp\Models\Empresa;
use App\Erp\Models\Periodo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADDENDUM v1.9 — tracking de cada archivo de Libro IVA Compras importado.
 *
 * Idempotencia: UNIQUE (empresa_id, archivo_hash). Re-subir el mismo archivo
 * devuelve 409 con info del import previo.
 *
 * Cada import puede dejar facturas con `cliente_auxiliar_id=NULL` cuando el
 * texto del campo "Cliente" no matcheó ningún auxiliar tipo Cliente. Esos
 * casos se acumulan en `clientes_no_mapeados` (JSON) para que el operador
 * los revise y vincule manualmente después.
 */
class LibroIvaComprasImport extends Model
{
    protected $table = 'erp_libros_iva_compras_import';
    public $timestamps = false;

    public const ESTADO_PROCESANDO       = 'PROCESANDO';
    public const ESTADO_COMPLETO         = 'COMPLETO'; // legacy alias de OK (sin warnings)
    public const ESTADO_PARCIAL          = 'PARCIAL';  // legacy (pre-v1.22, no se asigna nuevo)
    public const ESTADO_ERROR            = 'ERROR';    // legacy
    // v1.22 D-22-8.
    public const ESTADO_OK_CON_WARNINGS  = 'OK_CON_WARNINGS';
    public const ESTADO_ERROR_TOTAL      = 'ERROR_TOTAL';

    protected $fillable = [
        'empresa_id', 'archivo_nombre', 'archivo_hash',
        'encoding_detectado', // v1.19
        'periodo_afip', 'periodo_imputacion_id',
        'filas_totales', 'filas_tomadas', 'filas_no_tomadas',
        'filas_skipped', 'filas_error',
        'warnings_count', 'warnings_detalle', // v1.22
        'errores_detalle', 'clientes_no_mapeados',
        'proveedores_creados',
        'importado_por', 'importado_at', 'estado',
    ];

    protected $casts = [
        'errores_detalle' => 'array',
        'warnings_detalle' => 'array', // v1.22
        'clientes_no_mapeados' => 'array',
        'importado_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function periodoImputacion(): BelongsTo
    {
        return $this->belongsTo(Periodo::class, 'periodo_imputacion_id');
    }

    public function importadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'importado_por');
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(FacturaCompra::class, 'import_id');
    }
}
