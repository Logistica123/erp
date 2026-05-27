<?php

namespace App\Erp\Models\VentasCompras;

use App\Erp\Models\Asiento;
use App\Erp\Models\Auxiliar;
use App\Erp\Models\CentroCosto;
use App\Erp\Models\Empresa;
use App\Erp\Models\Fiscal\CondicionIva;
use App\Erp\Models\Fiscal\PuntoVenta;
use App\Erp\Models\Fiscal\TipoComprobante;
use App\Erp\Models\Moneda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class FacturaVenta extends Model
{
    use SoftDeletes;

    protected $table = 'erp_facturas_venta';

    protected $fillable = [
        'empresa_id', 'tipo_comprobante_id', 'punto_venta_id', 'numero',
        'cae', 'fecha_vto_cae', 'fecha_emision', 'fecha_vencimiento',
        'fecha_servicio_desde', 'fecha_servicio_hasta',
        'auxiliar_id', 'condicion_iva_id', 'doc_tipo_afip', 'doc_nro',
        'moneda_id', 'cotizacion',
        'concepto_afip', 'condicion_venta',
        'imp_neto_gravado', 'imp_no_gravado', 'imp_exento',
        'imp_iva', 'imp_tributos', 'imp_total',
        'es_fce', 'cbu_beneficiario', 'alias_beneficiario', 'saldo_aceptacion',
        'liq_id', 'origen', 'estado', 'estado_fce',
        'observaciones', 'centro_costo_id', 'asiento_id', 'created_by_user_id',
        // v1.43 — desglose IVA por alícuota.
        'imp_iva_27', 'imp_iva_21', 'imp_iva_10_5', 'imp_iva_5', 'imp_iva_2_5',
        'imp_neto_gravado_27', 'imp_neto_gravado_21', 'imp_neto_gravado_10_5',
        'imp_neto_gravado_5', 'imp_neto_gravado_2_5',
        // v1.45 — import del Libro IVA Ventas + metadata extra.
        'import_id', 'periodo_trabajado_texto', 'jurisdiccion_codigo',
        'pdf_path',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_vencimiento' => 'date',
        'fecha_vto_cae' => 'date',
        'fecha_servicio_desde' => 'date',
        'fecha_servicio_hasta' => 'date',
        'cotizacion' => 'decimal:4',
        'imp_neto_gravado' => 'decimal:2',
        'imp_no_gravado' => 'decimal:2',
        'imp_exento' => 'decimal:2',
        'imp_iva' => 'decimal:2',
        'imp_tributos' => 'decimal:2',
        'imp_total' => 'decimal:2',
        'saldo_aceptacion' => 'decimal:2',
        'es_fce' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function tipoComprobante(): BelongsTo
    {
        return $this->belongsTo(TipoComprobante::class);
    }

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class);
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
        return $this->hasMany(FacturaVentaItem::class, 'factura_id');
    }

    /** v1.51 — Reparto de base imponible IIBB por jurisdicción. */
    public function jurisdicciones(): HasMany
    {
        return $this->hasMany(FacturaVentaJurisdiccion::class, 'factura_venta_id');
    }

    public function iva(): HasMany
    {
        return $this->hasMany(FacturaVentaIva::class, 'factura_id');
    }

    public function tributos(): HasMany
    {
        return $this->hasMany(FacturaVentaTributo::class, 'factura_id');
    }

    public function cae(): HasOne
    {
        return $this->hasOne(FacturaVentaCae::class, 'factura_venta_id');
    }

    public function asociadas(): HasMany
    {
        return $this->hasMany(FacturaVentaAsociada::class, 'factura_id');
    }
}
