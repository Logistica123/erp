<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Config extends Model
{
    protected $table = 'erp_config';
    const CREATED_AT = null;

    protected $fillable = [
        'empresa_id', 'clave', 'valor', 'tipo',
        'categoria', 'descripcion', 'editable',
    ];

    protected $casts = [
        'editable' => 'boolean',
        'updated_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function getValorTipadoAttribute(): mixed
    {
        return match ($this->tipo) {
            'INT' => (int) $this->valor,
            'DECIMAL' => (float) $this->valor,
            'BOOLEAN' => filter_var($this->valor, FILTER_VALIDATE_BOOLEAN),
            'JSON' => json_decode($this->valor, true),
            default => $this->valor,
        };
    }
}
