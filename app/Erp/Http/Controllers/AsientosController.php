<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Asiento;
use App\Erp\Services\AsientoService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AsientosController
{
    use AuthorizesRequests;

    /**
     * Tablas con FK asiento_id (NO ACTION) que hay que liberar antes del
     * hard-delete. erp_movimientos_asiento cascadea solo (no va acá).
     *
     * @var array<string,string> tabla => columna
     */
    private const FK_ASIENTO = [
        'erp_af_amortizaciones' => 'asiento_id',
        'erp_af_movimientos' => 'asiento_id',
        'erp_af_reexpresiones' => 'asiento_id',
        'erp_ajustes_retroactivos' => 'asiento_ajuste_id',
        'erp_arqueos_caja' => 'asiento_ajuste_id',
        'erp_cobros' => 'asiento_id',
        'erp_dias_contables' => 'asiento_cierre_id',
        'erp_emp_cc_movimientos' => 'asiento_id',
        'erp_emp_liquidaciones' => 'asiento_id',
        'erp_emp_pagos' => 'asiento_id',
        'erp_emp_prestamos' => 'asiento_alta_id',
        'erp_facturas_compra' => 'asiento_id',
        'erp_facturas_venta' => 'asiento_id',
        'erp_movimientos_bancarios' => 'asiento_id',
        'erp_ordenes_pago' => 'asiento_id',
        'erp_recibos' => 'asiento_id',
        'erp_transferencias_internas' => 'asiento_id',
        // erp_asientos.asiento_reversa_id es SET NULL automático.
    ];

    public function __construct(
        private readonly AsientoService $service,
        private readonly AuditLogger $audit,
    ) {}

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

    /**
     * DELETE /api/erp/asientos/{id}/definitivo
     *
     * Hard-delete de un asiento (cualquier estado, incluso CONTABILIZADO).
     * Opción C: NUNCA sin traza. Requiere super_admin + permiso sensible=2 +
     * MFA fresh + motivo. Deja audit log inmutable con snapshot completo del
     * asiento + sus movimientos + las FKs liberadas. Libera (set NULL) las
     * referencias en facturas/cobros/recibos/OP/etc antes de borrar.
     *
     * Uso previsto: limpiar asientos de prueba o errores graves de setup. Para
     * la operación contable normal de revertir un asiento, usar /anular.
     */
    public function eliminarDefinitivo(Request $request, int $id): JsonResponse
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso('contabilidad.asientos.eliminar_definitivo')) {
            return response()->json(['error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => 'Solo super_admin con permiso contabilidad.asientos.eliminar_definitivo.',
            ]], 403);
        }

        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $empresaId = $this->empresaIdFromRequest($request);
        $asiento = Asiento::where('empresa_id', $empresaId)->with('movimientos')->findOrFail($id);

        // Snapshot completo ANTES de tocar nada (audit inmutable).
        $snapshot = [
            'asiento' => $asiento->toArray(),
            'movimientos' => $asiento->movimientos->map->toArray()->all(),
            'hash_integridad' => $asiento->hash_integridad,
            'estado' => $asiento->estado,
        ];

        $resultado = DB::transaction(function () use ($asiento, $snapshot, $data, $request, $empresaId) {
            // 1) Liberar FKs (NO ACTION) en todas las tablas que lo referencian.
            $refsLiberadas = [];
            foreach (self::FK_ASIENTO as $tabla => $col) {
                $n = DB::table($tabla)->where($col, $asiento->id)->update([$col => null]);
                if ($n > 0) $refsLiberadas["{$tabla}.{$col}"] = $n;
            }
            // asiento_reversa_id de otros asientos (SET NULL auto, pero explicitamos).
            DB::table('erp_asientos')->where('asiento_reversa_id', $asiento->id)->update(['asiento_reversa_id' => null]);

            // 2) Borrar movimientos + asiento (movimientos cascadea igual, explícito).
            $asiento->movimientos()->delete();
            $asientoId = $asiento->id;
            $asiento->delete();

            // 3) Audit log inmutable (hash-chain) con snapshot + refs liberadas + motivo.
            $this->audit->log(
                'eliminado_definitivo',
                $asiento,
                array_merge($snapshot, ['refs_liberadas' => $refsLiberadas]),
                null,
                sprintf('Borrado DEFINITIVO asiento #%d (estado %s) por %s. Motivo: %s. Refs liberadas: %s',
                    $asientoId, $snapshot['estado'], $request->user()->email ?? $request->user()->id,
                    $data['motivo'],
                    empty($refsLiberadas) ? 'ninguna' : json_encode($refsLiberadas)),
            );

            return ['asiento_id' => $asientoId, 'refs_liberadas' => $refsLiberadas];
        });

        return response()->json([
            'message' => 'Asiento eliminado definitivamente. Quedó registrado en audit log.',
            'data' => $resultado,
        ]);
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
