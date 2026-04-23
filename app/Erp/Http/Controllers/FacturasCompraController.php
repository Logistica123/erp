<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Services\FacturaCompraService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints de facturas de compra (SPEC 03 §6.3).
 *
 *   GET   /api/erp/facturas-compra                     Lista con filtros
 *   GET   /api/erp/facturas-compra/{id}                Detalle
 *   POST  /api/erp/facturas-compra                     Alta manual (con constatación RN-42)
 *   PATCH /api/erp/facturas-compra/{id}                Edición (solo RECIBIDA)
 *   POST  /api/erp/facturas-compra/{id}/controlar      El tilde: RECIBIDA→CONTROLADA, asiento RN-34
 *   POST  /api/erp/facturas-compra/{id}/observar       Marca OBSERVADA con motivo
 *   POST  /api/erp/facturas-compra/{id}/rechazar       Marca RECHAZADA
 */
class FacturasCompraController extends Controller
{
    public function __construct(private readonly FacturaCompraService $service) {}

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('erp_facturas_compra as f')
            ->leftJoin('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->leftJoin('erp_monedas as m', 'm.id', '=', 'f.moneda_id')
            ->leftJoin('erp_asientos as asi', 'asi.id', '=', 'f.asiento_id')
            ->where('f.empresa_id', 1)
            ->whereNull('f.deleted_at')
            ->select([
                'f.id', 'f.numero', 'f.cae', 'f.fecha_emision', 'f.fecha_vencimiento',
                'f.imp_neto_gravado', 'f.imp_iva', 'f.imp_total',
                'f.origen', 'f.estado', 'f.constatacion_estado',
                'tc.codigo_interno as tipo_codigo', 'tc.letra', 'tc.clase as tipo_clase',
                'f.punto_venta',
                'a.id as proveedor_id', 'a.nombre as proveedor_nombre', 'a.cuit as proveedor_cuit',
                'f.cuit_emisor', 'f.razon_social_emisor',
                'm.codigo as moneda',
                'f.asiento_id', 'asi.numero as asiento_numero',
            ]);

        if ($estado = $request->query('estado')) {
            $q->where('f.estado', $estado);
        }
        if ($proveedor = $request->integer('proveedor_id')) {
            $q->where('f.auxiliar_id', $proveedor);
        }
        if ($desde = $request->query('desde')) {
            $q->where('f.fecha_emision', '>=', $desde);
        }
        if ($hasta = $request->query('hasta')) {
            $q->where('f.fecha_emision', '<=', $hasta);
        }
        if ($origen = $request->query('origen')) {
            $q->where('f.origen', $origen);
        }

        return response()->json([
            'data' => $q->orderByDesc('f.fecha_emision')->orderByDesc('f.id')->limit(200)->get(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $f = FacturaCompra::with([
            'tipoComprobante', 'auxiliar', 'condicionIva', 'moneda', 'asiento',
            'items.alicuotaIva', 'iva.alicuotaIva', 'tributos.tipoTributo', 'asociadas',
        ])
            ->where('empresa_id', 1)
            ->findOrFail($id);

        return response()->json(['data' => $f]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo_comprobante_id' => ['required', 'integer', 'exists:erp_tipos_comprobante,id'],
            'punto_venta' => ['required', 'integer'],
            'numero' => ['required', 'integer'],
            'cae' => ['nullable', 'string', 'max:20'],
            'fecha_vto_cae' => ['nullable', 'date'],
            'fecha_emision' => ['required', 'date'],
            'fecha_recepcion' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'auxiliar_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'cuit_emisor' => ['required', 'string', 'max:13'],
            'razon_social_emisor' => ['nullable', 'string', 'max:250'],
            'condicion_iva_id' => ['required', 'integer', 'exists:erp_condiciones_iva,id'],
            'moneda_id' => ['required', 'integer', 'exists:erp_monedas,id'],
            'cotizacion' => ['required', 'numeric', 'min:0.0001'],
            'imp_neto_gravado' => ['required', 'numeric', 'min:0'],
            'imp_no_gravado' => ['nullable', 'numeric', 'min:0'],
            'imp_exento' => ['nullable', 'numeric', 'min:0'],
            'imp_iva' => ['nullable', 'numeric', 'min:0'],
            'imp_tributos' => ['nullable', 'numeric', 'min:0'],
            'imp_percepciones' => ['nullable', 'numeric', 'min:0'],
            'imp_retenciones' => ['nullable', 'numeric', 'min:0'],
            'imp_total' => ['required', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'centro_costo_id' => ['nullable', 'integer'],
        ]);

        $factura = DB::transaction(function () use ($data, $request) {
            return FacturaCompra::create([
                ...$data,
                'empresa_id' => 1,
                'origen' => $data['cae'] ? 'MANUAL' : 'MANUAL',
                'estado' => FacturaCompraService::ESTADO_RECIBIDA,
                'constatacion_estado' => $data['cae'] ? 'PENDIENTE' : 'NO_APLICA',
                'imp_no_gravado' => $data['imp_no_gravado'] ?? 0,
                'imp_exento' => $data['imp_exento'] ?? 0,
                'imp_iva' => $data['imp_iva'] ?? 0,
                'imp_tributos' => $data['imp_tributos'] ?? 0,
                'imp_percepciones' => $data['imp_percepciones'] ?? 0,
                'imp_retenciones' => $data['imp_retenciones'] ?? 0,
                'created_by_user_id' => $request->user()->id,
            ]);
        });

        return response()->json(['ok' => true, 'data' => $factura], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $factura = FacturaCompra::where('empresa_id', 1)->findOrFail($id);

        if ($factura->estado !== FacturaCompraService::ESTADO_RECIBIDA) {
            return response()->json([
                'error' => ['code' => 'FACTURA_NO_EDITABLE', 'message' => 'Solo editable en estado RECIBIDA (actual: '.$factura->estado.')'],
            ], 409);
        }

        $data = $request->validate([
            'fecha_emision' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'imp_neto_gravado' => ['nullable', 'numeric', 'min:0'],
            'imp_iva' => ['nullable', 'numeric', 'min:0'],
            'imp_total' => ['nullable', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'centro_costo_id' => ['nullable', 'integer'],
        ]);

        $factura->update($data);

        return response()->json(['ok' => true, 'data' => $factura->fresh()]);
    }

    public function controlar(Request $request, int $id): JsonResponse
    {
        $factura = FacturaCompra::where('empresa_id', 1)->findOrFail($id);

        try {
            $factura = $this->service->controlar($factura, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura->load('asiento')]);
    }

    public function observar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $factura = FacturaCompra::where('empresa_id', 1)->findOrFail($id);

        try {
            $factura = $this->service->observar($factura, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $factura = FacturaCompra::where('empresa_id', 1)->findOrFail($id);

        try {
            $factura = $this->service->rechazar($factura, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
