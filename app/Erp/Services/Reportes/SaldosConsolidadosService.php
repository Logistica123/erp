<?php

namespace App\Erp\Services\Reportes;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * v1.37 — Reporte consolidado de saldos.
 *
 * Calcula totales de Deudores por Ventas y Deuda con Proveedores, con desglose
 * de "de los cuales son operaciones EFECTIVO" (D-37-4: el TOTAL es siempre la
 * suma de FACTURA + EFECTIVO; solo EFECTIVO se expone como subtotal).
 *
 * No hay columna `saldo` stored en las tablas de facturas: el saldo se computa
 * on-the-fly a partir de imp_total menos imputaciones de recibos / cobros / NC
 * para ventas, y menos imputaciones de órdenes de pago para compras.
 *
 * Performance: para 700 facturas + 500 auxiliares el cálculo completo está bajo
 * el segundo. El controlador cachea 5min los resultados.
 */
class SaldosConsolidadosService
{
    /** Estados de venta que pueden tener saldo abierto. */
    public const ESTADOS_VENTA_ABIERTA = ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL'];

    /** Estados de compra que pueden tener saldo abierto. */
    public const ESTADOS_COMPRA_ABIERTA = ['RECIBIDA', 'CONTROLADA', 'OBSERVADA', 'PAGO_PARCIAL'];

    /** Estados de OP cuyo importe ya compromete el saldo de la factura compra. */
    public const ESTADOS_OP_ACTIVOS = ['EMITIDA', 'CARGADA_BANCO', 'LIBERADA', 'PAGADA'];

    /** Buckets de aging en días desde la fecha de vencimiento. */
    public const BUCKETS = [
        'corriente' => [null, 0],   // aún no vencidas
        '1_30'      => [1, 30],
        '31_60'     => [31, 60],
        '61_90'     => [61, 90],
        'mas_90'    => [91, null],
    ];

    /**
     * Punto de entrada principal del reporte.
     *
     * @param array{
     *   empresa_id?: int,
     *   fecha_corte?: string,           // Y-m-d
     *   moneda_codigo?: string,         // ARS, USD; default ARS
     *   incluir_efectivo?: bool,        // default true
     *   top_n?: int,                    // default 10
     * } $filtros
     */
    public function calcular(array $filtros = []): array
    {
        $empresaId = (int) ($filtros['empresa_id'] ?? 1);
        $fechaCorte = isset($filtros['fecha_corte']) ? Carbon::parse($filtros['fecha_corte']) : Carbon::today();
        $monedaCodigo = strtoupper((string) ($filtros['moneda_codigo'] ?? 'ARS'));
        $incluirEfectivo = (bool) ($filtros['incluir_efectivo'] ?? true);
        $topN = max(1, min(50, (int) ($filtros['top_n'] ?? 10)));

        $monedaId = $this->resolverMonedaId($monedaCodigo);

        $widgets = [
            'deudores_ventas'  => $this->widgetVentas($empresaId, $fechaCorte, $monedaId, $incluirEfectivo),
            'deuda_compras'    => $this->widgetCompras($empresaId, $fechaCorte, $monedaId, $incluirEfectivo),
        ];
        $widgets['posicion_neta'] = $widgets['deudores_ventas']['total'] - $widgets['deuda_compras']['total'];

        return [
            'fecha_corte'      => $fechaCorte->toDateString(),
            'moneda'           => $monedaCodigo,
            'incluir_efectivo' => $incluirEfectivo,
            'widgets'          => $widgets,
            'aging_deudores'   => $this->aging('venta', $empresaId, $fechaCorte, $monedaId, $incluirEfectivo),
            'aging_acreedores' => $this->aging('compra', $empresaId, $fechaCorte, $monedaId, $incluirEfectivo),
            'top_deudores'     => $this->topAuxiliares('venta', $empresaId, $fechaCorte, $monedaId, $incluirEfectivo, $topN),
            'top_acreedores'   => $this->topAuxiliares('compra', $empresaId, $fechaCorte, $monedaId, $incluirEfectivo, $topN),
            'calculado_at'     => now()->toIso8601String(),
        ];
    }

