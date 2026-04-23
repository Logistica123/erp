<?php

namespace App\Erp\Models\Fiscal;

use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlicuotaIva extends Model
{
    protected $table = 'erp_alicuotas_iva';
    public $timestamps = false;
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id', 'codigo_interno', 'nombre', 'tasa',
        'cuenta_debito_fiscal_id', 'cuenta_credito_fiscal_id', 'activo',
    ];

    protected $casts = [
        'tasa' => 'decimal:4',
        'activo' => 'boolean',
    ];

    public function cuentaDebitoFiscal(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_debito_fiscal_id');
    }

    public function cuentaCreditoFiscal(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_credito_fiscal_id');
    }
}
