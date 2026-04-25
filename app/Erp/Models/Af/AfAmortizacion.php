<?php

namespace App\Erp\Models\Af;

use App\Erp\Models\Asiento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AfAmortizacion extends Model
{
    public $timestamps = false;

    protected $table = 'erp_af_amortizaciones';

    protected $fillable = [
        'bien_id', 'periodo_anio', 'periodo_mes',
        'base_amort_contable', 'amort_contable_mes', 'amort_contable_acum',
        'base_amort_fiscal',   'amort_fiscal_mes',   'amort_fiscal_acum',
        'asiento_id', 'generado_at',
    ];

    protected $casts = [
        'periodo_anio'        => 'integer',
        'periodo_mes'         => 'integer',
        'base_amort_contable' => 'float',
        'amort_contable_mes'  => 'float',
        'amort_contable_acum' => 'float',
        'base_amort_fiscal'   => 'float',
        'amort_fiscal_mes'    => 'float',
        'amort_fiscal_acum'   => 'float',
        'diferencia_mes'      => 'float',
        'generado_at'         => 'datetime',
        'created_at'          => 'datetime',
    ];

    public function bien(): BelongsTo  { return $this->belongsTo(AfBien::class, 'bien_id'); }
    public function asiento(): BelongsTo { return $this->belongsTo(Asiento::class); }
}
