<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\Echeq;
use App\Erp\Services\EcheqService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de eCheq (SPEC 02 §6.4).
 *
 *   GET  /api/erp/echeq                     ?estado=&librador=&desde=&hasta=
 *   GET  /api/erp/echeq/{id}                detalle + historial
 *   POST /api/erp/echeq/{id}/depositar      body: { cuenta_bancaria_id }
 *   POST /api/erp/echeq/{id}/acreditar      body: { movimiento_bancario_id }
 *   POST /api/erp/echeq/{id}/rechazar       body: { motivo }
 *   POST /api/erp/echeq/{id}/anular         body: { motivo }
 */
class EcheqController
{
    public function __construct(private readonly EcheqService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'estado' => ['nullable', 'string'],
            'librador' => ['nullable', 'string'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $query = Echeq::query()
            ->with(['moneda:id,codigo', 'cuentaDeposito:id,codigo,nombre', 'cobro:id,numero'])
            ->when($data['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($data['librador'] ?? null, fn ($q, $v) => $q->where(function ($qq) use ($v) {
                $qq->where('cuit_librador', 'like', "%{$v}%")
                    ->orWhere('razon_social_librador', 'like', "%{$v}%");
            }))
            ->when($data['desde'] ?? null, fn ($q, $v) => $q->where('fecha_pago', '>=', $v))
            ->when($data['hasta'] ?? null, fn ($q, $v) => $q->where('fecha_pago', '<=', $v))
            ->orderBy('fecha_pago');

        return response()->json(['ok' => true, 'data' => $query->paginate(100)]);
    }

    public function show(int $id): JsonResponse
    {
        $echeq = Echeq::with([
            'moneda:id,codigo',
            'cuentaDeposito:id,codigo,nombre',
            'cobro:id,numero,fecha,estado',
            'movimientoBancario:id,fecha,concepto',
            'historial',
        ])->findOrFail($id);

        return response()->json(['ok' => true, 'data' => $echeq]);
    }

    public function depositar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id'],
        ]);

        $echeq = Echeq::findOrFail($id);

        try {
            $echeq = $this->service->depositar($echeq, $data['cuenta_bancaria_id'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $echeq]);
    }

    public function acreditar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'movimiento_bancario_id' => ['required', 'integer', 'exists:erp_movimientos_bancarios,id'],
        ]);

        $echeq = Echeq::findOrFail($id);

        try {
            $echeq = $this->service->acreditar($echeq, $data['movimiento_bancario_id'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $echeq]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $echeq = Echeq::findOrFail($id);

        try {
            $echeq = $this->service->rechazar($echeq, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $echeq]);
    }

    public function anular(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $echeq = Echeq::findOrFail($id);

        try {
            $echeq = $this->service->anular($echeq, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $echeq]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
