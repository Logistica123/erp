<?php

namespace App\Erp\Http\Controllers\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Services\Impuestos\PeriodoFiscalService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Períodos fiscales (SPEC 05 §6.1).
 *
 *   GET    /api/erp/impuestos/periodos                        — listar
 *   POST   /api/erp/impuestos/periodos                        — crear
 *   GET    /api/erp/impuestos/periodos/{id}                   — detalle
 *   PATCH  /api/erp/impuestos/periodos/{id}                   — transicionar estado
 *   POST   /api/erp/impuestos/periodos/{id}/rectificativa     — generar rectificativa
 */
class PeriodosFiscalesController extends Controller
{
    public function __construct(
        private readonly PeriodoFiscalService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = PeriodoFiscal::query()
            ->where('empresa_id', $this->empresaId($request))
            ->when($request->query('impuesto'), fn ($q, $v) => $q->where('impuesto', $v))
            ->when($request->query('anio'),     fn ($q, $v) => $q->where('anio', (int) $v))
            ->when($request->query('mes'),      fn ($q, $v) => $q->where('mes', (int) $v))
            ->when($request->query('estado'),   fn ($q, $v) => $q->where('estado', $v))
            ->orderByDesc('anio')->orderByDesc('mes')->orderBy('impuesto');

        return response()->json(['ok' => true, 'data' => $q->paginate((int) $request->query('per_page', 50))]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $periodo = PeriodoFiscal::with(['libroIvaVentas', 'libroIvaCompras'])
            ->where('empresa_id', $this->empresaId($request))
            ->findOrFail($id);

        return response()->json(['ok' => true, 'data' => $periodo]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'impuesto'         => ['required', 'string'],
            'anio'             => ['required', 'integer', 'min:2024', 'max:2100'],
            'mes'              => ['nullable', 'integer', 'min:1', 'max:12'],
            'ejercicio_id'     => ['nullable', 'integer'],
            'fecha_vencimiento'=> ['nullable', 'date'],
            'observaciones'    => ['nullable', 'string'],
        ]);
        $datos['empresa_id'] = $this->empresaId($request);

        try {
            $periodo = $this->service->crear($datos, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $periodo], Response::HTTP_CREATED);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $periodo = PeriodoFiscal::where('empresa_id', $this->empresaId($request))->findOrFail($id);

        $datos = $request->validate([
            'estado'             => ['required', 'string'],
            'nro_tramite'        => ['nullable', 'string', 'max:50'],
            'fecha_presentacion' => ['nullable', 'date'],
            'acuse_path'         => ['nullable', 'string', 'max:500'],
            'observaciones'      => ['nullable', 'string'],
        ]);

        try {
            $periodo = $this->service->transicionar($periodo, $datos['estado'], $request->user(), $datos);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $periodo]);
    }

    public function rectificar(int $id, Request $request): JsonResponse
    {
        $periodo = PeriodoFiscal::where('empresa_id', $this->empresaId($request))->findOrFail($id);
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5']]);

        try {
            $rect = $this->service->rectificar($periodo, $datos['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $rect], Response::HTTP_CREATED);
    }

    private function empresaId(Request $request): int
    {
        // En SPEC 01 la empresa por usuario está en su perfil; placeholder mientras
        // unificamos: la empresa actual es la 1 (LogísticaArgentinaSRL).
        return (int) ($request->header('X-Empresa-Id') ?: 1);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json([
            'ok' => false,
            'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
