<?php

namespace App\Erp\Models\Fiscal;

use App\Erp\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PuntoVenta extends Model
{
    protected $table = 'erp_puntos_venta';

    protected $fillable = [
        'empresa_id', 'numero', 'nombre', 'tipo_emision', 'bloqueado',
        'direccion', 'activo',
    ];

    protected $casts = [
        'bloqueado' => 'boolean',
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
