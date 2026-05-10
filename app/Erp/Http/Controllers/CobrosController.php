<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\Cobro;
use App\Erp\Services\CobroService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Endpoints de cobros (SPEC 02 §6.6).
 *
 *   GET    /api/erp/cobros
 *   POST   /api/erp/cobros             items + medios (RN-27 balance)
 *   GET    /api/erp/cobros/{id}
 *   POST   /api/erp/cobros/{id}/anular
 */
class CobrosController
{
    public function __construct(private readonly CobroService $service) {}

    /**
     * GET /api/erp/cobros/items-cobrables?cliente_id={X}
     *
     * v1.15 Sprint O — Devuelve facturas/ND con saldo > 0 y NC libres del
     * cliente (saldo_imputable > 0). Solo items contables que tengan saldo
     * para aplicar/imputar contra un cobro.
     */
    public function itemsCobrables(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'integer'],
        ]);
        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;

        // Facturas/ND del cliente con saldo > 0.
        // saldo = imp_total * tc.signo  −  SUM(cobro_items.importe)  −  SUM(imputaciones_nc.importe)
        // Para FACTURAs y NDs, tc.signo = +1 → saldo deudor positivo.
        $facturas = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->leftJoin('erp_cobro_items as ci', 'ci.factura_id', '=', 'f.id')
            ->leftJoin('erp_cobros as co', function ($j) {
                $j->on('co.id', '=', 'ci.cobro_id')->whereNotIn('co.estado', ['ANULADO']);
            })
            ->leftJoin('erp_imputaciones_nc as inc', 'inc.factura_id', '=', 'f.id')
            ->where('f.empresa_id', $empresaId)
            ->where('f.auxiliar_id', $data['cliente_id'])
            ->whereNull('f.deleted_at')
            ->whereIn('tc.clase', ['FACTURA', 'NOTA_DEBITO'])
            ->whereIn('f.estado', ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL'])
            ->groupBy('f.id', 'f.numero', 'f.fecha_emision', 'f.imp_total',
                'f.punto_venta', 'tc.codigo_interno', 'tc.letra', 'tc.clase')
            ->select(
                'f.id', 'f.numero', 'f.fecha_emision', 'f.punto_venta', 'f.imp_total',
                'tc.codigo_interno as tipo_codigo', 'tc.letra', 'tc.clase as tipo_clase',
                DB::raw('COALESCE(SUM(DISTINCT ci.importe), 0) as cobrado'),
                DB::raw('COALESCE(SUM(DISTINCT inc.importe), 0) as imputado_nc'),
            )
            ->get()
            ->map(function ($r) {
                $saldo = (float) $r->imp_total - (float) $r->cobrado - (float) $r->imputado_nc;
                return [
                    'tipo' => strtolower($r->tipo_clase),
                    'id' => (int) $r->id,
                    'label' => sprintf('%s-%s %s-%s',
                        $r->tipo_codigo, $r->letra,
                        str_pad((string) $r->punto_venta, 4, '0', STR_PAD_LEFT),
                        str_pad((string) $r->numero, 8, '0', STR_PAD_LEFT)),
                    'fecha' => $r->fecha_emision,
                    'total' => (float) $r->imp_total,
                    'saldo' => round($saldo, 2),
                ];
            })
            ->filter(fn ($r) => $r['saldo'] > 0.005)
            ->values();

        // NC del cliente con saldo imputable.
        // NC tiene tc.signo = -1, por lo que imp_total ya viene firmado por
        // convención del seed o lo imputamos como abs() y lo restamos al cobro.
        $ncs = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->leftJoin('erp_imputaciones_nc as inc', 'inc.nc_id', '=', 'f.id')
            ->where('f.empresa_id', $empresaId)
            ->where('f.auxiliar_id', $data['cliente_id'])
            ->whereNull('f.deleted_at')
            ->where('tc.clase', 'NOTA_CREDITO')
            ->whereIn('f.estado', ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL'])
            ->groupBy('f.id', 'f.numero', 'f.fecha_emision', 'f.imp_total',
                'f.punto_venta', 'tc.codigo_interno', 'tc.letra')
            ->select(
                'f.id', 'f.numero', 'f.fecha_emision', 'f.punto_venta', 'f.imp_total',
                'tc.codigo_interno as tipo_codigo', 'tc.letra',
                DB::raw('COALESCE(SUM(inc.importe), 0) as imputado'),
            )
            ->get()
            ->map(function ($r) {
                // NC: imp_total siempre positivo en DB, signo va por tc.signo.
                $saldoImp = (float) $r->imp_total - (float) $r->imputado;
                return [
                    'tipo' => 'nc',
                    'id' => (int) $r->id,
                    'label' => sprintf('%s-%s %s-%s',
                        $r->tipo_codigo, $r->letra,
                        str_pad((string) $r->punto_venta, 4, '0', STR_PAD_LEFT),
                        str_pad((string) $r->numero, 8, '0', STR_PAD_LEFT)),
                    'fecha' => $r->fecha_emision,
                    'total' => -1 * (float) $r->imp_total, // signo negativo para presentación
                    'saldo' => round(-1 * $saldoImp, 2),
                    'saldo_imputable' => round($saldoImp, 2),
                ];
            })
            ->filter(fn ($r) => abs($r['saldo']) > 0.005)
            ->values();

        return response()->json([
            'ok' => true,
            'data' => $facturas->concat($ncs)->sortBy('fecha')->values()->all(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Cobro::query()
            ->with(['auxiliar:id,codigo,nombre', 'moneda:id,codigo'])
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        if ($estado = $request->string('estado')->toString()) {
            $query->where('estado', $estado);
        }
        if ($auxId = $request->integer('auxiliar_id')) {
            $query->where('auxiliar_id', $auxId);
        }
        if ($desde = $request->date('desde')) {
            $query->where('fecha', '>=', $desde);
        }
        if ($hasta = $request->date('hasta')) {
            $query->where('fecha', '<=', $hasta);
        }

        return response()->json(['ok' => true, 'data' => $query->paginate(100)]);
    }

    public function show(int $id): JsonResponse
    {
        $cobro = Cobro::with([
            'auxiliar:id,codigo,nombre',
            'moneda:id,codigo',
            'asiento:id,numero,fecha',
            'items',
            'medios.medioPago:id,codigo,nombre',
            'medios.caja:id,codigo,nombre',
            'medios.cuentaBancaria:id,codigo,nombre',
            'medios.echeq:id,numero,estado',
        ])->findOrFail($id);

        return response()->json(['ok' => true, 'data' => $cobro]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'auxiliar_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'moneda_id' => ['required', 'integer', 'exists:erp_monedas,id'],
            'cotizacion' => ['nullable', 'numeric'],
            'concepto' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'total_retenciones' => ['nullable', 'numeric'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.tipo_item' => ['required', Rule::in(['FACTURA_VENTA', 'NOTA_DEBITO', 'SEÑA', 'OTRO'])],
            'items.*.factura_id' => ['nullable', 'integer'],
            'items.*.cuenta_contable_id' => ['nullable', 'integer'],
            'items.*.concepto' => ['required', 'string'],
            'items.*.importe' => ['required', 'numeric', 'gt:0'],
            'medios' => ['required', 'array', 'min:1'],
            'medios.*.medio_pago_id' => ['required', 'integer', 'exists:erp_medios_pago,id'],
            'medios.*.caja_id' => ['nullable', 'integer'],
            'medios.*.cuenta_bancaria_id' => ['nullable', 'integer'],
            'medios.*.cuenta_contable_id' => ['nullable', 'integer'],
            'medios.*.importe' => ['required', 'numeric', 'gt:0'],
            'medios.*.referencia' => ['nullable', 'string', 'max:100'],
            'medios.*.echeq' => ['nullable', 'array'],
            'medios.*.echeq.numero' => ['required_with:medios.*.echeq', 'string'],
            'medios.*.echeq.cuit_librador' => ['required_with:medios.*.echeq', 'string'],
            'medios.*.echeq.razon_social_librador' => ['nullable', 'string'],
            'medios.*.echeq.banco_origen' => ['nullable', 'string'],
            'medios.*.echeq.cbu_origen' => ['nullable', 'string'],
            'medios.*.echeq.fecha_emision' => ['nullable', 'date'],
            'medios.*.echeq.fecha_pago' => ['nullable', 'date'],
        ]);

        try {
            $cobro = $this->service->registrar([
                ...$data,
                'empresa_id' => $request->user()->erpPerfil?->empresa_id ?? 1,
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $cobro], 201);
    }

    public function anular(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $cobro = Cobro::findOrFail($id);

        try {
            $cobro = $this->service->anular($cobro, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $cobro]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
