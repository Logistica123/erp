<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Services\OrdenPagoService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrdenesPagoController
{
    public function __construct(private readonly OrdenPagoService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = OrdenPago::query()
            ->with(['auxiliar:id,codigo,nombre,tipo', 'moneda:id,codigo'])
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        if ($estado = $request->string('estado')->toString()) {
            $query->where('estado', $estado);
        }
        if ($tipo = $request->string('tipo')->toString()) {
            $query->where('tipo', $tipo);
        }
        if ($auxId = $request->integer('auxiliar_id')) {
            $query->where('auxiliar_id', $auxId);
        }

        return response()->json(['data' => $query->limit(100)->get()]);
    }

    public function show(int $id): JsonResponse
    {
        $op = OrdenPago::with([
            'auxiliar:id,codigo,nombre,tipo',
            'moneda:id,codigo',
            'asiento:id,numero,fecha',
            'items',
            'medios.medioPago:id,codigo,nombre',
            'medios.cuentaBancaria:id,codigo,nombre',
        ])->findOrFail($id);

        return response()->json(['data' => $op]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'tipo' => ['nullable', 'in:PROVEEDOR,DISTRIBUIDOR,LIQUIDACION_DISTRIBUIDOR,OTROS'],
            'auxiliar_id' => ['required', 'integer'],
            'liq_encabezado_id' => ['nullable', 'integer'],
            'moneda_id' => ['required', 'integer'],
            'cotizacion' => ['nullable', 'numeric'],
            'importe' => ['required', 'numeric', 'min:0.01'],
            'importe_bruto' => ['nullable', 'numeric'],
            'total_retenciones' => ['nullable', 'numeric'],
            'concepto' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array'],
            'items.*.tipo_item' => ['required_with:items', Rule::in(['FACTURA_COMPRA', 'ADELANTO', 'REINTEGRO', 'RETENCION', 'OTRO'])],
            'items.*.comprobante_id' => ['nullable', 'integer'],
            'items.*.cuenta_contable_id' => ['nullable', 'integer'],
            'items.*.concepto' => ['required_with:items', 'string'],
            'items.*.importe' => ['required_with:items', 'numeric'],
            'medios' => ['sometimes', 'array'],
            'medios.*.medio_pago_id' => ['required_with:medios', 'integer'],
            'medios.*.cuenta_bancaria_id' => ['nullable', 'integer'],
            'medios.*.importe' => ['required_with:medios', 'numeric'],
            'medios.*.referencia' => ['nullable', 'string'],
        ]);

        try {
            $op = $this->service->crear([
                ...$data,
                'empresa_id' => $request->user()->erpPerfil?->empresa_id ?? 1,
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op->load('auxiliar', 'moneda', 'items', 'medios')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'fecha' => ['nullable', 'date'],
            'tipo' => ['nullable', 'in:PROVEEDOR,DISTRIBUIDOR,LIQUIDACION_DISTRIBUIDOR,OTROS'],
            'moneda_id' => ['nullable', 'integer'],
            'cotizacion' => ['nullable', 'numeric'],
            'importe' => ['nullable', 'numeric', 'min:0.01'],
            'importe_bruto' => ['nullable', 'numeric'],
            'total_retenciones' => ['nullable', 'numeric'],
            'concepto' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array'],
            'medios' => ['sometimes', 'array'],
        ]);

        $op = OrdenPago::findOrFail($id);

        try {
            $op = $this->service->actualizar($op, $data);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op]);
    }

    public function cargarBanco(Request $request, int $id): JsonResponse
    {
        $op = OrdenPago::findOrFail($id);

        try {
            $op = $this->service->cargarBanco($op, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op]);
    }

    public function liberar(Request $request, int $id): JsonResponse
    {
        $op = OrdenPago::findOrFail($id);

        try {
            $op = $this->service->liberar($op, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $op = OrdenPago::findOrFail($id);

        try {
            $op = $this->service->rechazar($op, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op]);
    }

    public function pagar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer'],
            'concepto' => ['nullable', 'string', 'max:500'],
        ]);

        $op = OrdenPago::findOrFail($id);

        try {
            $result = $this->service->pagar($op, $data['cuenta_bancaria_id'], $request->user(), $data['concepto'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'data' => [
                'op' => $result['op']->load('auxiliar', 'asiento'),
                'movimiento_bancario_id' => $result['movimiento']->id,
                'asiento_id' => $result['asiento_id'],
            ],
        ]);
    }

    public function anular(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $op = OrdenPago::findOrFail($id);

        try {
            $op = $this->service->anular($op, $data['motivo']);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
