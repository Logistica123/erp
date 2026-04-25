<?php

namespace App\Erp\Models\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EeccEmision extends Model
{
    protected $table = 'erp_eecc_emisiones';

    public $timestamps = false;

    protected $fillable = [
        'ejercicio_id', 'formato', 'incluir', 'path', 'hash',
        'profesional_firmante', 'matricula_firmante', 'observaciones',
        'ajuste_por_inflacion', 'generado_at', 'generado_user_id',
    ];

    protected $casts = [
        'incluir'              => 'array',
        'ajuste_por_inflacion' => 'bool',
        'generado_at'          => 'datetime',
    ];

    public function ejercicio(): BelongsTo
    {
        return $this->belongsTo(Ejercicio::class);
    }

    public function generador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_user_id');
    }
}
