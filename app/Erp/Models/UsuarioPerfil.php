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
        // Permisos por rol.
        $porRol = $this->roles()
            ->whereHas('permisos', fn ($q) => $q->where('codigo', $codigo))
            ->exists();
        if ($porRol) return true;

        // v1.29 — Permisos temporales (concesión por usuario con expiración).
        // Sebastián otorga el permiso a un user específico por X horas. Vencido
        // o revocado, el permiso desaparece automáticamente.
        return \Illuminate\Support\Facades\DB::table('erp_permisos_temporales')
            ->where('user_id', $this->user_id)
            ->where('permiso_codigo', $codigo)
            ->where('expira_at', '>', now())
            ->whereNull('revocado_at')
            ->exists();
    }

    /**
     * v1.29 — Marca un permiso temporal como usado (al ejecutarse la acción).
     * No falla si no hay permiso temporal activo (puede venir del rol).
     */
    public function marcarPermisoTemporalUsado(string $codigo): void
    {
        \Illuminate\Support\Facades\DB::table('erp_permisos_temporales')
            ->where('user_id', $this->user_id)
            ->where('permiso_codigo', $codigo)
            ->where('expira_at', '>', now())
            ->whereNull('revocado_at')
            ->whereNull('usado_at')
            ->update(['usado_at' => now()]);
    }

    public function estaBloqueado(): bool
    {
        return $this->bloqueado_hasta !== null && $this->bloqueado_hasta->isFuture();
    }
}
