<?php

namespace App\Erp\Http\Controllers\Presupuesto;

use App\Erp\Models\Presupuesto\Presupuesto;
use App\Erp\Models\Presupuesto\PresupuestoItem;
use App\Erp\Services\Presupuesto\PresupuestoService;
use App\Erp\Services\Presupuesto\VariacionesService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Presupuestos (SPEC 06 §6.5 + §6.6).
 *
 *   GET    /presupuestos               ?ejercicio=&estado=
 *   POST   /presupuestos               crear borrador
 *   GET    /presupuestos/{id}
 *   PUT    /presupuestos/{id}          editar cabecera (BORRADOR)
 *   POST   /presupuestos/{id}/aprobar  BORRADOR → APROBADO
 *   POST   /presupuestos/{id}/vigente  → VIGENTE (anterior → HISTORICO RN-85)
 *   POST   /presupuestos/{id}/descartar → DESCARTADO
 *   POST   /presupuestos/{id}/reforecast {nombre} → clona BORRADOR
 *   POST   /presupuestos/{id}/items    bulk upsert
 *   DELETE /presupuestos/{id}/items/{itemId}
 *   GET    /presupuestos/{id}/items    ?cuenta=&cc=&mes=
 *   GET    /presupuestos/{id}/variaciones
 *   GET    /presupuestos/{id}/variaciones/resumen?por=cuenta|cc
 *   GET    /presupuestos/{id}/ejecucion?hasta_mes=
 */
class PresupuestosController extends Controller
{
    public function __construct(
        private readonly PresupuestoService $service,
        private readonly VariacionesService $variaciones,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = Presupuesto::with(['ejercicio', 'creador'])
            ->where('empresa_id', $this->empresaId($request))
            ->when($request->query('ejercicio_id'), fn ($q, $v) => $q->where('ejercicio_id', (int) $v))
            ->when($request->query('estado'),       fn ($q, $v) => $q->where('estado', $v))
            ->orderByDesc('created_at');
        return response()->json(['ok' => true, 'data' => $q->paginate((int) $request->query('per_page', 50))]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $p = Presupuesto::with(['ejercicio', 'creador', 'aprobador', 'base', 'versiones'])
            ->where('empresa_id', $this->empresaId($request))->findOrFail($id);
        return response()->json(['ok' => true, 'data' => $p]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'ejercicio_id' => ['required', 'integer'],
            'nombre'       => ['required', 'string', 'max:100'],
            'moneda'       => ['nullable', 'string', 'size:3'],
            'descripcion'  => ['nullable', 'string'],
        ]);
        $datos['empresa_id'] = $this->empresaId($request);
        $p = $this->service->crear($datos, $request->user());
        return response()->json(['ok' => true, 'data' => $p], Response::HTTP_CREATED);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $p = $this->presupuesto($id, $request);
        $datos = $request->validate([
            'nombre'      => ['nullable', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string'],
            'moneda'      => ['nullable', 'string', 'size:3'],
        ]);
        try {
            $p = $this->service->actualizarCabecera($p, $datos, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $p]);
    }

    public function aprobar(int $id, Request $request): JsonResponse  { return $this->transicion($id, 'APROBADO', $request); }
    public function vigente(int $id, Request $request): JsonResponse  { return $this->transicion($id, 'VIGENTE', $request); }
    public function descartar(int $id, Request $request): JsonResponse { return $this->transicion($id, 'DESCARTADO', $request); }

    public function reforecast(int $id, Request $request): JsonResponse
    {
        $p = $this->presupuesto($id, $request);
        $datos = $request->validate(['nombre' => ['required', 'string', 'max:100']]);
        try {
            $nuevo = $this->service->reforecast($p, $datos['nombre'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $nuevo], Response::HTTP_CREATED);
    }

    public function bulkItems(int $id, Request $request): JsonResponse
    {
        $p = $this->presupuesto($id, $request);
        $datos = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.cuenta_id'      => ['required', 'integer'],
            'items.*.centro_costo_id'=> ['nullable', 'integer'],
            'items.*.mes'            => ['required', 'integer', 'min:1', 'max:12'],
            'items.*.importe'        => ['required', 'numeric'],
            'items.*.notas'          => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $res = $this->service->bulkItems($p, $datos['items'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function listItems(int $id, Request $request): JsonResponse
    {
        $p = $this->presupuesto($id, $request);
        $q = PresupuestoItem::with(['cuenta', 'centroCosto'])
            ->where('presupuesto_id', $p->id)
            ->when($request->query('cuenta_id'),       fn ($q, $v) => $q->where('cuenta_id', (int) $v))
            ->when($request->query('centro_costo_id'), fn ($q, $v) => $q->where('centro_costo_id', (int) $v))
            ->when($request->query('mes'),             fn ($q, $v) => $q->where('mes', (int) $v))
            ->orderBy('cuenta_id')->orderBy('mes');
        return response()->json(['ok' => true, 'data' => $q->get()]);
    }

    public function deleteItem(int $id, int $itemId, Request $request): JsonResponse
    {
        $p = $this->presupuesto($id, $request);
        try {
            $this->service->eliminarItem($p, $itemId, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true]);
    }

    public function variaciones(int $id, Request $request): JsonResponse
    {
        $p = $this->presupuesto($id, $request);
        $filtros = $request->validate([
            'anio'           => ['nullable', 'integer'],
            'cuenta_id'      => ['nullable', 'integer'],
            'centro_costo_id'=> ['nullable', 'integer'],
            'mes'            => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        return response()->json(['ok' => true, 'data' => $this->variaciones->detalle($p, $filtros)]);
    }

    public function variacionesResumen(int $id, Request $request): JsonResponse
    {
        $p = $this->presupuesto($id, $request);
        $por = $request->query('por', 'cuenta');
        if (! in_array($por, ['cuenta', 'cc'], true)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'POR_INVALIDO']], 422);
        }
        return response()->json(['ok' => true, 'data' => $this->variaciones->resumen($p, $por)]);
    }

    public function ejecucion(int $id, Request $request): JsonResponse
    {
        $p = $this->presupuesto($id, $request);
        $hastaMes = $request->query('hasta_mes') !== null ? (int) $request->query('hasta_mes') : null;
        return response()->json(['ok' => true, 'data' => $this->variaciones->ejecucion($p, $hastaMes)]);
    }

    private function transicion(int $id, string $estado, Request $request): JsonResponse
    {
        $p = $this->presupuesto($id, $request);
        try {
            $p = $this->service->transicionar($p, $estado, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $p]);
    }

    private function presupuesto(int $id, Request $request): Presupuesto
    {
        return Presupuesto::where('empresa_id', $this->empresaId($request))->findOrFail($id);
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
