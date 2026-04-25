<?php

namespace App\Erp\Models\Sueldos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Categoria extends Model
{
    protected $table = 'erp_emp_categorias';

    protected $fillable = [
        'convenio_id', 'codigo', 'nombre',
        'nivel_jerarquia', 'descripcion', 'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
        'nivel_jerarquia' => 'integer',
    ];

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class, 'convenio_id');
    }
}
