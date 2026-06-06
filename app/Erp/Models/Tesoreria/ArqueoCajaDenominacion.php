<?php

namespace App\Erp\Models\Tesoreria;

use Illuminate\Database\Eloquent\Model;

/**
 * v1.42 — Línea de la grilla "billete a billete" de un arqueo.
 * subtotal = valor_billete * cantidad (validado en el service).
 */
class ArqueoCajaDenominacion extends Model
{
    protected $table = 'erp_arqueos_caja_denominaciones';
    public $timestamps = false;

    protected $fillable = ['arqueo_id', 'valor_billete', 'cantidad', 'subtotal'];

    protected $casts = [
        'valor_billete' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];
}
