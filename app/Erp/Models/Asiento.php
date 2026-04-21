<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asiento extends Model
{
    protected $table = 'erp_asientos';

    public const ESTADO_BORRADOR = 'BORRADOR';
    public const ESTADO_CONTABILIZADO = 'CONTABILIZADO';
    public const ESTADO_ANULADO = 'ANULADO';

    protected $fillable = [
        'empresa_id', 'ejercicio_id', 'periodo_id', 'diario_id',
        'numero', 'fecha', 'fecha_contabilizacion', 'glosa',
        'origen', 'origen_id', 'origen_tabla', 'estado', 'moneda_base',
        'total_debe', 'total_haber',
        // 'desbalance' es columna GENERATED — no se incluye en fillable.
        'usuario_id', 'usuario_modifico_id', 'usuario_anulo_id',
        'fecha_anulacion', 'motivo_anulacion', 'asiento_reversa_id',
        'hash_integridad',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_contabilizacion' => 'datetime',
        'fecha_anulacion' => 'datetime',
        'total_debe' => 'decimal:2',
        'total_haber' => 'decimal:2',
        'desbalance' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function ejercicio(): BelongsTo
    {
        return $this->belongsTo(Ejercicio::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function diario(): BelongsTo
    {
        return $this->belongsTo(Diario::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoAsiento::class);
    }

    public function asientoReversa(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_reversa_id');
    }
}
