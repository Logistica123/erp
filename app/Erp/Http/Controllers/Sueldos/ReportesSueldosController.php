<?php

namespace App\Erp\Http\Controllers\Sueldos;

use App\Erp\Models\Sueldos\ExportLiber;
use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Services\Sueldos\ExportLiberService;
use App\Erp\Services\Sueldos\ReportesSueldosService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export LIBER (XLSX FORMAL) + reportes (SPEC 08 §5.7 + §10).
 *
 *   POST /sueldos/liquidaciones/{id}/export-liber       generar XLSX
 *   GET  /sueldos/exports-liber                         listar exports
 *   GET  /sueldos/exports-liber/{id}                    detalle
 *   GET  /sueldos/exports-liber/{id}/descargar          descargar archivo
 *   POST /sueldos/exports-liber/{id}/marcar-enviado     trazabilidad
 *
 *   GET  /sueldos/reportes/liquidacion/{id}             resumen liquidación
 *   GET  /sueldos/reportes/empleado/{id}/historico      ?desde=YYYY-MM&hasta=YYYY-MM
 *   GET  /sueldos/reportes/costo-laboral?anio=2026
 *   GET  /sueldos/reportes/empleado/{id}/cc             saldos CC del empleado
 */
class ReportesSueldosController extends Controller
{
    public function __construct(
        private readonly ExportLiberService $exportSvc,
        private readonly ReportesSueldosService $reportesSvc,
    ) {}

    // ---- Export LIBER -------------------------------------------------------

    public function generarLiber(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.export.liber');
        $liq = Liquidacion::findOrFail($id);
        try {
            $exp = $this->exportSvc->generar($liq, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $exp], 201);
    }

    public function listarLiber(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.export.liber');
        $rows = ExportLiber::with('generador:id,name')
            ->when($request->query('periodo'), fn ($q, $v) => $q->where('periodo', $v))
            ->orderByDesc('fecha_export')
            ->paginate((int) $request->query('per_page', 50));
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function showLiber(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.export.liber');
        $exp = ExportLiber::with(['liquidacion:id,periodo,tipo,estado', 'generador:id,name'])->findOrFail($id);
        return response()->json(['ok' => true, 'data' => $exp]);
    }

    public function descargarLiber(int $id, Request $request): BinaryFileResponse
    {
        $this->mustHave($request, 'sueldos.export.liber');
        $exp = ExportLiber::findOrFail($id);
        $path = $this->exportSvc->pathAbsoluto($exp);
        if (! is_file($path)) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'ARCHIVO_NO_ENCONTRADO']], 404));
        }
        return response()->download($path, basename($exp->archivo_path), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function marcarEnviadoLiber(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.export.liber');
        $exp = ExportLiber::findOrFail($id);
        $exp = $this->exportSvc->marcarEnviado($exp);
        return response()->json(['ok' => true, 'data' => $exp]);
    }

    // ---- Reportes -----------------------------------------------------------

    public function liquidacionResumen(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.ver');
        $verEfectivos = $request->user()->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;
        return response()->json(['ok' => true, 'data' => $this->reportesSvc->resumenLiquidacion($id, $verEfectivos)]);
    }

    public function empleadoHistorico(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.empleados.ver');
        $datos = $request->validate([
            'desde' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'hasta' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);
        $verEfectivos = $request->user()->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;
        return response()->json(['ok' => true, 'data' => $this->reportesSvc->historicoEmpleado($id, $datos['desde'], $datos['hasta'], $verEfectivos)]);
    }

    public function costoLaboral(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.ver');
        $datos = $request->validate(['anio' => ['required', 'integer', 'min:2020', 'max:2100']]);
        $verEfectivos = $request->user()->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;
        return response()->json(['ok' => true, 'data' => $this->reportesSvc->costoLaboralAnual((int) $datos['anio'], $verEfectivos)]);
    }

    public function ccEmpleado(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.cc.ver');
        return response()->json(['ok' => true, 'data' => $this->reportesSvc->ccEmpleado($id)]);
    }

    // ---- Helpers ------------------------------------------------------------

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}"]], 403));
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }

    /** G-06 — Dashboard totales-por-mes con comparación y acumulado anual. */
    public function dashboard(Request $request): JsonResponse
    {
        $anio = (int) $request->query('anio', date('Y'));
        $verEfectivos = $request->user()?->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;

        return response()->json(['ok' => true,
            'data' => $this->reportesSvc->dashboardAnual($anio, $verEfectivos)]);
    }

    /** G-06 — Export XLSX del dashboard o del costo laboral anual. */
    public function exportXlsx(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $anio = (int) $request->query('anio', date('Y'));
        $tipo = (string) $request->query('tipo', 'dashboard');
        $verEfectivos = $request->user()?->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;

        $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja = $sp->getActiveSheet();

        if ($tipo === 'costo-laboral') {
            $data = $this->reportesSvc->costoLaboralAnual($anio, $verEfectivos);
            $hoja->setTitle('Costo laboral '.$anio);
            $hoja->fromArray(['Legajo', 'Empleado', ...array_map(fn ($m) => sprintf('%02d/%d', $m, $anio), range(1, 12)), 'TOTAL'], null, 'A1');
            $r = 2;
            foreach (($data['empleados'] ?? []) as $emp) {
                $fila = [$emp['legajo'] ?? '', $emp['nombre'] ?? ''];
                foreach (range(1, 12) as $m) {
                    $fila[] = $emp['meses'][$m]['total'] ?? 0;
                }
                $fila[] = $emp['total_anual'] ?? 0;
                $hoja->fromArray($fila, null, 'A'.$r++);
            }
            $filename = "sueldos_costo_laboral_{$anio}.xlsx";
        } else {
            $data = $this->reportesSvc->dashboardAnual($anio, $verEfectivos);
            $hoja->setTitle('Sueldos '.$anio);
            $hoja->fromArray(['Período', 'Tipo', 'Haberes', 'Descuentos', 'Neto', 'Formal', 'Efectivo', 'MT', 'Var % vs mes ant.'], null, 'A1');
            $r = 2;
            foreach ($data['meses'] as $m) {
                $hoja->fromArray([
                    $m['periodo'], $m['tipo'], $m['haberes'], $m['descuentos'], $m['neto'],
                    $m['formal'], $m['efectivo'] ?? 'oculto', $m['mt'], $m['variacion_neto_pct'],
                ], null, 'A'.$r++);
            }
            $a = $data['acumulado'];
            $hoja->fromArray(['ACUMULADO', '', $a['haberes'], $a['descuentos'], $a['neto'], $a['formal'], $a['efectivo'] ?? 'oculto', $a['mt'], ''], null, 'A'.$r);
            $filename = "sueldos_dashboard_{$anio}.xlsx";
        }

        return response()->streamDownload(function () use ($sp) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
