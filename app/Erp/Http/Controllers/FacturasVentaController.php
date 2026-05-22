<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Services\CobroFacturaService;
use App\Erp\Services\EmisorFacturaService;
use App\Erp\Services\FacturaVentaService;
use App\Erp\Services\FceService;
use App\Erp\Support\AuditLogger;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints read-only de facturas de venta contra erp_facturas_venta.
 * Joins con tipos_comprobante, puntos_venta, auxiliares, monedas y asiento.
 */
class FacturasVentaController extends Controller
{
    public function __construct(
        private EmisorFacturaService $emisor,
        private CobroFacturaService $cobrador,
        private FacturaVentaService $service,
        private FceService $fce,
        private AuditLogger $audit,
    ) {}

    public function fceAceptada(Request $request, int $id): JsonResponse
    {
        $factura = FacturaVenta::where('empresa_id', 1)->findOrFail($id);
        try {
            $factura = $this->fce->aceptar($factura, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura]);
    }

    public function fceRechazada(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:300']]);
        $factura = FacturaVenta::where('empresa_id', 1)->findOrFail($id);
        try {
            $factura = $this->fce->rechazar($factura, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura]);
    }

    public function controlar(Request $request, int $id): JsonResponse
    {
        $factura = FacturaVenta::where('empresa_id', 1)->findOrFail($id);

        try {
            $factura = $this->service->controlar($factura, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura->load('asiento')]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $factura = FacturaVenta::where('empresa_id', 1)->findOrFail($id);

        try {
            $factura = $this->service->rechazar($factura, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura]);
    }

    /**
     * Emite NC que cancela (total o parcial) la factura. RN-33.
     * Si importe=null, se cancela el saldo remanente (total - NCs previas).
     */
    public function anular(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
            'importe' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $factura = FacturaVenta::where('empresa_id', 1)->findOrFail($id);

        try {
            $nc = $this->service->anular($factura, $data['motivo'], $data['importe'] ?? null, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'nota_credito' => $nc,
                'factura_original_id' => $factura->id,
                'factura_original_estado' => $factura->fresh()->estado,
            ],
        ], 201);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }

