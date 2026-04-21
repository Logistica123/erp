<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MapeoEtiquetaCuenta extends Model
{
    protected $table = 'erp_mapeo_etiqueta_cuenta';

    protected $fillable = [
        'empresa_id', 'etiqueta', 'descripcion',
        'cuenta_id', 'contrapartida_hint', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
