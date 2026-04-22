<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\ArqueoCaja;
use App\Erp\Models\Tesoreria\Caja;
use App\Erp\Services\ArqueoCajaService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de caja física (SPEC 02 §6.8, RN-16/22/23).
 *
 *   GET  /api/erp/caja/movimientos     ?caja_id=&desde=&hasta=
 *   GET  /api/erp/caja/arqueos         historial por caja
 *   POST /api/erp/caja/arqueo          registra arqueo + ajuste contable si hay diferencia
 *   GET  /api/erp/caja/fechas-sin-arqueo  ?caja_id=&desde=&hasta=  (RN-22)
 */
class CajaController
{
    public function __construct(private readonly ArqueoCajaService $service) {}

    public function movimientos(Request $request): JsonResponse
    {
        $data = $request->validate([
            'caja_id' => ['required', 'integer', 'exists:erp_cajas,id'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);

        $caja = Caja::findOrFail($data['caja_id']);
        $movs = $this->service->movimientos($caja, $data['desde'], $data['hasta']);

        return response()->json([
            'ok' => true,
            'data' => [
                'caja' => ['id' => $caja->id, 'codigo' => $caja->codigo, 'nombre' => $caja->nombre, 'saldo_actual' => $caja->saldo_actual],
                'rango' => ['desde' => $data['desde'], 'hasta' => $data['hasta']],
                'movimientos' => $movs,
            ],
        ]);
    }

    public function arqueos(Request $request): JsonResponse
    {
        $data = $request->validate([
            'caja_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $query = ArqueoCaja::query()
            ->with(['caja:id,codigo,nombre', 'asientoAjuste:id,numero,fecha', 'realizadoPor:id,name'])
            ->when($data['caja_id'] ?? null, fn ($q, $v) => $q->where('caja_id', $v))
            ->when($data['desde'] ?? null, fn ($q, $v) => $q->where('fecha', '>=', $v))
            ->when($data['hasta'] ?? null, fn ($q, $v) => $q->where('fecha', '<=', $v))
            ->orderByDesc('fecha')->orderByDesc('id');

        return response()->json(['ok' => true, 'data' => $query->paginate(100)]);
    }

    public function registrarArqueo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'caja_id' => ['required', 'integer', 'exists:erp_cajas,id'],
            'fecha' => ['required', 'date'],
            'saldo_fisico' => ['required', 'numeric'],
            'motivo' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $arqueo = $this->service->registrar([
                ...$data,
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $arqueo->fresh(['caja', 'asientoAjuste'])], 201);
    }

    public function fechasSinArqueo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'caja_id' => ['required', 'integer', 'exists:erp_cajas,id'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);

        $fechas = $this->service->fechasSinArqueo($data['caja_id'], $data['desde'], $data['hasta']);

        return response()->json([
            'ok' => true,
            'data' => [
                'caja_id' => $data['caja_id'],
                'rango' => ['desde' => $data['desde'], 'hasta' => $data['hasta']],
                'fechas_sin_arqueo' => $fechas,
                'cantidad' => count($fechas),
            ],
        ]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
