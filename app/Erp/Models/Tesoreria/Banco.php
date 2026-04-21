<?php

namespace App\Erp\Models\Tesoreria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Banco extends Model
{
    protected $table = 'erp_bancos';

    protected $fillable = ['codigo', 'nombre', 'codigo_parser', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function cuentasBancarias(): HasMany
    {
        return $this->hasMany(CuentaBancaria::class);
    }
}
