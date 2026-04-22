<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Periodo;
use App\Erp\Services\PeriodoService;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodosController
{
    use AuthorizesRequests;

    public function __construct(private readonly PeriodoService $service) {}

    /**
     * POST /api/erp/periodos/{id}/cerrar
     */
    public function cerrar(Request $request, int $id): JsonResponse
    {
        $periodo = Periodo::findOrFail($id);
        $this->authorize('cerrar', $periodo);

        try {
            $periodo = $this->service->cerrar($periodo, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $periodo]);
    }

    /**
     * POST /api/erp/periodos/{id}/bloquear
     */
    public function bloquear(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $periodo = Periodo::findOrFail($id);
        $this->authorize('cerrar', $periodo);

        try {
            $periodo = $this->service->bloquear($periodo, $request->user(), $data['motivo']);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $periodo]);
    }

    /**
     * POST /api/erp/periodos/{id}/desbloquear
     */
    public function desbloquear(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $periodo = Periodo::findOrFail($id);
        $this->authorize('cerrar', $periodo);

        try {
            $periodo = $this->service->desbloquear($periodo, $request->user(), $data['motivo']);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $periodo]);
    }

    /**
     * POST /api/erp/periodos/{id}/reabrir
     */
    public function reabrir(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $periodo = Periodo::findOrFail($id);
        $this->authorize('reabrir', $periodo);

        try {
            $periodo = $this->service->reabrir($periodo, $request->user(), $data['motivo']);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $periodo]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0] ?? 'DOMINIO';

        return response()->json([
            'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], 409);
    }
}
