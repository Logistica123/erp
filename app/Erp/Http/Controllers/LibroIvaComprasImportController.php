<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\LibroIvaComprasImport;
use App\Erp\Services\LibroIvaComprasImportService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.9 — endpoints del wizard de import del Libro IVA Compras.
 *
 *   POST /libro-iva-compras/import/preview     — paso 1: detección sin persistir
 *   POST /libro-iva-compras/import/confirmar   — paso 2: procesa el archivo
 *   GET  /libro-iva-compras/imports            — histórico
 *   GET  /libro-iva-compras/imports/{id}       — detalle
 *   GET  /libro-iva-compras/no-tomadas         — facturas con no_tomada=1
 *   POST /libro-iva-compras/no-tomadas/tomar   — reactivar facturas en período X
 */
class LibroIvaComprasImportController
{
    public function __construct(
        private readonly LibroIvaComprasImportService $svc,
        private readonly AuditLogger $audit,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $this->mustHave($request, 'compras.libro_iva.importar');
        $data = $request->validate([
            'archivo' => ['required', 'file', 'max:51200'],
        ]);
        try {
            $r = $this->svc->preview(
                $data['archivo']->getRealPath(),
                $data['archivo']->getClientOriginalName(),
                (int) ($request->header('X-Empresa-Id') ?: 1),
            );
            return response()->json(['ok' => true, 'data' => $r]);
        } catch (DomainException $e) {
            return $this->errorDomain($e);
        }
    }

    public function confirmar(Request $request): JsonResponse
    {
        $this->mustHave($request, 'compras.libro_iva.importar');
        $data = $request->validate([
            'archivo' => ['required', 'file', 'max:51200'],
            'periodo_imputacion_id' => ['required', 'integer', 'exists:erp_periodos,id'],
            'confirmar_periodo_cerrado' => ['nullable', 'boolean'],
        ]);
        try {
            $r = $this->svc->confirmar(
                $data['archivo']->getRealPath(),
                $data['archivo']->getClientOriginalName(),
                (int) $data['periodo_imputacion_id'],
                $request->user(),
                (bool) ($data['confirmar_periodo_cerrado'] ?? false),
                (int) ($request->header('X-Empresa-Id') ?: 1),
            );
            return response()->json(['ok' => true, 'data' => $r], 201);
        } catch (DomainException $e) {
            return $this->errorDomain($e);
        }
    }

    public function imports(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $rows = LibroIvaComprasImport::where('empresa_id', $empresaId)
            ->withCount('facturas') // v1.20 — agrega facturas_count
            ->orderByDesc('importado_at')
            ->limit(100)
            ->get(['id', 'archivo_nombre', 'archivo_hash', 'periodo_afip',
                'filas_totales', 'filas_tomadas', 'filas_no_tomadas',
                'filas_skipped', 'filas_error', 'estado', 'importado_at']);

        // v1.20 — derivar puede_borrar para que el frontend renderice condicional
        // sin tener que cruzar con /mi-permisos. El permiso solo lo tiene super_admin.
        $puedePermiso = $request->user()?->erpPerfil?->tienePermiso('compras.libro_iva.borrar_import') ?? false;
        $rows->each(function ($r) use ($puedePermiso) {
            $r->puede_borrar = $puedePermiso && (int) $r->facturas_count === 0;
        });

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function importDetalle(Request $request, int $id): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $imp = LibroIvaComprasImport::where('empresa_id', $empresaId)->findOrFail($id);
        return response()->json(['ok' => true, 'data' => $imp]);
    }

