<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permiso extends Model
{
    protected $table = 'erp_permisos';
    public $timestamps = false;

    protected $fillable = [
        'codigo', 'modulo', 'entidad', 'accion', 'descripcion', 'sensible',
    ];

    protected $casts = [
        'sensible' => 'boolean',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Rol::class,
            'erp_rol_permiso',
            'permiso_id',
            'rol_id'
        );
    }
}
