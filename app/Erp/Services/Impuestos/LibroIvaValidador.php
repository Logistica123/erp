<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use Illuminate\Support\Facades\DB;

/**
 * Valida que un período fiscal IVA cumpla las precondiciones para pasar
 * a APROBADO (RN-46).
 *
 * Anomalías que se detectan:
 *   - Facturas de venta confirmadas sin CAE válido en erp_factura_venta_cae
 *     (estado != PREPARADA, EMISION_FALLIDA, RECHAZADA y sin row CAE).
 *   - NCs sin factura origen referenciada (alerta, no bloquea).
 *   - Comprobantes con `imp_total != imp_neto + imp_iva + tributos` (warning).
 *
 * El validador NO modifica datos. Devuelve estructura con `ok` + lista de
 * `anomalias`. El controller decide si rechazar la transición.
 */
class LibroIvaValidador
{
    public const SEVERIDAD_BLOQ = 'bloqueante';
    public const SEVERIDAD_WARN = 'warning';

    /** Estados de venta que indican que ya debió emitirse CAE. */
    private const ESTADOS_REQUIEREN_CAE = ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL', 'COBRADA', 'ANULADA_POR_NC'];

    /**
     * @return array{ok: bool, bloqueantes: int, warnings: int, anomalias: array<int, array>}
     */
    public function validarCierrePeriodo(PeriodoFiscal $periodo): array
    {
        $anomalias = [];
        $anomalias = array_merge($anomalias, $this->facturasSinCaeValido($periodo));
        $anomalias = array_merge($anomalias, $this->totalesInconsistentes($periodo));

        $bloq = array_filter($anomalias, fn ($a) => $a['severidad'] === self::SEVERIDAD_BLOQ);
        $warn = array_filter($anomalias, fn ($a) => $a['severidad'] === self::SEVERIDAD_WARN);

        return [
            'ok'           => count($bloq) === 0,
            'bloqueantes'  => count($bloq),
            'warnings'     => count($warn),
            'anomalias'    => array_values($anomalias),
        ];
    }

    /** RN-46: facturas en estados emitidos sin CAE válido. */
    private function facturasSinCaeValido(PeriodoFiscal $periodo): array
    {
        $rows = DB::table('erp_facturas_venta as f')
            ->leftJoin('erp_factura_venta_cae as c', 'c.factura_venta_id', '=', 'f.id')
            ->join('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $periodo->empresa_id)
            ->whereYear('f.fecha_emision', $periodo->anio)
            ->whereMonth('f.fecha_emision', $periodo->mes)
            ->whereIn('f.estado', self::ESTADOS_REQUIEREN_CAE)
            ->where(function ($q) {
                $q->whereNull('c.cae')
                  ->orWhere('c.resultado', '!=', 'A')
                  ->orWhereNull('c.resultado');
            })
            ->select([
                'f.id', 'f.numero', 'f.fecha_emision', 'f.estado',
                't.codigo_interno as tipo_codigo', 't.letra',
                'c.cae', 'c.resultado',
            ])
            ->get();

        return $rows->map(fn ($r) => [
            'severidad'    => self::SEVERIDAD_BLOQ,
            'codigo'       => 'RN46_FACTURA_SIN_CAE',
            'factura_id'   => $r->id,
            'descripcion'  => "Factura {$r->letra} {$r->tipo_codigo} #{$r->numero} ({$r->fecha_emision}) "
                            ."estado={$r->estado} sin CAE válido — emitir CAE o anular antes de aprobar período",
        ])->all();
    }

    /** Warning: imp_total != imp_neto_gravado + imp_no_gravado + imp_exento + imp_iva + imp_tributos. */
    private function totalesInconsistentes(PeriodoFiscal $periodo): array
    {
        $rows = DB::table('erp_facturas_venta as f')
            ->where('f.empresa_id', $periodo->empresa_id)
            ->whereYear('f.fecha_emision', $periodo->anio)
            ->whereMonth('f.fecha_emision', $periodo->mes)
            ->whereIn('f.estado', self::ESTADOS_REQUIEREN_CAE)
            ->whereRaw('ABS(f.imp_total - (f.imp_neto_gravado + f.imp_no_gravado + f.imp_exento + f.imp_iva + f.imp_tributos)) > 0.05')
            ->select(['f.id', 'f.numero', 'f.fecha_emision', 'f.imp_total', 'f.imp_neto_gravado', 'f.imp_iva', 'f.imp_tributos'])
            ->get();

        return $rows->map(fn ($r) => [
            'severidad'   => self::SEVERIDAD_WARN,
            'codigo'      => 'TOTALES_INCONSISTENTES',
            'factura_id'  => $r->id,
            'descripcion' => "Factura #{$r->numero} ({$r->fecha_emision}): "
                           ."total={$r->imp_total} ≠ neto+IVA+tributos. Revisar.",
        ])->all();
    }
}
