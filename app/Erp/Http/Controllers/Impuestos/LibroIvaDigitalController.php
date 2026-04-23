<?php

namespace App\Erp\Http\Controllers\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Services\Impuestos\LibroIvaF8001Service;
use App\Erp\Services\Impuestos\LibroIvaService;
use App\Erp\Services\Impuestos\LibroIvaValidador;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Libro IVA Digital (SPEC 05 §6.2).
 *
 *   GET    /api/erp/impuestos/libro-iva/{periodo_id}                — armar + listar comprobantes
 *   POST   /api/erp/impuestos/libro-iva/{periodo_id}/validar        — RN-46
 *   POST   /api/erp/impuestos/libro-iva/{periodo_id}/generar-f8001  — TXT RG 4597
 *   GET    /api/erp/impuestos/libro-iva/{periodo_id}/descargar      — descarga el último TXT
 */
class LibroIvaDigitalController extends Controller
{
    public function __construct(
        private readonly LibroIvaService $libroIva,
        private readonly LibroIvaValidador $validador,
        private readonly LibroIvaF8001Service $f8001,
    ) {}

    public function show(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        $detalle = $this->libroIva->detalle($periodo);

        $data = [
            'periodo'  => $periodo,
            'ventas'   => [
                'cabecera'  => $periodo->libroIvaVentas,
                'comprobantes' => $detalle['ventas'],
            ],
            'compras'  => [
                'cabecera'  => $periodo->libroIvaCompras,
                'comprobantes' => $detalle['compras'],
            ],
        ];

        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function armar(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        try {
            $resultado = $this->libroIva->armar($periodo, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $resultado]);
    }

    public function validar(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        $reporte = $this->validador->validarCierrePeriodo($periodo);

        return response()->json(['ok' => $reporte['ok'], 'data' => $reporte]);
    }

    public function generar(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);

        // Bloquear generación si hay anomalías bloqueantes (RN-46).
        $reporte = $this->validador->validarCierrePeriodo($periodo);
        if (! $reporte['ok']) {
            return response()->json([
                'ok' => false,
                'error' => [
                    'code'    => 'VALIDACION_BLOQ',
                    'message' => "Hay {$reporte['bloqueantes']} anomalías bloqueantes — corregir antes de generar F.8001",
                ],
                'data' => $reporte,
            ], Response::HTTP_CONFLICT);
        }

        try {
            $paths = $this->f8001->generar($periodo, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $paths]);
    }

    public function descargar(int $periodoId, Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        $tipo = $request->query('tipo', 'ventas');
        if (! in_array($tipo, ['ventas', 'compras'], true)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'TIPO_INVALIDO', 'message' => 'tipo debe ser ventas|compras']], 422);
        }

        $cabecera = $tipo === 'ventas' ? $periodo->libroIvaVentas : $periodo->libroIvaCompras;
        if (! $cabecera || ! $cabecera->archivo_f8001_path || ! Storage::disk('local')->exists($cabecera->archivo_f8001_path)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'F8001_NO_GENERADO', 'message' => 'Generar F.8001 primero']], 404);
        }

        $mes = str_pad((string) $periodo->mes, 2, '0', STR_PAD_LEFT);
        $nombre = "F8001_{$tipo}_{$periodo->anio}-{$mes}.txt";

        return Storage::disk('local')->download($cabecera->archivo_f8001_path, $nombre);
    }

    private function periodo(int $id, Request $request): PeriodoFiscal
    {
        return PeriodoFiscal::with(['libroIvaVentas', 'libroIvaCompras'])
            ->where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->where('impuesto', 'IVA')
            ->findOrFail($id);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json([
            'ok' => false,
            'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
