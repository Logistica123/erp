<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\Contabilidad\ReclasificacionPendientesService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v1.47 §14.3 — Saneamiento cuenta puente 1.1.6.99.
 */
class ReclasificacionPendientesController
{
    public function __construct(private readonly ReclasificacionPendientesService $svc) {}

    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request);
        return response()->json(['ok' => true, 'data' => $this->svc->pendientes()]);
    }

    public function saldo(Request $request): JsonResponse
    {
        $this->mustHave($request);
        return response()->json(['ok' => true, 'data' => $this->svc->saldo()]);
    }

    public function reclasificar(Request $request): JsonResponse
    {
        $this->mustHave($request);
        $data = $request->validate([
            'linea_id' => ['required', 'integer'],
            'cuenta_destino_id' => ['required', 'integer', 'exists:erp_cuentas_contables,id'],
            'auxiliar_id' => ['nullable', 'integer'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $asiento = $this->svc->reclasificar([...$data, 'usuario_id' => $request->user()->id]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => ['asiento_id' => $asiento->id, 'numero' => $asiento->numero]], 201);
    }

    private function mustHave(Request $request): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso('contabilidad.pendientes.reclasificar')) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => 'Falta permiso contabilidad.pendientes.reclasificar']], 403));
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
