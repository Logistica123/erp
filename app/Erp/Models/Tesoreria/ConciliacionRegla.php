<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\Auxiliar;
use App\Erp\Models\CentroCosto;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Diario;
use App\Erp\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regla de auto-conciliación (complemento de erp_mapeo_etiqueta_cuenta).
 * Tipos: CONCEPTO_REGEX (patron sobre concepto del movimiento),
 *        IMPORTE_EXACTO (rango desde/hasta), COMBINADA (ambas).
 */
class ConciliacionRegla extends Model
{
    protected $table = 'erp_conciliacion_reglas';

    public const TIPO_CONCEPTO_REGEX = 'CONCEPTO_REGEX';
    public const TIPO_IMPORTE_EXACTO = 'IMPORTE_EXACTO';
    public const TIPO_COMBINADA = 'COMBINADA';

    protected $fillable = [
        'empresa_id', 'codigo', 'descripcion', 'tipo',
        'patron_concepto', 'patron_importe_desde', 'patron_importe_hasta',
        'cuenta_contable_id', 'auxiliar_id', 'centro_costo_id', 'diario_id',
        'orden_prioridad', 'activa',
    ];

    protected $casts = [
        'patron_importe_desde' => 'decimal:2',
        'patron_importe_hasta' => 'decimal:2',
        'orden_prioridad' => 'integer',
        'activa' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cuentaContable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class);
    }

    public function auxiliar(): BelongsTo
    {
        return $this->belongsTo(Auxiliar::class);
    }

    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class);
    }

    public function diario(): BelongsTo
    {
        return $this->belongsTo(Diario::class);
    }
}
