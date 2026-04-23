<?php

namespace App\Erp\Models\Fiscal;

use Illuminate\Database\Eloquent\Model;

class TipoComprobante extends Model
{
    protected $table = 'erp_tipos_comprobante';
    public $timestamps = false;
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id', 'codigo_interno', 'nombre', 'letra', 'clase', 'signo',
        'es_fce', 'discrimina_iva', 'activo',
    ];

    protected $casts = [
        'signo' => 'integer',
        'es_fce' => 'boolean',
        'discrimina_iva' => 'boolean',
        'activo' => 'boolean',
    ];
}
