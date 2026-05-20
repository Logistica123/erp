<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Models\VentasCompras\LibroIvaVentasImport;
use App\Erp\Services\LibroIvaVentasImportService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v1.45 — Wizard de import del Libro IVA Ventas.
 *
 *   POST /libro-iva-ventas/import/preview     paso 1: detección sin persistir
 *   POST /libro-iva-ventas/import/confirmar   paso 2: procesa el archivo
 *   GET  /libro-iva-ventas/imports            histórico
 *   GET  /libro-iva-ventas/imports/{id}       detalle
 *   GET  /libro-iva-ventas/imports/{id}/errores.csv
 *   DELETE /libro-iva-ventas/imports/{id}     super_admin
 */
class LibroIvaVentasImportController
{
    public function __construct(
        private readonly LibroIvaVentasImportService $svc,
        private readonly AuditLogger $audit,
    ) {}

    public function preview(Request $request): JsonResponse
    {
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
        $data = $request->validate([
            'archivo' => ['required', 'file', 'max:51200'],
            'periodo_imputacion_id' => ['required', 'integer', 'exists:erp_periodos,id'],
        ]);
        try {
            $r = $this->svc->confirmar(
                $data['archivo']->getRealPath(),
                $data['archivo']->getClientOriginalName(),
                (int) $data['periodo_imputacion_id'],
                $request->user(),
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
        $rows = LibroIvaVentasImport::where('empresa_id', $empresaId)
            ->withCount('facturas')
            ->orderByDesc('importado_at')
            ->limit(100)
            ->get(['id', 'archivo_nombre', 'archivo_hash', 'periodo_afip',
                'filas_totales', 'filas_ok', 'filas_skipped', 'filas_error',
                'clientes_creados', 'estado', 'importado_at']);

        $puedeBorrar = $request->user()?->erpPerfil?->tienePermiso('ventas.libro_iva.borrar_import') ?? false;
        $rows->each(function ($r) use ($puedeBorrar) {
            $r->puede_borrar = $puedeBorrar && (int) $r->facturas_count === 0;
        });

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function importDetalle(Request $request, int $id): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $imp = LibroIvaVentasImport::where('empresa_id', $empresaId)->findOrFail($id);
        return response()->json(['ok' => true, 'data' => $imp]);
    }

    public function descargarErrores(Request $request, int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $imp = LibroIvaVentasImport::where('empresa_id', $empresaId)->findOrFail($id);
        $errores = (array) ($imp->errores_detalle ?? []);

        $filename = sprintf('errores_import_ventas_%d_%s.csv', $imp->id, now()->format('Ymd_His'));

        return response()->stream(function () use ($errores) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['fila', 'codigo_error', 'mensaje'], ';');
            foreach ($errores as $e) {
                $msg = (string) ($e['motivo'] ?? $e['mensaje'] ?? '');
                $codigo = '';
                if (preg_match('/^([A-Z_][A-Z0-9_]*):\s*(.+)$/', $msg, $m)) {
                    $codigo = $m[1];
                    $msg = $m[2];
                }
                fputcsv($out, [$e['row'] ?? $e['fila'] ?? '', $codigo, $msg], ';');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $cascada = $request->boolean('cascada');
        $this->mustHave(
            $request,
            $cascada ? 'ventas.facturas.anular' : 'ventas.libro_iva.borrar_import',
        );

        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $imp = LibroIvaVentasImport::where('empresa_id', $empresaId)->findOrFail($id);

        $facturasCount = FacturaVenta::where('import_id', $imp->id)->count();

        if ($facturasCount > 0 && ! $cascada) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'IMPORT_TIENE_FACTURAS',
                'message' => "Este import generó {$facturasCount} facturas con asientos. "
                    . "Para borrar todo marcá 'cascada' (requiere permiso ventas.facturas.anular).",
            ]], 409);
        }

        $motivo = trim((string) $request->input('motivo', ''));

        if ($cascada && $facturasCount > 0) {
            // v1.50.5 — Borrado en cascada respetando el trigger trg_movimiento_ad
            // (AFTER DELETE en erp_movimientos_asiento llama a sp_recalc_asiento
            // que valida balance debe=haber). Patrón replicado del v1.22 §13
            // compras: borrar el ASIENTO directamente y dejar que el CASCADE
            // de la FK fk_mov_asiento limpie los movimientos. Antes intentábamos
            // borrar movs uno por uno y el trigger rebotaba con "Asiento
            // desbalanceado" después del primer DELETE.
            $facturas = FacturaVenta::where('import_id', $imp->id)->get();
            $facturaIds = $facturas->pluck('id')->all();
            $asientoIds = $facturas->pluck('asiento_id')->filter()->unique()->values()->all();

            DB::transaction(function () use ($imp, $motivo, $facturaIds, $asientoIds, $facturasCount) {
                // 1) Romper FK factura.asiento_id antes de borrar el asiento.
                DB::table('erp_facturas_venta')
                    ->whereIn('id', $facturaIds)
                    ->update(['asiento_id' => null]);

                // 2) Borrar los asientos. erp_movimientos_asiento se va por
                //    CASCADE (fk_mov_asiento ON DELETE CASCADE).
                if (! empty($asientoIds)) {
                    DB::table('erp_asientos')->whereIn('id', $asientoIds)->delete();
                }

                // 3) Borrar las facturas (forceDelete porque tiene SoftDeletes y
                //    queremos liberar el UNIQUE de tipo+PV+nro).
                FacturaVenta::whereIn('id', $facturaIds)->forceDelete();

                // 4) Audit log y borrar el upload (libera el hash).
                $this->audit->log('eliminado_cascada', $imp,
                    ['facturas_count' => $facturasCount, 'asientos_ids' => $asientoIds], null,
                    "Borrado cascada upload Libro IVA Ventas #{$imp->id}: ".
                    ($motivo ?: 'sin motivo'));
                $imp->delete();
            });
            return response()->json(null, 204);
        }

        DB::transaction(function () use ($imp, $motivo) {
            $snapshot = $imp->toArray();
            $descripcion = $motivo !== ''
                ? "Borrado upload Libro IVA Ventas #{$imp->id} ({$imp->archivo_nombre}). Motivo: {$motivo}"
                : "Borrado upload Libro IVA Ventas #{$imp->id} ({$imp->archivo_nombre}).";
            $this->audit->log('eliminado', $imp, $snapshot, null, $descripcion);
            $imp->delete();
        });

        return response()->json(null, 204);
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
            'PERIODO_CERRADO' => 422,
            'PERIODO_NO_ENCONTRADO' => 404,
            default => 422,
        };
        return response()->json(['ok' => false, 'error' => [
            'code' => $code, 'message' => $e->getMessage(),
        ]], $status);
    }
}
