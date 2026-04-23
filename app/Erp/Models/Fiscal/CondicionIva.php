<?php

namespace App\Erp\Models\Fiscal;

use Illuminate\Database\Eloquent\Model;

class CondicionIva extends Model
{
    protected $table = 'erp_condiciones_iva';
    public $timestamps = false;
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id', 'codigo_interno', 'nombre', 'letra_default', 'acepta_fce', 'activo',
    ];

    protected $casts = [
        'acepta_fce' => 'boolean',
        'activo' => 'boolean',
    ];
}