    public function noTomadas(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $rows = FacturaCompra::query()
            ->where('empresa_id', $empresaId)
            ->where('no_tomada', 1)
            ->with(['tipoComprobante:id,codigo_interno,letra', 'auxiliar:id,nombre,cuit'])
            ->orderByDesc('fecha_emision')
            ->limit(500)
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /**
     * GET /api/erp/libro-iva-compras/imports/{id}/errores.csv — v1.19.
     *
     * Devuelve CSV con todos los errores del import para diagnóstico offline.
     * Útil cuando el wizard reporta muchos errores y el contador quiere
     * analizar en Excel.
     */
    public function descargarErrores(Request $request, int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $imp = LibroIvaComprasImport::where('empresa_id', $empresaId)->findOrFail($id);
        $errores = (array) ($imp->errores_detalle ?? []);

        $filename = sprintf('errores_import_%d_%s.csv', $imp->id, now()->format('Ymd_His'));

        return response()->stream(function () use ($errores) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para que abra limpio en Excel
            fputcsv($out, ['fila', 'codigo_error', 'mensaje'], ';');
            foreach ($errores as $e) {
                $msg = (string) ($e['motivo'] ?? $e['mensaje'] ?? '');
                // Extraer código si vino prefijado tipo "CUENTA_X: mensaje".
                $codigo = '';
                if (preg_match('/^([A-Z_][A-Z0-9_]*):\s*(.+)$/', $msg, $m)) {
                    $codigo = $m[1];
                    $msg = $m[2];
                }
                fputcsv($out, [
                    $e['row'] ?? $e['fila'] ?? '',
                    $codigo,
                    $msg,
                ], ';');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * DELETE /api/erp/libro-iva-compras/imports/{id} — v1.20.
     *
     * Borra un upload del Libro IVA Compras. Requiere permiso
     * `compras.libro_iva.borrar_import` (solo super_admin).
     *
     * Bloqueos:
     *   - 403 si falta el permiso.
     *   - 404 si el import no existe en la empresa.
     *   - 409 IMPORT_TIENE_ASIENTOS si tiene facturas vinculadas (la FK
     *     `erp_facturas_compra.import_id` con DELETE_RULE=NO ACTION
     *     bloquearía igual; lo detectamos antes para devolver mensaje útil).
     *
     * Borrado físico (D-20-3): libera el hash SHA256 para permitir re-subir
     * el mismo archivo después de un fix. El histórico queda en audit log
     * inmutable (hash-chain), con snapshot completo del upload + motivo.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        // v1.22 §13 — cascada=true requiere otro permiso más amplio (borra
        // también facturas + asientos generados por este import).
        $cascada = $request->boolean('cascada');
        $this->mustHave(
            $request,
            $cascada ? 'compras.facturas.borrar_masivo' : 'compras.libro_iva.borrar_import',
        );

        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $imp = LibroIvaComprasImport::where('empresa_id', $empresaId)->findOrFail($id);

        $facturas = FacturaCompra::where('import_id', $imp->id)->get();
        $facturasCount = $facturas->count();

        if ($facturasCount > 0 && ! $cascada) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'IMPORT_TIENE_FACTURAS',
                'message' => "Este import generó {$facturasCount} facturas con asientos. "
                    . "Para borrar todo (incluyendo asientos) marcá 'cascada' en el modal — solo si el período está ABIERTO.",
            ]], 409);
        }

        $motivo = trim((string) $request->input('motivo', ''));

        // v1.22 §13 — si cascada, primero borramos masivamente las facturas
        // (con sus asientos) reusando la lógica de FacturasCompraController.
        // Las validaciones de período cerrado / conciliación las hace el helper.
        if ($cascada && $facturasCount > 0) {
            // Cargar con período para validación.
            $facturasConPeriodo = FacturaCompra::with(['periodo:id,anio,mes,estado'])
                ->where('import_id', $imp->id)->get();

            $cerradas = $facturasConPeriodo->filter(
                fn ($f) => $f->periodo && in_array($f->periodo->estado, ['CERRADO', 'BLOQUEADO'], true)
            );
            if ($cerradas->isNotEmpty()) {
                return response()->json(['ok' => false, 'error' => [
                    'code' => 'PERIODO_CERRADO_EN_SELECCION',
                    'message' => 'Hay facturas en períodos cerrados o bloqueados. Reabrí el período primero.',
                ]], 422);
            }
            $facturaIds = $facturasConPeriodo->pluck('id')->all();
            $conciliadasOp = DB::table('erp_op_items')
                ->whereIn('comprobante_id', $facturaIds)
                ->where('tipo_item', 'FACTURA_COMPRA')
                ->exists();
            $conciliadasEmp = DB::table('erp_emp_pagos')
                ->whereIn('factura_compra_id', $facturaIds)
                ->exists();
            if ($conciliadasOp || $conciliadasEmp) {
                return response()->json(['ok' => false, 'error' => [
                    'code' => 'FACTURA_CONCILIADA',
                    'message' => 'Hay facturas conciliadas con órdenes de pago o pagos. Desconciliá primero.',
                ]], 422);
            }

            // El helper borra facturas + asientos + libera uploads vacíos.
            // Como las facturas son TODAS del mismo import, el import se va a
            // borrar automáticamente vía D-22-14.
            $facturasCtrl = app(\App\Erp\Http\Controllers\FacturasCompraController::class);
            $facturasCtrl->borrarMasivoInterno($facturasConPeriodo,
                $motivo !== '' ? "[cascada upload #{$imp->id}] {$motivo}" : "[cascada upload #{$imp->id}]");

            return response()->json(null, 204);
        }

