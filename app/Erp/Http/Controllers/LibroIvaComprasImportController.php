<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\LibroIvaComprasImport;
use App\Erp\Services\LibroIvaComprasImportService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function __construct(private readonly LibroIvaComprasImportService $svc) {}

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
            ->orderByDesc('importado_at')
            ->limit(100)
            ->get(['id', 'archivo_nombre', 'archivo_hash', 'periodo_afip',
                'filas_totales', 'filas_tomadas', 'filas_no_tomadas',
                'filas_skipped', 'filas_error', 'estado', 'importado_at']);
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

    public function tomarFacturas(Request $request): JsonResponse
    {
        $data = $request->validate([
            'factura_ids' => ['required', 'array', 'min:1'],
            'factura_ids.*' => ['integer'],
            'periodo_id' => ['required', 'integer', 'exists:erp_periodos,id'],
        ]);
        try {
            $tomadas = $this->svc->tomarFacturas(
                $data['factura_ids'],
                (int) $data['periodo_id'],
                $request->user(),
                (int) ($request->header('X-Empresa-Id') ?: 1),
            );
            return response()->json(['ok' => true, 'data' => ['tomadas' => $tomadas]]);
        } catch (DomainException $e) {
            return $this->errorDomain($e);
        }
    }

    private function errorDomain(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        $status = match ($code) {
            'ARCHIVO_DUPLICADO' => 409,
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
