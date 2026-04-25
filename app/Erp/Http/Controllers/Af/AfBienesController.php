<?php

namespace App\Erp\Http\Controllers\Af;

use App\Erp\Models\Af\AfBien;
use App\Erp\Models\Af\AfMovimiento;
use App\Erp\Services\Af\AfBienService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Bienes AF (SPEC 06 §6.2).
 *
 *   GET    /af/bienes                        — listado con filtros
 *   POST   /af/bienes                        — alta manual
 *   POST   /af/bienes/activar-desde-factura  — RN-75
 *   GET    /af/bienes/{id}                   — detalle
 *   PUT    /af/bienes/{id}                   — editar (solo no contables)
 *   GET    /af/bienes/{id}/movimientos       — timeline
 */
class AfBienesController extends Controller
{
    public function __construct(
        private readonly AfBienService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = AfBien::with(['categoria', 'centroCosto', 'responsable'])
            ->where('empresa_id', $this->empresaId($request))
            ->when($request->query('estado'),       fn ($q, $v) => $q->where('estado', $v))
            ->when($request->query('categoria_id'), fn ($q, $v) => $q->where('categoria_id', (int) $v))
            ->when($request->query('cc_id'),        fn ($q, $v) => $q->where('centro_costo_id', (int) $v))
            ->when($request->query('responsable_user_id'), fn ($q, $v) => $q->where('responsable_user_id', (int) $v))
            ->when($request->query('q'), function ($q, $v) {
                $like = '%'.$v.'%';
                $q->where(function ($q2) use ($like) {
                    $q2->where('nro_inventario', 'like', $like)
                       ->orWhere('descripcion', 'like', $like)
                       ->orWhere('marca', 'like', $like)
                       ->orWhere('modelo', 'like', $like)
                       ->orWhere('nro_serie', 'like', $like)
                       ->orWhere('patente', 'like', $like);
                });
            })
            ->orderByDesc('fecha_alta')->orderBy('nro_inventario');

        return response()->json(['ok' => true, 'data' => $q->paginate((int) $request->query('per_page', 50))]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $bien = AfBien::with(['categoria', 'centroCosto', 'responsable', 'proveedor', 'facturaCompra'])
            ->where('empresa_id', $this->empresaId($request))->findOrFail($id);
        return response()->json(['ok' => true, 'data' => $bien]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);
        $datos['empresa_id'] = $this->empresaId($request);
        try {
            $bien = $this->service->alta($datos, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $bien], Response::HTTP_CREATED);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $bien = AfBien::where('empresa_id', $this->empresaId($request))->findOrFail($id);

        $datos = $request->validate([
            'descripcion'         => ['nullable', 'string', 'max:255'],
            'marca'               => ['nullable', 'string', 'max:60'],
            'modelo'              => ['nullable', 'string', 'max:60'],
            'nro_serie'           => ['nullable', 'string', 'max:100'],
            'patente'             => ['nullable', 'string', 'max:20'],
            'centro_costo_id'     => ['nullable', 'integer'],
            'responsable_user_id' => ['nullable', 'integer'],
            'ubicacion'           => ['nullable', 'string', 'max:100'],
            'estado'              => ['nullable', 'in:ALTA,EN_REPARACION,PRESTADO,BAJA'],
        ]);

        try {
            $bien = $this->service->editar($bien, $datos, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $bien]);
    }

    public function activarDesdeFactura(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'factura_compra_id' => ['required', 'integer'],
            'bienes'            => ['required', 'array', 'min:1'],
            'bienes.*.categoria_id'   => ['required', 'integer'],
            'bienes.*.nro_inventario' => ['required', 'string', 'max:30'],
            'bienes.*.descripcion'    => ['required', 'string', 'max:255'],
            'bienes.*.valor_origen'   => ['required', 'numeric', 'min:0'],
            'bienes.*.fecha_alta'     => ['nullable', 'date'],
            'bienes.*.centro_costo_id'      => ['nullable', 'integer'],
            'bienes.*.responsable_user_id'  => ['nullable', 'integer'],
            'bienes.*.ubicacion'            => ['nullable', 'string', 'max:100'],
            'bienes.*.marca'                => ['nullable', 'string', 'max:60'],
            'bienes.*.modelo'               => ['nullable', 'string', 'max:60'],
            'bienes.*.nro_serie'            => ['nullable', 'string', 'max:100'],
            'bienes.*.patente'              => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $bienes = $this->service->activarDesdeFactura(
                (int) $datos['factura_compra_id'], $datos['bienes'], $request->user()
            );
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $bienes], Response::HTTP_CREATED);
    }

    public function movimientos(int $id, Request $request): JsonResponse
    {
        $bien = AfBien::where('empresa_id', $this->empresaId($request))->findOrFail($id);
        $rows = AfMovimiento::with(['ccAnterior', 'ccNuevo', 'respAnterior', 'respNuevo', 'usuario'])
            ->where('bien_id', $bien->id)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'categoria_id'             => ['required', 'integer'],
            'nro_inventario'           => ['required', 'string', 'max:30'],
            'descripcion'              => ['required', 'string', 'max:255'],
            'fecha_alta'               => ['required', 'date'],
            'valor_origen'             => ['required', 'numeric', 'min:0.01'],
            'marca'                    => ['nullable', 'string', 'max:60'],
            'modelo'                   => ['nullable', 'string', 'max:60'],
            'nro_serie'                => ['nullable', 'string', 'max:100'],
            'patente'                  => ['nullable', 'string', 'max:20'],
            'moneda_origen'            => ['nullable', 'string', 'size:3'],
            'valor_origen_me'          => ['nullable', 'numeric'],
            'cotizacion_alta'          => ['nullable', 'numeric'],
            'valor_residual_cfg'       => ['nullable', 'numeric'],
            'vida_util_contable_meses' => ['nullable', 'integer', 'min:1'],
            'vida_util_fiscal_meses'   => ['nullable', 'integer', 'min:1'],
            'centro_costo_id'          => ['nullable', 'integer'],
            'responsable_user_id'      => ['nullable', 'integer'],
            'ubicacion'                => ['nullable', 'string', 'max:100'],
            'factura_compra_id'        => ['nullable', 'integer'],
            'proveedor_auxiliar_id'    => ['nullable', 'integer'],
        ]);
    }

    private function empresaId(Request $request): int
    {
        return (int) ($request->header('X-Empresa-Id') ?: 1);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json([
            'ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
