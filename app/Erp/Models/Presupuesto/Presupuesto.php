<?php

namespace App\Erp\Models\Presupuesto;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Empresa;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Presupuesto extends Model
{
    protected $table = 'erp_presupuestos';

    protected $fillable = [
        'empresa_id', 'ejercicio_id', 'nombre', 'estado',
        'es_reforecast', 'forecast_base_id', 'moneda', 'descripcion',
        'creado_por', 'aprobado_por', 'aprobado_at',
        'vigente_desde', 'vigente_hasta',
    ];

    protected $casts = [
        'es_reforecast' => 'bool',
        'aprobado_at'   => 'datetime',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    public function empresa(): BelongsTo { return $this->belongsTo(Empresa::class); }
    public function ejercicio(): BelongsTo { return $this->belongsTo(Ejercicio::class); }
    public function creador(): BelongsTo  { return $this->belongsTo(User::class, 'creado_por'); }
    public function aprobador(): BelongsTo { return $this->belongsTo(User::class, 'aprobado_por'); }
    public function base(): BelongsTo     { return $this->belongsTo(self::class, 'forecast_base_id'); }

    public function items(): HasMany { return $this->hasMany(PresupuestoItem::class); }
    public function versiones(): HasMany { return $this->hasMany(PresupuestoVersion::class)->orderBy('created_at'); }

    public function esEditable(): bool   { return $this->estado === 'BORRADOR'; }
}
