<?php

namespace App\Erp\Http\Controllers\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Models\Impuestos\RetencionPracticada;
use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Services\Impuestos\CertificadoRetencionService;
use App\Erp\Services\Impuestos\RetencionService;
use App\Erp\Services\Impuestos\SireGeneratorService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SICORE / SIRE — retenciones practicadas (SPEC 05 §6.4).
 *
 *   GET  /api/erp/impuestos/sicore/{periodo_id}                          — agrupado por régimen
 *   POST /api/erp/impuestos/sicore/{periodo_id}/generar                  — TXT SIRE por tipo
 *   GET  /api/erp/impuestos/sicore/{periodo_id}/descargar?tipo=IVA       — descarga
 *   POST /api/erp/impuestos/sicore/aplicar/{op_id}                       — aplica retenciones a una OP
 *   GET  /api/erp/impuestos/sicore/certificados/{retencion_id}           — HTML imprimible
 *   POST /api/erp/impuestos/sicore/certificados/{retencion_id}/anular    — anula (RN-48)
 */
class SicoreController extends Controller
{
    public function __construct(
        private readonly RetencionService $retencionService,
        private readonly SireGeneratorService $sire,
        private readonly CertificadoRetencionService $certificado,
    ) {}

    public function show(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        $rows = RetencionPracticada::where('periodo_id', $periodo->id)
            ->orderBy('tipo_retencion')->orderBy('nro_certificado')
            ->get();

        $byTipo = $rows->groupBy('tipo_retencion')->map(fn ($g) => [
            'cantidad' => $g->count(),
            'total'    => round($g->sum('importe_retenido'), 2),
            'detalle'  => $g->values(),
        ]);

        return response()->json(['ok' => true, 'data' => [
            'periodo' => $periodo, 'por_tipo' => $byTipo,
            'totales' => [
                'cantidad_total' => $rows->count(),
                'monto_total'    => round($rows->sum('importe_retenido'), 2),
            ],
        ]]);
    }

    public function generar(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        try {
            $resultados = $this->sire->generar($periodo, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $resultados]);
    }

    public function descargar(int $periodoId, Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        $tipo = strtoupper((string) $request->query('tipo', 'IVA'));
        if (! in_array($tipo, ['IVA', 'GAN', 'IIBB', 'SUSS'], true)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'TIPO_INVALIDO', 'message' => 'tipo debe ser IVA|GAN|IIBB|SUSS']], 422);
        }

        $mes = str_pad((string) $periodo->mes, 2, '0', STR_PAD_LEFT);
        $path = "sire/{$periodo->empresa_id}/{$periodo->anio}-{$mes}/SIRE_{$tipo}.txt";
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'SIRE_NO_GENERADO', 'message' => "Generar SIRE para {$tipo} primero"]], 404);
        }

        return Storage::disk('local')->download($path, "SIRE_{$tipo}_{$periodo->anio}-{$mes}.txt");
    }

    public function aplicarOp(int $opId, Request $request): JsonResponse
    {
        $datos = $request->validate([
            'condicion_iva' => ['required', 'string'],
            'naturaleza'    => ['nullable', 'in:SERVICIOS,OBRA,BIENES,TRANSPORTE'],
            'jurisdiccion'  => ['nullable', 'in:CABA,PBA'],
            'incluir_suss'  => ['nullable', 'boolean'],
            'factura_compra_id'  => ['nullable', 'integer'],
            'comprobante_origen' => ['nullable', 'string', 'max:30'],
        ]);

        $op = OrdenPago::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($opId);

        try {
            $resultado = $this->retencionService->aplicar($op, $request->user(), $datos);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => [
            'op'                       => $resultado['op'],
            'retenciones_aplicadas'    => $resultado['retenciones'],
            'propuestas_no_aplicadas'  => $resultado['propuestas_no_aplicadas'],
        ]], Response::HTTP_CREATED);
    }

    public function certificadoHtml(int $retencionId, Request $request): Response
    {
        $ret = RetencionPracticada::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($retencionId);
        $html = $this->certificado->renderHtml($ret);
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function anularCertificado(int $retencionId, Request $request): JsonResponse
    {
        $ret = RetencionPracticada::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($retencionId);
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5']]);

        try {
            $ret = $this->retencionService->anular($ret, $request->user(), $datos['motivo']);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $ret]);
    }

    private function periodo(int $id, Request $request): PeriodoFiscal
    {
        return PeriodoFiscal::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->where('impuesto', 'SICORE')
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
