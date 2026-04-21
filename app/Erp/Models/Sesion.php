<?php

namespace App\Erp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sesion extends Model
{
    protected $table = 'erp_sesiones';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id', 'user_id', 'empresa_id',
        'mfa_verificado', 'ip', 'user_agent',
        'inicio', 'ultimo_uso', 'expira_en',
        'cerrada_en', 'motivo_cierre',
    ];

    protected $casts = [
        'mfa_verificado' => 'boolean',
        'inicio' => 'datetime',
        'ultimo_uso' => 'datetime',
        'expira_en' => 'datetime',
        'cerrada_en' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function estaActiva(): bool
    {
        return $this->cerrada_en === null && $this->expira_en->isFuture();
    }
}
