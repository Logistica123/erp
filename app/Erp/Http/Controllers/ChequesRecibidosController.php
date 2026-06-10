<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\Tesoreria\ChequeRecibidoService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChequesRecibidosController
{
    public function __construct(private readonly ChequeRecibidoService $svc) {}

    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.ver');
        $filtros = $request->validate([
            'estado' => ['nullable', 'in:EN_CARTERA,DEPOSITADO,COBRADO,RECHAZADO,VENCIDO_NO_COBRADO'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'numero' => ['nullable', 'string'],
            'solo_vencidos_sin_cobrar' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        return response()->json(['ok' => true, 'data' => $this->svc->listar($filtros)]);
    }

    public function alertas(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.ver');
        return response()->json(['ok' => true, 'data' => $this->svc->alertasVencidos()]);
    }

    public function depositar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $data = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'fecha_deposito' => ['required', 'date'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $c = $this->svc->depositar($id, (int) $data['cuenta_bancaria_id'], $data['fecha_deposito'], $request->user()->id, $data['observaciones'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $c]);
    }

    public function cobrar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $data = $request->validate([
            'fecha_acreditacion' => ['required', 'date'],
            'mov_bancario_id' => ['nullable', 'integer'],
        ]);
        try {
            $c = $this->svc->cobrar($id, $data['fecha_acreditacion'], $request->user()->id, $data['mov_bancario_id'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $c]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        try {
            $c = $this->svc->rechazar($id, $data['motivo'], $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $c]);
    }

    public function marcarVencidos(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $n = $this->svc->marcarVencidos();
        return response()->json(['ok' => true, 'data' => ['actualizados' => $n]]);
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => "Falta permiso {$codigo}",
            ]], 403));
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