    /**
     * Drill-down: listado de operaciones pendientes de un auxiliar puntual.
     */
    public function detalleAuxiliar(int $auxiliarId, array $filtros = []): array
    {
        $empresaId = (int) ($filtros['empresa_id'] ?? 1);
        $fechaCorte = isset($filtros['fecha_corte']) ? Carbon::parse($filtros['fecha_corte']) : Carbon::today();
        $monedaCodigo = strtoupper((string) ($filtros['moneda_codigo'] ?? 'ARS'));
        $incluirEfectivo = (bool) ($filtros['incluir_efectivo'] ?? true);
        $monedaId = $this->resolverMonedaId($monedaCodigo);

        $aux = DB::table('erp_auxiliares')->where('id', $auxiliarId)
            ->where('empresa_id', $empresaId)
            ->first(['id', 'codigo', 'nombre', 'cuit', 'tipo']);

        if (! $aux) {
            return ['auxiliar' => null, 'operaciones' => []];
        }

        // Determinar si es deudor (Cliente) o acreedor (Proveedor, Distribuidor, etc.).
        $esCliente = $aux->tipo === 'Cliente';

        $operaciones = $esCliente
            ? $this->detalleVentasAuxiliar($empresaId, $auxiliarId, $fechaCorte, $monedaId, $incluirEfectivo)
            : $this->detalleComprasAuxiliar($empresaId, $auxiliarId, $fechaCorte, $monedaId, $incluirEfectivo);

        return [
            'auxiliar' => $aux,
            'es_cliente' => $esCliente,
            'fecha_corte' => $fechaCorte->toDateString(),
            'moneda' => $monedaCodigo,
            'operaciones' => $operaciones,
            'totales' => [
                'total'    => array_sum(array_map(fn ($o) => (float) $o->saldo, $operaciones)),
                'efectivo' => array_sum(array_map(fn ($o) => $o->categoria === 'EFECTIVO' ? (float) $o->saldo : 0.0, $operaciones)),
                'vencido'  => array_sum(array_map(fn ($o) => $o->dias_vencido > 0 ? (float) $o->saldo : 0.0, $operaciones)),
            ],
        ];
    }

    // ------------------------------------------------------------------------
    // Expressions reutilizables para computar saldo on-the-fly.
    // ------------------------------------------------------------------------

