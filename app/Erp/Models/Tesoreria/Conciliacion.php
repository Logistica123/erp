<?php

namespace App\Erp\Models\Tesoreria;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conciliación polimórfica entre un movimiento bancario y su origen
 * (OP, Cobro, TransferenciaInterna, AsientoManual, Echeq, ReglaAuto).
 *
 * SPEC 02 RN-14: un movimiento puede conciliarse contra varios orígenes
 * (1-a-N) pero un origen no se concilia contra varios movimientos (N-a-1
 * está prohibido — para partidas fraccionadas se usan OP/Cobros separados).
 */
class Conciliacion extends Model
{
    protected $table = 'erp_conciliaciones';
    public $timestamps = false;

    public const REF_ORDEN_PAGO = 'ORDEN_PAGO';
    public const REF_COBRO = 'COBRO';
    public const REF_TRANSFERENCIA_INTERNA = 'TRANSFERENCIA_INTERNA';
    public const REF_ASIENTO_MANUAL = 'ASIENTO_MANUAL';
    public const REF_ECHEQ = 'ECHEQ';
    public const REF_REGLA_AUTO = 'REGLA_AUTO';

    public const MODO_MANUAL = 'MANUAL';
    public const MODO_AUTO = 'AUTO';

    protected $fillable = [
        'movimiento_bancario_id',
        'referencia_tipo', 'referencia_id',
        'importe_conciliado', 'user_id', 'modo', 'observacion',
    ];

    protected $casts = [
        'importe_conciliado' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function movimientoBancario(): BelongsTo
    {
        return $this->belongsTo(MovimientoBancario::class, 'movimiento_bancario_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resuelve la entidad referenciada (referencia_tipo + referencia_id) como
     * una instancia del modelo correspondiente. Morph manual: la columna
     * referencia_tipo es un ENUM, no un classname, por eso no se usa MorphTo
     * directo de Eloquent.
     */
    public function referencia()
    {
        $map = [
            self::REF_ORDEN_PAGO => OrdenPago::class,
            self::REF_COBRO => Cobro::class,
            self::REF_TRANSFERENCIA_INTERNA => TransferenciaInterna::class,
            self::REF_ASIENTO_MANUAL => \App\Erp\Models\Asiento::class,
            self::REF_ECHEQ => Echeq::class,
            self::REF_REGLA_AUTO => ConciliacionRegla::class,
        ];

        $class = $map[$this->referencia_tipo] ?? null;

        return $class ? $class::find($this->referencia_id) : null;
    }
}
