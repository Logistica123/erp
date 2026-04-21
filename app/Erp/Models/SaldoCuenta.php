<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaldoCuenta extends Model
{
    protected $table = 'erp_saldos_cuenta';
    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'cuenta_id', 'periodo_id',
        'auxiliar_id', 'centro_costo_id',
        'saldo_inicial', 'debitos', 'creditos', 'saldo_final',
        'actualizado_en',
    ];

    protected $casts = [
        'saldo_inicial' => 'decimal:2',
        'debitos' => 'decimal:2',
        'creditos' => 'decimal:2',
        'saldo_final' => 'decimal:2',
        'actualizado_en' => 'datetime',
    ];

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_id');
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function auxiliar(): BelongsTo
    {
        return $this->belongsTo(Auxiliar::class, 'auxiliar_id');
    }

    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }
}
