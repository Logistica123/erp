<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\Cobro;
use App\Erp\Services\CobroService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
