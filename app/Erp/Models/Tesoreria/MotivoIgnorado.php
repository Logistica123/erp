<?php

namespace App\Erp\Models\Tesoreria;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de motivos para marcar un movimiento bancario como IGNORADO
 * (SPEC 02 RN-26 exige motivo al ignorar).
 */
class MotivoIgnorado extends Model
{
    protected $table = 'erp_motivos_ignorado';
    public $timestamps = false;

    protected $fillable = ['codigo', 'descripcion', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}