    /**
     * v1.29 — DELETE de factura de venta con lógica condicional por origen + CAE.
     *
     * - origen=MANUAL → permiso `compras.facturas.borrar_masivo` (super_admin/contador)
     * - origen WS con CAE válido → permiso `ventas.facturas.eliminar_ws` (sensible=2)
     * - origen WS sin CAE (ERROR_EMISION) → permiso `ventas.facturas.eliminar_sin_cae`
     *
     * Body requerido para WS con CAE válido: { confirm_text: "ELIMINAR", motivo: ≥20 chars }.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $factura = FacturaVenta::where('empresa_id', 1)->findOrFail($id);

        // Clasificación.
        $esWs = in_array($factura->origen, ['EMITIDA', 'WSFE', 'WS', 'WSFE_ERP', 'MIS_COMPROBANTES'], true);
        $tieneCae = ! empty($factura->cae);
        $caeVigente = $tieneCae
            && $factura->estado !== 'EMISION_FALLIDA'
            && $factura->estado !== 'ANULADA_POR_NC';

        // Resolver permiso requerido.
        if (! $esWs) {
            $permiso = 'compras.facturas.borrar_masivo'; // reutilizamos del v1.22 §13
        } elseif ($esWs && $caeVigente) {
            $permiso = 'ventas.facturas.eliminar_ws';
        } else {
            $permiso = 'ventas.facturas.eliminar_sin_cae';
        }

        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($permiso)) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => $esWs && $caeVigente
                    ? 'Las facturas emitidas por Web Service no se pueden eliminar. Para anular, emití una Nota de Crédito. Si la factura quedó sin CAE válido, contactá a Sebastián para autorización temporal.'
                    : "Falta permiso {$permiso}",
            ]], 403);
        }

        // Validación adicional para el caso sensible=2 (WS con CAE).
        if ($esWs && $caeVigente) {
            $data = $request->validate([
                'confirm_text' => ['required', 'string', 'in:ELIMINAR'],
                'motivo' => ['required', 'string', 'min:20', 'max:500'],
            ]);
        } else {
            $data = $request->validate([
                'motivo' => ['nullable', 'string', 'max:500'],
            ]);
        }
        $motivo = trim((string) ($data['motivo'] ?? ''));

        // Snapshot completo antes del DELETE.
        $snapshot = [
            'id' => $factura->id,
            'numero' => $factura->numero,
            'tipo_comprobante_id' => $factura->tipo_comprobante_id,
            'punto_venta_id' => $factura->punto_venta_id,
            'fecha_emision' => $factura->fecha_emision?->toDateString(),
            'imp_total' => (float) $factura->imp_total,
            'origen' => $factura->origen,
            'estado' => $factura->estado,
            'cae' => $factura->cae,
            'asiento_id' => $factura->asiento_id,
            'auxiliar_id' => $factura->auxiliar_id,
        ];

        $descripcion = sprintf(
            '%s factura venta #%d (origen=%s, cae=%s). Permiso: %s. Motivo: %s',
            $esWs ? '[WS]' : '[MANUAL]', $factura->id,
            $factura->origen, $factura->cae ?? 'SIN_CAE',
            $permiso, $motivo ?: 'sin motivo',
        );

        \Illuminate\Support\Facades\DB::transaction(function () use ($factura, $snapshot, $descripcion, $request, $permiso) {
            \Illuminate\Support\Facades\DB::statement('SET @erp_current_user_id = ?', [$request->user()->id]);

            // Borrar asiento si existe (CASCADE en movimientos por trigger v1.50.5).
            if ($factura->asiento_id) {
                \Illuminate\Support\Facades\DB::table('erp_facturas_venta')
                    ->where('id', $factura->id)
                    ->update(['asiento_id' => null]);
                \Illuminate\Support\Facades\DB::table('erp_asientos')
                    ->where('id', $factura->asiento_id)->delete();
            }

            $this->audit->log('factura_venta_eliminada', $factura, $snapshot, null, $descripcion);
            $factura->forceDelete();

            // Marcar el permiso temporal como usado (si vino de uno temporal).
            $request->user()->erpPerfil?->marcarPermisoTemporalUsado($permiso);
        });

        return response()->json(null, 204);
    }

    public function cobrar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'medio_pago_id' => ['required', 'integer'],
            'caja_id' => ['nullable', 'integer'],
            'cuenta_bancaria_id' => ['nullable', 'integer'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);
        $data['factura_id'] = $id;
        $user = $request->user();
        try {
            $result = $this->cobrador->cobrar($data, 1, $user?->id ?? 1);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Cobro registrado OK', ...$result]);
    }

    /**
     * Catálogos combinados para el form de Nueva Factura:
     * clientes activos, tipos de comprobante, PVs, alícuotas IVA, monedas.
     */
    public function catalogosEmision(Request $request): JsonResponse
    {
        return response()->json([
            // Addendum v1.14: incluimos centro_costo_id+codigo derivado del
            // CC asociado al auxiliar (1:1) para mostrarlo read-only en el form.
            'clientes' => DB::table('erp_auxiliares as a')
                ->leftJoin('erp_centros_costo as cc', 'cc.auxiliar_id', '=', 'a.id')
                ->where('a.empresa_id', 1)->where('a.tipo', 'Cliente')->where('a.activo', 1)
                ->orderBy('a.nombre')
                ->get([
                    'a.id', 'a.nombre', 'a.cuit', 'a.codigo',
                    'cc.id as centro_costo_id', 'cc.codigo as centro_costo_codigo',
                ]),
            'jurisdicciones' => DB::table('erp_iibb_jurisdicciones')
                ->where('activa', 1)->orderBy('codigo')
                ->get(['codigo', 'nombre']),
            'tipos_comprobante' => DB::table('erp_tipos_comprobante')
                ->where('activo', 1)
                ->whereIn('clase', ['FACTURA', 'NOTA_CREDITO', 'NOTA_DEBITO'])
                ->orderBy('id')
                ->get(['id', 'codigo_interno', 'nombre', 'letra', 'clase', 'discrimina_iva']),
            'puntos_venta' => DB::table('erp_puntos_venta')
                ->where('empresa_id', 1)->where('activo', 1)->where('bloqueado', 0)
                ->orderBy('numero')
                ->get(['id', 'numero', 'nombre', 'tipo_emision']),
            'alicuotas_iva' => DB::table('erp_alicuotas_iva')
                ->where('activo', 1)->orderBy('tasa')
                ->get(['id', 'codigo_interno', 'nombre', 'tasa']),
            'monedas' => DB::table('erp_monedas')
                ->where('activa', 1)->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre', 'simbolo']),
            'medios_pago' => DB::table('erp_medios_pago')
                ->where('activo', 1)->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre', 'afecta_caja', 'afecta_banco']),
            'cajas' => DB::table('erp_cajas')
                ->where('empresa_id', 1)->where('activo', 1)->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre']),
            'cuentas_bancarias' => DB::table('erp_cuentas_bancarias')
                ->where('empresa_id', 1)->where('activo', 1)->whereNull('deleted_at')
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function emitir(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'integer'],
            'tipo_comprobante_id' => ['required', 'integer'],
            'punto_venta_id' => ['required', 'integer'],
            'concepto_afip' => ['required', 'integer', 'min:1', 'max:3'],
            'fecha_emision' => ['required', 'date'],
            'moneda_id' => ['nullable', 'integer'],
            'cotizacion' => ['nullable', 'numeric', 'min:0.0001'],
            // Multi-item (preferido)
            'items' => ['nullable', 'array', 'min:1', 'max:50'],
            'items.*.descripcion' => ['required_with:items', 'string', 'max:500'],
            'items.*.cantidad' => ['required_with:items', 'numeric', 'min:0.0001'],
            'items.*.precio_unit' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.alicuota_iva_id' => ['required_with:items', 'integer'],
            // Single-item (back-compat)
            'descripcion' => ['nullable', 'string', 'max:500'],
            'cantidad' => ['nullable', 'numeric', 'min:0.0001'],
            'precio_unit' => ['nullable', 'numeric', 'min:0'],
            'alicuota_iva_id' => ['nullable', 'integer'],
            // Addendum v1.14: período trabajado (YYYY-MM o YYYY-MM-Q1/Q2)
            // y jurisdicción IIBB (código AFIP 901-924). Ambos opcionales.
            'periodo_trabajado_texto' => ['nullable', 'string', 'max:20'],
            'jurisdiccion_codigo' => ['nullable', 'string', 'size:3'],
        ]);

        $user = $request->user();
        try {
            $result = $this->emisor->emitir($data, 1, $user?->id ?? 1);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Factura emitida OK', ...$result]);
    }

    public function index(Request $request)
    {
        $q = $this->baseQuery();
        $this->aplicarFiltros($q, $request);

        // v1.27 Sprint D — paginate server-side (antes limit(200) hardcoded,
        // espejo del v1.42 compras).
        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(10, min(500, $perPage));
        $paginator = $q->orderByDesc('f.fecha_emision')
            ->orderByDesc('f.id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * v1.27 Sprint D §14 — Export XLSX de facturas de venta (espejo v1.49).
     */
    public function exportXlsx(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $q = DB::table('erp_facturas_venta as f')
            ->leftJoin('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->leftJoin('erp_puntos_venta as pv', 'pv.id', '=', 'f.punto_venta_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->leftJoin('erp_monedas as m', 'm.id', '=', 'f.moneda_id')
            ->leftJoin('erp_asientos as asi', 'asi.id', '=', 'f.asiento_id')
            ->where('f.empresa_id', 1)
            ->whereNull('f.deleted_at')
            ->select([
                'f.id', 'f.fecha_emision',
                'tc.codigo_interno as tipo_codigo', 'tc.letra',
                'pv.numero as pto_vta', 'f.numero', 'f.cae',
                'a.cuit as cliente_cuit', 'a.nombre as cliente_nombre',
                'm.codigo as moneda',
                'f.imp_neto_gravado', 'f.imp_iva',
                'f.imp_neto_gravado_21', 'f.imp_iva_21',
                'f.imp_neto_gravado_10_5', 'f.imp_iva_10_5',
                'f.imp_neto_gravado_27', 'f.imp_iva_27',
                'f.imp_no_gravado', 'f.imp_exento',
                'f.imp_total',
                'f.estado', 'f.origen', 'f.verificada_arca',
                'f.periodo_trabajado_texto', 'f.jurisdiccion_codigo',
                'asi.numero as asiento_numero',
            ]);

        $this->aplicarFiltros($q, $request);
        $rows = $q->orderBy('f.fecha_emision')->orderBy('f.id')->get();

        $sheet = (new \PhpOffice\PhpSpreadsheet\Spreadsheet())->getActiveSheet();
        $sheet->setTitle('Facturas Venta');
        $headers = [
            'ID', 'Fecha Emisión', 'Tipo', 'Letra', 'PV', 'Número', 'CAE',
            'CUIT Cliente', 'Cliente', 'Moneda',
            'Neto Gravado', 'IVA',
            'Neto 21%', 'IVA 21%',
            'Neto 10.5%', 'IVA 10.5%',
            'Neto 27%', 'IVA 27%',
            'No Gravado', 'Exento', 'Total',
            'Estado', 'Origen', 'Verif. ARCA',
            'Período Trabajado', 'Juris. IIBB',
            'Asiento #',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)).'1')
            ->getFont()->setBold(true);

        $rowIdx = 2;
        foreach ($rows as $r) {
            $sheet->fromArray([
                $r->id,
                $this->isoToDmy($r->fecha_emision),
                $r->tipo_codigo, $r->letra ?? '',
                $r->pto_vta, $r->numero, $r->cae,
                $r->cliente_cuit, $r->cliente_nombre,
                $r->moneda,
                (float) $r->imp_neto_gravado, (float) $r->imp_iva,
                (float) ($r->imp_neto_gravado_21 ?? 0), (float) ($r->imp_iva_21 ?? 0),
                (float) ($r->imp_neto_gravado_10_5 ?? 0), (float) ($r->imp_iva_10_5 ?? 0),
                (float) ($r->imp_neto_gravado_27 ?? 0), (float) ($r->imp_iva_27 ?? 0),
                (float) $r->imp_no_gravado, (float) $r->imp_exento,
                (float) $r->imp_total,
                $r->estado, $r->origen, ((int) $r->verificada_arca) === 1 ? 'SI' : 'NO',
                $r->periodo_trabajado_texto, $r->jurisdiccion_codigo,
                $r->asiento_numero ? '#'.$r->asiento_numero : '',
            ], null, 'A'.$rowIdx);
            $rowIdx++;
        }
        foreach (range('K', 'U') as $col) {
            $sheet->getStyle("{$col}2:{$col}{$rowIdx}")
                ->getNumberFormat()->setFormatCode('#,##0.00');
        }
        foreach (range('A', 'Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = sprintf('facturas_venta_%s.xlsx', now()->format('Ymd_His'));
        return response()->stream(function () use ($sheet) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet->getParent()))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    private function isoToDmy(?string $iso): ?string
    {
        if (! $iso) return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }
        return $iso;
    }

    private function baseQuery()
    {
        return DB::table('erp_facturas_venta as f')
            ->leftJoin('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->leftJoin('erp_puntos_venta as pv', 'pv.id', '=', 'f.punto_venta_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->leftJoin('erp_monedas as m', 'm.id', '=', 'f.moneda_id')
            ->leftJoin('erp_asientos as asi', 'asi.id', '=', 'f.asiento_id')
            ->where('f.empresa_id', 1)
            ->whereNull('f.deleted_at')
            ->select([
                'f.id', 'f.numero', 'f.cae', 'f.fecha_vto_cae', 'f.fecha_emision',
                'f.imp_neto_gravado', 'f.imp_iva', 'f.imp_total', 'f.origen', 'f.estado',
                'f.verificada_arca', 'f.es_fce', 'f.created_at',
                'f.periodo_trabajado_texto', 'f.jurisdiccion_codigo',
                'tc.codigo_interno as tipo_codigo', 'tc.nombre as tipo_nombre', 'tc.letra',
                'tc.clase as tipo_clase', 'tc.signo as tipo_signo',
                'pv.numero as pto_vta',
                'a.id as cliente_id', 'a.nombre as cliente_nombre', 'a.cuit as cliente_cuit',
                'm.codigo as moneda',
                'f.asiento_id', 'asi.numero as asiento_numero', 'asi.estado as asiento_estado',
            ]);
    }

    private function aplicarFiltros($q, Request $request): void
    {
        if ($desde = $request->query('desde')) {
            $q->where('f.fecha_emision', '>=', $desde);
        }
        if ($hasta = $request->query('hasta')) {
            $q->where('f.fecha_emision', '<=', $hasta);
        }
        // v1.27 Sprint D — filtros por imputación (espejo v1.49 compras).
        if ($impDesde = $request->query('imp_desde')) {
            $q->where('f.fecha_emision', '>=', $impDesde);
        }
        if ($impHasta = $request->query('imp_hasta')) {
            $q->where('f.fecha_emision', '<=', $impHasta);
        }
        if ($estado = $request->query('estado')) {
            $q->where('f.estado', $estado);
        }
        if ($origen = $request->query('origen')) {
            $q->where('f.origen', $origen);
        }
        if ($periodoTrab = $request->query('periodo_trabajado')) {
            if ($periodoTrab === '__VACIOS__') {
                $q->where(function ($sub) {
                    $sub->whereNull('f.periodo_trabajado_texto')
                        ->orWhere('f.periodo_trabajado_texto', '');
                });
            } else {
                $q->where('f.periodo_trabajado_texto', $periodoTrab);
            }
        }
        if ($juris = $request->query('jurisdiccion')) {
            $q->where('f.jurisdiccion_codigo', $juris);
        }
    }

    public function show(int $id)
    {
        $factura = DB::table('erp_facturas_venta as f')
            ->leftJoin('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->leftJoin('erp_puntos_venta as pv', 'pv.id', '=', 'f.punto_venta_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->leftJoin('erp_condiciones_iva as ci', 'ci.id', '=', 'f.condicion_iva_id')
            ->leftJoin('erp_monedas as m', 'm.id', '=', 'f.moneda_id')
            ->leftJoin('erp_asientos as asi', 'asi.id', '=', 'f.asiento_id')
            ->where('f.empresa_id', 1)
            ->where('f.id', $id)
            ->select([
                'f.*',
                'tc.codigo_interno as tipo_codigo', 'tc.nombre as tipo_nombre', 'tc.letra',
                'tc.clase as tipo_clase', 'tc.signo as tipo_signo',
                'pv.numero as pto_vta',
                'a.nombre as cliente_nombre', 'a.cuit as cliente_cuit', 'a.tipo as cliente_tipo',
                'ci.codigo_interno as condicion_iva_codigo', 'ci.nombre as condicion_iva_nombre',
                'm.codigo as moneda',
                'asi.numero as asiento_numero', 'asi.estado as asiento_estado', 'asi.fecha as asiento_fecha',
            ])
            ->first();

        if (!$factura) {
            return response()->json(['message' => 'Factura no encontrada'], 404);
        }

        $items = DB::table('erp_factura_venta_items as i')
            ->leftJoin('erp_alicuotas_iva as ai', 'ai.id', '=', 'i.alicuota_iva_id')
            ->where('i.factura_id', $id)
            ->select('i.*', 'ai.nombre as alicuota_nombre', 'ai.tasa as alicuota_tasa')
            ->orderBy('i.nro_linea')
            ->get();

        $iva = DB::table('erp_factura_venta_iva as v')
            ->leftJoin('erp_alicuotas_iva as ai', 'ai.id', '=', 'v.alicuota_iva_id')
            ->where('v.factura_id', $id)
            ->select('v.*', 'ai.nombre as alicuota_nombre', 'ai.tasa as alicuota_tasa')
            ->get();

        $asientoMovs = null;
        if ($factura->asiento_id) {
            $asientoMovs = DB::table('erp_movimientos_asiento as m')
                ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
                ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'm.auxiliar_id')
                ->where('m.asiento_id', $factura->asiento_id)
                ->select('m.linea', 'c.codigo', 'c.nombre as cuenta_nombre',
                    'm.debe', 'm.haber', 'a.nombre as auxiliar_nombre')
                ->orderBy('m.linea')
                ->get();
        }

        return response()->json([
            'factura' => $factura,
            'items' => $items,
            'iva' => $iva,
            'asiento_movimientos' => $asientoMovs,
        ]);
    }

    /**
     * v1.27 — Listado de períodos trabajados distinct para el dropdown del filtro.
     */
    public function periodosTrabajadosDistinct(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $rows = Cache::remember(
            "facturas_venta.periodos_trabajados.{$empresaId}",
            300,
            fn () => DB::table('erp_facturas_venta')
                ->where('empresa_id', $empresaId)
                ->whereNotNull('periodo_trabajado_texto')
                ->where('periodo_trabajado_texto', '!=', '')
                ->whereNull('deleted_at')
                ->distinct()
                ->orderByDesc('periodo_trabajado_texto')
                ->pluck('periodo_trabajado_texto')
                ->all(),
        );
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /**
     * v1.27 — PATCH período trabajado individual de una factura de venta.
     */
    public function patchPeriodoTrabajado(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'ventas.facturas.editar');

        $data = $request->validate([
            'periodo_trabajado_texto' => ['nullable', 'string', 'max:20', 'regex:/^(|\d{4}-\d{2}(-Q[12])?)$/'],
        ]);
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $factura = FacturaVenta::where('empresa_id', $empresaId)->findOrFail($id);

        $old = $factura->periodo_trabajado_texto;
        $new = $data['periodo_trabajado_texto'] ?: null;
        $factura->update(['periodo_trabajado_texto' => $new]);

        $this->audit->log('periodo_trabajado_editado', $factura,
            ['periodo_trabajado_texto' => $old],
            ['periodo_trabajado_texto' => $new],
            sprintf('Factura venta #%d: período trabajado %s → %s', $id, $old ?? 'NULL', $new ?? 'NULL'),
        );
        Cache::forget("facturas_venta.periodos_trabajados.{$empresaId}");

        return response()->json(['ok' => true, 'data' => $factura->fresh()]);
    }

    /**
     * v1.27 — PATCH período trabajado masivo de facturas de venta.
     */
    public function patchPeriodosTrabajadosBulk(Request $request): JsonResponse
    {
        $this->mustHave($request, 'ventas.facturas.editar');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'periodo_trabajado_texto' => ['nullable', 'string', 'max:20', 'regex:/^(|\d{4}-\d{2}(-Q[12])?)$/'],
        ]);
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $new = $data['periodo_trabajado_texto'] ?: null;

        $facturas = FacturaVenta::where('empresa_id', $empresaId)
            ->whereIn('id', $data['ids'])->get(['id', 'periodo_trabajado_texto']);

        if ($facturas->isEmpty()) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'FACTURAS_NO_ENCONTRADAS',
                'message' => 'Ningún ID coincide con facturas de la empresa.',
            ]], 422);
        }

        $snapshot = $facturas->mapWithKeys(fn ($f) => [$f->id => $f->periodo_trabajado_texto])->all();

        DB::transaction(function () use ($facturas, $new, $empresaId) {
            FacturaVenta::where('empresa_id', $empresaId)
                ->whereIn('id', $facturas->pluck('id')->all())
                ->update(['periodo_trabajado_texto' => $new]);
        });

        $this->audit->log('periodo_trabajado_editado_masivo', $facturas->first(),
            ['old_values' => $snapshot],
            ['new_value' => $new, 'count' => $facturas->count()],
            sprintf('Edición masiva de período trabajado en %d facturas de venta → %s',
                $facturas->count(), $new ?? 'NULL'),
        );
        Cache::forget("facturas_venta.periodos_trabajados.{$empresaId}");

        return response()->json(['ok' => true, 'data' => ['updated' => $facturas->count()]]);
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => "Falta permiso {$codigo}",
            ]], 403));
        }
    }
}
