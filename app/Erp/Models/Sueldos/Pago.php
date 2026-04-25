<?php

namespace App\Erp\Models\Sueldos;

use App\Erp\Models\Asiento;
use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Models\VentasCompras\FacturaCompra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pago extends Model
{
    protected $table = 'erp_emp_pagos';
    public $timestamps = false;

    protected $fillable = [
        'liquidacion_id', 'empleado_id', 'componente',
        'medio', 'importe', 'fecha',
        'orden_pago_id', 'movimiento_caja_id', 'factura_compra_id',
        'cbu_destino', 'banco_destino',
        'recibido_por', 'dni_recibio', 'firma_path',
        'asiento_id', 'observaciones',
    ];

    protected $casts = [
        'importe'    => 'float',
        'fecha'      => 'date',
        'created_at' => 'datetime',
    ];

    public const MEDIO_TRANSFERENCIA = 'TRANSFERENCIA';
    public const MEDIO_EFECTIVO      = 'EFECTIVO';
    public const MEDIO_CHEQUE        = 'CHEQUE';
    public const MEDIO_OTRO          = 'OTRO';

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function ordenPago(): BelongsTo
    {
        return $this->belongsTo(OrdenPago::class, 'orden_pago_id');
    }

    public function facturaCompra(): BelongsTo
    {
        return $this->belongsTo(FacturaCompra::class, 'factura_compra_id');
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_id');
    }
}
