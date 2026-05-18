<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\VentasCompras\LibroIvaComprasExport;
use App\Erp\Services\GeneradorF8001Service;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADDENDUM v1.11 — endpoints del generador F.8001 Libro IVA Compras.
 *
 *   POST /libro-iva-compras/{periodoId}/exportar-f8001
 *   GET  /libro-iva-compras/exports
 *   GET  /libro-iva-compras/exports/{id}
 *   GET  /libro-iva-compras/exports/{id}/cbte
 *   GET  /libro-iva-compras/exports/{id}/alicuotas
 *   POST /libro-iva-compras/exports/{id}/marcar-enviado
 *   POST /libro-iva-compras/exports/{id}/comparar-liber
 */
class LibroIvaComprasExportController
{
    public function __construct(private readonly GeneradorF8001Service $svc) {}

    public function exportar(Request $request, int $periodoId): JsonResponse
    {
        try {
            $r = $this->svc->generar(
                $periodoId,
                $request->user(),
                (int) ($request->header('X-Empresa-Id') ?: 1),
            );
            return response()->json(['ok' => true, 'data' => $r], 201);
        } catch (DomainException $e) {
            return $this->errorDomain($e);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $rows = LibroIvaComprasExport::where('empresa_id', $empresaId)
            ->orderByDesc('generado_at')
            ->limit(100)
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $exp = LibroIvaComprasExport::where('empresa_id', $empresaId)->findOrFail($id);
        return response()->json(['ok' => true, 'data' => $exp]);
    }

    public function descargarCbte(Request $request, int $id): StreamedResponse|JsonResponse
    {
        return $this->descargarArchivo($request, $id, 'cbte');
    }

    public function descargarAlicuotas(Request $request, int $id): StreamedResponse|JsonResponse
    {
        return $this->descargarArchivo($request, $id, 'alicuotas');
    }

    /**
     * v1.26 — fix de descarga F.8001.
     *
     * Antes: `storage_path('app/'.$path)` → busca en `storage/app/<path>` y
     * tira 404 silente porque Laravel 11 cambió el disco `local` a
     * `storage/app/private/<path>`. Como el SPA tiene fallback al index.html
     * para rutas no encontradas, el navegador descargaba `<!DOCTYPE html>...`
     * con el nombre de archivo forzado por el `download` attribute.
     *
     * Ahora: `Storage::disk('local')->download($path, $filename)` abstrae el
     * path interno y resuelve bien (mismo disco con el que el generador guarda
     * los archivos vía `Storage::disk('local')->put($path, ...)`).
     */
    private function descargarArchivo(Request $request, int $id, string $kind): StreamedResponse|JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $exp = LibroIvaComprasExport::where('empresa_id', $empresaId)->findOrFail($id);

        $path = $kind === 'cbte' ? $exp->archivo_cbte_path : $exp->archivo_alicuotas_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'ARCHIVO_NO_ENCONTRADO',
                'message' => sprintf('El archivo del export #%d no existe en disco (%s).', $id, $kind),
            ]], 404);
        }

        return Storage::disk('local')->download($path, basename($path), [
            'Content-Type' => 'text/plain; charset=ISO-8859-1',
        ]);
    }

    public function marcarEnviado(Request $request, int $id): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $exp = LibroIvaComprasExport::where('empresa_id', $empresaId)->findOrFail($id);
        $exp->update([
            'enviado_afip' => 1,
            'enviado_at' => now(),
            'enviado_por' => $request->user()->id,
        ]);
        return response()->json(['ok' => true, 'data' => $exp->fresh()]);
    }

    /**
     * Compara los TXT generados por el ERP contra los del estudio LIBER
     * (durante el soak). Calcula hashes y reporta primera diferencia si las
     * hay. No modifica nada — es solo lectura.
     */
    public function compararLiber(Request $request, int $id): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $exp = LibroIvaComprasExport::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $request->validate([
            'cbte_liber' => ['required', 'file'],
            'alicuotas_liber' => ['required', 'file'],
        ]);

        $cbteLiber = file_get_contents($data['cbte_liber']->getRealPath());
        $alicLiber = file_get_contents($data['alicuotas_liber']->getRealPath());
        $cbteErp = file_get_contents(storage_path('app/'.$exp->archivo_cbte_path));
        $alicErp = file_get_contents(storage_path('app/'.$exp->archivo_alicuotas_path));

        return response()->json(['ok' => true, 'data' => [
            'cbte_match' => hash('sha256', $cbteLiber) === hash('sha256', $cbteErp),
            'cbte_primera_diferencia' => $this->primeraDiferencia($cbteErp, $cbteLiber),
            'alicuotas_match' => hash('sha256', $alicLiber) === hash('sha256', $alicErp),
            'alicuotas_primera_diferencia' => $this->primeraDiferencia($alicErp, $alicLiber),
        ]]);
    }

    private function primeraDiferencia(string $a, string $b): ?array
    {
        $linesA = preg_split("/\r\n|\n|\r/", rtrim($a, "\r\n"));
        $linesB = preg_split("/\r\n|\n|\r/", rtrim($b, "\r\n"));
        $max = max(count($linesA), count($linesB));
        for ($i = 0; $i < $max; $i++) {
            $la = $linesA[$i] ?? '';
            $lb = $linesB[$i] ?? '';
            if ($la !== $lb) {
                return ['linea' => $i + 1, 'erp' => $la, 'liber' => $lb];
            }
        }
        return null;
    }

    private function errorDomain(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        $status = match ($code) {
            'PERIODO_CERRADO_SIN_PERMISO' => 403,
            'PERIODO_NO_ENCONTRADO' => 404,
            'SIN_FACTURAS' => 422,
            'VALIDACION_BLOQUEANTE' => 422,
            default => 422,
        };
        return response()->json(['ok' => false, 'error' => [
            'code' => $code, 'message' => $e->getMessage(),
        ]], $status);
    }
}
