<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\ConciliacionService;
use App\Erp\Services\MovimientoBancarioService;
use App\Erp\Services\Tesoreria\MatchingContraparteService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $mov = MovimientoBancario::findOrFail($id);
        try {
            $mov = $this->concilService->conciliarDirecto($mov, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $mov->load('asiento')]);
    }

    public function autoconciliar(Request $request): JsonResponse
    {
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

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
