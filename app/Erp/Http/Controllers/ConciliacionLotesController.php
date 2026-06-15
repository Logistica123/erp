<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\Conciliacion\ConciliacionLoteService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v1.47 §15.5 — Conciliación en lote N:M.
 */
class ConciliacionLotesController
{
    public function __construct(private readonly ConciliacionLoteService $svc) {}

    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request);
        return response()->json(['ok' => true, 'data' => $this->svc->listar($request->only(['estado', 'auxiliar_id', 'per_page']))]);
    }

    public function candidatos(Request $request): JsonResponse
    {
        $this->mustHave($request);
        $data = $request->validate([
            'auxiliar_id' => ['required', 'integer'],
            'cuenta_bancaria_id' => ['nullable', 'integer'],
            'tipo_factura' => ['nullable', 'in:VENTA,COMPRA'],
        ]);
        return response()->json(['ok' => true, 'data' => $this->svc->candidatos(
            (int) $data['auxiliar_id'], $data['cuenta_bancaria_id'] ?? null, $data['tipo_factura'] ?? 'VENTA')]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request);
        try {
            return response()->json(['ok' => true, 'data' => $this->svc->detalle($id)]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request);
        $data = $request->validate([
            'auxiliar_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'cuenta_bancaria_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'signo' => ['required', 'in:+,-'],
            'movimientos' => ['required', 'array', 'min:1'],
            'movimientos.*' => ['integer'],
            'facturas' => ['required', 'array', 'min:1'],
            'facturas.*.id' => ['required', 'integer'],
            'facturas.*.tipo' => ['required', 'in:VENTA,COMPRA'],
            'facturas.*.monto' => ['required', 'numeric', 'min:0.01'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'motivo_diferencia' => ['nullable', 'string', 'max:255'],
            'cuenta_ajuste_id' => ['nullable', 'integer'],
        ]);
        try {
            $id = $this->svc->crear([...$data, 'usuario_id' => $request->user()->id]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => ['id' => $id]], 201);
    }

    public function confirmar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request);
        try { $this->svc->confirmar($id, $request->user()); }
        catch (DomainException $e) { return $this->domainError($e); }
        return response()->json(['ok' => true]);
    }

    public function revertir(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request);
        $data = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:255']]);
        try { $this->svc->revertir($id, $data['motivo'], $request->user()); }
        catch (DomainException $e) { return $this->domainError($e); }
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request);
        try { $this->svc->borrar($id, $request->user()); }
        catch (DomainException $e) { return $this->domainError($e); }
        return response()->json(['ok' => true]);
    }

    private function mustHave(Request $request): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso('conciliacion.lotes.administrar')) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => 'Falta permiso conciliacion.lotes.administrar']], 403));
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
