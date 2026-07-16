<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\Tesoreria\CalendarioCobrosService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calendario de cobros proyectados (2026-07-15):
 *   GET /tesoreria/calendario-cobros?desde&hasta
 *   GET /tesoreria/plazos-cobro
 *   PUT /tesoreria/plazos-cobro/{auxiliarId}   {dias: int|null}
 */
class CalendarioCobrosController
{
    public function __construct(private readonly CalendarioCobrosService $svc) {}

    public function calendario(Request $request): JsonResponse
    {
        $desde = Carbon::parse($request->query('desde', now()->startOfMonth()->toDateString()));
        $hasta = Carbon::parse($request->query('hasta', now()->addDays(60)->toDateString()));

        return response()->json(['ok' => true, 'data' => $this->svc->calendario($desde, $hasta)]);
    }

    public function plazos(): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->svc->plazos()]);
    }

    public function guardarPlazo(int $auxiliarId, Request $request): JsonResponse
    {
        $data = $request->validate(['dias' => ['nullable', 'integer', 'min:0', 'max:365']]);
        try {
            $this->svc->guardarPlazo($auxiliarId, $data['dias'] ?? null, $request->user()->id);
        } catch (DomainException $e) {
            $code = explode(':', $e->getMessage(), 2)[0];

            return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 422);
        }

        return response()->json(['ok' => true]);
    }
}
