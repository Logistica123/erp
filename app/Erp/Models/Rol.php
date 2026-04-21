<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Rol extends Model
{
    protected $table = 'erp_roles';

    protected $fillable = [
        'empresa_id', 'codigo', 'nombre', 'descripcion',
        'nivel_jerarquia', 'protegido', 'activo',
    ];

    protected $casts = [
        'nivel_jerarquia' => 'integer',
        'protegido' => 'boolean',
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function permisos(): BelongsToMany
    {
        return $this->belongsToMany(
            Permiso::class,
            'erp_rol_permiso',
            'rol_id',
            'permiso_id'
        )->withPivot('created_at');
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(
            UsuarioPerfil::class,
            'erp_usuario_rol',
            'rol_id',
            'usuario_perfil_id'
        )->withPivot(['asignado_por', 'asignado_en', 'vigente_hasta']);
    }

    public function tienePermiso(string $codigo): bool
    {
        return $this->permisos()->where('codigo', $codigo)->exists();
    }
}
