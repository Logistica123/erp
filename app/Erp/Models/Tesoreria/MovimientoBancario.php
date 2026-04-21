<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoBancario extends Model
{
    protected $table = 'erp_movimientos_bancarios';

    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_ETIQUETADO = 'ETIQUETADO';
    public const ESTADO_CONCILIADO = 'CONCILIADO';
    public const ESTADO_IGNORADO = 'IGNORADO';

    protected $fillable = [
        'extracto_id', 'cuenta_bancaria_id',
        'fecha', 'fecha_valor', 'concepto', 'comprobante_banco',
        'debito', 'credito', 'saldo',
        'estado', 'etiqueta_sugerida',
        'cuenta_contable_propuesta_id', 'asiento_id',
        'motivo_ignorado_id', 'observacion', 'hash_linea',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_valor' => 'date',
        'debito' => 'decimal:2',
        'credito' => 'decimal:2',
        'saldo' => 'decimal:2',
    ];

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class);
    }

    public function extracto(): BelongsTo
    {
        return $this->belongsTo(ExtractoBancario::class, 'extracto_id');
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    public function cuentaContablePropuesta(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_propuesta_id');
    }
}
