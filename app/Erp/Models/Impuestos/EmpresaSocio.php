<?php

namespace App\Erp\Models\Impuestos;

use App\Erp\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpresaSocio extends Model
{
    protected $table = 'erp_empresa_socios';

    protected $fillable = [
        'empresa_id', 'cuit', 'nombre', 'tipo',
        'porcentaje_participacion',
        'fecha_alta', 'fecha_baja', 'activo', 'observaciones',
    ];

    protected $casts = [
        'fecha_alta' => 'date',
        'fecha_baja' => 'date',
        'activo'     => 'bool',
        'porcentaje_participacion' => 'float',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
