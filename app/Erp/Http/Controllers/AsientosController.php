<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Asiento;
use App\Erp\Services\AsientoService;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsientosController
{
    use AuthorizesRequests;

    public function __construct(private readonly AsientoService $service) {}

    /**
     * GET /api/erp/asientos
     *   ?estado=BORRADOR|CONTABILIZADO|ANULADO
     *   ?periodo_id=
     *   ?diario_id=
     *   ?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
     *   ?cuenta_id=           (asientos que toquen esa cuenta)
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asiento::class);

        $empresaId = $this->empresaIdFromRequest($request);

        $query = Asiento::query()
            ->where('empresa_id', $empresaId)
            ->with(['diario:id,codigo,nombre', 'periodo:id,anio,mes'])
            ->orderByDesc('fecha')
            ->orderByDesc('numero');

        if ($estado = $request->string('estado')->toString()) {
            $query->where('estado', $estado);
        }
        if ($periodoId = $request->integer('periodo_id')) {
            $query->where('periodo_id', $periodoId);
        }
        if ($diarioId = $request->integer('diario_id')) {
            $query->where('diario_id', $diarioId);
        }
        if ($desde = $request->date('desde')) {
            $query->where('fecha', '>=', $desde);
        }
        if ($hasta = $request->date('hasta')) {
            $query->where('fecha', '<=', $hasta);
        }
        if ($cuentaId = $request->integer('cuenta_id')) {
            $query->whereHas('movimientos', fn ($q) => $q->where('cuenta_id', $cuentaId));
        }

        return response()->json([
            'data' => $query->limit(100)->get(),
            'meta' => ['limit' => 100],
        ]);
    }

    /**
     * GET /api/erp/asientos/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $asiento = Asiento::where('empresa_id', $empresaId)
            ->with([
                'diario:id,codigo,nombre',
                'periodo:id,anio,mes,estado',
                'movimientos.cuenta:id,codigo,nombre,imputable,admite_cc,admite_auxiliar',
                'movimientos.centroCosto:id,codigo,nombre',
                'movimientos.auxiliar:id,codigo,nombre,cuit',
            ])
            ->findOrFail($id);
        $this->authorize('view', $asiento);

        return response()->json(['data' => $asiento]);
    }

    /**
     * POST /api/erp/asientos
     * Crea BORRADOR.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Asiento::class);

        $data = $request->validate([
            'diario_id' => ['required', 'integer'],
            'fecha' => ['required', 'date'],
            'glosa' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string'], // v1.15 Sprint M
            'movimientos' => ['required', 'array', 'min:2'],
            'movimientos.*.cuenta_id' => ['nullable', 'integer'],
            'movimientos.*.cuenta_codigo' => ['nullable', 'string', 'max:20'],
            'movimientos.*.centro_costo_id' => ['nullable', 'integer'],
            'movimientos.*.auxiliar_id' => ['nullable', 'integer'],
            'movimientos.*.glosa' => ['nullable', 'string', 'max:300'],
            'movimientos.*.debe' => ['required', 'numeric', 'min:0'],
            'movimientos.*.haber' => ['required', 'numeric', 'min:0'],
            'movimientos.*.moneda' => ['nullable', 'string', 'size:3'],
            'movimientos.*.importe_origen' => ['nullable', 'numeric'],
            'movimientos.*.cotizacion' => ['nullable', 'numeric'],
        ]);

        try {
            $asiento = $this->service->crearBorrador([
                ...$data,
                'empresa_id' => $this->empresaIdFromRequest($request),
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $asiento->load('movimientos')], 201);
    }

    /**
     * POST /api/erp/asientos/{id}/contabilizar
     */
    public function contabilizar(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $asiento = Asiento::where('empresa_id', $empresaId)->findOrFail($id);
        $this->authorize('contabilizar', $asiento);

        try {
            $asiento = $this->service->contabilizar($asiento);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $asiento->load('movimientos')]);
    }

    /**
     * POST /api/erp/asientos/{id}/anular
     */
    public function anular(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $empresaId = $this->empresaIdFromRequest($request);
        $asiento = Asiento::where('empresa_id', $empresaId)->findOrFail($id);
        $this->authorize('anular', $asiento);

        try {
            $asiento = $this->service->anular($asiento, $request->user()->id, $data['motivo']);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $asiento->load(['movimientos', 'asientoReversa'])]);
    }

    /**
     * DELETE /api/erp/asientos/{id}
     * Solo elimina borradores.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $asiento = Asiento::where('empresa_id', $empresaId)->findOrFail($id);
        $this->authorize('delete', $asiento);

        if ($asiento->estado !== Asiento::ESTADO_BORRADOR) {
            return response()->json(
                ['message' => 'Solo se eliminan asientos BORRADOR. Usá /anular para revertir contabilizados.'],
                409
            );
        }

        $asiento->movimientos()->delete();
        $asiento->delete();

        return response()->json(['message' => 'Asiento eliminado.']);
    }

    private function empresaIdFromRequest(Request $request): int
    {
        $perfil = $request->user()->erpPerfil ?? null;

        return $perfil?->empresa_id ?? 1;
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0] ?? 'DOMINIO';
        // v1.15 Sprint M+: las validaciones de input (cuenta inexistente/no
        // imputable, CC/auxiliar requerido, líneas inválidas) van con 422 en
        // lugar del genérico 400 anterior. El mensaje ya viene "línea N: …"
        // del service, así que el frontend puede parsear y mostrar inline.
        $status = match ($code) {
            'PERIODO_BLOQUEADO',
            'ESTADO_INVALIDO' => 409,
            'CUENTA_NO_ENCONTRADA',
            'CUENTA_NO_IMPUTABLE',
            'CC_REQUERIDO',
            'AUXILIAR_REQUERIDO',
            'LINEA_INVALIDA',
            'ASIENTO_MINIMO',
            'ASIENTO_DESBALANCEADO' => 422,
            default => 422,
        };

        return response()->json([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $e->getMessage(),
            ],
        ], $status);
    }
}
