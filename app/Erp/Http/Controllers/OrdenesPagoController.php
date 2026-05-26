<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Models\Tesoreria\OrdenPagoAudit;
use App\Erp\Models\Tesoreria\OrdenPagoTipo;
use App\Erp\Services\AsientoService;
use App\Erp\Services\OrdenPagoService;
use App\Erp\Services\Tesoreria\OrdenesPagoSyncService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class OrdenesPagoController
{
    public function __construct(
        private readonly OrdenPagoService $service,
        private readonly AsientoService $asientoService,    // v1.35
        private readonly OrdenesPagoSyncService $syncService, // v1.35
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = OrdenPago::query()
            ->with(['auxiliar:id,codigo,nombre,tipo,cuit', 'moneda:id,codigo', 'tipoOp:id,codigo,nombre'])
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        if ($estado = $request->string('estado')->toString()) {
            $query->where('estado', $estado);
        }
        if ($tipo = $request->string('tipo')->toString()) {
            $query->where('tipo', $tipo);
        }
        if ($auxId = $request->integer('auxiliar_id')) {
            $query->where('auxiliar_id', $auxId);
        }
        // v1.35 — filtros nuevos.
        if ($origen = $request->string('origen')->toString()) {
            $query->where('origen', $origen);
        }
        if ($tipoOpId = $request->integer('tipo_op_id')) {
            $query->where('tipo_op_id', $tipoOpId);
        }
        if ($request->boolean('solo_no_contabilizadas')) {
            $query->where('contabilizada', false);
        }
        if ($desde = $request->string('desde')->toString()) {
            $query->where('fecha', '>=', $desde);
        }
        if ($hasta = $request->string('hasta')->toString()) {
            $query->where('fecha', '<=', $hasta);
        }

        return response()->json(['data' => $query->limit(500)->get()]);
    }

    public function show(int $id): JsonResponse
    {
        $op = OrdenPago::with([
            'auxiliar:id,codigo,nombre,tipo',
            'moneda:id,codigo',
            'asiento:id,numero,fecha',
            'items',
            'medios.medioPago:id,codigo,nombre',
            'medios.cuentaBancaria:id,codigo,nombre',
        ])->findOrFail($id);

        return response()->json(['data' => $op]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'tipo' => ['nullable', 'in:PROVEEDOR,DISTRIBUIDOR,LIQUIDACION_DISTRIBUIDOR,OTROS'],
            'auxiliar_id' => ['required', 'integer'],
            'liq_encabezado_id' => ['nullable', 'integer'],
            'moneda_id' => ['required', 'integer'],
            'cotizacion' => ['nullable', 'numeric'],
            'importe' => ['required', 'numeric', 'min:0.01'],
            'importe_bruto' => ['nullable', 'numeric'],
            'total_retenciones' => ['nullable', 'numeric'],
            'concepto' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array'],
            'items.*.tipo_item' => ['required_with:items', Rule::in(['FACTURA_COMPRA', 'ADELANTO', 'REINTEGRO', 'RETENCION', 'OTRO'])],
            'items.*.comprobante_id' => ['nullable', 'integer'],
            'items.*.cuenta_contable_id' => ['nullable', 'integer'],
            'items.*.concepto' => ['required_with:items', 'string'],
            'items.*.importe' => ['required_with:items', 'numeric'],
            'medios' => ['sometimes', 'array'],
            'medios.*.medio_pago_id' => ['required_with:medios', 'integer'],
            'medios.*.cuenta_bancaria_id' => ['nullable', 'integer'],
            'medios.*.importe' => ['required_with:medios', 'numeric'],
            'medios.*.referencia' => ['nullable', 'string'],
        ]);

        try {
            $op = $this->service->crear([
                ...$data,
                'empresa_id' => $request->user()->erpPerfil?->empresa_id ?? 1,
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op->load('auxiliar', 'moneda', 'items', 'medios')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'fecha' => ['nullable', 'date'],
            'tipo' => ['nullable', 'in:PROVEEDOR,DISTRIBUIDOR,LIQUIDACION_DISTRIBUIDOR,OTROS'],
            'moneda_id' => ['nullable', 'integer'],
            'cotizacion' => ['nullable', 'numeric'],
            'importe' => ['nullable', 'numeric', 'min:0.01'],
            'importe_bruto' => ['nullable', 'numeric'],
            'total_retenciones' => ['nullable', 'numeric'],
            'concepto' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array'],
            'medios' => ['sometimes', 'array'],
        ]);

        $op = OrdenPago::findOrFail($id);

        try {
            $op = $this->service->actualizar($op, $data);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op]);
    }

    public function cargarBanco(Request $request, int $id): JsonResponse
    {
        $op = OrdenPago::findOrFail($id);

        try {
            $op = $this->service->cargarBanco($op, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op]);
    }

    public function liberar(Request $request, int $id): JsonResponse
    {
        $op = OrdenPago::findOrFail($id);

        try {
            $op = $this->service->liberar($op, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $op = OrdenPago::findOrFail($id);

        try {
            $op = $this->service->rechazar($op, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op]);
    }

    public function pagar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer'],
            'concepto' => ['nullable', 'string', 'max:500'],
        ]);

        $op = OrdenPago::findOrFail($id);

        try {
            $result = $this->service->pagar($op, $data['cuenta_bancaria_id'], $request->user(), $data['concepto'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'data' => [
                'op' => $result['op']->load('auxiliar', 'asiento'),
                'movimiento_bancario_id' => $result['movimiento']->id,
                'asiento_id' => $result['asiento_id'],
            ],
        ]);
    }

    public function anular(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $op = OrdenPago::findOrFail($id);

        try {
            $op = $this->service->anular($op, $data['motivo']);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $op]);
    }

    /**
     * v1.35 — Crear OP local simple (1 beneficiario, sin items/medios).
     * Form: beneficiario_id, moneda (ARS/USD), fecha, tipo_op_id, importe, concepto.
     */
    public function storeLocal(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.op.crear_local');
        $data = $request->validate([
            'beneficiario_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'moneda' => ['required', 'in:ARS,USD'],
            'fecha' => ['required', 'date'],
            'tipo_op_id' => ['required', 'integer', 'exists:erp_ordenes_pago_tipos,id'],
            'importe' => ['required', 'numeric', 'min:0.01'],
            'cotizacion_usd' => ['nullable', 'numeric', 'min:0.0001'],
            'concepto' => ['required', 'string', 'min:3', 'max:500'],
            'emitir' => ['nullable', 'boolean'],
        ]);
        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;

        // No permitir tipo DIST desde el form local.
        $tipo = OrdenPagoTipo::find($data['tipo_op_id']);
        if ($tipo && $tipo->codigo === 'DIST') {
            return response()->json(['error' => ['code' => 'TIPO_DIST_RESERVADO',
                'message' => 'El tipo "Distribuidor" es exclusivo del sync de DistriApp.']], 422);
        }
        if ($data['moneda'] === 'USD' && empty($data['cotizacion_usd'])) {
            return response()->json(['error' => ['code' => 'COTIZACION_REQUERIDA',
                'message' => 'Para OP en USD la cotización es obligatoria.']], 422);
        }

        $beneficiario = DB::table('erp_auxiliares')->where('id', $data['beneficiario_id'])->first();
        if (! $beneficiario || ! $beneficiario->activo) {
            return response()->json(['error' => ['code' => 'BENEFICIARIO_INACTIVO',
                'message' => 'El beneficiario no está activo.']], 422);
        }

        $monedaId = DB::table('erp_monedas')->where('codigo', $data['moneda'])->value('id') ?? 1;
        $importeArs = $data['moneda'] === 'USD'
            ? round((float) $data['importe'] * (float) $data['cotizacion_usd'], 2)
            : null;

        $op = OrdenPago::create([
            'empresa_id' => $empresaId,
            'origen' => OrdenPago::ORIGEN_LOCAL,
            'numero' => $this->siguienteNumeroErpHelper($empresaId),
            'fecha' => $data['fecha'],
            'tipo' => 'OTROS',
            'tipo_op_id' => $data['tipo_op_id'],
            'auxiliar_id' => $data['beneficiario_id'],
            'moneda_id' => $monedaId,
            'cotizacion' => $data['moneda'] === 'USD' ? $data['cotizacion_usd'] : 1.0,
            'cotizacion_usd' => $data['moneda'] === 'USD' ? $data['cotizacion_usd'] : null,
            'importe' => $data['importe'],
            'importe_bruto' => $data['importe'],
            'importe_ars_equivalente' => $importeArs,
            'total_retenciones' => 0,
            'concepto' => $data['concepto'],
            'estado' => ($data['emitir'] ?? false) ? OrdenPago::ESTADO_EMITIDA : OrdenPago::ESTADO_BORRADOR,
            'creado_por_user_id' => $request->user()->id,
            'beneficiario_snapshot' => [
                'nombre' => $beneficiario->nombre, 'cuit' => $beneficiario->cuit,
            ],
        ]);

        $this->audit($op->id, ($data['emitir'] ?? false) ? 'EMITIR' : 'CREAR', $request->user()->id, null, $op->toArray());

        return response()->json(['data' => $op->load('auxiliar', 'tipoOp', 'moneda')], 201);
    }

    /**
     * v1.35 — Registrar pago de una OP (EMITIDA/BORRADOR → PAGADA).
     */
    public function registrarPago(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.op.pagar');
        $data = $request->validate([
            'fecha_pago' => ['required', 'date'],
            'medio_pago' => ['required', 'in:TRANSFERENCIA,CHEQUE,ECHEQ,EFECTIVO,OTRO'],
            'cuenta_bancaria_pago_id' => ['nullable', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'referencia_pago' => ['nullable', 'string', 'max:100'],
        ]);
        $op = OrdenPago::findOrFail($id);
        if (in_array($op->estado, [OrdenPago::ESTADO_PAGADA, OrdenPago::ESTADO_ANULADA], true)) {
            return response()->json(['error' => ['code' => 'ESTADO_INVALIDO',
                'message' => "No se puede pagar una OP en estado {$op->estado}."]], 422);
        }
        $antes = $op->toArray();
        $op->update([
            'estado' => OrdenPago::ESTADO_PAGADA,
            'fecha_pago' => $data['fecha_pago'],
            'medio_pago' => $data['medio_pago'],
            'cuenta_bancaria_pago_id' => $data['cuenta_bancaria_pago_id'] ?? null,
            'referencia_pago' => $data['referencia_pago'] ?? null,
        ]);
        $this->audit($op->id, 'PAGAR', $request->user()->id, $antes, $op->toArray());
        return response()->json(['data' => $op->fresh(['auxiliar'])]);
    }

    /**
     * v1.35 — Contabilizar (confirmación manual). Genera asiento + marca contabilizada.
     */
    public function contabilizar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.op.contabilizar');
        $data = $request->validate([
            'cuenta_debe_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'cuenta_haber_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
        ]);
        $op = OrdenPago::with('tipoOp')->findOrFail($id);
        if ($op->contabilizada) {
            return response()->json(['error' => ['code' => 'YA_CONTABILIZADA',
                'message' => 'Esta OP ya tiene asiento generado.']], 422);
        }
        if (! in_array($op->estado, [OrdenPago::ESTADO_EMITIDA, OrdenPago::ESTADO_PAGADA], true)) {
            return response()->json(['error' => ['code' => 'ESTADO_INVALIDO',
                'message' => "Solo se contabilizan OP en EMITIDA o PAGADA (actual: {$op->estado})."]], 422);
        }

        $empresaId = $op->empresa_id;
        // Cuenta DEBE (gasto): provista, o default del tipo.
        $cuentaDebe = $data['cuenta_debe_id'] ?? $op->tipoOp?->cuenta_contable_default_id
            ?? DB::table('erp_auxiliares')->where('id', $op->auxiliar_id)->value('cuenta_contable_default_id');
        // Cuenta HABER (banco/caja): provista, o de la cuenta bancaria de pago.
        $cuentaHaber = $data['cuenta_haber_id'] ?? null;
        if (! $cuentaHaber && $op->cuenta_bancaria_pago_id) {
            $cuentaHaber = DB::table('erp_cuentas_bancarias')->where('id', $op->cuenta_bancaria_pago_id)->value('cuenta_contable_id');
        }
        // Mapeo banco DistriApp → cuenta (si vino de sync sin cuenta de pago).
        if (! $cuentaHaber && $op->origen === OrdenPago::ORIGEN_DISTRIAPP && $op->medio_pago) {
            $cuentaHaber = DB::table('erp_mapeo_bancos_distriapp as m')
                ->join('erp_cuentas_bancarias as cb', 'cb.id', '=', 'm.cuenta_bancaria_id')
                ->where('m.empresa_id', $empresaId)
                ->where('m.banco_origen_distriapp', strtoupper($op->medio_pago))
                ->value('cb.cuenta_contable_id');
        }

        if (! $cuentaDebe || ! $cuentaHaber) {
            return response()->json(['error' => ['code' => 'CUENTAS_REQUERIDAS',
                'message' => 'Faltan cuentas contables. Elegí débito (gasto) y haber (banco/caja).',
                'sugerencia_debe' => $cuentaDebe, 'sugerencia_haber' => $cuentaHaber]], 422);
        }

        try {
            $importe = (float) ($op->importe_ars_equivalente ?? $op->importe);
            $ccGeneral = DB::table('erp_centros_costo')->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id');
            $diarioId = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'TES')->value('id')
                ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
            if (! $diarioId) throw new RuntimeException('Diario TES/GEN no existe.');

            $asiento = DB::transaction(function () use ($op, $empresaId, $diarioId, $cuentaDebe, $cuentaHaber, $importe, $ccGeneral, $request) {
                $a = $this->asientoService->crearBorrador([
                    'empresa_id' => $empresaId,
                    'diario_id' => $diarioId,
                    'fecha' => $op->fecha->toDateString(),
                    'glosa' => sprintf('OP %s - %s', $op->numero, $op->concepto),
                    'origen' => 'ORDEN_PAGO',
                    'origen_id' => $op->id,
                    'origen_tabla' => 'erp_ordenes_pago',
                    'usuario_id' => $request->user()->id,
                    'movimientos' => [
                        [
                            'cuenta_id' => (int) $cuentaDebe,
                            'centro_costo_id' => $this->admiteCc((int) $cuentaDebe) ? $ccGeneral : null,
                            'auxiliar_id' => $op->auxiliar_id,
                            'debe' => $importe, 'haber' => 0,
                            'glosa' => 'Gasto ' . $op->numero,
                        ],
                        [
                            'cuenta_id' => (int) $cuentaHaber,
                            'centro_costo_id' => $this->admiteCc((int) $cuentaHaber) ? $ccGeneral : null,
                            'auxiliar_id' => null,
                            'debe' => 0, 'haber' => $importe,
                            'glosa' => 'Pago ' . ($op->medio_pago ?? ''),
                        ],
                    ],
                ]);
                $a = $this->asientoService->contabilizar($a);
                $op->update([
                    'contabilizada' => true,
                    'fecha_contabilizada' => now(),
                    'contabilizada_por_user_id' => $request->user()->id,
                    'asiento_id' => $a->id,
                ]);
                $this->audit($op->id, 'CONTABILIZAR', $request->user()->id, null, ['asiento_id' => $a->id, 'importe' => $importe]);
                return $a;
            });

            return response()->json(['data' => ['op_id' => $op->id, 'asiento_id' => $asiento->id, 'contabilizada' => true]]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
    }

    /**
     * v1.35 — Forzar sync desde DistriApp ahora.
     */
    public function sync(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.op.sync_forzar');
        $r = $request->boolean('backfill')
            ? $this->syncService->backfillCompleto(false)
            : $this->syncService->syncIncremental();
        return response()->json(['data' => $r]);
    }

    public function syncEstado(Request $request): JsonResponse
    {
        $ultima = \Illuminate\Support\Facades\Cache::get('op_sync_ultima_exitosa');
        return response()->json(['data' => [
            'ultima_sync' => $ultima?->toIso8601String(),
            'total_distriapp' => OrdenPago::where('origen', OrdenPago::ORIGEN_DISTRIAPP)->count(),
            'total_local' => OrdenPago::where('origen', OrdenPago::ORIGEN_LOCAL)->count(),
            'no_contabilizadas' => OrdenPago::where('contabilizada', false)
                ->whereNotIn('estado', [OrdenPago::ESTADO_ANULADA, OrdenPago::ESTADO_BORRADOR])->count(),
        ]]);
    }

    public function audit(int $opId, string $accion, ?int $userId, ?array $antes, array $despues, ?string $motivo = null): void
    {
        OrdenPagoAudit::create([
            'op_id' => $opId, 'accion' => $accion, 'user_id' => $userId,
            'snapshot_antes' => $antes, 'snapshot_despues' => $despues,
            'motivo' => $motivo, 'created_at' => now(),
        ]);
    }

    public function auditList(int $id): JsonResponse
    {
        $rows = OrdenPagoAudit::where('op_id', $id)->orderByDesc('created_at')->limit(200)->get();
        return response()->json(['data' => $rows]);
    }

    public function tipos(): JsonResponse
    {
        $rows = OrdenPagoTipo::where('activo', true)->orderBy('orden')->get();
        return response()->json(['data' => $rows]);
    }

    private function siguienteNumeroErpHelper(int $empresaId): string
    {
        $anio = (int) date('Y');
        return DB::transaction(function () use ($empresaId, $anio) {
            $sec = DB::table('erp_secuencias_op')->where('empresa_id', $empresaId)->where('anio', $anio)->lockForUpdate()->first();
            $ultimo = $sec ? (int) $sec->ultimo_numero : 0;
            if (! $sec) {
                DB::table('erp_secuencias_op')->insert(['empresa_id' => $empresaId, 'anio' => $anio, 'ultimo_numero' => 0]);
            }
            $proximo = $ultimo + 1;
            DB::table('erp_secuencias_op')->where('empresa_id', $empresaId)->where('anio', $anio)->update(['ultimo_numero' => $proximo]);
            return sprintf('OP-%d-%06d', $anio, $proximo);
        });
    }

    private function admiteCc(int $cuentaId): bool
    {
        return (bool) DB::table('erp_cuentas_contables')->where('id', $cuentaId)->value('admite_centro_costo');
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['error' => ['code' => 'NO_AUTORIZADO',
                'message' => "Falta permiso {$codigo}"]], 403));
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
