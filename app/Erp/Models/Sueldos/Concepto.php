<?php

namespace App\Erp\Models\Sueldos;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Concepto extends Model
{
    protected $table = 'erp_emp_conceptos';

    protected $fillable = [
        'codigo', 'nombre', 'tipo', 'signo',
        'afecta_formal', 'afecta_efectivo', 'afecta_mt',
        'formula', 'cuenta_debe_id', 'cuenta_haber_id',
        'orden', 'activo',
    ];

    protected $casts = [
        'afecta_formal'   => 'boolean',
        'afecta_efectivo' => 'boolean',
        'afecta_mt'       => 'boolean',
        'orden'           => 'integer',
        'activo'          => 'boolean',
    ];

    public const TIPO_REMUNERATIVO    = 'REMUNERATIVO';
    public const TIPO_NO_REMUNERATIVO = 'NO_REMUNERATIVO';
    public const TIPO_DESCUENTO_LEGAL = 'DESCUENTO_LEGAL';
    public const TIPO_DESCUENTO_OTRO  = 'DESCUENTO_OTRO';
    public const TIPO_SAC             = 'SAC';
    public const TIPO_COMISION        = 'COMISION';
    public const TIPO_AJUSTE          = 'AJUSTE';

    public const SIGNO_HABER     = 'HABER';
    public const SIGNO_DESCUENTO = 'DESCUENTO';

    public function cuentaDebe(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_debe_id');
    }

    public function cuentaHaber(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_haber_id');
    }
}
