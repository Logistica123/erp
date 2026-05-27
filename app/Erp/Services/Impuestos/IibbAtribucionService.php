<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Materializa movimientos atribuidos por jurisdicción IIBB en
 * `erp_iibb_jurisdiccion_mov` a partir de facturas de venta y compra
 * del período/rango.
 *
 * Reglas (RN-54):
 *   - Ventas: se usa `erp_factura_venta_items.jurisdiccion_iibb` si está
 *     seteado por línea. Si no, default = jurisdicción del domicilio de
 *     la empresa (configurable; fallback '901' CABA).
 *   - Compras: por el momento se atribuye toda la factura al default
 *     (sin desglose por línea en `erp_factura_compra_items`). El usuario
 *     puede corregir manualmente post-cálculo.
 *
 * Idempotente: borra y reinserta las filas con origen FACTURA_* del
 * rango pedido antes de cargar las nuevas.
 */
class IibbAtribucionService
{
    /** Estados de venta incluidos (mismos que LibroIvaService). */
    private const ESTADOS_VENTA = [
        'EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL', 'COBRADA', 'ANULADA_POR_NC',
    ];

    /** Estados de compra incluidos. */
    private const ESTADOS_COMPRA = [
        'CONTROLADA', 'PAGO_PARCIAL', 'PAGADA', 'ANULADA_POR_NC',
    ];

    /** Jurisdicción por defecto cuando la factura no declara una específica. */
    private const JUR_DEFAULT = '901';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Recalcula atribuciones en rango [desde, hasta]. Devuelve totales por
     * jurisdicción y tipo (INGRESO / GASTO).
     *
     * @return array{
     *   filas:int,
     *   por_jurisdiccion:array<string, array{ingresos:float, gastos:float}>
     * }
     */
    public function recalcularRango(
        int $empresaId,
        string $desde,
        string $hasta,
        User $usuario,
    ): array {
        $defaultJur = $this->jurisdiccionDefault($empresaId);

        return DB::transaction(function () use ($empresaId, $desde, $hasta, $defaultJur, $usuario) {
            DB::table('erp_iibb_jurisdiccion_mov')
                ->where('empresa_id', $empresaId)
                ->whereBetween('fecha', [$desde, $hasta])
                ->whereIn('origen', ['FACTURA_VENTA', 'FACTURA_COMPRA'])
                ->delete();

            $insert = array_merge(
                $this->fetchVentas($empresaId, $desde, $hasta, $defaultJur),
                $this->fetchCompras($empresaId, $desde, $hasta, $defaultJur),
            );

            if (! empty($insert)) {
                foreach (array_chunk($insert, 500) as $chunk) {
                    DB::table('erp_iibb_jurisdiccion_mov')->insert($chunk);
                }
            }

            $totales = $this->totalesPorJurisdiccion($empresaId, $desde, $hasta);

            $this->audit->logEvento(
                'atribuir_jurisdicciones',
                'impuestos',
                "Atribución IIBB $desde..$hasta — ".count($insert)." movs (user #{$usuario->id})",
                $empresaId,
            );

            return ['filas' => count($insert), 'por_jurisdiccion' => $totales];
        });
    }

    /**
     * Totales por jurisdicción y tipo para un rango de fechas.
     *
     * @return array<string, array{ingresos:float, gastos:float}>
     */
    public function totalesPorJurisdiccion(int $empresaId, string $desde, string $hasta): array
    {
        $rows = DB::table('erp_iibb_jurisdiccion_mov')
            ->where('empresa_id', $empresaId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('jurisdiccion', 'tipo')
            ->select(['jurisdiccion', 'tipo', DB::raw('SUM(importe) as total')])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r->jurisdiccion;
            if (! isset($out[$key])) {
                $out[$key] = ['ingresos' => 0.0, 'gastos' => 0.0];
            }
            if ($r->tipo === 'INGRESO') {
                $out[$key]['ingresos'] += (float) $r->total;
            } else {
                $out[$key]['gastos'] += (float) $r->total;
            }
        }
        return $out;
    }

    /**
     * Totales por jurisdicción sumando ventas del período fiscal (base CM03).
     */
    public function baseImponibleCm03(PeriodoFiscal $periodo): array
    {
        $mesInicio = sprintf('%04d-%02d-01', $periodo->anio, $periodo->mes);
        $mesFin    = date('Y-m-d', strtotime("{$mesInicio} +1 month -1 day"));

        $this->recalcularRango($periodo->empresa_id, $mesInicio, $mesFin, request()->user() ?? new \App\Models\User());

        $totales = $this->totalesPorJurisdiccion($periodo->empresa_id, $mesInicio, $mesFin);
        $out = [];
        foreach ($totales as $jur => $t) {
            $out[$jur] = $t['ingresos'];
        }
        return $out;
    }

