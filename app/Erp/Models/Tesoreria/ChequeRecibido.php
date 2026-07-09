<?php

namespace App\Erp\Models\Tesoreria;

use Illuminate\Database\Eloquent\Model;

class ChequeRecibido extends Model
{
    protected $table = 'erp_cheques_recibidos';

    public const ESTADO_EN_CARTERA = 'EN_CARTERA';
    public const ESTADO_DEPOSITADO = 'DEPOSITADO';
    public const ESTADO_COBRADO = 'COBRADO';
    public const ESTADO_RECHAZADO = 'RECHAZADO';
    public const ESTADO_VENCIDO = 'VENCIDO_NO_COBRADO';
    public const ESTADO_DESCONTADO = 'DESCONTADO';
    public const ESTADO_ENDOSADO = 'ENDOSADO';

    protected $fillable = [
        'empresa_id', 'recibo_id',
        'numero_cheque', 'banco_emisor', 'cuit_librador', 'librador_nombre',
        'fecha_emision', 'fecha_pago', 'importe',
        'estado',
        'cuenta_bancaria_deposito_id', 'fecha_deposito', 'fecha_acreditacion', 'mov_bancario_id',
        'descuento_entidad', 'descuento_intereses', 'descuento_iva', 'descuento_comision',
        'descuento_sellado', 'descuento_percepcion_iva', 'descuento_percepcion_iibb', 'descuento_otros',
        'descuento_neto', 'asiento_id',
        'endoso_op_id',
        'fecha_rechazo', 'motivo_rechazo',
        'observaciones', 'created_by_user_id',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_pago' => 'date',
        'fecha_deposito' => 'date',
        'fecha_acreditacion' => 'date',
        'fecha_rechazo' => 'date',
        'importe' => 'decimal:2',
    ];

    public function recibo()
    {
        return $this->belongsTo(Recibo::class, 'recibo_id');
    }
}
