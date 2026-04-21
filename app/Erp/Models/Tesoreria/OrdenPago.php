<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\Auxiliar;
use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenPago extends Model
{
    protected $table = 'erp_ordenes_pago';

    public const ESTADO_BORRADOR = 'BORRADOR';
    public const ESTADO_CARGADA_BANCO = 'CARGADA_BANCO';
    public const ESTADO_LIBERADA = 'LIBERADA';
    public const ESTADO_PAGADA = 'PAGADA';
    public const ESTADO_RECHAZADA = 'RECHAZADA';
    public const ESTADO_ANULADA = 'ANULADA';

    protected $fillable = [
        'empresa_id', 'numero', 'fecha', 'tipo',
        'auxiliar_id', 'liq_encabezado_id',
        'moneda_id', 'cotizacion',
        'importe', 'importe_bruto', 'total_retenciones',
        'estado',
        'fecha_carga_banco', 'fecha_liberacion', 'fecha_pago',
        'concepto', 'observaciones',
        'creado_por_user_id', 'cargado_por_user_id', 'liberado_por_user_id',
        'asiento_id', 'motivo_rechazo', 'motivo_anulacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_carga_banco' => 'datetime',
        'fecha_liberacion' => 'datetime',
        'fecha_pago' => 'datetime',
        'cotizacion' => 'decimal:4',
        'importe' => 'decimal:2',
        'importe_bruto' => 'decimal:2',
        'total_retenciones' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function auxiliar(): BelongsTo
    {
        return $this->belongsTo(Auxiliar::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }
}