    // ------------------------------------------------------------------------
    // Queries
    // ------------------------------------------------------------------------

    private function fetchVentas(int $empresaId, string $desde, string $hasta, string $defaultJur): array
    {
        // v1.51 — Atribución a nivel factura. Antes era por ítem con INNER JOIN
        // a erp_factura_venta_items, lo que dejaba afuera TODAS las facturas
        // importadas del Libro IVA Ventas (no tienen ítems). Prioridad por
        // factura:
        //   1) reparto manual en erp_factura_venta_jurisdicciones (1..N filas)
        //   2) ítems con jurisdiccion_iibb seteada (comportamiento previo)
        //   3) factura completa con f.jurisdiccion_codigo (o default)
        $facturas = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $empresaId)
            ->whereBetween('f.fecha_emision', [$desde, $hasta])
            ->whereIn('f.estado', self::ESTADOS_VENTA)
            ->whereNull('f.deleted_at')
            ->whereNotNull('f.cae')
            ->select(['f.id', 'f.fecha_emision', 'f.imp_neto_gravado', 'f.jurisdiccion_codigo', 't.signo'])
            ->get();

        if ($facturas->isEmpty()) {
            return [];
        }
        $ids = $facturas->pluck('id')->all();

        $distrib = DB::table('erp_factura_venta_jurisdicciones')
            ->whereIn('factura_venta_id', $ids)
            ->get(['factura_venta_id', 'jurisdiccion_codigo', 'base_imponible'])
            ->groupBy('factura_venta_id');

        $items = DB::table('erp_factura_venta_items')
            ->whereIn('factura_id', $ids)
            ->whereNotNull('jurisdiccion_iibb')
            ->select(['factura_id', 'jurisdiccion_iibb', DB::raw('SUM(imp_neto) as neto')])
            ->groupBy('factura_id', 'jurisdiccion_iibb')
            ->get()
            ->groupBy('factura_id');

        $out = [];
        foreach ($facturas as $f) {
            $signo = (int) $f->signo;
            if (isset($distrib[$f->id])) {
                foreach ($distrib[$f->id] as $d) {
                    $out[] = $this->movVenta($empresaId, $f, $d->jurisdiccion_codigo, (float) $d->base_imponible * $signo);
                }
            } elseif (isset($items[$f->id])) {
                foreach ($items[$f->id] as $it) {
                    $out[] = $this->movVenta($empresaId, $f, $it->jurisdiccion_iibb ?: $defaultJur, (float) $it->neto * $signo);
                }
            } else {
                $out[] = $this->movVenta($empresaId, $f, $f->jurisdiccion_codigo ?: $defaultJur, (float) $f->imp_neto_gravado * $signo);
            }
        }
        return $out;
    }

    /** Arma una fila de erp_iibb_jurisdiccion_mov para una venta. */
    private function movVenta(int $empresaId, object $f, string $jur, float $importe): array
    {
        return [
            'empresa_id'        => $empresaId,
            'fecha'             => $f->fecha_emision,
            'jurisdiccion'      => $jur,
            'tipo'              => 'INGRESO',
            'importe'           => $importe,
            'origen'            => 'FACTURA_VENTA',
            'factura_venta_id'  => $f->id,
            'factura_compra_id' => null,
            'descripcion'       => null,
            'created_at'        => now(),
        ];
    }

    private function fetchCompras(int $empresaId, string $desde, string $hasta, string $defaultJur): array
    {
        $rows = DB::table('erp_facturas_compra as f')
            ->join('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $empresaId)
            ->whereBetween('f.fecha_emision', [$desde, $hasta])
            ->whereIn('f.estado', self::ESTADOS_COMPRA)
            ->select([
                'f.id as factura_id', 'f.fecha_emision', 'f.imp_neto_gravado',
                't.signo', 'f.razon_social_emisor',
            ])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $importe = (float) $r->imp_neto_gravado * (int) $r->signo;
            $out[] = [
                'empresa_id'       => $empresaId,
                'fecha'            => $r->fecha_emision,
                'jurisdiccion'     => $defaultJur,
                'tipo'             => 'GASTO',
                'importe'          => $importe,
                'origen'           => 'FACTURA_COMPRA',
                'factura_venta_id' => null,
                'factura_compra_id'=> $r->factura_id,
                'descripcion'      => mb_substr((string) $r->razon_social_emisor, 0, 240),
                'created_at'       => now(),
            ];
        }
        return $out;
    }

    private function jurisdiccionDefault(int $empresaId): string
    {
        // Si algún día se agrega `domicilio_iibb` a erp_empresas, leerlo aquí.
        // Por ahora fijamos 901 (CABA) ya que es el domicilio fiscal
        // declarado de Logística Argentina SRL.
        return self::JUR_DEFAULT;
    }
}
