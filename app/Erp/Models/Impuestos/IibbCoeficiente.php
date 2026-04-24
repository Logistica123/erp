<?php

namespace App\Erp\Models\Impuestos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IibbCoeficiente extends Model
{
    protected $table = 'erp_iibb_coeficientes';

    protected $fillable = [
        'anio_vigencia', 'jurisdiccion', 'coeficiente',
        'origen', 'estado', 'aprobado_at', 'aprobado_user_id',
    ];

    protected $casts = [
        'aprobado_at' => 'datetime',
    ];

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_user_id');
    }
}
