<?php

namespace App\Erp\Models\VentasCompras;

use App\Erp\Models\Empresa;
use App\Erp\Models\Periodo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * v1.45 — Tracking de cada archivo de Libro IVA Ventas importado (mirror del
 * v1.9 Compras). Idempotencia por hash SHA256 + empresa_id.
 *
 * El campo `cliente_auxiliar_id` de cada factura puede quedar NULL cuando el
 * CSV de AFIP trae un CUIT que no matchea ningún auxiliar tipo Cliente. En
 * esos casos el importer hace upsert automático (v1.39) si trae razón social,
 * sino reporta en `clientes_no_mapeados`.
 */
class LibroIvaVentasImport extends Model
{
    protected $table = 'erp_libros_iva_ventas_import';
    public $timestamps = false;

    public const ESTADO_PROCESANDO       = 'PROCESANDO';
    public const ESTADO_COMPLETO         = 'COMPLETO';
    public const ESTADO_OK_CON_WARNINGS  = 'OK_CON_WARNINGS';
    public const ESTADO_ERROR_TOTAL      = 'ERROR_TOTAL';

    protected $fillable = [
        'empresa_id', 'archivo_nombre', 'archivo_hash',
        'encoding_detectado', 'periodo_afip', 'periodo_imputacion_id',
        'filas_totales', 'filas_ok', 'filas_skipped', 'filas_error',
        'warnings_count', 'warnings_detalle', 'errores_detalle',
        'clientes_no_mapeados', 'clientes_creados',
        'importado_por', 'importado_at', 'estado',
    ];

    protected $casts = [
        'errores_detalle' => 'array',
        'warnings_detalle' => 'array',
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
        return $this->hasMany(FacturaVenta::class, 'import_id');
    }
}
