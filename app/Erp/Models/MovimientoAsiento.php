<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoAsiento extends Model
{
    protected $table = 'erp_movimientos_asiento';
    public $timestamps = false;

    protected $fillable = [
        'asiento_id', 'linea', 'cuenta_id',
        'centro_costo_id', 'auxiliar_id', 'glosa',
        'debe', 'haber', 'moneda', 'importe_origen', 'cotizacion',
        'referencia_ext',
        'factura_venta_id', 'factura_compra_id', 'movimiento_banco_id',
    ];

    protected $casts = [
        'linea' => 'integer',
        'debe' => 'decimal:2',
        'haber' => 'decimal:2',
        'importe_origen' => 'decimal:2',
        'cotizacion' => 'decimal:4',
    ];

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_id');
    }

    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function auxiliar(): BelongsTo
    {
        return $this->belongsTo(Auxiliar::class, 'auxiliar_id');
    }
}