        DB::transaction(function () use ($imp, $motivo) {
            // Snapshot completo antes del DELETE (D-20-5: audit log inmutable).
            $snapshot = [
                'id' => $imp->id,
                'empresa_id' => $imp->empresa_id,
                'archivo_nombre' => $imp->archivo_nombre,
                'archivo_hash' => $imp->archivo_hash,
                'encoding_detectado' => $imp->encoding_detectado,
                'periodo_afip' => $imp->periodo_afip,
                'periodo_imputacion_id' => $imp->periodo_imputacion_id,
                'filas_totales' => $imp->filas_totales,
                'filas_tomadas' => $imp->filas_tomadas,
                'filas_no_tomadas' => $imp->filas_no_tomadas,
                'filas_skipped' => $imp->filas_skipped,
                'filas_error' => $imp->filas_error,
                'errores_detalle' => $imp->errores_detalle,
                'clientes_no_mapeados' => $imp->clientes_no_mapeados,
                'proveedores_creados' => $imp->proveedores_creados,
                'importado_por' => $imp->importado_por,
                'importado_at' => $imp->importado_at?->toIso8601String(),
                'estado' => $imp->estado,
            ];
            $descripcion = $motivo !== ''
                ? "Borrado upload Libro IVA Compras #{$imp->id} ({$imp->archivo_nombre}). Motivo: {$motivo}"
                : "Borrado upload Libro IVA Compras #{$imp->id} ({$imp->archivo_nombre}).";

            $this->audit->log('eliminado', $imp, $snapshot, null, $descripcion);
            $imp->delete();
        });

        return response()->json(null, 204);
    }

    public function tomarFacturas(Request $request): JsonResponse
    {
        $data = $request->validate([
            'factura_ids' => ['required', 'array', 'min:1'],
            'factura_ids.*' => ['integer'],
            'periodo_id' => ['required', 'integer', 'exists:erp_periodos,id'],
            // v1.27 — opcional: setear el período trabajado en la misma operación.
            'periodo_trabajado_texto' => ['nullable', 'string', 'max:20', 'regex:/^(|\d{4}-\d{2}(-Q[12])?)$/'],
        ]);
        try {
            $tomadas = $this->svc->tomarFacturas(
                $data['factura_ids'],
                (int) $data['periodo_id'],
                $request->user(),
                (int) ($request->header('X-Empresa-Id') ?: 1),
                $data['periodo_trabajado_texto'] ?? null,
            );
            return response()->json(['ok' => true, 'data' => ['tomadas' => $tomadas]]);
        } catch (DomainException $e) {
            return $this->errorDomain($e);
        }
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

    private function errorDomain(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        $status = match ($code) {
            'ARCHIVO_DUPLICADO' => 409,
            // v1.19 D-19-3: PERIODO_CERRADO ahora es 422 sin bypass.
            // Los códigos viejos (PERIODO_CERRADO_SIN_PERMISO + CONFIRMACION_REQUERIDA)
            // se mantienen por backward-compat con audit logs históricos.
            'PERIODO_CERRADO' => 422,
            'PERIODO_CERRADO_SIN_PERMISO' => 403,
            'CONFIRMACION_REQUERIDA' => 422,
            'PERIODO_NO_ENCONTRADO' => 404,
            default => 422,
        };
        return response()->json(['ok' => false, 'error' => [
            'code' => $code, 'message' => $e->getMessage(),
        ]], $status);
    }
}
