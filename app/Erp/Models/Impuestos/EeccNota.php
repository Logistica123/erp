<?php

namespace App\Erp\Models\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EeccNota extends Model
{
    protected $table = 'erp_eecc_notas';

    protected $fillable = [
        'ejercicio_id', 'numero', 'titulo', 'contenido',
        'editado_user_id', 'editado_at',
    ];

    protected $casts = [
        'editado_at' => 'datetime',
    ];

    public function ejercicio(): BelongsTo
    {
        return $this->belongsTo(Ejercicio::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_user_id');
    }
}
