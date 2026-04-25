<?php

namespace App\Erp\Models\Af;

use App\Erp\Models\Auxiliar;
use App\Erp\Models\CentroCosto;
use App\Erp\Models\Empresa;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AfBien extends Model
{
    use SoftDeletes;

    protected $table = 'erp_af_bienes';

    protected $fillable = [
        'empresa_id', 'nro_inventario', 'categoria_id', 'descripcion',
        'marca', 'modelo', 'nro_serie', 'patente', 'fecha_alta',
        'factura_compra_id', 'proveedor_auxiliar_id',
        'valor_origen', 'moneda_origen', 'valor_origen_me', 'cotizacion_alta',
        'valor_residual_cfg', 'vida_util_contable_meses', 'vida_util_fiscal_meses',
        'centro_costo_id', 'responsable_user_id', 'ubicacion',
        'estado', 'fecha_baja', 'motivo_baja', 'valor_recupero', 'factura_venta_baja_id',
        'indice_alta', 'valor_reexpresado',
    ];

    protected $casts = [
        'fecha_alta'         => 'date',
        'fecha_baja'         => 'date',
        'valor_origen'       => 'float',
        'valor_origen_me'    => 'float',
        'cotizacion_alta'    => 'float',
        'valor_residual_cfg' => 'float',
        'valor_recupero'     => 'float',
        'indice_alta'        => 'float',
        'valor_reexpresado'  => 'float',
    ];

    public function empresa(): BelongsTo  { return $this->belongsTo(Empresa::class); }
    public function categoria(): BelongsTo { return $this->belongsTo(AfCategoria::class, 'categoria_id'); }
    public function centroCosto(): BelongsTo { return $this->belongsTo(CentroCosto::class, 'centro_costo_id'); }
    public function responsable(): BelongsTo { return $this->belongsTo(User::class, 'responsable_user_id'); }
    public function proveedor(): BelongsTo { return $this->belongsTo(Auxiliar::class, 'proveedor_auxiliar_id'); }
    public function facturaCompra(): BelongsTo { return $this->belongsTo(FacturaCompra::class, 'factura_compra_id'); }
    public function facturaVentaBaja(): BelongsTo { return $this->belongsTo(FacturaVenta::class, 'factura_venta_baja_id'); }

    public function movimientos(): HasMany { return $this->hasMany(AfMovimiento::class, 'bien_id')->orderBy('fecha')->orderBy('id'); }

    /** Vida útil efectiva (override del bien o default de la categoría). */
    public function vuContable(): int
    {
        return (int) ($this->vida_util_contable_meses ?? $this->categoria->vida_util_contable_meses);
    }

    public function vuFiscal(): int
    {
        return (int) ($this->vida_util_fiscal_meses ?? $this->categoria->vida_util_fiscal_meses);
    }

    /** Base amortizable = valor_origen − residual. */
    public function baseAmort(): float
    {
        $residualCfg = $this->valor_residual_cfg;
        if ($residualCfg !== null) {
            return round((float) $this->valor_origen - (float) $residualCfg, 2);
        }
        $pct = (float) $this->categoria->valor_residual_pct;
        return round((float) $this->valor_origen * (1 - $pct / 100), 2);
    }
}
