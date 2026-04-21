<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Ejercicio;
use App\Erp\Services\EjercicioService;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EjerciciosController
{
    use AuthorizesRequests;

    public function __construct(private readonly EjercicioService $service) {}

    /**
     * POST /api/erp/ejercicios/{id}/cerrar
     */
    public function cerrar(Request $request, int $id): JsonResponse
    {
        $ejercicio = Ejercicio::findOrFail($id);
        $this->authorize('cerrar', $ejercicio);

        try {
            $result = $this->service->cerrar($ejercicio, $request->user());
        } catch (DomainException $e) {
            $code = explode(':', $e->getMessage(), 2)[0] ?? 'DOMINIO';

            return response()->json([
                'error' => ['code' => $code, 'message' => $e->getMessage()],
            ], 409);
        }

        return response()->json([
            'data' => [
                'ejercicio' => $result['ejercicio'],
                'asiento_refundicion' => $result['asiento_refundicion']->load('movimientos'),
                'resultado' => $result['resultado'],
            ],
        ]);
    }

    /**
     * POST /api/erp/ejercicios/{id}/reabrir
     * Permiso sensible: contabilidad.ejercicios.reabrir (solo super_admin).
     */
    public function reabrir(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $ejercicio = Ejercicio::findOrFail($id);
        $this->authorize('reabrir', $ejercicio);

        try {
            $ejercicio = $this->service->reabrir($ejercicio, $request->user(), $data['motivo']);
        } catch (DomainException $e) {
            $code = explode(':', $e->getMessage(), 2)[0] ?? 'DOMINIO';

            return response()->json([
                'error' => ['code' => $code, 'message' => $e->getMessage()],
            ], 409);
        }

        return response()->json(['data' => $ejercicio]);
    }
}
