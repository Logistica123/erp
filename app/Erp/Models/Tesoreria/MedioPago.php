<?php

namespace App\Erp\Models\Tesoreria;

use Illuminate\Database\Eloquent\Model;

class MedioPago extends Model
{
    protected $table = 'erp_medios_pago';

    protected $fillable = [
        'codigo', 'nombre',
        'afecta_caja', 'afecta_banco', 'genera_echeq', 'activo',
    ];

    protected $casts = [
        'afecta_caja' => 'boolean',
        'afecta_banco' => 'boolean',
        'genera_echeq' => 'boolean',
        'activo' => 'boolean',
    ];
}
