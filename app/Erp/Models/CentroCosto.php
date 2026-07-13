<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentroCosto extends Model
{
    protected $table = 'erp_centros_costo';

    protected $fillable = [
        'empresa_id', 'codigo', 'nombre', 'tipo',
        'padre_id', 'ref_externa', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * CC operativo por defecto de la empresa — fallback único para las
     * líneas de asiento sobre cuentas admite_cc sin CC explícito.
     *
     * Mini-tanda 2026-07-13 bug 1: 5 services buscaban 'CENTRAL', que en
     * prod no existe (el operativo real es 'GENERAL'); v1.51 había
     * arreglado solo ArqueoCajaService. Este resolver unifica el criterio:
     * CENTRAL si existe (compat con entornos viejos) → GENERAL → null.
     */
    public static function operativoId(int $empresaId): ?int
    {
        $id = static::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('codigo', ['CENTRAL', 'GENERAL'])
            ->where('activo', 1)
            ->orderByRaw("FIELD(codigo, 'CENTRAL', 'GENERAL')")
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(CentroCosto::class, 'padre_id');
    }
}
