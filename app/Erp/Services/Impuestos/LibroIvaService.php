<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\LibroIvaComprasPeriodo;
use App\Erp\Models\Impuestos\LibroIvaVentasPeriodo;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agrega comprobantes (ventas y compras) en cabeceras por período fiscal IVA.
 *
 * El "armado" del libro consiste en sumar facturas confirmadas del mes y
 * acumular netos / IVA por alícuota — no genera el archivo TXT (eso es el
 * `LibroIvaF8001Service`). El cálculo se ejecuta cada vez que se consulta
 * el detalle del período mientras esté `ABIERTO` o `EN_REVISION`; para
 * estados posteriores se devuelve el snapshot guardado en BD.
 *
 * Reglas:
 *   - RN-46: solo facturas con CAE válido entran. Las que están confirmadas
 *     pero sin CAE las marca el `LibroIvaValidador`.
 *   - RN-47: NCs van por separado con `signo = -1` del tipo de comprobante.
 *   - Solo facturas en estados que ya emitieron CAE: EMITIDA, CONTROLADA,
 *     COBRO_PARCIAL, COBRADA, ANULADA_POR_NC.
 */
class LibroIvaService
{
    /** Estados de factura de venta que se incluyen en el libro IVA. */
    private const ESTADOS_VENTA_INCLUIDOS = [
        'EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL', 'COBRADA', 'ANULADA_POR_NC',
    ];

    /** Estados de factura de compra que se incluyen en el libro IVA. */
    private const ESTADOS_COMPRA_INCLUIDOS = [
        'CONTROLADA', 'PAGO_PARCIAL', 'PAGADA', 'ANULADA_POR_NC',
    ];

