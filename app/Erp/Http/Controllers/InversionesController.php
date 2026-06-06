<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\InversionesService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InversionesController
{
    public function __construct(private readonly InversionesService $service) {}

    public function index(Request $request): JsonResponse
    {
        $empresaId = (int) $request->query('empresa_id', 1);
        $tipo = $request->query('tipo') ?: null;
        $activoQ = $request->query('activo');
        $activo = $activoQ === null ? null : (bool) $activoQ;
        $data = $this->service->listar($empresaId, $tipo, $activo);
        $totales = $this->service->totales($empresaId);
        return response()->json(['ok' => true, 'data' => ['inversiones' => $data, 'totales' => $totales]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'empresa_id' => ['required', 'integer', 'exists:erp_empresas,id'],
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', 'in:FCI,PLAZO_FIJO,CAUCION,BONO,OTRO'],
            'entidad' => ['required', 'string', 'max:80'],
            'moneda' => ['nullable', 'string', 'size:3'],
            'cuenta_contable_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'fecha_alta' => ['required', 'date'],
            'plazo_dias' => ['nullable', 'integer', 'min:1'],
            'tasa_nominal' => ['nullable', 'numeric'],
            'fecha_vencimiento' => ['nullable', 'date'],
        ]);
        try {
            $id = $this->service->crear([...$data, 'usuario_id' => $request->user()->id]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => ['id' => $id]], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $inv = DB::table('erp_inversiones')->find($id);
        if (! $inv) abort(404);
        return response()->json(['ok' => true, 'data' => $inv]);
    }

    public function movimientos(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->service->movimientos($id)]);
    }

    public function registrarMovimiento(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:SUSCRIPCION,RESCATE,INTERES,CONSTITUCION,VENCIMIENTO,AJUSTE_SALDO_FONDO'],
            'fecha' => ['required', 'date'],
            'importe' => ['required', 'numeric', 'min:0'],
            'saldo_segun_fondo' => ['nullable', 'numeric'],
            'cuenta_bancaria_id' => ['nullable', 'integer'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $movId = $this->service->registrarMovimiento($id, [...$data, 'usuario_id' => $request->user()->id]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => ['mov_id' => $movId]], 201);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
