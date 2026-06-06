<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\FlujoFondosService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v1.42 Fase B — Endpoints de Flujo de Fondos.
 */
class FlujoFondosController
{
    public function __construct(private readonly FlujoFondosService $service) {}

    public function escenariosIndex(Request $request): JsonResponse
    {
        $empresaId = (int) $request->query('empresa_id', 1);
        $anio = $request->query('anio') ? (int) $request->query('anio') : null;
        return response()->json(['ok' => true, 'data' => $this->service->listarEscenarios($empresaId, $anio)]);
    }

    public function escenariosStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'empresa_id' => ['required', 'integer', 'exists:erp_empresas,id'],
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', 'in:REALISTA,OPTIMISTA,PESIMISTA,CUSTOM'],
            'anio' => ['required', 'integer', 'min:2024', 'max:2100'],
            'descripcion' => ['nullable', 'string'],
            'es_default' => ['nullable', 'boolean'],
        ]);
        try {
            $id = $this->service->crearEscenario([
                ...$data,
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => ['id' => $id]], 201);
    }

    public function escenariosClonar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', 'in:REALISTA,OPTIMISTA,PESIMISTA,CUSTOM'],
            'anio' => ['nullable', 'integer'],
            'descripcion' => ['nullable', 'string'],
            'factor_proyectado' => ['nullable', 'numeric', 'min:0'],
        ]);
        try {
            $nuevoId = $this->service->clonarEscenario($id, [
                ...$data,
                'usuario_id' => $request->user()->id,
            ], (float) ($data['factor_proyectado'] ?? 1.0));
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => ['id' => $nuevoId]], 201);
    }

    public function matriz(Request $request, int $escenarioId): JsonResponse
    {
        $data = $request->validate([
            'granularidad' => ['nullable', 'in:DIA,SEMANA,MES'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);
        try {
            $matriz = $this->service->matriz(
                $escenarioId,
                $data['granularidad'] ?? 'MES',
                $data['desde'] ?? null,
                $data['hasta'] ?? null,
            );
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $matriz]);
    }

    public function overrideCelda(Request $request, int $escenarioId): JsonResponse
    {
        $data = $request->validate([
            'categoria_id' => ['required', 'integer', 'exists:erp_flujo_categorias,id'],
            'periodo_key' => ['required', 'string'],
            'nuevo_proyectado' => ['required', 'numeric'],
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        try {
            $id = $this->service->overrideCelda(
                $escenarioId,
                (int) $data['categoria_id'],
                (string) $data['periodo_key'],
                (float) $data['nuevo_proyectado'],
                (string) $data['motivo'],
                $request->user()->id,
            );
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => ['linea_id' => $id]], 201);
    }

    public function recalcular(Request $request, int $escenarioId): JsonResponse
    {
        try {
            $r = $this->service->recalcular($escenarioId, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $r]);
    }

    public function drill(Request $request, int $escenarioId): JsonResponse
    {
        $data = $request->validate([
            'categoria_id' => ['required', 'integer'],
            'periodo_key' => ['required', 'string'],
        ]);
        try {
            $rows = $this->service->drillCelda(
                $escenarioId, (int) $data['categoria_id'], (string) $data['periodo_key']
            );
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function categoriasIndex(Request $request): JsonResponse
    {
        $empresaId = (int) $request->query('empresa_id', 1);
        $rows = DB::table('erp_flujo_categorias')
            ->where('empresa_id', $empresaId)
            ->orderBy('tipo')->orderBy('orden_presentacion')
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function calendarioCobrosIndex(Request $request): JsonResponse
    {
        $rows = DB::table('erp_flujo_calendario_cobros as cc')
            ->join('erp_auxiliares as a', 'a.id', '=', 'cc.auxiliar_id')
            ->select(['cc.*', 'a.codigo as auxiliar_codigo', 'a.nombre as auxiliar_nombre'])
            ->orderBy('a.nombre')
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function calendarioCobrosUpsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auxiliar_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'periodicidad' => ['required', 'in:QUINCENAL,MENSUAL,EVENTUAL'],
            'dia_cobro_1q' => ['nullable', 'integer', 'min:1', 'max:31'],
            'dia_cobro_2q' => ['nullable', 'integer', 'min:1', 'max:31'],
            'dia_cobro_mensual' => ['nullable', 'integer', 'min:1', 'max:31'],
            'plazo_post_cierre_dias' => ['nullable', 'integer', 'min:0'],
            'porcentaje_q1' => ['nullable', 'numeric'],
            'porcentaje_q2' => ['nullable', 'numeric'],
            'observaciones' => ['nullable', 'string'],
            'activo' => ['nullable', 'boolean'],
        ]);
        DB::table('erp_flujo_calendario_cobros')->updateOrInsert(
            ['auxiliar_id' => $data['auxiliar_id']],
            [
                'periodicidad' => $data['periodicidad'],
                'dia_cobro_1q' => $data['dia_cobro_1q'] ?? null,
                'dia_cobro_2q' => $data['dia_cobro_2q'] ?? null,
                'dia_cobro_mensual' => $data['dia_cobro_mensual'] ?? null,
                'plazo_post_cierre_dias' => $data['plazo_post_cierre_dias'] ?? null,
                'porcentaje_q1' => $data['porcentaje_q1'] ?? null,
                'porcentaje_q2' => $data['porcentaje_q2'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                'activo' => (bool) ($data['activo'] ?? true),
            ],
        );
        return response()->json(['ok' => true]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
