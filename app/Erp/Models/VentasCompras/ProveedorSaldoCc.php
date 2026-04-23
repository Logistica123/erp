<?php

namespace App\Erp\Models\VentasCompras;

use App\Erp\Models\Auxiliar;
use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProveedorSaldoCc extends Model
{
    protected $table = 'erp_proveedor_saldos_cc';

    public const CREATED_AT = null;

    protected $fillable = [
        'empresa_id', 'auxiliar_id', 'moneda_id',
        'saldo_actual', 'saldo_vencido', 'ultimo_movimiento_at',
    ];

    protected $casts = [
        'saldo_actual' => 'decimal:2',
        'saldo_vencido' => 'decimal:2',
        'ultimo_movimiento_at' => 'datetime',
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
}
