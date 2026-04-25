<?php

namespace App\Erp\Models\Af;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AfCategoria extends Model
{
    protected $table = 'erp_af_categorias';

    protected $fillable = [
        'codigo', 'nombre', 'descripcion',
        'vida_util_contable_meses', 'vida_util_fiscal_meses',
        'valor_residual_pct', 'metodo_amortizacion',
        'cuenta_bien_id', 'cuenta_amort_acum_id', 'cuenta_amort_ejercicio_id',
        'cuenta_resultado_baja_pos_id', 'cuenta_resultado_baja_neg_id',
        'umbral_baja_cuantia', 'activa',
    ];

    protected $casts = [
        'valor_residual_pct'       => 'float',
        'umbral_baja_cuantia'      => 'float',
        'vida_util_contable_meses' => 'integer',
        'vida_util_fiscal_meses'   => 'integer',
        'activa'                   => 'bool',
    ];

    public function bienes(): HasMany
    {
        return $this->hasMany(AfBien::class, 'categoria_id');
    }

    public function cuentaBien(): BelongsTo       { return $this->belongsTo(CuentaContable::class, 'cuenta_bien_id'); }
    public function cuentaAmortAcum(): BelongsTo  { return $this->belongsTo(CuentaContable::class, 'cuenta_amort_acum_id'); }
    public function cuentaAmortGasto(): BelongsTo { return $this->belongsTo(CuentaContable::class, 'cuenta_amort_ejercicio_id'); }
    public function cuentaResultPos(): BelongsTo  { return $this->belongsTo(CuentaContable::class, 'cuenta_resultado_baja_pos_id'); }
    public function cuentaResultNeg(): BelongsTo  { return $this->belongsTo(CuentaContable::class, 'cuenta_resultado_baja_neg_id'); }
}
