<?php

namespace App\Erp\Models\Af;

use App\Erp\Models\Asiento;
use App\Erp\Models\Ejercicio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AfReexpresion extends Model
{
    public $timestamps = false;

    protected $table = 'erp_af_reexpresiones';

    protected $fillable = [
        'bien_id', 'ejercicio_id',
        'indice_origen', 'indice_cierre', 'coeficiente',
        'valor_original', 'valor_reexpresado', 'resultado_exposicion',
        'asiento_id',
    ];

    protected $casts = [
        'indice_origen' => 'float', 'indice_cierre' => 'float', 'coeficiente' => 'float',
        'valor_original' => 'float', 'valor_reexpresado' => 'float',
        'resultado_exposicion' => 'float',
        'created_at' => 'datetime',
    ];

    public function bien(): BelongsTo { return $this->belongsTo(AfBien::class, 'bien_id'); }
    public function ejercicio(): BelongsTo { return $this->belongsTo(Ejercicio::class); }
    public function asiento(): BelongsTo { return $this->belongsTo(Asiento::class); }
}
