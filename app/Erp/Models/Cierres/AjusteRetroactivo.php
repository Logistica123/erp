<?php

namespace App\Erp\Models\Cierres;

use App\Erp\Models\Asiento;
use App\Erp\Models\Empresa;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste retroactivo a un día ya cerrado (anexo §4.2 + RN-CD-6).
 *
 * El día original NO se modifica. Se genera un asiento forward con fecha_actual
 * y glosa "Ajuste retroactivo del DD/MM/YYYY · {motivo}" y se registra acá.
 */
class AjusteRetroactivo extends Model
{
    protected $table = 'erp_ajustes_retroactivos';
    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'fecha_dia_afectado',
        'fecha_asiento_ajuste',
        'asiento_ajuste_id',
        'motivo',
        'iniciado_por',
        'iniciado_at',
        'movimiento_origen_id',
    ];

    protected $casts = [
        'fecha_dia_afectado'   => 'date',
        'fecha_asiento_ajuste' => 'date',
        'iniciado_at'          => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class, 'asiento_ajuste_id');
    }

    public function iniciador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }

    public function movimientoOrigen(): BelongsTo
    {
        return $this->belongsTo(MovimientoBancario::class, 'movimiento_origen_id');
    }
}
