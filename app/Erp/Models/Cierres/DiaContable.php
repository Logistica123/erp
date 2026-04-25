<?php

namespace App\Erp\Models\Cierres;

use App\Erp\Models\Asiento;
use App\Erp\Models\Empresa;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Día contable (anexo Cierres Diarios §4.1).
 *
 * Estados:
 *   ABIERTO    — registro creado, sin movs procesados.
 *   EN_PROCESO — import en curso (parsers + reglas) o esperando decisión del usuario.
 *   CERRADO    — sellado. Movs estampados. Saldos cierre snapshot.
 *   REAPERTO   — reabierto por super_admin (caso edge).
 */
class DiaContable extends Model
{
    protected $table = 'erp_dias_contables';

    public const ESTADO_ABIERTO    = 'ABIERTO';
    public const ESTADO_EN_PROCESO = 'EN_PROCESO';
    public const ESTADO_CERRADO    = 'CERRADO';
    public const ESTADO_REAPERTO   = 'REAPERTO';

    protected $fillable = [
        'empresa_id', 'fecha', 'estado',
        'saldos_apertura', 'saldos_cierre',
        'total_movimientos', 'total_conciliados', 'total_pendientes', 'total_ignorados',
        'asiento_cierre_id', 'cerrado_por', 'cerrado_at',
        'observaciones',
    ];

    protected $casts = [
        'fecha'             => 'date',
        'saldos_apertura'   => 'array',
        'saldos_cierre'     => 'array',
        'total_movimientos' => 'integer',
        'total_conciliados' => 'integer',
        'total_pendientes'  => 'integer',
        'total_ignorados'   => 'integer',
        'cerrado_at'        => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cerrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    public function asientoCierre(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_cierre_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoBancario::class, 'dia_contable_id');
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(AjusteRetroactivo::class, 'fecha_dia_afectado', 'fecha');
    }

    public function esCerrado(): bool
    {
        return $this->estado === self::ESTADO_CERRADO;
    }

    public function esEditable(): bool
    {
        return in_array($this->estado, [self::ESTADO_ABIERTO, self::ESTADO_EN_PROCESO, self::ESTADO_REAPERTO], true);
    }
}
