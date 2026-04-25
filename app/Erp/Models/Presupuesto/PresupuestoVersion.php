<?php

namespace App\Erp\Models\Presupuesto;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PresupuestoVersion extends Model
{
    public $timestamps = false;

    protected $table = 'erp_presupuesto_versiones';

    protected $fillable = ['presupuesto_id', 'evento', 'usuario_id', 'detalle'];

    protected $casts = ['created_at' => 'datetime'];

    public function presupuesto(): BelongsTo { return $this->belongsTo(Presupuesto::class); }
    public function usuario(): BelongsTo     { return $this->belongsTo(User::class, 'usuario_id'); }
}
