<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Services\Impuestos\LibroIvaService;
use App\Erp\Services\Reportes\AgingService;
use App\Erp\Services\Reportes\ComparativoService;
use App\Erp\Services\Reportes\CtaCorrienteService;
use App\Erp\Services\Reportes\DiarioService;
use App\Erp\Services\Reportes\MayorService;
use App\Erp\Services\Reportes\SumasSaldosService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Reportes contables y gerenciales (SPEC 05 §6.8, RN-66, RN-67, RN-68, RN-69).
 *
 *   GET /api/erp/reportes/mayor                ?cuenta_id, desde, hasta, formato
 *   GET /api/erp/reportes/diario               ?desde, hasta, diario_id?
 *   GET /api/erp/reportes/sumas-y-saldos       ?desde, hasta
 *   GET /api/erp/reportes/libro-iva-interno    ?periodo_id
 *   GET /api/erp/reportes/cc-clientes          ?cliente_id, fecha?
 *   GET /api/erp/reportes/cc-proveedores       ?proveedor_id, fecha?
 *   GET /api/erp/reportes/aging                ?tipo=clientes|proveedores, fecha?
 *   GET /api/erp/reportes/comparativo          ?reporte=resultado|balance, periodos=p1,p2[,..]
 *
 * Formato JSON por default. PDF/XLSX se entregan en H8 (necesitan DomPDF /
 * PhpSpreadsheet) y devuelven 501 mientras tanto.
 */
class ReportesContablesController extends Controller
{
    public function __construct(
        private readonly MayorService $mayor,
        private readonly DiarioService $diario,
        private readonly SumasSaldosService $sys,
        private readonly LibroIvaService $libroIva,
        private readonly CtaCorrienteService $cc,
        private readonly AgingService $aging,
        private readonly ComparativoService $comp,
    ) {}

    public function mayor(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cuenta_id' => ['required', 'integer'],
            'desde'     => ['required', 'date'],
            'hasta'     => ['required', 'date', 'after_or_equal:desde'],
            'formato'   => ['nullable', 'in:json,pdf,xlsx'],
        ]);
        $this->guardFormato($datos['formato'] ?? 'json');

        $data = $this->mayor->calcular($this->empresaId($request),
            (int) $datos['cuenta_id'], $datos['desde'], $datos['hasta']);

        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function diario(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'desde'     => ['required', 'date'],
            'hasta'     => ['required', 'date', 'after_or_equal:desde'],
            'diario_id' => ['nullable', 'integer'],
            'formato'   => ['nullable', 'in:json,pdf,xlsx'],
        ]);
        $this->guardFormato($datos['formato'] ?? 'json');

        $data = $this->diario->calcular(
            $this->empresaId($request), $datos['desde'], $datos['hasta'],
            $datos['diario_id'] ?? null
        );
        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function sumasYSaldos(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'desde'   => ['required', 'date'],
            'hasta'   => ['required', 'date', 'after_or_equal:desde'],
            'formato' => ['nullable', 'in:json,pdf,xlsx'],
        ]);
        $this->guardFormato($datos['formato'] ?? 'json');

        $data = $this->sys->calcular($this->empresaId($request), $datos['desde'], $datos['hasta']);
        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function libroIvaInterno(Request $request): JsonResponse
    {
        $datos = $request->validate(['periodo_id' => ['required', 'integer']]);
        $periodo = PeriodoFiscal::where('empresa_id', $this->empresaId($request))
            ->where('impuesto', 'IVA')->findOrFail($datos['periodo_id']);

        return response()->json(['ok' => true, 'data' => $this->libroIva->detalle($periodo)]);
    }

    public function ccClientes(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cliente_id' => ['required', 'integer'],
            'fecha'      => ['nullable', 'date'],
        ]);
        $data = $this->cc->clientes($this->empresaId($request),
            (int) $datos['cliente_id'], $datos['fecha'] ?? null);
        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function ccProveedores(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'proveedor_id' => ['required', 'integer'],
            'fecha'        => ['nullable', 'date'],
        ]);
        $data = $this->cc->proveedores($this->empresaId($request),
            (int) $datos['proveedor_id'], $datos['fecha'] ?? null);
        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function aging(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tipo'  => ['required', 'in:clientes,proveedores'],
            'fecha' => ['nullable', 'date'],
        ]);
        $data = $this->aging->calcular($this->empresaId($request),
            $datos['tipo'], $datos['fecha'] ?? null);
        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function comparativo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'reporte'  => ['required', 'in:resultado,balance'],
            'periodos' => ['required', 'string'],
        ]);
        $periodos = array_values(array_filter(array_map('trim', explode(',', $datos['periodos']))));

        try {
            $data = $this->comp->calcular($this->empresaId($request), $datos['reporte'], $periodos);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $data]);
    }

    private function guardFormato(string $formato): void
    {
        if ($formato !== 'json') {
            abort(Response::HTTP_NOT_IMPLEMENTED,
                "Formato {$formato} pendiente para H8 (DomPDF / PhpSpreadsheet)");
        }
    }

    private function empresaId(Request $request): int
    {
        return (int) ($request->header('X-Empresa-Id') ?: 1);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json([
            'ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
