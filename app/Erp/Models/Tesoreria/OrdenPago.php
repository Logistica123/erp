<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\Auxiliar;
use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenPago extends Model
{
    protected $table = 'erp_ordenes_pago';

    public const ESTADO_BORRADOR = 'BORRADOR';
    public const ESTADO_EMITIDA = 'EMITIDA'; // v1.35
    public const ESTADO_CARGADA_BANCO = 'CARGADA_BANCO';
    public const ESTADO_LIBERADA = 'LIBERADA';
    public const ESTADO_PAGADA = 'PAGADA';
    public const ESTADO_RECHAZADA = 'RECHAZADA';
    public const ESTADO_ANULADA = 'ANULADA';

    public const ORIGEN_LOCAL = 'LOCAL';
    public const ORIGEN_DISTRIAPP = 'DISTRIAPP';

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
        // v1.35
        'origen', 'distriapp_op_id', 'distriapp_concepto_id', 'distriapp_numero_correlativo',
        'tipo_op_id', 'beneficiario_snapshot',
        'cotizacion_usd', 'importe_ars_equivalente',
        'medio_pago', 'cuenta_bancaria_pago_id', 'referencia_pago',
        'contabilizada', 'fecha_contabilizada', 'contabilizada_por_user_id',
        'sync_ultima_actualizacion', 'sync_hash', 'sync_payload_completo',
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
        // v1.35
        'beneficiario_snapshot' => 'array',
        'sync_payload_completo' => 'array',
        'cotizacion_usd' => 'decimal:4',
        'importe_ars_equivalente' => 'decimal:2',
        'contabilizada' => 'boolean',
        'fecha_contabilizada' => 'datetime',
        'sync_ultima_actualizacion' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(OpItem::class, 'op_id')->orderBy('orden');
    }

    public function medios(): HasMany
    {
        return $this->hasMany(OpMedio::class, 'op_id');
    }

    // v1.35
    public function tipoOp(): BelongsTo
    {
        return $this->belongsTo(OrdenPagoTipo::class, 'tipo_op_id');
    }

    public function auditoria(): HasMany
    {
        return $this->hasMany(OrdenPagoAudit::class, 'op_id')->orderByDesc('created_at');
    }
}
