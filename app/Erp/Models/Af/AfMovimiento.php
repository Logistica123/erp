<?php

namespace App\Erp\Models\Af;

use App\Erp\Models\Asiento;
use App\Erp\Models\CentroCosto;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AfMovimiento extends Model
{
    public $timestamps = false;

    protected $table = 'erp_af_movimientos';

    protected $fillable = [
        'bien_id', 'tipo', 'fecha', 'importe',
        'cc_anterior_id', 'cc_nuevo_id',
        'responsable_anterior_id', 'responsable_nuevo_id',
        'ubicacion_anterior', 'ubicacion_nueva',
        'descripcion', 'asiento_id', 'factura_compra_id', 'usuario_id',
    ];

    protected $casts = [
        'fecha'      => 'date',
        'importe'    => 'float',
        'created_at' => 'datetime',
    ];

    public function bien(): BelongsTo  { return $this->belongsTo(AfBien::class, 'bien_id'); }
    public function asiento(): BelongsTo { return $this->belongsTo(Asiento::class); }
    public function ccAnterior(): BelongsTo { return $this->belongsTo(CentroCosto::class, 'cc_anterior_id'); }
    public function ccNuevo(): BelongsTo { return $this->belongsTo(CentroCosto::class, 'cc_nuevo_id'); }
    public function respAnterior(): BelongsTo { return $this->belongsTo(User::class, 'responsable_anterior_id'); }
    public function respNuevo(): BelongsTo { return $this->belongsTo(User::class, 'responsable_nuevo_id'); }
    public function facturaCompra(): BelongsTo { return $this->belongsTo(FacturaCompra::class, 'factura_compra_id'); }
    public function usuario(): BelongsTo { return $this->belongsTo(User::class, 'usuario_id'); }
}
