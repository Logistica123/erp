<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\Auxiliar;
use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * v1.31 — Recibo de cobranza. Junta factura + NC aplicadas + retenciones + cobro
 * en un único documento. Reemplaza el flow de cobros sueltos del v1.15.
 *
 * Estados: BORRADOR → EMITIDO (genera asiento) → CONCILIADO (vs extracto banco)
 *          o ANULADO (reversa). EMITIDO+ es read-only excepto anulación.
 */
class Recibo extends Model
{
    protected $table = 'erp_recibos';

    public const ESTADO_BORRADOR = 'BORRADOR';
    public const ESTADO_EMITIDO = 'EMITIDO';
    public const ESTADO_CONCILIADO = 'CONCILIADO';
    public const ESTADO_ANULADO = 'ANULADO';

    public $timestamps = false; // Manejamos created_at + emitido_at + anulado_at manualmente.

    protected $fillable = [
        'empresa_id', 'numero_correlativo', 'fecha_emision',
        'cliente_auxiliar_id', 'factura_venta_id',
        'total_factura', 'total_nc_aplicadas', 'total_retenciones',
        'monto_cobrable', 'monto_cobrado', 'saldo_factura_post',
        'medio_cobro_id', 'cae', 'estado',
        'asiento_id', 'mov_bancario_id',
        'observaciones',
        'created_by_user_id', 'created_at', 'emitido_at',
        'anulado_at', 'anulado_por_user_id', 'anulado_motivo',
        'migrado_de_cobro_id', 'migracion_fecha',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'total_factura' => 'decimal:2',
        'total_nc_aplicadas' => 'decimal:2',
        'total_retenciones' => 'decimal:2',
        'monto_cobrable' => 'decimal:2',
        'monto_cobrado' => 'decimal:2',
        'saldo_factura_post' => 'decimal:2',
        'created_at' => 'datetime',
        'emitido_at' => 'datetime',
        'anulado_at' => 'datetime',
        'migracion_fecha' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Auxiliar::class, 'cliente_auxiliar_id');
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_venta_id');
    }

    public function medioCobro(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'medio_cobro_id');
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    public function ncAplicadas(): HasMany
    {
        return $this->hasMany(ReciboNcAplicada::class);
    }

    public function retenciones(): HasMany
    {
        return $this->hasMany(ReciboRetencion::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
