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

        $this->aplicarFiltrosV150($query, $request);

        // v1.50 §4.6 — sumatoria del TOTAL filtrado (sin el limit del listado).
        $tot = (clone $query)->reorder()
            ->selectRaw('COUNT(*) c, COALESCE(SUM(debito),0) d, COALESCE(SUM(credito),0) h')
            ->first();

        $limit = min(1000, max(50, (int) $request->query('per_page', 200)));

        return response()->json([
            'data' => $query->limit($limit)->get(),
            'meta' => [
                'limit' => $limit,
                'total_movs' => (int) $tot->c,
                'total_debitos' => round((float) $tot->d, 2),
                'total_creditos' => round((float) $tot->h, 2),
                'total_neto' => round((float) $tot->h - (float) $tot->d, 2),
            ],
        ], 200, [
            'X-Total-Movs' => (int) $tot->c,
            'X-Total-Debitos' => round((float) $tot->d, 2),
            'X-Total-Creditos' => round((float) $tot->h, 2),
            'X-Total-Neto' => round((float) $tot->h - (float) $tot->d, 2),
        ]);
    }

    /**
     * v1.50 Bloque A — filtros del listado (chips + avanzados). Compartido por
     * index() y exportXlsx().
     */
    private function aplicarFiltrosV150($query, Request $request): void
    {
        if ($cb = $request->integer('cuenta_bancaria_id')) {
            $query->where('cuenta_bancaria_id', $cb);
        }
        if ($estado = $request->string('estado')->toString()) {
            $query->where('estado', $estado);
        }
        if (is_array($request->query('estados'))) {
            $query->whereIn('estado', array_filter($request->query('estados')));
        }
        if ($desde = $request->date('desde')) $query->where('fecha', '>=', $desde);
        if ($hasta = $request->date('hasta')) $query->where('fecha', '<=', $hasta);

        // Chips Débitos/Créditos.
        $tipo = strtoupper($request->string('tipo')->toString());
        if ($tipo === 'DEBITO') $query->where('debito', '>', 0);
        if ($tipo === 'CREDITO') $query->where('credito', '>', 0);

        // Rango de importes (sobre el lado con valor).
        if (($impDesde = $request->query('importe_desde')) !== null && $impDesde !== '') {
            $query->whereRaw('GREATEST(debito, credito) >= ?', [(float) $impDesde]);
        }
        if (($impHasta = $request->query('importe_hasta')) !== null && $impHasta !== '') {
            $query->whereRaw('GREATEST(debito, credito) <= ?', [(float) $impHasta]);
        }

        if ($regla = $request->integer('regla_id')) $query->where('regla_aplicada_id', $regla);
        if ($cta = $request->integer('cuenta_contable_id')) $query->where('cuenta_contable_propuesta_id', $cta);
        if ($aux = $request->integer('auxiliar_id')) $query->where('auxiliar_resuelto_id', $aux);

        // Chips de categoría (OR entre las seleccionadas).
        $cats = array_filter((array) $request->query('categorias', []));
        if ($cats) {
            $query->where(function ($outer) use ($cats) {
                foreach ($cats as $cat) {
                    $outer->orWhere(fn ($w) => $this->condicionCategoria($w, strtoupper((string) $cat)));
                }
            });
        }
    }

    /** Condición SQL de cada chip de categoría (v1.50 §4.1, con reglas/cuentas reales). */
    private function condicionCategoria($q, string $cat): void
    {
        $cuentas = fn (array $codigos) => DB::table('erp_cuentas_contables')
            ->whereIn('codigo', $codigos)->pluck('id');
        $reglasLike = function (array $patrones) {
            $r = DB::table('erp_conciliacion_reglas');
            foreach ($patrones as $i => $p) {
                $i === 0 ? $r->where('codigo', 'like', $p) : $r->orWhere('codigo', 'like', $p);
            }
            return $r->pluck('id');
        };

        switch ($cat) {
            case 'SIN_ETIQUETAR':
                $q->where('estado', MovimientoBancario::ESTADO_PENDIENTE);
                break;
            case 'IMPUESTOS':
                $q->whereIn('cuenta_contable_propuesta_id', $cuentas(['5.4.04', '5.5.03', '5.5.07', '5.5.08', '1.1.6.11', '1.1.6.12']))
                    ->orWhereIn('regla_aplicada_id', $reglasLike(['%IMP%', '%SIRCREB%', '%SELLADO%', '%AFIP%']));
                break;
            case 'COMISIONES':
                $q->whereIn('cuenta_contable_propuesta_id', $cuentas(['5.4.02', '5.4.05', '5.4.06']))
                    ->orWhereIn('regla_aplicada_id', $reglasLike(['%COM%']));
                break;
            case 'SERVICIOS':
                $q->whereIn('cuenta_contable_propuesta_id', $cuentas(['5.2.2.01', '5.2.2.02', '5.2.2.03', '5.2.2.04', '5.2.2.05', '5.2.2.06', '5.2.2.07', '5.2.2.08', '5.2.2.09']))
                    ->orWhereIn('regla_aplicada_id', $reglasLike(['%PAGO-SERV%', '%NOSIS%', '%PAGO-SEGURO%']));
                break;
            case 'SUELDOS':
                $q->whereIn('cuenta_contable_propuesta_id', $cuentas(['5.2.1.01', '5.2.1.10']))
                    ->orWhereIn('regla_aplicada_id', $reglasLike(['%SUELDO%', '%PAGO-PERSONAL%']));
                break;
            case 'TRANSFERENCIAS_INTERNAS':
                $q->where('es_transferencia_interna', 1);
                break;
            case 'CHEQUES':
                $q->whereIn('estado', [
                    MovimientoBancario::ESTADO_CONFIRMADO_CHEQUES,
                    MovimientoBancario::ESTADO_CONFIRMADO_DESCUENTO,
                ])->orWhereIn('regla_aplicada_id', $reglasLike(['%CHQ%', '%CH-CAMARA%', '%ECHEQ%']));
                break;
            case 'COBROS':
                $q->where('credito', '>', 0)->whereNotNull('cuit_contraparte');
                break;
            default:
                // Categoría desconocida: no filtra nada (condición neutra).
                $q->whereRaw('1=1');
        }
    }

    /** v1.50 §4.5 — Export del listado filtrado a Excel (9 columnas). */
    public function exportXlsx(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');

        $query = MovimientoBancario::query()
            ->with(['cuentaBancaria:id,codigo,nombre'])
            ->orderBy('fecha')->orderBy('id');
        $this->aplicarFiltrosV150($query, $request);
        // Export de selección puntual (footer del multi-select).
        if (is_array($request->query('ids'))) {
            $query->whereIn('id', array_map('intval', $request->query('ids')));
        }
        $rows = $query->limit(5000)->get();

        $reglas = DB::table('erp_conciliacion_reglas')->pluck('codigo', 'id');
        $ctas = DB::table('erp_cuentas_contables')->pluck('codigo', 'id');

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Conciliación');
        $headers = ['Fecha', 'Cuenta', 'Concepto', 'Contraparte', 'Débito', 'Crédito', 'Estado', 'Regla aplicada', 'Cuenta destino'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E7EBF0');

        $i = 2;
        foreach ($rows as $m) {
            $sheet->fromArray([
                $m->fecha->format('d/m/Y'),
                $m->cuentaBancaria?->codigo,
                $m->concepto,
                $m->nombre_contraparte ?: ($m->cuit_contraparte ?: ''),
                (float) $m->debito ?: null,
                (float) $m->credito ?: null,
                $m->estado,
                $m->regla_aplicada_id ? ($reglas[$m->regla_aplicada_id] ?? '#'.$m->regla_aplicada_id) : '',
                $m->cuenta_contable_propuesta_id ? ($ctas[$m->cuenta_contable_propuesta_id] ?? '') : '',
            ], null, 'A'.$i);
            $i++;
        }
        foreach (['E', 'F'] as $col) {
            $sheet->getStyle("{$col}2:{$col}{$i}")->getNumberFormat()->setFormatCode('#,##0.00');
        }
        foreach (range('A', 'I') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

        $cuentaCod = $rows->first()?->cuentaBancaria?->codigo ?? 'todas';
        $filename = sprintf('conciliacion_%s_%s.xlsx', $cuentaCod, now()->format('Y-m-d'));

        return response()->stream(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /** v1.50 §4.5 — Ignorar en bulk (motivo del catálogo, como el ignorar unitario). */
    public function bulkIgnorar(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate([
            'mov_ids' => ['required', 'array', 'min:1', 'max:200'],
            'mov_ids.*' => ['integer'],
            'motivo_ignorado_id' => ['required', 'integer'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]);
        $ok = 0;
        $errores = [];
        foreach (array_unique($data['mov_ids']) as $id) {
            try {
                $mov = MovimientoBancario::findOrFail($id);
                $this->concilService->ignorar($mov, (int) $data['motivo_ignorado_id'], $data['observacion'] ?? null, $request->user());
                $ok++;
            } catch (\Throwable $e) {
                $errores[] = "#{$id}: ".$e->getMessage();
            }
        }
        return response()->json(['ok' => true, 'data' => ['ignorados' => $ok, 'errores' => $errores]]);
    }

    /** v1.50 §3.3 — Filtros guardados por usuario (CRUD mínimo). */
    public function filtrosGuardados(Request $request): JsonResponse
    {
        $rows = DB::table('erp_movimientos_bancarios_filtros_guardados')
            ->where('user_id', $request->user()->id)
            ->orderBy('nombre')->get(['id', 'nombre', 'filtros_json', 'es_default']);
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function guardarFiltro(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'filtros' => ['required', 'array'],
            'es_default' => ['nullable', 'boolean'],
        ]);
        DB::table('erp_movimientos_bancarios_filtros_guardados')->updateOrInsert(
            ['user_id' => $request->user()->id, 'nombre' => $data['nombre']],
            ['filtros_json' => json_encode($data['filtros'], JSON_UNESCAPED_UNICODE),
             'es_default' => (int) ($data['es_default'] ?? 0), 'updated_at' => now()],
        );
        return response()->json(['ok' => true]);
    }

    public function borrarFiltro(Request $request, int $id): JsonResponse
    {
        DB::table('erp_movimientos_bancarios_filtros_guardados')
            ->where('id', $id)->where('user_id', $request->user()->id)->delete();
        return response()->json(['ok' => true]);
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

        // v1.48 Anexo A Pieza 1 — si la cuenta contraparte admite auxiliar,
        // exigir auxiliar_id (sino el saldo por proveedor no es trackeable, ej.
        // 1.1.5.01 Anticipos a Proveedores).
        if (($data['referencia_tipo'] ?? null) === 'ASIENTO_MANUAL' && ! empty($data['cuenta_contable_contraparte_id'])) {
            $admite = DB::table('erp_cuentas_contables')
                ->where('id', $data['cuenta_contable_contraparte_id'])->value('admite_auxiliar');
            if ($admite && empty($data['auxiliar_id'])) {
                return response()->json(['ok' => false, 'error' => [
                    'code' => 'AUXILIAR_REQUERIDO',
                    'message' => 'La cuenta seleccionada requiere auxiliar (ej. Anticipos a Proveedores).',
                ]], 422);
            }
        }

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
     * v1.49 §7.2 — Candidatos unificados para el modal de Sugerencias:
     * descuento de cheque + cheques pendientes + recibos directos + facturas
     * (formato existente del v1.48). Cada sección viene vacía si no aplica.
     */
    public function candidatos(Request $request, int $id): JsonResponse
    {
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($id);
        $facturas = $this->concilService->sugerirFacturasConMatchingCuit($mov, (int) $request->query('top', 10));
        $recibos = app(\App\Erp\Services\Conciliacion\ConciliacionRecibosService::class)->candidatos($mov);
        $cheques = app(\App\Erp\Services\Conciliacion\ConciliacionChequesService::class)->candidatos($mov);

        // v1.50 §5.4 — cheques sueltos = candidatos que NO están anidados en un
        // recibo del listado (para no ofrecerlos dos veces).
        $anidados = [];
        foreach ($recibos as $r) {
            foreach ($r['cheques'] as $ch) $anidados[] = $ch['cheque_id'];
        }
        $chequesSueltos = array_values(array_filter($cheques, fn ($c) => ! in_array($c['cheque_id'], $anidados, true)));

        return response()->json(['ok' => true, 'data' => [
            'mov' => [
                'id' => $mov->id, 'fecha' => $mov->fecha->toDateString(), 'concepto' => $mov->concepto,
                'debito' => (float) $mov->debito, 'credito' => (float) $mov->credito,
                'cuenta_bancaria' => $mov->cuentaBancaria?->nombre,
            ],
            'recibos' => $recibos,
            'cheques_sueltos' => $chequesSueltos,
            'descuentos_cheque' => app(\App\Erp\Services\Conciliacion\AutoVincularDescuentosService::class)->candidatos($mov),
            'facturas' => $facturas,
            // Back-compat con el modal v1.49 (mismo dato, claves viejas).
            'recibos_directos' => $recibos,
            'cheques_pendientes' => $cheques,
        ]]);
    }

    /** v1.49 §4.1 — Cheques candidatos (endpoint dedicado, útil para tests). */
    public function chequesCandidatos(Request $request, int $id): JsonResponse
    {
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($id);
        $data = app(\App\Erp\Services\Conciliacion\ConciliacionChequesService::class)
            ->candidatos($mov, $request->query('fecha_desde'), $request->query('fecha_hasta'));
        return response()->json(['ok' => true, 'data' => [
            'cheques' => $data,
            'monto_mov' => (float) $mov->credito,
        ]]);
    }

    /** v1.49 §6.1 — Recibos candidatos (endpoint dedicado). */
    public function recibosCandidatos(Request $request, int $id): JsonResponse
    {
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($id);
        return response()->json(['ok' => true, 'data' => [
            'recibos' => app(\App\Erp\Services\Conciliacion\ConciliacionRecibosService::class)->candidatos($mov),
        ]]);
    }

    /** v1.49 §4.2 — Concilia el mov contra N cheques (asiento consolidado). */
    public function conciliarCheques(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate([
            'cheques' => ['required', 'array', 'min:1'],
            'cheques.*.cheque_id' => ['required', 'integer'],
            'cheques.*.monto' => ['required', 'numeric', 'gt:0'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($id);
        try {
            $mov = app(\App\Erp\Services\Conciliacion\ConciliacionChequesService::class)
                ->conciliar($mov, $data['cheques'], $data['observaciones'] ?? null, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $mov->load('asiento')]);
    }

    /** v1.49 §5.3 — Vincula el mov al asiento de un descuento existente (sin asiento nuevo). */
    public function vincularAsientoDescuento(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate(['asiento_id' => ['required', 'integer']]);
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($id);
        try {
            $mov = app(\App\Erp\Services\Conciliacion\AutoVincularDescuentosService::class)
                ->vincular($mov, (int) $data['asiento_id'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $mov]);
    }

    /** v1.49 §6.2 — Vincula el mov a recibo(s) con medio directo (sin asiento nuevo). */
    public function vincularReciboDirecto(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate([
            'recibos' => ['required', 'array', 'min:1'],
            'recibos.*.recibo_id' => ['required', 'integer'],
            'recibos.*.monto' => ['required', 'numeric', 'gt:0'],
        ]);
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($id);
        try {
            $mov = app(\App\Erp\Services\Conciliacion\ConciliacionRecibosService::class)
                ->vincular($mov, $data['recibos'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $mov]);
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
     * v1.47.2 — Concilia 1 movimiento contra N facturas (1:N) con asiento
     * consolidado. Soporta facturas de distintos auxiliares + diferencia.
     */
    public function conciliarMultiple(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate([
            'facturas' => ['required', 'array', 'min:1'],
            'facturas.*.id' => ['required', 'integer'],
            'facturas.*.tipo' => ['required', Rule::in(['VENTA', 'COMPRA'])],
            'facturas.*.monto_imputado' => ['required', 'numeric', 'gt:0'],
            'motivo' => ['nullable', 'string', 'min:10', 'max:500'],
            'permitir_diferencia' => ['nullable', 'boolean'],
            'cuenta_ajuste_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'motivo_diferencia_id' => ['nullable', 'integer', 'exists:erp_conciliacion_motivos,id'],
            // v1.48 Anexo A — anticipos a cancelar (movs de adelanto previos).
            'anticipos_a_cancelar' => ['nullable', 'array'],
            'anticipos_a_cancelar.*.mov_id' => ['required_with:anticipos_a_cancelar', 'integer'],
            'anticipos_a_cancelar.*.monto' => ['required_with:anticipos_a_cancelar', 'numeric', 'gt:0'],
        ]);
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($id);
        try {
            $mov = $this->concilService->conciliarMultiplesFacturas(
                $mov, $data['facturas'], $request->user(),
                $data['motivo'] ?? null, (bool) ($data['permitir_diferencia'] ?? false),
                $data['cuenta_ajuste_id'] ?? null, $data['motivo_diferencia_id'] ?? null,
                $data['anticipos_a_cancelar'] ?? [],
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
