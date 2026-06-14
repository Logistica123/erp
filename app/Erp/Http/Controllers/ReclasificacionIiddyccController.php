<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\Contabilidad\ReclasificacionIiddyccService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v1.45 §9 — Reclasificación Imp Ley 25413.
 *   GET  /api/erp/contabilidad/iiddycc/saldo-acumulado?desde=&hasta=
 *   POST /api/erp/contabilidad/iiddycc/reclasificar
 */
class ReclasificacionIiddyccController
{
    public function __construct(private readonly ReclasificacionIiddyccService $svc) {}

    public function saldo(Request $request): JsonResponse
    {
        $this->mustHave($request);
        $data = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);
        return response()->json(['ok' => true, 'data' => $this->svc->saldoAcumulado($data['desde'], $data['hasta'])]);
    }

    public function reclasificar(Request $request): JsonResponse
    {
        $this->mustHave($request);
        $data = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'porcentaje' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'fecha' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $asiento = $this->svc->generar([...$data, 'usuario_id' => $request->user()->id]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => [
            'asiento_id' => $asiento->id,
            'numero' => $asiento->numero,
            'total_debe' => $asiento->total_debe,
        ]], 201);
    }

    private function mustHave(Request $request): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso('contabilidad.iiddycc.reclasificar')) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => 'Falta permiso contabilidad.iiddycc.reclasificar']], 403));
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
