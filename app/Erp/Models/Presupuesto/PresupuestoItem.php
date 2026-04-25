<?php

namespace App\Erp\Models\Presupuesto;

use App\Erp\Models\CentroCosto;
use App\Erp\Models\CuentaContable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PresupuestoItem extends Model
{
    public $timestamps = false;

    protected $table = 'erp_presupuesto_items';

    protected $fillable = [
        'presupuesto_id', 'cuenta_id', 'centro_costo_id',
        'mes', 'importe', 'notas',
    ];

    protected $casts = [
        'mes'     => 'integer',
        'importe' => 'float',
    ];

    public function presupuesto(): BelongsTo { return $this->belongsTo(Presupuesto::class); }
    public function cuenta(): BelongsTo      { return $this->belongsTo(CuentaContable::class, 'cuenta_id'); }
    public function centroCosto(): BelongsTo { return $this->belongsTo(CentroCosto::class, 'centro_costo_id'); }
}
