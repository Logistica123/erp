<?php

namespace App\Erp\Models\Tesoreria;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de cambios de estado de un eCheq. Insertado por el trigger
 * trg_echeq_historial_au cada vez que erp_echeq.estado cambia.
 */
class EcheqMovimiento extends Model
{
    protected $table = 'erp_echeq_movimientos';
    public $timestamps = false;

    protected $fillable = [
        'echeq_id', 'estado_anterior', 'estado_nuevo',
        'motivo', 'user_id', 'fecha',
    ];

    protected $casts = ['fecha' => 'datetime'];

    public function echeq(): BelongsTo
    {
        return $this->belongsTo(Echeq::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
