<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConciliacionPrefijo extends Model
{
    protected $table = 'erp_conciliacion_prefijos';
    public $timestamps = false;

    protected $fillable = [
        'banco_id', 'prefijo', 'tipo_numero',
        'longitud_min', 'longitud_max',
        'cuenta_contable_default_id', 'observacion', 'activo',
    ];

    protected $casts = [
        'longitud_min' => 'integer',
        'longitud_max' => 'integer',
        'activo' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class);
    }

    public function cuentaContableDefault(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_default_id');
    }
}
