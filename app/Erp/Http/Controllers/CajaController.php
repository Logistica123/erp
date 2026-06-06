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
            // v1.42 — grilla billete a billete (opcional pero recomendado).
            'denominaciones' => ['nullable', 'array'],
            'denominaciones.*.valor' => ['required_with:denominaciones', 'numeric', 'min:0.01'],
            'denominaciones.*.cantidad' => ['required_with:denominaciones', 'integer', 'min:0'],
        ]);

        try {
            $arqueo = $this->service->registrar([
                ...$data,
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $arqueo->fresh(['caja', 'asientoAjuste', 'denominaciones'])], 201);
    }

    /**
     * v1.42 — Autorizar un arqueo en PENDIENTE_AUTORIZACION.
     * POST /api/erp/caja/arqueos/{id}/autorizar
     *   body: { decision: AJUSTAR|CERRAR_CON_DISCREPANCIA|RECHAZAR, motivo: string }
     */
    public function autorizarArqueo(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:AJUSTAR,CERRAR_CON_DISCREPANCIA,RECHAZAR'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);
        $arqueo = ArqueoCaja::findOrFail($id);

        try {
            $arqueo = $this->service->autorizar($arqueo, [
                ...$data,
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'ok' => true,
            'data' => $arqueo->fresh(['caja', 'asientoAjuste', 'autorizadoPor', 'denominaciones']),
        ]);
    }

    /**
     * v1.42 — Listado de arqueos PENDIENTE_AUTORIZACION.
     * GET /api/erp/caja/arqueos-pendientes
     */
    public function arqueosPendientes(Request $request): JsonResponse
    {
        $rows = ArqueoCaja::query()
            ->with(['caja:id,codigo,nombre', 'realizadoPor:id,name'])
            ->where('estado', 'PENDIENTE_AUTORIZACION')
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /**
     * v1.42 — Catálogo de denominaciones activas (para el form del arqueo).
     * GET /api/erp/caja/denominaciones-catalogo?moneda=ARS
     */
    public function denominacionesCatalogo(Request $request): JsonResponse
    {
        $moneda = strtoupper((string) $request->query('moneda', 'ARS'));
        $rows = \Illuminate\Support\Facades\DB::table('erp_caja_denominaciones_catalogo')
            ->where('moneda', $moneda)->where('activa', 1)
            ->orderBy('orden_presentacion')
            ->get(['id', 'moneda', 'valor', 'descripcion']);
        return response()->json(['ok' => true, 'data' => $rows]);
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
