<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\TransferenciaInterna;
use App\Erp\Services\TransferenciaInternaService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de transferencias internas entre cuentas propias (SPEC 02 §6.7).
 *
 *   GET  /api/erp/transferencias-internas
 *   POST /api/erp/transferencias-internas
 *   POST /api/erp/transferencias-internas/{id}/contabilizar
 *   POST /api/erp/transferencias-internas/{id}/anular
 */
class TransferenciasInternasController
{
    public function __construct(private readonly TransferenciaInternaService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = TransferenciaInterna::query()
            ->with([
                'cuentaOrigen:id,codigo,nombre',
                'cuentaDestino:id,codigo,nombre',
                'monedaOrigen:id,codigo',
                'monedaDestino:id,codigo',
            ])
            ->orderByDesc('fecha')->orderByDesc('id');

        if ($estado = $request->string('estado')->toString()) {
            $query->where('estado', $estado);
        }

        return response()->json(['ok' => true, 'data' => $query->paginate(100)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'cuenta_origen_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'cuenta_destino_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id', 'different:cuenta_origen_id'],
            'importe_origen' => ['required', 'numeric', 'gt:0'],
            'importe_destino' => ['nullable', 'numeric', 'gt:0'],
            'tipo_cambio' => ['nullable', 'numeric', 'gt:0'],
            'concepto' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $ti = $this->service->registrar([
                ...$data,
                'empresa_id' => $request->user()->erpPerfil?->empresa_id ?? 1,
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $ti], 201);
    }

    public function contabilizar(Request $request, int $id): JsonResponse
    {
        $ti = TransferenciaInterna::findOrFail($id);

        try {
            $ti = $this->service->contabilizar($ti, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $ti]);
    }

    public function anular(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $ti = TransferenciaInterna::findOrFail($id);

        try {
            $ti = $this->service->anular($ti, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $ti]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
