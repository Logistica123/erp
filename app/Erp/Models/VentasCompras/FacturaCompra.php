<?php

namespace App\Erp\Models\VentasCompras;

use App\Erp\Models\Asiento;
use App\Erp\Models\Auxiliar;
use App\Erp\Models\CentroCosto;
use App\Erp\Models\Empresa;
use App\Erp\Models\Fiscal\CondicionIva;
use App\Erp\Models\Fiscal\TipoComprobante;
use App\Erp\Models\Moneda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FacturaCompra extends Model
{
    use SoftDeletes;

    protected $table = 'erp_facturas_compra';

    protected $fillable = [
        'empresa_id', 'tipo_comprobante_id', 'punto_venta', 'numero',
        'cae', 'fecha_vto_cae', 'fecha_emision', 'fecha_recepcion', 'fecha_vencimiento',
        'fecha_imputacion', 'periodo_id', 'imputacion_diferida',
        'auxiliar_id', 'cuit_emisor', 'razon_social_emisor', 'condicion_iva_id',
        'moneda_id', 'cotizacion',
        'imp_neto_gravado', 'imp_no_gravado', 'imp_exento',
        'imp_iva', 'imp_tributos', 'imp_percepciones', 'imp_retenciones', 'imp_total',
        // v1.24 — desglose por alícuota IVA + tipo de percepción + impuestos como gasto
        'imp_iva_21', 'imp_iva_10_5', 'imp_iva_27', 'imp_iva_2_5', 'imp_iva_5',
        'imp_percepciones_iva', 'imp_percepciones_iibb', 'imp_percepciones_otros_nac',
        'imp_municipales', 'imp_internos', 'imp_otros_tributos',
        // v1.25 — neto gravado por alícuota IVA (par a los `imp_iva_*` del v1.24)
        'imp_neto_gravado_21', 'imp_neto_gravado_10_5', 'imp_neto_gravado_27',
        'imp_neto_gravado_2_5', 'imp_neto_gravado_5',
        'origen', 'estado', 'constatacion_estado',
        'observaciones', 'motivo_observacion', 'adjunto_url',
        'centro_costo_id', 'asiento_id',
        'created_by_user_id', 'controlada_by_user_id', 'controlada_at',
        // Addendum v1.13 (ex v1.9 reescrito) — import enriquecido del Libro IVA Compras
        'no_tomada', 'cliente_auxiliar_id', 'tipo_gasto', 'import_id',
        // Addendum v1.14 — período trabajado + jurisdicción IIBB
        'periodo_trabajado_texto', 'jurisdiccion_codigo',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_recepcion' => 'date',
        'fecha_vencimiento' => 'date',
        'fecha_vto_cae' => 'date',
        'fecha_imputacion' => 'date',
        'imputacion_diferida' => 'boolean',
        'no_tomada' => 'boolean',
        'controlada_at' => 'datetime',
        'cotizacion' => 'decimal:4',
        'imp_neto_gravado' => 'decimal:2',
        'imp_no_gravado' => 'decimal:2',
        'imp_exento' => 'decimal:2',
        'imp_iva' => 'decimal:2',
        'imp_tributos' => 'decimal:2',
        'imp_percepciones' => 'decimal:2',
        'imp_retenciones' => 'decimal:2',
        'imp_total' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function tipoComprobante(): BelongsTo
    {
        return $this->belongsTo(TipoComprobante::class);
    }

    public function auxiliar(): BelongsTo
    {
        return $this->belongsTo(Auxiliar::class);
    }

    public function condicionIva(): BelongsTo
    {
        return $this->belongsTo(CondicionIva::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class);
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FacturaCompraItem::class, 'factura_id');
    }

    public function iva(): HasMany
    {
        return $this->hasMany(FacturaCompraIva::class, 'factura_id');
    }

    public function tributos(): HasMany
    {
        return $this->hasMany(FacturaCompraTributo::class, 'factura_id');
    }

    public function asociadas(): HasMany
    {
        return $this->hasMany(FacturaCompraAsociada::class, 'factura_id');
    }

    public function constataciones(): HasMany
    {
        return $this->hasMany(ComprobanteConstatacion::class, 'factura_compra_id');
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(\App\Erp\Models\Periodo::class, 'periodo_id');
    }

    public function clienteAuxiliar(): BelongsTo
    {
        return $this->belongsTo(\App\Erp\Models\Auxiliar::class, 'cliente_auxiliar_id');
    }

    public function importLibroIva(): BelongsTo
    {
        return $this->belongsTo(LibroIvaComprasImport::class, 'import_id');
    }
}
