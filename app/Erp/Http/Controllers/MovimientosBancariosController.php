<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\ConciliacionService;
use App\Erp\Services\MovimientoBancarioService;
use App\Erp\Services\Tesoreria\MatchingContraparteService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Endpoints de movimientos bancarios + conciliación (SPEC 02 §6.3).
 *
 *   GET   /movimientos-bancarios                         list
 *   POST  /movimientos-bancarios                         carga manual
 *   PATCH /movimientos-bancarios/{id}/etiquetar          asigna cuenta propuesta
 *   POST  /movimientos-bancarios/{id}/conciliar          polimórfica (RN-14/21)
 *   POST  /movimientos-bancarios/{id}/desconciliar       RN-21 reverso
 *   POST  /movimientos-bancarios/{id}/ignorar            RN-26 motivo catálogo
 *   POST  /movimientos-bancarios/autoconciliar           bulk por etiqueta/OP/cobro
 */
class MovimientosBancariosController
{
    public function __construct(
        private readonly MovimientoBancarioService $service,
        private readonly ConciliacionService $concilService,
        private readonly MatchingContraparteService $matcher,
        private readonly \App\Erp\Support\AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = MovimientoBancario::query()
            ->with(['cuentaBancaria:id,codigo,nombre,banco_id', 'cuentaBancaria.banco:id,codigo'])
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        if ($cb = $request->integer('cuenta_bancaria_id')) {
            $query->where('cuenta_bancaria_id', $cb);
        }
        if ($estado = $request->string('estado')->toString()) {
            $query->where('estado', $estado);
        }
        if ($desde = $request->date('desde')) {
            $query->where('fecha', '>=', $desde);
        }
        if ($hasta = $request->date('hasta')) {
            $query->where('fecha', '<=', $hasta);
        }

        return response()->json([
            'data' => $query->limit(200)->get(),
            'meta' => ['limit' => 200],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer'],
            'fecha' => ['required', 'date'],
            'fecha_valor' => ['nullable', 'date'],
            'concepto' => ['required', 'string', 'max:500'],
            'comprobante_banco' => ['nullable', 'string', 'max:100'],
            'debito' => ['nullable', 'numeric', 'min:0'],
            'credito' => ['nullable', 'numeric', 'min:0'],
        ]);

        $debito = (float) ($data['debito'] ?? 0);
        $credito = (float) ($data['credito'] ?? 0);
        if ($debito == 0 && $credito == 0) {
            return response()->json(['error' => ['code' => 'IMPORTE_CERO', 'message' => 'Debe informar débito o crédito > 0']], 422);
        }
        if ($debito > 0 && $credito > 0) {
            return response()->json(['error' => ['code' => 'UN_LADO', 'message' => 'Informar débito XOR crédito, no ambos.']], 422);
        }

        try {
            $mov = $this->service->crearManual([
                ...$data,
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $mov->load('cuentaBancaria.banco')], 201);
    }

    public function etiquetar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cuenta_contable_id' => ['required', 'integer', 'exists:erp_cuentas_contables,id'],
            'etiqueta_sugerida' => ['nullable', 'string', 'max:100'],
        ]);

        $mov = MovimientoBancario::findOrFail($id);
        if ($mov->estado === MovimientoBancario::ESTADO_CONCILIADO) {
            return response()->json([
                'error' => ['code' => 'MOVIMIENTO_CONCILIADO', 'message' => 'RN-21: desconciliá antes de re-etiquetar'],
            ], 409);
        }

        $mov->update([
            'estado' => MovimientoBancario::ESTADO_ETIQUETADO,
            'cuenta_contable_propuesta_id' => $data['cuenta_contable_id'],
            'etiqueta_sugerida' => $data['etiqueta_sugerida'] ?? $mov->etiqueta_sugerida,
        ]);

        return response()->json(['ok' => true, 'data' => $mov->fresh()]);
    }

    /**
     * Conciliación polimórfica. Acepta:
     *  · referencia_tipo=ORDEN_PAGO + referencia_id
     *  · referencia_tipo=COBRO + referencia_id
     *  · referencia_tipo=TRANSFERENCIA_INTERNA + referencia_id
     *  · referencia_tipo=ASIENTO_MANUAL + cuenta_contable_contraparte_id + auxiliar_id (opt)
     *  · referencia_tipo=ECHEQ + referencia_id (eCheq se acredita por EcheqService; este endpoint solo linkea)
     */
    public function conciliar(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate([
            'referencia_tipo' => ['required', Rule::in([
                'ORDEN_PAGO', 'COBRO', 'TRANSFERENCIA_INTERNA', 'ASIENTO_MANUAL', 'ECHEQ',
            ])],
            'referencia_id' => ['required_unless:referencia_tipo,ASIENTO_MANUAL', 'integer'],
            'cuenta_contable_contraparte_id' => ['required_if:referencia_tipo,ASIENTO_MANUAL', 'integer'],
            'auxiliar_id' => ['nullable', 'integer'],
            'centro_costo_id' => ['nullable', 'integer'],
            'importe_conciliado' => ['nullable', 'numeric', 'gt:0'],
            'glosa' => ['nullable', 'string', 'max:500'],
            'observacion' => ['nullable', 'string', 'max:300'],
        ]);

        $mov = MovimientoBancario::findOrFail($id);

        try {
            $mov = $this->concilService->conciliar($mov, $data, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $mov->load('asiento')]);
    }

    public function desconciliar(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $mov = MovimientoBancario::findOrFail($id);

        try {
            $mov = $this->concilService->desconciliar($mov, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $mov]);
    }

    public function ignorar(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate([
            'motivo_ignorado_id' => ['required', 'integer'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]);

        $mov = MovimientoBancario::findOrFail($id);

        try {
            $mov = $this->concilService->ignorar($mov, $data['motivo_ignorado_id'], $data['observacion'] ?? null, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $mov]);
    }

    /**
     * v1.27 Sprint A — Conciliación directa para tipos auto.
     * Usa erp_banco_config para resolver la cuenta contrapartida.
     */
    public function conciliarDirecto(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $mov = MovimientoBancario::findOrFail($id);
        try {
            $mov = $this->concilService->conciliarDirecto($mov, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $mov->load('asiento')]);
    }

    /**
     * v1.27 Sprint C + §15 — Sugerencias top-N con matching por CUIT
     * detectado en el concepto.
     */
    public function sugerencias(Request $request, int $id): JsonResponse
    {
        $top = (int) $request->query('top', 10);
        $top = max(1, min(50, $top));
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($id);
        // §15: devolvemos la respuesta enriquecida (sugerencias + cuit + contraparte + motivo_fallback).
        $r = $this->concilService->sugerirFacturasConMatchingCuit($mov, $top);
        return response()->json(['ok' => true, 'data' => $r]);
    }

    /**
     * v1.27 Sprint C + §15 — Conciliar movimiento contra factura (venta o compra).
     * §15: opcional `motivo` cuando se concilia manualmente (sin match de CUIT).
     */
    public function conciliarFactura(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate([
            'tipo_factura' => ['required', Rule::in(['VENTA', 'COMPRA'])],
            'factura_id' => ['required', 'integer'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'motivo' => ['nullable', 'string', 'min:10', 'max:500'], // §15
        ]);
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($id);
        try {
            $mov = $this->concilService->conciliarContraFactura(
                $mov, $data['tipo_factura'], (int) $data['factura_id'],
                (float) $data['monto'], $request->user(),
                $data['motivo'] ?? null,
            );
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $mov->load('asiento')]);
    }

    /**
     * v1.27 §15 — Búsqueda de auxiliares (Cliente o Proveedor) para el modal
     * de conciliación manual. Filtra por nombre o CUIT.
     */
    public function buscarAuxiliares(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::in(['Cliente', 'Proveedor'])],
            'q' => ['required', 'string', 'min:2'],
        ]);
        $q = $data['q'];
        $qDigitos = preg_replace('/[^0-9]/', '', $q);

        $rows = DB::table('erp_auxiliares')
            ->where('tipo', $data['tipo'])
            ->where('activo', 1)
            ->where(function ($w) use ($q, $qDigitos) {
                $w->where('nombre', 'like', "%{$q}%");
                if ($qDigitos !== '') $w->orWhere('cuit', 'like', "{$qDigitos}%");
            })
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'codigo', 'nombre', 'cuit', 'tipo']);

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /**
     * v1.27 §16 — Borrado bulk de movimientos sin conciliar / ignorados.
     * Requiere permiso `tesoreria.movimientos.borrar_bulk` (super_admin).
     * Si alguno está CONCILIADO → 409 con lista de cuáles bloquean (no se
     * borra ninguno).
     */
    public function borrarBulk(Request $request): JsonResponse
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso('tesoreria.movimientos.borrar_bulk')) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => 'Falta permiso tesoreria.movimientos.borrar_bulk',
            ]], 403);
        }
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $movs = MovimientoBancario::whereIn('id', $ids)->get();
        if ($movs->isEmpty()) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'NO_ENCONTRADOS', 'message' => 'Ninguno de los movimientos existe',
            ]], 404);
        }

        // Validar estados: solo PENDIENTE/ETIQUETADO/IGNORADO se pueden borrar.
        $bloqueantes = $movs->filter(fn ($m) => $m->estado === MovimientoBancario::ESTADO_CONCILIADO);
        if ($bloqueantes->isNotEmpty()) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'MOVIMIENTOS_CONCILIADOS',
                'message' => sprintf('%d movimientos están CONCILIADOS y no se pueden borrar. Desconciliá primero.',
                    $bloqueantes->count()),
                'ids' => $bloqueantes->pluck('id')->all(),
            ]], 409);
        }

        $motivo = trim((string) ($data['motivo'] ?? ''));
        $snapshot = $movs->map(fn ($m) => [
            'id' => $m->id, 'fecha' => $m->fecha?->toDateString(),
            'concepto' => $m->concepto, 'debito' => (float) $m->debito,
            'credito' => (float) $m->credito, 'estado' => $m->estado,
            'tipo_operativo' => $m->tipo_operativo,
            'cuit_contraparte' => $m->cuit_contraparte,
            'cuenta_bancaria_id' => $m->cuenta_bancaria_id,
        ])->all();

        $count = 0;
        $extractosHuerfanos = [];
        DB::transaction(function () use ($movs, $motivo, $snapshot, $request, &$count, &$extractosHuerfanos) {
            DB::statement('SET @erp_current_user_id = ?', [$request->user()->id]);
            $ids = $movs->pluck('id')->all();
            // Capturar extracto_ids únicos ANTES de borrar (para chequear
            // huérfanos después).
            $extractoIds = $movs->pluck('extracto_id')->filter()->unique()->all();
            $count = DB::table('erp_movimientos_bancarios')->whereIn('id', $ids)->delete();
            $this->audit->log('MOVIMIENTO_BANCARIO_BORRADO_BULK',
                $movs->first(), $snapshot, null,
                sprintf('Borrado bulk de %d movimientos. Motivo: %s',
                    count($snapshot), $motivo !== '' ? $motivo : '(sin motivo)'));

            // v1.27 §16 fix — cleanup de extractos huérfanos: si un
            // extracto queda sin movimientos vivos (porque se borraron
            // todos los suyos), también lo borramos. Esto libera el hash
            // SHA-256 y permite re-cargar el mismo archivo desde cero.
            foreach ($extractoIds as $extractoId) {
                $movsRestantes = DB::table('erp_movimientos_bancarios')
                    ->where('extracto_id', $extractoId)->count();
                if ($movsRestantes === 0) {
                    DB::table('erp_extractos_bancarios')->where('id', $extractoId)->delete();
                    $extractosHuerfanos[] = $extractoId;
                }
            }
        });

        return response()->json(['ok' => true, 'data' => [
            'borrados' => $count,
            'extractos_eliminados' => $extractosHuerfanos,
        ]]);
    }

    /**
     * v1.27 §16 — Confirmar movimientos auto-etiquetados en bulk.
     * Cada movimiento debe estar en estado ETIQUETADO con
     * cuenta_contable_propuesta_id seteada. Genera un asiento por cada uno
     * en una sola transacción.
     */
    public function confirmarAutoEtiquetados(Request $request): JsonResponse
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso('tesoreria.extractos.conciliar')) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => 'Falta permiso tesoreria.extractos.conciliar',
            ]], 403);
        }
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $movs = MovimientoBancario::whereIn('id', $ids)
            ->where('estado', MovimientoBancario::ESTADO_ETIQUETADO)
            ->whereNotNull('cuenta_contable_propuesta_id')
            ->get();

        if ($movs->count() !== count($ids)) {
            $faltantes = array_diff($ids, $movs->pluck('id')->all());
            return response()->json(['ok' => false, 'error' => [
                'code' => 'MOVIMIENTOS_INVALIDOS',
                'message' => 'Algunos movimientos no están en estado ETIQUETADO con cuenta propuesta',
                'ids_problematicos' => array_values($faltantes),
            ]], 422);
        }

        $asientosGenerados = [];
        try {
            DB::transaction(function () use ($movs, $request, &$asientosGenerados) {
                foreach ($movs as $m) {
                    // Reusar conciliar() del servicio con referencia ASIENTO_MANUAL
                    // + cuenta_contable_contraparte_id = propuesta.
                    $r = $this->concilService->conciliar($m, [
                        'referencia_tipo' => 'ASIENTO_MANUAL',
                        'cuenta_contable_contraparte_id' => $m->cuenta_contable_propuesta_id,
                        'glosa' => 'Auto-etiquetado bulk · '.($m->concepto ?? ''),
                        'observacion' => '[AUTO] confirmación bulk §16',
                    ], $request->user());
                    if ($r->asiento_id) $asientosGenerados[] = $r->asiento_id;
                }
            });
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => [
            'conciliados' => $movs->count(),
            'asientos_generados' => $asientosGenerados,
        ]]);
    }

    /**
     * v1.27 §15 — Lista facturas pendientes de un auxiliar específico
     * (para el modal de conciliación manual).
     */
    public function facturasPendientesAuxiliar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auxiliar_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(['VENTA', 'COMPRA'])],
        ]);

        $auxiliarId = (int) $data['auxiliar_id'];
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);

        if ($data['tipo'] === 'VENTA') {
            $rows = DB::table('erp_facturas_venta as f')
                ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                ->where('f.empresa_id', $empresaId)
                ->where('f.auxiliar_id', $auxiliarId)
                ->whereNull('f.deleted_at')
                ->whereIn('f.estado', ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL'])
                ->select('f.id', 'f.numero', 'f.punto_venta_id', 'f.imp_total',
                    'f.fecha_emision', 'tc.codigo_interno as tipo_codigo', 'tc.letra')
                ->orderByDesc('f.fecha_emision')
                ->limit(50)
                ->get();
        } else {
            $rows = DB::table('erp_facturas_compra as f')
                ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                ->where('f.empresa_id', $empresaId)
                ->where('f.auxiliar_id', $auxiliarId)
                ->whereNull('f.deleted_at')
                ->whereIn('f.estado', ['RECIBIDA', 'CONTROLADA', 'PAGO_PARCIAL'])
                ->select('f.id', 'f.numero', 'f.punto_venta', 'f.imp_total',
                    'f.fecha_emision', 'tc.codigo_interno as tipo_codigo', 'tc.letra')
                ->orderByDesc('f.fecha_emision')
                ->limit(50)
                ->get();
        }

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function autoconciliar(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'rango_dias' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]);

        $resumen = $this->concilService->autoconciliar(
            $data['cuenta_bancaria_id'],
            $data['desde'],
            $data['hasta'],
            (int) ($data['rango_dias'] ?? 5),
            $request->user(),
        );

        return response()->json(['ok' => true, 'data' => $resumen]);
    }

    /**
     * Batch: aplica una acción (conciliar contra cuenta contable / ignorar) a
     * un conjunto de movimientos PENDIENTES o ETIQUETADOS. Devuelve resumen
     * con éxitos y errores por id (no aborta toda la lista si uno falla).
     *
     * Body:
     *   accion: 'CONCILIAR_CONTRA_CUENTA' | 'IGNORAR'
     *   ids: int[]
     *   payload: depende de la acción.
     */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accion' => ['required', Rule::in(['CONCILIAR_CONTRA_CUENTA', 'IGNORAR'])],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'payload' => ['required', 'array'],
            'payload.cuenta_contable_contraparte_id' => ['required_if:accion,CONCILIAR_CONTRA_CUENTA', 'integer'],
            'payload.motivo_ignorado_id' => ['required_if:accion,IGNORAR', 'integer'],
            'payload.observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $exitos = [];
        $errores = [];
        $movs = MovimientoBancario::whereIn('id', $data['ids'])->get();

        foreach ($movs as $mov) {
            try {
                if ($data['accion'] === 'CONCILIAR_CONTRA_CUENTA') {
                    $this->concilService->conciliar($mov, [
                        'referencia_tipo' => 'ASIENTO_MANUAL',
                        'cuenta_contable_contraparte_id' => $data['payload']['cuenta_contable_contraparte_id'],
                        'observacion' => $data['payload']['observacion'] ?? null,
                    ], $request->user());
                } else {
                    $this->concilService->ignorar(
                        $mov,
                        $data['payload']['motivo_ignorado_id'],
                        $data['payload']['observacion'] ?? null,
                        $request->user()
                    );
                }
                $exitos[] = $mov->id;
            } catch (DomainException $e) {
                $errores[] = ['id' => $mov->id, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'pedidos' => count($data['ids']),
                'exitos' => count($exitos),
                'errores' => count($errores),
                'ids_exitos' => $exitos,
                'detalle_errores' => $errores,
            ],
        ]);
    }

    /**
     * Preview: re-corre el MatchingContraparteService sobre un mov sin
     * persistir. Útil para que el frontend muestre "qué propondría el sistema
     * si re-procesara este movimiento ahora" tras configurar reglas/aliases.
     */
    public function matchPreview(Request $request, int $id): JsonResponse
    {
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($id);
        $r = $this->matcher->matchear($mov);

        return response()->json([
            'ok' => true,
            'data' => [
                'movimiento_id' => $mov->id,
                'concepto' => $mov->concepto,
                'importe' => $mov->importeFirmado(),
                'estado_actual' => $mov->estado,
                'sugerencia' => $r,
            ],
        ]);
    }

    private function requierePermiso(Request $request, string $codigo): void
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

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