    /**
     * Subquery escalar que devuelve el saldo abierto de un comprobante de
     * venta, ya con SIGNO aplicado: facturas (signo=+1) suman como deuda del
     * cliente, NC (signo=-1) restan (son créditos del cliente a aplicar).
     *
     * Para que el reporte de Deudores cuadre como "facturas pendientes − NC
     * pendientes" hay que tratar cada tipo distinto:
     *   - FACTURA (signo=+1): saldo = imp_total − cobros − NC_imputadas_a_ella − recibos.
     *   - NC      (signo=-1): saldo = imp_total − imputaciones_DESDE_la_NC (uso
     *     erp_imputaciones_nc.nc_id, no factura_id). Y se multiplica por -1
     *     para que SUMar deje el efecto de restar.
     *
     * El JOIN a erp_tipos_comprobante debe estar disponible en el caller con
     * el alias `tc`.
     */
    public function saldoFacturaVentaExpr(string $alias = 'fv', string $tcAlias = 'tc'): string
    {
        return "(CASE WHEN {$tcAlias}.signo > 0 THEN
            (
                {$alias}.imp_total
                - COALESCE((SELECT SUM(ci.importe) FROM erp_cobro_items ci
                            WHERE ci.factura_id = {$alias}.id AND ci.tipo_item = 'FACTURA_VENTA'), 0)
                - COALESCE((SELECT SUM(inc.importe) FROM erp_imputaciones_nc inc
                            WHERE inc.factura_id = {$alias}.id), 0)
                - COALESCE((SELECT SUM(rci.monto_imputado)
                            FROM erp_recibos_comprobantes_imputados rci
                            JOIN erp_recibos r ON r.id = rci.recibo_id
                            WHERE rci.factura_venta_id = {$alias}.id
                              AND r.estado <> 'ANULADO'), 0)
            )
        ELSE
            -1 * (
                {$alias}.imp_total
                - COALESCE((SELECT SUM(inc2.importe) FROM erp_imputaciones_nc inc2
                            WHERE inc2.nc_id = {$alias}.id), 0)
            )
        END)";
    }

    /**
     * Subquery escalar que devuelve el saldo abierto de una factura compra.
     * Considera: imp_total - imputaciones de OP activas (EMITIDA / CARGADA /
     * LIBERADA / PAGADA).
     */
    public function saldoFacturaCompraExpr(string $alias = 'fc'): string
    {
        $estados = "'" . implode("','", self::ESTADOS_OP_ACTIVOS) . "'";
        return "(
            {$alias}.imp_total
            - COALESCE((SELECT SUM(opi.importe)
                        FROM erp_op_items opi
                        JOIN erp_ordenes_pago op ON op.id = opi.op_id
                        WHERE opi.tipo_item = 'FACTURA_COMPRA'
                          AND opi.comprobante_id = {$alias}.id
                          AND op.estado IN ({$estados})), 0)
        )";
    }

    // ------------------------------------------------------------------------
    // Cálculos internos.
    // ------------------------------------------------------------------------

    private function widgetVentas(int $empresaId, Carbon $fechaCorte, int $monedaId, bool $incluirEfectivo): array
    {
        // JOIN a tipos_comprobante para que saldoFacturaVentaExpr pueda
        // aplicar el signo (NC restan).
        $saldoExpr = $this->saldoFacturaVentaExpr('fv', 'tc');

        $rows = DB::table('erp_facturas_venta as fv')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'fv.tipo_comprobante_id')
            ->where('fv.empresa_id', $empresaId)
            ->where('fv.moneda_id', $monedaId)
            ->whereIn('fv.estado', self::ESTADOS_VENTA_ABIERTA)
            ->where('fv.fecha_emision', '<=', $fechaCorte->toDateString())
            ->whereNull('fv.deleted_at')
            ->when(! $incluirEfectivo, fn ($q) => $q->where('fv.categoria', 'FACTURA'))
            ->select([
                'fv.categoria',
                DB::raw("{$saldoExpr} AS saldo"),
            ])
            ->get();

        return $this->resumirPorCategoria($rows, true);
    }

    private function widgetCompras(int $empresaId, Carbon $fechaCorte, int $monedaId, bool $incluirEfectivo): array
    {
        $saldoExpr = $this->saldoFacturaCompraExpr('fc');

        $rows = DB::table('erp_facturas_compra as fc')
            ->where('fc.empresa_id', $empresaId)
            ->where('fc.moneda_id', $monedaId)
            ->whereIn('fc.estado', self::ESTADOS_COMPRA_ABIERTA)
            ->where('fc.fecha_emision', '<=', $fechaCorte->toDateString())
            ->whereNull('fc.deleted_at')
            ->when(! $incluirEfectivo, fn ($q) => $q->where('fc.categoria', 'FACTURA'))
            ->select([
                'fc.categoria',
                DB::raw("{$saldoExpr} AS saldo"),
            ])
            ->get();

        return $this->resumirPorCategoria($rows);
    }

    /**
     * Acumula filas con (categoria, saldo) en totales global / efectivo.
     *
     * Si `permitirNegativos=true` (caso ventas, donde NC vienen con saldo
     * negativo por signo), suma todo incluyendo restos. La cantidad cuenta
     * solo operaciones con saldo > 0 (facturas/comprobantes con deuda real,
     * no NC que ya restaron).
     *
     * Si false (caso compras, sin NC con signo), solo suma saldos > 0.
     */
    private function resumirPorCategoria(Collection $rows, bool $permitirNegativos = false): array
    {
        $total = 0.0;
        $efectivo = 0.0;
        $cantidad = 0;
        foreach ($rows as $r) {
            $saldo = round((float) $r->saldo, 2);
            if (! $permitirNegativos && $saldo <= 0.0) continue;
            if ($permitirNegativos && abs($saldo) < 0.005) continue;
            $total += $saldo;
            if ($r->categoria === 'EFECTIVO') $efectivo += $saldo;
            if ($saldo > 0.0) $cantidad++;
        }
        return [
            'total'                 => round($total, 2),
            'efectivo'              => round($efectivo, 2),
            'pct_efectivo'          => $total > 0 ? round(($efectivo / $total) * 100, 1) : 0.0,
            'cantidad_operaciones'  => $cantidad,
        ];
    }

    /**
     * Calcula aging por buckets. Devuelve para cada bucket:
     *   { total, efectivo, pct (sobre total general), cantidad }
     */
    private function aging(string $tipo, int $empresaId, Carbon $fechaCorte, int $monedaId, bool $incluirEfectivo): array
    {
        $esVenta = $tipo === 'venta';
        if ($esVenta) {
            $tabla = 'erp_facturas_venta'; $alias = 'fv';
            $saldoExpr = $this->saldoFacturaVentaExpr($alias, 'tc');
            $estados = self::ESTADOS_VENTA_ABIERTA;
        } else {
            $tabla = 'erp_facturas_compra'; $alias = 'fc';
            $saldoExpr = $this->saldoFacturaCompraExpr($alias);
            $estados = self::ESTADOS_COMPRA_ABIERTA;
        }

        $rows = DB::table("{$tabla} as {$alias}")
            ->when($esVenta, fn ($q) => $q->join('erp_tipos_comprobante as tc', 'tc.id', '=', "{$alias}.tipo_comprobante_id"))
            ->where("{$alias}.empresa_id", $empresaId)
            ->where("{$alias}.moneda_id", $monedaId)
            ->whereIn("{$alias}.estado", $estados)
            ->where("{$alias}.fecha_emision", '<=', $fechaCorte->toDateString())
            ->whereNull("{$alias}.deleted_at")
            ->when(! $incluirEfectivo, fn ($q) => $q->where("{$alias}.categoria", 'FACTURA'))
            ->select([
                "{$alias}.categoria",
                "{$alias}.fecha_vencimiento",
                DB::raw("{$saldoExpr} AS saldo"),
            ])
            ->get();

        // Acumulamos por bucket. Para ventas el saldo puede ser negativo (NC)
        // y se acepta como resta del bucket. Para compras todo > 0.
        $buckets = array_fill_keys(array_keys(self::BUCKETS), [
            'total' => 0.0, 'efectivo' => 0.0, 'cantidad' => 0,
        ]);
        $totalGeneral = 0.0;

        foreach ($rows as $r) {
            $saldo = round((float) $r->saldo, 2);
            if ($esVenta) {
                if (abs($saldo) < 0.005) continue;
            } else {
                if ($saldo <= 0.0) continue;
            }

            $diasVenc = $r->fecha_vencimiento
                ? $fechaCorte->diffInDays(Carbon::parse($r->fecha_vencimiento), false) * -1
                : 0; // sin vencimiento → tratamos como corriente
            // diff*-1: si fecha_vencimiento > corte (no vencida aún) da negativo; si está vencida da positivo.

            $bucket = $this->bucketDe($diasVenc);
            $buckets[$bucket]['total'] += $saldo;
            if ($saldo > 0) $buckets[$bucket]['cantidad']++;
            if ($r->categoria === 'EFECTIVO') $buckets[$bucket]['efectivo'] += $saldo;
            $totalGeneral += $saldo;
        }

        // Redondeos finales y %.
        foreach ($buckets as $k => &$b) {
            $b['total']    = round($b['total'], 2);
            $b['efectivo'] = round($b['efectivo'], 2);
            $b['pct']      = $totalGeneral > 0 ? round(($b['total'] / $totalGeneral) * 100, 1) : 0.0;
        }
        unset($b);

        return $buckets;
    }

    private function bucketDe(int $diasVencido): string
    {
        if ($diasVencido <= 0) return 'corriente';
        if ($diasVencido <= 30) return '1_30';
        if ($diasVencido <= 60) return '31_60';
        if ($diasVencido <= 90) return '61_90';
        return 'mas_90';
    }

    /**
     * Top N deudores (tipo=venta) o acreedores (tipo=compra) por saldo total.
     */
    private function topAuxiliares(string $tipo, int $empresaId, Carbon $fechaCorte, int $monedaId, bool $incluirEfectivo, int $topN): array
    {
        $esVenta = $tipo === 'venta';
        if ($esVenta) {
            $tabla = 'erp_facturas_venta'; $alias = 'fv';
            $saldoExpr = $this->saldoFacturaVentaExpr($alias, 'tc');
            $estados = self::ESTADOS_VENTA_ABIERTA;
        } else {
            $tabla = 'erp_facturas_compra'; $alias = 'fc';
            $saldoExpr = $this->saldoFacturaCompraExpr($alias);
            $estados = self::ESTADOS_COMPRA_ABIERTA;
        }

        // Subquery: una fila por factura con saldo (puede ser negativo si es NC).
        $sub = DB::table("{$tabla} as {$alias}")
            ->join('erp_auxiliares as a', "a.id", '=', "{$alias}.auxiliar_id")
            ->when($esVenta, fn ($q) => $q->join('erp_tipos_comprobante as tc', 'tc.id', '=', "{$alias}.tipo_comprobante_id"))
            ->where("{$alias}.empresa_id", $empresaId)
            ->where("{$alias}.moneda_id", $monedaId)
            ->whereIn("{$alias}.estado", $estados)
            ->where("{$alias}.fecha_emision", '<=', $fechaCorte->toDateString())
            ->whereNull("{$alias}.deleted_at")
            ->when(! $incluirEfectivo, fn ($q) => $q->where("{$alias}.categoria", 'FACTURA'))
            ->select([
                'a.id as auxiliar_id',
                'a.codigo as auxiliar_codigo',
                'a.nombre as auxiliar_nombre',
                'a.cuit as auxiliar_cuit',
                "{$alias}.categoria",
                "{$alias}.fecha_vencimiento",
                DB::raw("{$saldoExpr} AS saldo"),
            ]);

        // Agrupamos en PHP. Para ventas, las NC vienen con saldo negativo y
        // restan del saldo neto del cliente. Al final filtramos auxiliares
        // con saldo_total > 0 (los que efectivamente deben algo neto).
        $rows = $sub->get();
        $agrup = [];
        foreach ($rows as $r) {
            $saldo = round((float) $r->saldo, 2);
            if ($esVenta) {
                if (abs($saldo) < 0.005) continue;
            } else {
                if ($saldo <= 0.0) continue;
            }
            $aid = (int) $r->auxiliar_id;
            if (! isset($agrup[$aid])) {
                $agrup[$aid] = [
                    'auxiliar_id'      => $aid,
                    'codigo'           => $r->auxiliar_codigo,
                    'nombre'           => $r->auxiliar_nombre,
                    'cuit'             => $r->auxiliar_cuit,
                    'saldo_total'      => 0.0,
                    'saldo_efectivo'   => 0.0,
                    'saldo_vencido'    => 0.0,
                    'cantidad'         => 0,
                ];
            }
            $agrup[$aid]['saldo_total'] += $saldo;
            if ($saldo > 0) $agrup[$aid]['cantidad']++;
            if ($r->categoria === 'EFECTIVO') {
                $agrup[$aid]['saldo_efectivo'] += $saldo;
            }
            $vencido = $r->fecha_vencimiento && Carbon::parse($r->fecha_vencimiento)->lt($fechaCorte);
            if ($vencido) {
                $agrup[$aid]['saldo_vencido'] += $saldo;
            }
        }

        // Solo nos quedamos con auxiliares con saldo neto > 0 (los que
        // efectivamente deben/nos deben algo). Si las NC ya cancelaron toda
        // la deuda del cliente, no figura en el top.
        $agrup = array_values(array_filter($agrup, fn ($a) => $a['saldo_total'] > 0.005));

        // Ordenar desc por saldo_total y tomar top N.
        usort($agrup, fn ($a, $b) => $b['saldo_total'] <=> $a['saldo_total']);
        $top = array_slice($agrup, 0, $topN);
        foreach ($top as &$r) {
            $r['saldo_total']    = round($r['saldo_total'], 2);
            $r['saldo_efectivo'] = round($r['saldo_efectivo'], 2);
            $r['saldo_vencido']  = round($r['saldo_vencido'], 2);
        }
        unset($r);

        return array_values($top);
    }

    /**
     * Drill-down: facturas venta con saldo abierto de un auxiliar.
     */
    private function detalleVentasAuxiliar(int $empresaId, int $auxiliarId, Carbon $fechaCorte, int $monedaId, bool $incluirEfectivo): array
    {
        // tc alias coincide con saldoFacturaVentaExpr (signo aplicado: NC restan).
        $saldoExpr = $this->saldoFacturaVentaExpr('fv', 'tc');

        $rows = DB::table('erp_facturas_venta as fv')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'fv.tipo_comprobante_id')
            ->join('erp_puntos_venta as pv', 'pv.id', '=', 'fv.punto_venta_id')
            ->where('fv.empresa_id', $empresaId)
            ->where('fv.auxiliar_id', $auxiliarId)
            ->where('fv.moneda_id', $monedaId)
            ->whereIn('fv.estado', self::ESTADOS_VENTA_ABIERTA)
            ->where('fv.fecha_emision', '<=', $fechaCorte->toDateString())
            ->whereNull('fv.deleted_at')
            ->when(! $incluirEfectivo, fn ($q) => $q->where('fv.categoria', 'FACTURA'))
            ->select([
                'fv.id',
                'fv.fecha_emision',
                'fv.fecha_vencimiento',
                'fv.imp_total',
                'fv.categoria',
                'fv.estado',
                'fv.origen',
                'tc.nombre as tipo_comprobante',
                'tc.letra',
                'tc.signo as tc_signo',
                'pv.numero as pv_numero',
                'fv.numero',
                DB::raw("{$saldoExpr} AS saldo"),
            ])
            ->orderBy('fv.fecha_emision')
            ->get()
            // Mostrar todas las operaciones con saldo no-cero (NC con saldo
            // negativo deben verse en el drill-down para que el usuario
            // entienda por qué el neto del cliente es ese).
            ->filter(fn ($r) => abs(round((float) $r->saldo, 2)) > 0.005)
            ->map(function ($r) use ($fechaCorte) {
                $r->dias_vencido = $r->fecha_vencimiento
                    ? $fechaCorte->diffInDays(Carbon::parse($r->fecha_vencimiento), false) * -1
                    : 0;
                return $r;
            })
            ->values()
            ->all();

        return $rows;
    }

    private function detalleComprasAuxiliar(int $empresaId, int $auxiliarId, Carbon $fechaCorte, int $monedaId, bool $incluirEfectivo): array
    {
        $saldoExpr = $this->saldoFacturaCompraExpr('fc');

        $rows = DB::table('erp_facturas_compra as fc')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'fc.tipo_comprobante_id')
            ->where('fc.empresa_id', $empresaId)
            ->where('fc.auxiliar_id', $auxiliarId)
            ->where('fc.moneda_id', $monedaId)
            ->whereIn('fc.estado', self::ESTADOS_COMPRA_ABIERTA)
            ->where('fc.fecha_emision', '<=', $fechaCorte->toDateString())
            ->whereNull('fc.deleted_at')
            ->when(! $incluirEfectivo, fn ($q) => $q->where('fc.categoria', 'FACTURA'))
            ->select([
                'fc.id',
                'fc.fecha_emision',
                'fc.fecha_vencimiento',
                'fc.imp_total',
                'fc.categoria',
                'fc.estado',
                'tc.nombre as tipo_comprobante',
                'tc.letra',
                'fc.punto_venta',
                'fc.numero',
                DB::raw("{$saldoExpr} AS saldo"),
            ])
            ->orderBy('fc.fecha_emision')
            ->get()
            ->filter(fn ($r) => round((float) $r->saldo, 2) > 0)
            ->map(function ($r) use ($fechaCorte) {
                $r->dias_vencido = $r->fecha_vencimiento
                    ? $fechaCorte->diffInDays(Carbon::parse($r->fecha_vencimiento), false) * -1
                    : 0;
                return $r;
            })
            ->values()
            ->all();

        return $rows;
    }

    private function resolverMonedaId(string $codigo): int
    {
        $id = (int) DB::table('erp_monedas')->where('codigo', $codigo)->value('id');
        if (! $id) {
            throw new \DomainException("MONEDA_INVALIDA: codigo '{$codigo}' no existe en erp_monedas.");
        }
        return $id;
    }
}
