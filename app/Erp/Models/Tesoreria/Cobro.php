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

/**
 * Cobro de cliente (SPEC 02 §4.1, RN-27 balance multi-medios).
 * Estados: REGISTRADO → (PARCIAL_ACREDITADO) → ACREDITADO | RECHAZADO_PARCIAL | RECHAZADO | ANULADO.
 */
class Cobro extends Model
{
    protected $table = 'erp_cobros';

    public const ESTADO_REGISTRADO = 'REGISTRADO';
    public const ESTADO_PARCIAL_ACREDITADO = 'PARCIAL_ACREDITADO';
    public const ESTADO_ACREDITADO = 'ACREDITADO';
    public const ESTADO_RECHAZADO_PARCIAL = 'RECHAZADO_PARCIAL';
    public const ESTADO_RECHAZADO = 'RECHAZADO';
    public const ESTADO_ANULADO = 'ANULADO';

    protected $fillable = [
        'empresa_id', 'numero', 'fecha', 'auxiliar_id',
        'moneda_id', 'cotizacion', 'importe_total', 'total_retenciones',
        'estado', 'concepto', 'observaciones',
        'creado_por_user_id', 'asiento_id', 'motivo_anulacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'cotizacion' => 'decimal:4',
        'importe_total' => 'decimal:2',
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

    public function items(): HasMany
    {
        return $this->hasMany(CobroItem::class);
    }

    public function medios(): HasMany
    {
        return $this->hasMany(CobroMedio::class);
    }
}
