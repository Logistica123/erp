<?php

namespace App\Erp\Models\Tesoreria;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExtractoBancario extends Model
{
    protected $table = 'erp_extractos_bancarios';

    public $timestamps = false;
    const CREATED_AT = 'importado_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'cuenta_bancaria_id', 'fecha_desde', 'fecha_hasta',
        'hash_archivo', 'nombre_archivo', 'ruta_archivo',
        'saldo_inicial', 'saldo_final', 'cant_movimientos',
        'importado_por_user_id', 'importado_at', 'observaciones',
    ];

    protected $casts = [
        'fecha_desde' => 'date',
        'fecha_hasta' => 'date',
        'saldo_inicial' => 'decimal:2',
        'saldo_final' => 'decimal:2',
        'cant_movimientos' => 'integer',
        'importado_at' => 'datetime',
    ];

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class);
    }

    public function importadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'importado_por_user_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoBancario::class, 'extracto_id');
    }
}
