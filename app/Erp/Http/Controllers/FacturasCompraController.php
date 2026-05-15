<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Asiento;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\LibroIvaComprasImport;
use App\Erp\Services\FacturaCompraService;
use App\Erp\Support\AuditLogger;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints de facturas de compra (SPEC 03 §6.3).
 *
 *   GET   /api/erp/facturas-compra                     Lista con filtros
 *   GET   /api/erp/facturas-compra/{id}                Detalle
 *   POST  /api/erp/facturas-compra                     Alta manual (con constatación RN-42)
 *   PATCH /api/erp/facturas-compra/{id}                Edición (solo RECIBIDA)
 *   POST  /api/erp/facturas-compra/{id}/controlar      El tilde: RECIBIDA→CONTROLADA, asiento RN-34
 *   POST  /api/erp/facturas-compra/{id}/observar       Marca OBSERVADA con motivo
 *   POST  /api/erp/facturas-compra/{id}/rechazar       Marca RECHAZADA
 */
class FacturasCompraController extends Controller
{
    public function __construct(
        private readonly FacturaCompraService $service,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('erp_facturas_compra as f')
            ->leftJoin('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->leftJoin('erp_monedas as m', 'm.id', '=', 'f.moneda_id')
            ->leftJoin('erp_asientos as asi', 'asi.id', '=', 'f.asiento_id')
            ->where('f.empresa_id', 1)
            ->whereNull('f.deleted_at')
            ->select([
                'f.id', 'f.numero', 'f.cae', 'f.fecha_emision', 'f.fecha_vencimiento',
                'f.fecha_imputacion', 'f.periodo_id', 'f.imputacion_diferida',
                'f.imp_neto_gravado', 'f.imp_iva', 'f.imp_total',
                'f.origen', 'f.verificada_arca', 'f.estado', 'f.constatacion_estado',
                'tc.codigo_interno as tipo_codigo', 'tc.letra', 'tc.clase as tipo_clase',
                'f.punto_venta',
                'a.id as proveedor_id', 'a.nombre as proveedor_nombre', 'a.cuit as proveedor_cuit',
                'f.cuit_emisor', 'f.razon_social_emisor',
                'm.codigo as moneda',
                'f.asiento_id', 'asi.numero as asiento_numero',
                // Addendum v1.13 + v1.14 — campos enriquecidos del import
                'f.no_tomada', 'f.cliente_auxiliar_id', 'f.tipo_gasto',
                'f.periodo_trabajado_texto', 'f.jurisdiccion_codigo', 'f.centro_costo_id',
            ]);

        if ($estado = $request->query('estado')) {
            $q->where('f.estado', $estado);
        }
        if ($proveedor = $request->integer('proveedor_id')) {
            $q->where('f.auxiliar_id', $proveedor);
        }
        if ($desde = $request->query('desde')) {
            $q->where('f.fecha_emision', '>=', $desde);
        }
        if ($hasta = $request->query('hasta')) {
            $q->where('f.fecha_emision', '<=', $hasta);
        }
        if ($origen = $request->query('origen')) {
            $q->where('f.origen', $origen);
        }
        // Addendum v1.13 + v1.14 — filtros enriquecidos.
        $noTomada = $request->query('no_tomada');
        if ($noTomada === '0' || $noTomada === '1') {
            $q->where('f.no_tomada', (int) $noTomada);
        }
        if ($tipoGasto = $request->query('tipo_gasto')) {
            $q->where('f.tipo_gasto', $tipoGasto);
        }
        if ($periodoTrab = $request->query('periodo_trabajado')) {
            $q->where('f.periodo_trabajado_texto', $periodoTrab);
        }
        if ($juris = $request->query('jurisdiccion')) {
            $q->where('f.jurisdiccion_codigo', $juris);
        }

        return response()->json([
            'data' => $q->orderByDesc('f.fecha_emision')->orderByDesc('f.id')->limit(200)->get(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $f = FacturaCompra::with([
            'tipoComprobante', 'auxiliar', 'condicionIva', 'moneda', 'asiento',
            'items.alicuotaIva', 'iva.alicuotaIva', 'tributos.tipoTributo', 'asociadas',
        ])
            ->where('empresa_id', 1)
            ->findOrFail($id);

        return response()->json(['data' => $f]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo_comprobante_id' => ['required', 'integer', 'exists:erp_tipos_comprobante,id'],
            'punto_venta' => ['required', 'integer'],
            'numero' => ['required', 'integer'],
            'cae' => ['nullable', 'string', 'max:20'],
            'fecha_vto_cae' => ['nullable', 'date'],
            'fecha_emision' => ['required', 'date'],
            'fecha_imputacion' => ['nullable', 'date'],
            'fecha_recepcion' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'auxiliar_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'cuit_emisor' => ['required', 'string', 'max:13'],
            'razon_social_emisor' => ['nullable', 'string', 'max:250'],
            'condicion_iva_id' => ['required', 'integer', 'exists:erp_condiciones_iva,id'],
            'moneda_id' => ['required', 'integer', 'exists:erp_monedas,id'],
            'cotizacion' => ['required', 'numeric', 'min:0.0001'],
            'imp_neto_gravado' => ['required', 'numeric', 'min:0'],
            'imp_no_gravado' => ['nullable', 'numeric', 'min:0'],
            'imp_exento' => ['nullable', 'numeric', 'min:0'],
            'imp_iva' => ['nullable', 'numeric', 'min:0'],
            'imp_tributos' => ['nullable', 'numeric', 'min:0'],
            'imp_percepciones' => ['nullable', 'numeric', 'min:0'],
            'imp_retenciones' => ['nullable', 'numeric', 'min:0'],
            'imp_total' => ['required', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'centro_costo_id' => ['nullable', 'integer'],
        ]);

        try {
            $imputacion = $this->service->resolverImputacion(
                $data['fecha_emision'],
                $data['fecha_imputacion'] ?? null,
                $request->user(),
            );
        } catch (\DomainException $e) {
            $code = explode(':', $e->getMessage(), 2)[0];
            $status = $code === 'PERIODO_CERRADO_SIN_PERMISO' ? 403 : 422;
            return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], $status);
        }

        $factura = DB::transaction(function () use ($data, $request, $imputacion) {
            return FacturaCompra::create([
                ...$data,
                ...$imputacion,
                'empresa_id' => 1,
                'origen' => $data['cae'] ? 'MANUAL' : 'MANUAL',
                'estado' => FacturaCompraService::ESTADO_RECIBIDA,
                'constatacion_estado' => $data['cae'] ? 'PENDIENTE' : 'NO_APLICA',
                'imp_no_gravado' => $data['imp_no_gravado'] ?? 0,
                'imp_exento' => $data['imp_exento'] ?? 0,
                'imp_iva' => $data['imp_iva'] ?? 0,
                'imp_tributos' => $data['imp_tributos'] ?? 0,
                'imp_percepciones' => $data['imp_percepciones'] ?? 0,
                'imp_retenciones' => $data['imp_retenciones'] ?? 0,
                'created_by_user_id' => $request->user()->id,
            ]);
        });

        return response()->json(['ok' => true, 'data' => $factura], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $factura = FacturaCompra::where('empresa_id', 1)->findOrFail($id);

        if ($factura->estado !== FacturaCompraService::ESTADO_RECIBIDA) {
            return response()->json([
                'error' => ['code' => 'FACTURA_NO_EDITABLE', 'message' => 'Solo editable en estado RECIBIDA (actual: '.$factura->estado.')'],
            ], 409);
        }

        $data = $request->validate([
            'fecha_emision' => ['nullable', 'date'],
            'fecha_imputacion' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'imp_neto_gravado' => ['nullable', 'numeric', 'min:0'],
            'imp_iva' => ['nullable', 'numeric', 'min:0'],
            'imp_total' => ['nullable', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'centro_costo_id' => ['nullable', 'integer'],
        ]);

        // Si tocan alguna fecha relevante, recomputar imputación.
        if (isset($data['fecha_emision']) || isset($data['fecha_imputacion'])) {
            try {
                $imputacion = $this->service->resolverImputacion(
                    $data['fecha_emision'] ?? $factura->fecha_emision->toDateString(),
                    $data['fecha_imputacion'] ?? null,
                    $request->user(),
                );
                $data = [...$data, ...$imputacion];
            } catch (\DomainException $e) {
                $code = explode(':', $e->getMessage(), 2)[0];
                $status = $code === 'PERIODO_CERRADO_SIN_PERMISO' ? 403 : 422;
                return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], $status);
            }
        }

        $factura->update($data);

        return response()->json(['ok' => true, 'data' => $factura->fresh()]);
    }

    public function controlar(Request $request, int $id): JsonResponse
    {
        $factura = FacturaCompra::where('empresa_id', 1)->findOrFail($id);

        try {
            $factura = $this->service->controlar($factura, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura->load('asiento')]);
    }

    public function observar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $factura = FacturaCompra::where('empresa_id', 1)->findOrFail($id);

        try {
            $factura = $this->service->observar($factura, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $factura = FacturaCompra::where('empresa_id', 1)->findOrFail($id);

        try {
            $factura = $this->service->rechazar($factura, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura]);
    }

    /**
     * Registra NC recibida del proveedor vinculada a una factura de compra
     * original (SPEC 03 §6.3 / RN-33). El ID de la factura original viene en la URL.
     */
    public function registrarNc(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tipo_comprobante_id' => ['required', 'integer', 'exists:erp_tipos_comprobante,id'],
            'punto_venta' => ['required', 'integer'],
            'numero' => ['required', 'integer'],
            'cuit_emisor' => ['required', 'string', 'max:13'],
            'fecha_emision' => ['required', 'date'],
            'fecha_recepcion' => ['nullable', 'date'],
            'cae' => ['nullable', 'string', 'max:20'],
            'fecha_vto_cae' => ['nullable', 'date'],
            'imp_neto_gravado' => ['required', 'numeric', 'min:0'],
            'imp_no_gravado' => ['nullable', 'numeric', 'min:0'],
            'imp_exento' => ['nullable', 'numeric', 'min:0'],
            'imp_iva' => ['nullable', 'numeric', 'min:0'],
            'imp_tributos' => ['nullable', 'numeric', 'min:0'],
            'imp_total' => ['required', 'numeric', 'gt:0'],
            'motivo' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $nc = $this->service->registrarNc([
                ...$data,
                'factura_original_id' => $id,
            ], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'ok' => true,
            'data' => ['nota_credito' => $nc, 'factura_original_id' => $id],
        ], 201);
    }

    /**
     * v1.22 §13 — POST /api/erp/facturas-compra/borrar-masivo
     *
     * Borra facturas de compra masivamente con sus asientos asociados. Pensado
     * para limpieza de uploads de testing donde el botón 🗑️ del v1.20 no sirve
     * (porque las facturas tienen asientos generados → 409 IMPORT_TIENE_ASIENTOS).
     *
     * Bloqueos (D-22-12, D-22-17):
     *   - 403 si falta permiso `compras.facturas.borrar_masivo` (solo super_admin)
     *   - 422 PERIODO_CERRADO_EN_SELECCION si alguna factura está en período CERRADO/BLOQUEADO
     *   - 422 FACTURA_CONCILIADA si alguna factura está vinculada a una OP o pago a empleado
     *
     * Procedimiento (D-22-13, D-22-14, D-22-15):
     *   1. Audit log snapshot ANTES del DELETE.
     *   2. NULL-ear asiento_id en facturas (rompe la FK).
     *   3. forceDelete de asientos (cascade movimientos_asiento por FK).
     *   4. forceDelete de facturas (cascade items/iva/tributos/asociadas/constatacion).
     *   5. Liberar uploads del Libro IVA que queden sin facturas vinculadas.
     */
    public function borrarMasivo(Request $request): JsonResponse
    {
        $this->mustHave($request, 'compras.facturas.borrar_masivo');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $motivo = trim((string) ($data['motivo'] ?? ''));

        $facturas = FacturaCompra::with(['periodo:id,anio,mes,estado'])
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $ids)
            ->get();

        if ($facturas->count() !== count($ids)) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'FACTURAS_NO_ENCONTRADAS',
                'message' => sprintf('Se pidieron %d IDs pero solo %d existen en la empresa.', count($ids), $facturas->count()),
            ]], 422);
        }

        $cerradas = $facturas->filter(
            fn ($f) => $f->periodo && in_array($f->periodo->estado, ['CERRADO', 'BLOQUEADO'], true)
        );
        if ($cerradas->isNotEmpty()) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'PERIODO_CERRADO_EN_SELECCION',
                'message' => 'Hay facturas en períodos cerrados o bloqueados. Reabrí el período o desmarcalas.',
                'facturas_bloqueadas' => $cerradas->map(fn ($f) => [
                    'id' => $f->id,
                    'comprobante' => sprintf('%d-%05d-%08d', $f->tipo_comprobante_id, $f->punto_venta, $f->numero),
                    'periodo' => sprintf('%02d/%d', $f->periodo->mes, $f->periodo->anio),
                ])->values(),
            ]], 422);
        }

        $idsLista = $facturas->pluck('id')->all();
        $conciliadasOp = DB::table('erp_op_items')
            ->whereIn('comprobante_id', $idsLista)
            ->where('tipo_item', 'FACTURA_COMPRA')
            ->pluck('comprobante_id')->unique()->values();
        $conciliadasEmp = DB::table('erp_emp_pagos')
            ->whereIn('factura_compra_id', $idsLista)
            ->pluck('factura_compra_id')->unique()->values();
        $conciliadas = $conciliadasOp->concat($conciliadasEmp)->unique()->values();
        if ($conciliadas->isNotEmpty()) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'FACTURA_CONCILIADA',
                'message' => 'Hay facturas conciliadas con órdenes de pago o pagos a empleados. Desconciliá primero.',
                'facturas_bloqueadas' => $conciliadas->all(),
            ]], 422);
        }

        $borradas = $this->borrarMasivoInterno($facturas, $motivo);

        return response()->json(['ok' => true, 'data' => ['borradas' => $borradas]], 200);
    }

    /**
     * v1.22 §13 — núcleo del borrado masivo, reusable desde
     * LibroIvaComprasImportController cuando se llama con ?cascada=true.
     *
     * Asume que las validaciones de período y conciliación ya pasaron.
     * Devuelve cantidad borrada.
     */
    public function borrarMasivoInterno($facturas, string $motivo = ''): int
    {
        if ($facturas->isEmpty()) {
            return 0;
        }

        $snapshot = [
            'count' => $facturas->count(),
            'importe_total' => (float) $facturas->sum('imp_total'),
            'asientos_ids' => $facturas->pluck('asiento_id')->filter()->unique()->values()->all(),
            'import_ids' => $facturas->pluck('import_id')->filter()->unique()->values()->all(),
            'comprobantes' => $facturas->map(fn ($f) => [
                'id' => $f->id,
                'tipo' => $f->tipo_comprobante_id,
                'pv' => $f->punto_venta,
                'numero' => $f->numero,
                'cuit' => $f->cuit_emisor,
                'razon_social' => $f->razon_social_emisor,
                'fecha_emision' => $f->fecha_emision?->toDateString(),
                'imp_total' => (float) $f->imp_total,
            ])->values()->all(),
            'motivo' => $motivo !== '' ? $motivo : null,
        ];
        $descripcion = $motivo !== ''
            ? sprintf('Borrado masivo de %d facturas de compra. Motivo: %s', $facturas->count(), $motivo)
            : sprintf('Borrado masivo de %d facturas de compra.', $facturas->count());

        // Snapshot al audit log usando la primera factura como modelo "ancla"
        // (la cadena de hashes es por empresa, no por entidad puntual).
        $this->audit->log('borrado_masivo', $facturas->first(), $snapshot, null, $descripcion);

        $asientoIds = $facturas->pluck('asiento_id')->filter()->unique()->values()->all();
        $facturaIds = $facturas->pluck('id')->all();
        $importIds = $facturas->pluck('import_id')->filter()->unique()->values()->all();

        DB::transaction(function () use ($facturaIds, $asientoIds) {
            // Tablas que referencian factura con FK NO ACTION — limpiar primero.
            DB::table('erp_libro_iva_detalle')->whereIn('factura_compra_id', $facturaIds)->delete();
            DB::table('erp_iibb_jurisdiccion_mov')->whereIn('factura_compra_id', $facturaIds)->delete();
            DB::table('erp_percepciones_sufridas')->whereIn('factura_compra_id', $facturaIds)->delete();
            DB::table('erp_retenciones_practicadas')->whereIn('factura_compra_id', $facturaIds)->delete();

            // Romper FK factura → asiento antes de borrar el asiento.
            DB::table('erp_facturas_compra')->whereIn('id', $facturaIds)->update(['asiento_id' => null]);

            if (! empty($asientoIds)) {
                // erp_movimientos_asiento se borra por CASCADE.
                Asiento::whereIn('id', $asientoIds)->forceDelete();
            }

            // forceDelete porque erp_facturas_compra usa SoftDeletes (queremos
            // borrado físico para liberar el UNIQUE de tipo+pv+numero y permitir
            // re-importar el mismo CSV).
            FacturaCompra::whereIn('id', $facturaIds)->forceDelete();
        });

        // D-22-14: si algún import quedó sin facturas, borrar el upload (libera el hash).
        foreach ($importIds as $importId) {
            $quedan = FacturaCompra::where('import_id', $importId)->count();
            if ($quedan === 0) {
                $imp = LibroIvaComprasImport::find($importId);
                if ($imp) {
                    $this->audit->log('eliminado_por_cascada', $imp,
                        ['archivo_hash' => $imp->archivo_hash, 'archivo_nombre' => $imp->archivo_nombre],
                        null,
                        sprintf('Upload Libro IVA #%d borrado tras vaciarse por borrado_masivo de facturas.', $imp->id),
                    );
                    $imp->delete();
                }
            }
        }

        return $facturas->count();
    }

    private function mustHave(Request $request, string $codigo): void
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
