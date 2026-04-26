<?php

namespace App\Erp\Models\Tesoreria;

use App\Erp\Models\CuentaContable;
use App\Erp\Models\Empresa;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cache de asignaciones manuales nombre -> persona/cliente. Se materializa la
 * primera vez que un operador asigna manualmente; en posteriores extractos el
 * matching se resuelve solo via este alias con confianza 100.
 */
class AliasContraparte extends Model
{
    protected $table = 'erp_alias_contraparte';
    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'banco_id', 'alias_normalizado',
        'persona_id', 'cliente_id', 'cuenta_contable_id',
        'confianza', 'asignado_por', 'asignado_at',
    ];

    protected $casts = [
        'confianza' => 'integer',
        'asignado_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class);
    }

    public function cuentaContable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class);
    }

    public function asignadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_por');
    }

    /**
     * Normaliza un nombre/concepto: uppercase, trim, collapse whitespace,
     * reemplaza S.R.L./S.A. variantes y quita prefijos cortesía.
     */
    public static function normalizar(string $nombre): string
    {
        $s = mb_strtoupper(trim($nombre));
        // Cortesías habituales antes de nombres.
        $s = preg_replace('/^(SR\.?\s+|SRA\.?\s+|DR\.?\s+|DRA\.?\s+)/u', '', $s) ?? $s;
        // S.R.L. -> SRL, S.A. -> SA (sin puntos).
        $s = preg_replace('/\bS\.R\.L\.?\b/u', 'SRL', $s) ?? $s;
        $s = preg_replace('/\bS\.A\.?\b/u', 'SA', $s) ?? $s;
        // Caracteres no relevantes.
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        // Collapse whitespace.
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
}
