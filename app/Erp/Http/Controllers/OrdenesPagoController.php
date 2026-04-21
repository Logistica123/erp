<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Services\OrdenPagoService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json([
            'data' => $query->limit(100)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'tipo' => ['nullable', 'in:PROVEEDOR,DISTRIBUIDOR,LIQUIDACION_DISTRIBUIDOR,OTROS'],
            'auxiliar_id' => ['required', 'integer'],
            'moneda_id' => ['required', 'integer'],
            'cotizacion' => ['nullable', 'numeric'],
            'importe' => ['required', 'numeric', 'min:0.01'],
            'concepto' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
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

        return response()->json(['data' => $op->load('auxiliar', 'moneda')], 201);
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
