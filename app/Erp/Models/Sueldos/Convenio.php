<?php

namespace App\Erp\Models\Sueldos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Convenio extends Model
{
    protected $table = 'erp_emp_convenios';

    protected $fillable = ['codigo', 'nombre', 'descripcion', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class, 'convenio_id');
    }
}
