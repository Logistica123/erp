<?php

namespace App\Erp\Models\Arca;

use App\Erp\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibroIvaImportacion extends Model
{
    protected $table = 'erp_libro_iva_importaciones';

    public const UPDATED_AT = null;

    protected $fillable = [
        'empresa_id', 'tipo', 'periodo_anio', 'periodo_mes',
        'archivo_hash', 'archivo_url',
        'total_filas', 'filas_nuevas', 'filas_match_erp',
        'filas_match_distriapp', 'filas_conflicto',
        'estado', 'error_detail', 'created_by_user_id', 'finished_at',
    ];

    protected $casts = [
        'finished_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(LibroIvaDetalle::class, 'importacion_id');
    }
}