    /** Mapping codigo_interno alicuota → suffix de columna en libro_iva_*_periodo. */
    private const MAP_ALICUOTA = [
        'IVA_21'   => '21',
        'IVA_10_5' => '10_5',
        'IVA_27'   => '27',
        'IVA_5'    => '5',
        'IVA_2_5'  => '2_5',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Agrega ventas + compras del período y persiste cabecera. Idempotente.
     *
     * @return array{ventas: LibroIvaVentasPeriodo, compras: LibroIvaComprasPeriodo}
     */
    public function armar(PeriodoFiscal $periodo, User $usuario): array
    {
        if ($periodo->impuesto !== 'IVA') {
            throw new DomainException('LIBRO_IVA_PERIODO_INVALIDO: solo períodos IVA admiten libro');
        }

        if (! $periodo->esEditable()) {
            throw new DomainException(
                "LIBRO_IVA_PERIODO_NO_EDITABLE: estado actual {$periodo->estado}"
            );
        }

        return DB::transaction(function () use ($periodo, $usuario) {
            $ventas  = $this->armarVentas($periodo);
            $compras = $this->armarCompras($periodo);

            $this->audit->log('armar_libro_iva', $periodo,
                null, ['ventas_total' => $ventas->total_facturado, 'compras_total' => $compras->total_facturado],
                "Armado libro IVA {$periodo->anio}/{$periodo->mes} por user #{$usuario->id}"
            );

            return ['ventas' => $ventas->fresh(), 'compras' => $compras->fresh()];
        });
    }

    /**
     * Devuelve el listado detallado de comprobantes del período (ventas + compras),
     * sin persistir cabecera. Útil para UI de revisión y para el F.8001.
     *
     * @return array{ventas: Collection, compras: Collection}
     */
    public function detalle(PeriodoFiscal $periodo): array
    {
        return [
            'ventas'  => $this->queryVentas($periodo)->get(),
            'compras' => $this->queryCompras($periodo)->get(),
        ];
    }

    // ------------------------------------------------------------------------
    // Ventas
    // ------------------------------------------------------------------------

    private function armarVentas(PeriodoFiscal $periodo): LibroIvaVentasPeriodo
    {
        $facturas = $this->queryVentas($periodo)->get();
        $ivaPorAlicuota = $this->queryVentasIvaAgrupado($periodo);

        $totals = [
            'periodo_id' => $periodo->id,
            'cantidad_comprobantes' => $facturas->count(),
            'percepciones_iibb_practicadas' => 0, // H4: cuando IIBB esté implementado se computa
            'otros_tributos' => (float) $facturas->sum(fn ($f) => $this->signo($f->signo) * (float) $f->imp_tributos),
            'neto_no_gravado' => (float) $facturas->sum(fn ($f) => $this->signo($f->signo) * (float) $f->imp_no_gravado),
            'neto_exento'     => (float) $facturas->sum(fn ($f) => $this->signo($f->signo) * (float) $f->imp_exento),
            'total_facturado' => (float) $facturas->sum(fn ($f) => $this->signo($f->signo) * (float) $f->imp_total),
        ];

        // Inicializar buckets por alícuota en cero.
        foreach (self::MAP_ALICUOTA as $suffix) {
            $totals["neto_gravado_{$suffix}"] = 0.0;
            $totals["iva_{$suffix}"]          = 0.0;
        }

        // Sumar por alícuota (ya incluye signo del tipo de comprobante).
        foreach ($ivaPorAlicuota as $row) {
            $codigo = $row->codigo_interno;
            if (! isset(self::MAP_ALICUOTA[$codigo])) {
                continue; // IVA_0 cae en neto_exento ya contado arriba.
            }
            $suffix = self::MAP_ALICUOTA[$codigo];
            $totals["neto_gravado_{$suffix}"] = (float) $row->base_total;
            $totals["iva_{$suffix}"]          = (float) $row->iva_total;
        }

        $totals['archivo_f8001_path'] = null;
        $totals['archivo_f8001_hash'] = null;
        $totals['generado_at']        = null;
        $totals['generado_user_id']   = null;

        return LibroIvaVentasPeriodo::updateOrCreate(
            ['periodo_id' => $periodo->id],
            $totals
        );
    }

    private function queryVentas(PeriodoFiscal $periodo)
    {
        return DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_factura_venta_cae as c', 'c.factura_venta_id', '=', 'f.id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->join('erp_puntos_venta as pv', 'pv.id', '=', 'f.punto_venta_id')
            ->where('f.empresa_id', $periodo->empresa_id)
            ->whereYear('f.fecha_emision', $periodo->anio)
            ->whereMonth('f.fecha_emision', $periodo->mes)
            ->whereIn('f.estado', self::ESTADOS_VENTA_INCLUIDOS)
            ->whereNotNull('c.cae')
            ->where('c.resultado', 'A')
            ->select([
                'f.id', 'f.fecha_emision', 'f.numero',
                't.codigo_interno as tipo_codigo', 't.letra', 't.signo', 't.clase',
                'pv.numero as pto_vta',
                'a.nombre as razon_social', 'f.doc_tipo_afip', 'f.doc_nro',
                'f.imp_neto_gravado', 'f.imp_no_gravado', 'f.imp_exento',
                'f.imp_iva', 'f.imp_tributos', 'f.imp_total',
                'c.cae', 'c.fecha_vto_cae',
            ])
            ->orderBy('f.fecha_emision')
            ->orderBy('f.id');
    }

    private function queryVentasIvaAgrupado(PeriodoFiscal $periodo): Collection
    {
        return collect(DB::table('erp_factura_venta_iva as fi')
            ->join('erp_facturas_venta as f', 'f.id', '=', 'fi.factura_id')
            ->join('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_alicuotas_iva as a', 'a.id', '=', 'fi.alicuota_iva_id')
            ->join('erp_factura_venta_cae as c', 'c.factura_venta_id', '=', 'f.id')
            ->where('f.empresa_id', $periodo->empresa_id)
            ->whereYear('f.fecha_emision', $periodo->anio)
            ->whereMonth('f.fecha_emision', $periodo->mes)
            ->whereIn('f.estado', self::ESTADOS_VENTA_INCLUIDOS)
            ->whereNotNull('c.cae')
            ->where('c.resultado', 'A')
            ->groupBy('a.codigo_interno')
            ->select([
                'a.codigo_interno',
                DB::raw('SUM(fi.base_imponible * t.signo) as base_total'),
                DB::raw('SUM(fi.importe_iva * t.signo) as iva_total'),
            ])
            ->get());
    }

    // ------------------------------------------------------------------------
    // Compras
    // ------------------------------------------------------------------------

    private function armarCompras(PeriodoFiscal $periodo): LibroIvaComprasPeriodo
    {
        $facturas = $this->queryCompras($periodo)->get();
        $ivaPorAlicuota = $this->queryComprasIvaAgrupado($periodo);

        $totals = [
            'periodo_id' => $periodo->id,
            'cantidad_comprobantes' => $facturas->count(),
            'percepciones_iva_sufridas'  => (float) $facturas->sum(fn ($f) => $this->signo($f->signo) * (float) $f->imp_percepciones),
            'percepciones_iibb_sufridas' => 0, // H3/H4 desagregar por tipo
            'retenciones_iva_sufridas'   => 0, // H3
            'retenciones_gan_sufridas'   => 0, // H3
            'otros_tributos'  => (float) $facturas->sum(fn ($f) => $this->signo($f->signo) * (float) $f->imp_tributos),
            'neto_no_gravado' => (float) $facturas->sum(fn ($f) => $this->signo($f->signo) * (float) $f->imp_no_gravado),
            'neto_exento'     => (float) $facturas->sum(fn ($f) => $this->signo($f->signo) * (float) $f->imp_exento),
            'total_facturado' => (float) $facturas->sum(fn ($f) => $this->signo($f->signo) * (float) $f->imp_total),
        ];

        foreach (self::MAP_ALICUOTA as $suffix) {
            $totals["neto_gravado_{$suffix}"] = 0.0;
            $totals["iva_{$suffix}"]          = 0.0;
        }

        foreach ($ivaPorAlicuota as $row) {
            $codigo = $row->codigo_interno;
            if (! isset(self::MAP_ALICUOTA[$codigo])) {
                continue;
            }
            $suffix = self::MAP_ALICUOTA[$codigo];
            $totals["neto_gravado_{$suffix}"] = (float) $row->base_total;
            $totals["iva_{$suffix}"]          = (float) $row->iva_total;
        }

        $totals['archivo_f8001_path'] = null;
        $totals['archivo_f8001_hash'] = null;
        $totals['generado_at']        = null;
        $totals['generado_user_id']   = null;

        return LibroIvaComprasPeriodo::updateOrCreate(
            ['periodo_id' => $periodo->id],
            $totals
        );
    }

    private function queryCompras(PeriodoFiscal $periodo)
    {
        return DB::table('erp_facturas_compra as f')
            ->join('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $periodo->empresa_id)
            ->whereYear('f.fecha_emision', $periodo->anio)
            ->whereMonth('f.fecha_emision', $periodo->mes)
            ->whereIn('f.estado', self::ESTADOS_COMPRA_INCLUIDOS)
            ->select([
                'f.id', 'f.fecha_emision', 'f.numero',
                't.codigo_interno as tipo_codigo', 't.letra', 't.signo', 't.clase',
                'f.punto_venta as pto_vta',
                'f.cuit_emisor', 'f.razon_social_emisor as razon_social',
                'f.imp_neto_gravado', 'f.imp_no_gravado', 'f.imp_exento',
                'f.imp_iva', 'f.imp_tributos', 'f.imp_percepciones',
                'f.imp_retenciones', 'f.imp_total', 'f.cae',
            ])
            ->orderBy('f.fecha_emision')
            ->orderBy('f.id');
    }

    private function queryComprasIvaAgrupado(PeriodoFiscal $periodo): Collection
    {
        return collect(DB::table('erp_factura_compra_iva as fi')
            ->join('erp_facturas_compra as f', 'f.id', '=', 'fi.factura_id')
            ->join('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_alicuotas_iva as a', 'a.id', '=', 'fi.alicuota_iva_id')
            ->where('f.empresa_id', $periodo->empresa_id)
            ->whereYear('f.fecha_emision', $periodo->anio)
            ->whereMonth('f.fecha_emision', $periodo->mes)
            ->whereIn('f.estado', self::ESTADOS_COMPRA_INCLUIDOS)
            ->groupBy('a.codigo_interno')
            ->select([
                'a.codigo_interno',
                DB::raw('SUM(fi.base_imponible * t.signo) as base_total'),
                DB::raw('SUM(fi.importe_iva * t.signo) as iva_total'),
            ])
            ->get());
    }

    private function signo(int|string $signo): int
    {
        return ((int) $signo) === -1 ? -1 : 1;
    }
}
