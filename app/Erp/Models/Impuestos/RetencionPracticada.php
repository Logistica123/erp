<?php

namespace App\Erp\Models\Impuestos;

use App\Erp\Models\Auxiliar;
use App\Erp\Models\Empresa;
use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Models\VentasCompras\FacturaCompra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetencionPracticada extends Model
{
    protected $table = 'erp_retenciones_practicadas';

    protected $fillable = [
        'empresa_id', 'factura_compra_id', 'orden_pago_id', 'proveedor_id',
        'cuit_retenido', 'tipo_retencion', 'regimen',
        'fecha_emision', 'base_imponible', 'alicuota', 'importe_retenido',
        'nro_certificado', 'estado', 'comprobante_origen', 'periodo_id',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function ordenPago(): BelongsTo
    {
        return $this->belongsTo(OrdenPago::class, 'orden_pago_id');
    }

    public function facturaCompra(): BelongsTo
    {
        return $this->belongsTo(FacturaCompra::class, 'factura_compra_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Auxiliar::class, 'proveedor_id');
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoFiscal::class, 'periodo_id');
    }
}
