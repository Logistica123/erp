<?php

namespace App\Erp\Http\Controllers\Impuestos;

use App\Erp\Models\Impuestos\IibbCmDeclaracion;
use App\Erp\Models\Impuestos\IibbCoeficiente;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Services\Impuestos\IibbAtribucionService;
use App\Erp\Services\Impuestos\IibbCm03Calculator;
use App\Erp\Services\Impuestos\IibbCm05Calculator;
use App\Erp\Services\Impuestos\IibbJurisdiccionLocalService;
use App\Erp\Services\Impuestos\SifereGeneratorService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * IIBB — Convenio Multilateral + ARCIBA + ARBA (SPEC 05 §6.5).
 *
 *   GET  /iibb/cm/{periodo_id}                           — detalle CM03
 *   POST /iibb/cm/{periodo_id}/calcular                  — calcula CM03
 *   POST /iibb/cm/{periodo_id}/generar-sifere            — TXT
 *   GET  /iibb/cm/{periodo_id}/descargar                 — descarga
 *
 *   POST /iibb/cm05/{periodo_id}/calcular-coeficientes   — recalcula coef. DRAFT
 *   POST /iibb/cm05/coeficientes/{id}/ajustar            — override manual
 *   POST /iibb/cm05/{anio}/aprobar                       — DRAFT → VIGENTE
 *   GET  /iibb/cm05/{anio}/coeficientes                  — lista
 *
 *   GET  /iibb/caba/{periodo_id}                         — detalle ARCIBA
 *   POST /iibb/caba/{periodo_id}/calcular
 *   POST /iibb/caba/{periodo_id}/generar
 *
 *   GET  /iibb/pba/{periodo_id}                          — detalle ARBA
 *   POST /iibb/pba/{periodo_id}/calcular
 *   POST /iibb/pba/{periodo_id}/generar
 */
class IibbController extends Controller
{
    public function __construct(
        private readonly IibbCm03Calculator $cm03,
        private readonly IibbCm05Calculator $cm05,
        private readonly IibbJurisdiccionLocalService $local,
        private readonly IibbAtribucionService $atribucion,
        private readonly SifereGeneratorService $generador,
    ) {}

    // ----- CM03 mensual -----

    public function showCm(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request, 'IIBB_CM');
        $detalle = IibbCmDeclaracion::where('periodo_id', $periodo->id)
            ->where('tipo', 'CM03')->orderBy('jurisdiccion')->get();

        return response()->json(['ok' => true, 'data' => [
            'periodo' => $periodo, 'detalle' => $detalle,
            'total_determinado' => round($detalle->sum('impuesto_determinado'), 2),
            'total_a_pagar'     => round($detalle->sum('importe_a_pagar'), 2),
        ]]);
    }

    public function calcularCm(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request, 'IIBB_CM');
        try {
            $resultado = $this->cm03->calcular($periodo, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $resultado]);
    }

    public function generarCm(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request, 'IIBB_CM');
        try {
            $resultado = $this->generador->generar($periodo, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $resultado]);
    }

    // ----- CM05 anual -----

    public function calcularCoeficientesCm05(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request, 'IIBB_CM');
        $base = $request->validate([
            'base_calendar' => ['nullable', 'in:abril_marzo,enero_diciembre'],
        ])['base_calendar'] ?? 'abril_marzo';

        try {
            $res = $this->cm05->calcular($periodo, $request->user(), $base);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function ajustarCoeficiente(int $id, Request $request): JsonResponse
    {
        $datos = $request->validate(['coeficiente' => ['required', 'numeric', 'min:0', 'max:1']]);
        $row = IibbCoeficiente::findOrFail($id);

        try {
            $actualizado = $this->cm05->ajustarManual(
                $row->anio_vigencia, $row->jurisdiccion, (float) $datos['coeficiente'], $request->user()
            );
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $actualizado]);
    }

    public function aprobarCoeficientesCm05(int $anio, Request $request): JsonResponse
    {
        try {
            $n = $this->cm05->aprobar($anio, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => ['anio' => $anio, 'coeficientes_aprobados' => $n]]);
    }

    public function listarCoeficientes(int $anio): JsonResponse
    {
        $rows = IibbCoeficiente::where('anio_vigencia', $anio)
            ->orderBy('jurisdiccion')->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    // ----- ARCIBA (CABA) y ARBA (PBA) -----

    public function showLocal(int $periodoId, string $impuesto, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request, $impuesto);
        $detalle = IibbCmDeclaracion::where('periodo_id', $periodo->id)->first();

        return response()->json(['ok' => true, 'data' => [
            'periodo' => $periodo, 'detalle' => $detalle,
        ]]);
    }

    public function calcularLocal(int $periodoId, string $impuesto, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request, $impuesto);
        try {
            $res = $this->local->calcular($periodo, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function generarLocal(int $periodoId, string $impuesto, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request, $impuesto);
        try {
            $res = $this->generador->generar($periodo, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function descargar(int $periodoId, Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $periodo = PeriodoFiscal::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->whereIn('impuesto', ['IIBB_CM', 'IIBB_CABA', 'IIBB_PBA'])
            ->findOrFail($periodoId);

        $mes = str_pad((string) $periodo->mes, 2, '0', STR_PAD_LEFT);
        $path = "iibb/{$periodo->empresa_id}/{$periodo->anio}-{$mes}/{$periodo->impuesto}.txt";
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'IIBB_NO_GENERADO', 'message' => 'Generar primero']], 404);
        }
        return Storage::disk('local')->download($path, "{$periodo->impuesto}_{$periodo->anio}-{$mes}.txt");
    }

    // ----- helpers -----

    private function periodo(int $id, Request $request, string $impuestoEsperado): PeriodoFiscal
    {
        $periodo = PeriodoFiscal::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($id);
        if ($periodo->impuesto !== $impuestoEsperado) {
            abort(404, "Período {$id} no es {$impuestoEsperado}");
        }
        return $periodo;
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json([
            'ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
