<?php

namespace App\Erp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UsuarioPerfil extends Model
{
    use SoftDeletes;

    protected $table = 'erp_usuario_perfil';

    protected $fillable = [
        'user_id', 'empresa_id', 'legajo',
        'mfa_habilitado', 'mfa_secret',
        'ultimo_login', 'ultimo_ip',
        'intentos_fallidos', 'bloqueado_hasta',
        'acceso_erp',
    ];

    protected $hidden = [
        'mfa_secret',
    ];

    protected $casts = [
        'mfa_habilitado' => 'boolean',
        'acceso_erp' => 'boolean',
        'ultimo_login' => 'datetime',
        'bloqueado_hasta' => 'datetime',
        'intentos_fallidos' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Rol::class,
            'erp_usuario_rol',
            'usuario_perfil_id',
            'rol_id'
        )->withPivot(['asignado_por', 'asignado_en', 'vigente_hasta']);
    }

    public function tienePermiso(string $codigo): bool
    {
        return $this->roles()
            ->whereHas('permisos', fn ($q) => $q->where('codigo', $codigo))
            ->exists();
    }

    public function estaBloqueado(): bool
    {
        return $this->bloqueado_hasta !== null && $this->bloqueado_hasta->isFuture();
    }
}
