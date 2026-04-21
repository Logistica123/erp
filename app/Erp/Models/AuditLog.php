<?php

namespace App\Erp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log inmutable. Insert-only por diseño (ver handoff §8.3).
 * No se permiten UPDATE/DELETE desde la aplicación.
 */
class AuditLog extends Model
{
    protected $table = 'erp_audit_log';
    public $timestamps = false;
    const UPDATED_AT = null;

    protected $fillable = [
        'empresa_id', 'user_id',
        'modulo', 'entidad', 'entidad_id', 'accion', 'descripcion',
        'datos_antes', 'datos_despues',
        'ip', 'user_agent',
        'hash_prev', 'hash_actual',
        'created_at',
    ];

    protected $casts = [
        'datos_antes' => 'array',
        'datos_despues' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('AuditLog is insert-only'));
        static::deleting(fn () => throw new \LogicException('AuditLog is insert-only'));
    }
}
