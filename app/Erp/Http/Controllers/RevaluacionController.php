<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Periodo;
use App\Erp\Services\RevaluacionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Revaluación mensual USD (SPEC_01 RN-11).
 *   POST /api/erp/revaluacion/ejecutar    body: { periodo_id }
 */
class RevaluacionController
{
    public function __construct(private readonly RevaluacionService $service) {}

    public function ejecutar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'periodo_id' => ['required', 'integer', 'exists:erp_periodos,id'],
        ]);

        $periodo = Periodo::findOrFail($data['periodo_id']);

        try {
            $asiento = $this->service->ejecutar($periodo, $request->user());
        } catch (DomainException $e) {
            $code = explode(':', $e->getMessage(), 2)[0];

            return response()->json([
                'ok' => false,
                'error' => ['code' => $code, 'message' => $e->getMessage()],
            ], 409);
        }

        if ($asiento === null) {
            return response()->json([
                'ok' => true,
                'data' => ['asiento_id' => null, 'message' => 'Sin diferencias que ajustar.'],
            ]);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'asiento_id' => $asiento->id,
                'numero' => $asiento->numero,
                'total_debe' => $asiento->total_debe,
                'total_haber' => $asiento->total_haber,
            ],
        ], 201);
    }
}
