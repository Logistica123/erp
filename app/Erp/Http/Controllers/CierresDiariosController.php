<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Cierres\AjusteRetroactivo;
use App\Erp\Models\Cierres\DiaContable;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Cierres\CerrarDiaService;
use App\Erp\Services\Cierres\McpMpClient;
use App\Erp\Services\Tesoreria\ExtractoImporterService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cierres diarios — endpoints REST (anexo §7).
 *
 *   GET    /api/erp/cierres-diarios                           ?desde=&hasta=
 *   GET    /api/erp/cierres-diarios/{fecha}                   detalle día
 *   POST   /api/erp/cierres-diarios/{fecha}/iniciar           inicia + procesa archivos
 *   POST   /api/erp/cierres-diarios/{fecha}/sellar            sella día
 *   POST   /api/erp/cierres-diarios/{fecha}/ajuste-retroactivo asiento forward
 *   GET    /api/erp/cierres-diarios/{fecha}/exportar-liber    XLSX para contador
 *   GET    /api/erp/cierres-diarios/{fecha}/exportar-pdf      HTML print-friendly
 */
class CierresDiariosController extends Controller
{
    public function __construct(
        private readonly CerrarDiaService $svc,
        private readonly ExtractoImporterService $importer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'cierres.dia.ver');
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);

        $datos = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $q = DiaContable::with('cerrador:id,name')
            ->where('empresa_id', $empresaId)
            ->when($datos['desde'] ?? null, fn ($q, $v) => $q->where('fecha', '>=', $v))
            ->when($datos['hasta'] ?? null, fn ($q, $v) => $q->where('fecha', '<=', $v))
            ->orderByDesc('fecha');

        return response()->json(['ok' => true, 'data' => $q->get()]);
    }

    public function show(string $fecha, Request $request): JsonResponse
    {
        $this->mustHave($request, 'cierres.dia.ver');
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $f = $this->parseFecha($fecha);

        $dia = DiaContable::with(['cerrador:id,name', 'asientoCierre:id,numero,fecha'])
            ->where('empresa_id', $empresaId)
            ->where('fecha', $f->toDateString())->first();

        if (! $dia) {
            return response()->json(['ok' => false, 'error' => ['code' => 'DIA_NO_INICIADO']], 404);
        }

        $cuentasIds = CuentaBancaria::where('empresa_id', $empresaId)->pluck('id');
        $movs = MovimientoBancario::with(['cuentaBancaria:id,codigo,nombre', 'asiento:id,numero'])
            ->whereIn('cuenta_bancaria_id', $cuentasIds)
            ->whereDate('fecha', $f->toDateString())
            ->orderBy('cuenta_bancaria_id')->orderBy('id')
            ->get();

        $ajustes = AjusteRetroactivo::with(['asiento:id,numero,fecha', 'iniciador:id,name'])
            ->where('empresa_id', $empresaId)
            ->where('fecha_dia_afectado', $f->toDateString())
            ->orderByDesc('iniciado_at')->get();

        return response()->json([
            'ok' => true,
            'data' => [
                'dia'           => $dia,
                'movimientos'   => $movs,
                'ajustes_retro' => $ajustes,
            ],
        ]);
    }

    public function iniciar(string $fecha, Request $request): JsonResponse
    {
        $this->mustHave($request, 'cierres.dia.iniciar');
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $f = $this->parseFecha($fecha);

        // Acepta archivos opcionales en multipart: archivos[i][cuenta_id], archivos[i][file].
        // También acepta importar_mp (boolean) para traer MP via MCP automáticamente.
        $request->validate([
            'archivos'                => ['nullable', 'array'],
            'archivos.*.cuenta_id'    => ['required_with:archivos', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'archivos.*.file'         => ['required_with:archivos', 'file', 'max:30720'],
            'importar_mp'             => ['nullable', 'boolean'],
            'mp_cuenta_id'            => ['nullable', 'integer', 'exists:erp_cuentas_bancarias,id'],
        ]);

        $resumen = ['archivos_procesados' => 0, 'errores_archivos' => [], 'mp' => null];

        // 1) Procesar archivos si vinieron.
        foreach ($request->input('archivos', []) as $i => $meta) {
            $file = $request->file("archivos.$i.file");
            if (! $file) continue;
            try {
                $cuenta = CuentaBancaria::findOrFail((int) $meta['cuenta_id']);
                $r = $this->importer->importar($file->getRealPath(), $cuenta, $request->user(), $file->getClientOriginalName());
                $resumen['archivos_procesados']++;
                $resumen['detalle_archivos'][] = [
                    'cuenta_id' => $cuenta->id, 'cuenta_codigo' => $cuenta->codigo,
                    'archivo' => $file->getClientOriginalName(),
                    ...$r,
                ];
            } catch (DomainException $e) {
                $resumen['errores_archivos'][] = [
                    'cuenta_id' => $meta['cuenta_id'] ?? null,
                    'archivo'   => $file->getClientOriginalName(),
                    'error'     => $e->getMessage(),
                ];
            }
        }

        // 2) Trae MP via MCP si se solicitó.
        if ($request->boolean('importar_mp') && $request->filled('mp_cuenta_id')) {
            try {
                $cuentaMp = CuentaBancaria::findOrFail((int) $request->input('mp_cuenta_id'));
                $client = McpMpClient::fromConfig();
                $pathCsv = $client->obtenerMovimientos($f, $f);
                $r = $this->importer->importar($pathCsv, $cuentaMp, $request->user(), basename($pathCsv));
                $resumen['mp'] = ['ok' => true, ...$r];
                @unlink($pathCsv);
            } catch (DomainException $e) {
                $resumen['mp'] = ['ok' => false, 'error' => $e->getMessage(),
                    'fallback' => 'Subir archivo MP manual desde el panel'];
            }
        }

        // 3) Iniciar el cierre (corre detector + actualiza métricas).
        try {
            $dia = $this->svc->iniciar($f, $empresaId);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => ['dia' => $dia, 'resumen' => $resumen]]);
    }

    public function sellar(string $fecha, Request $request): JsonResponse
    {
        $this->mustHave($request, 'cierres.dia.sellar');
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $f = $this->parseFecha($fecha);

        $datos = $request->validate([
            'confirmar_pendientes' => ['nullable', 'boolean'],
        ]);
        $confirmar = (bool) ($datos['confirmar_pendientes'] ?? true);

        try {
            $dia = $this->svc->sellar($f, $empresaId, $request->user(), $confirmar);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $dia]);
    }

    public function ajusteRetroactivo(string $fecha, Request $request): JsonResponse
    {
        $this->mustHave($request, 'contabilidad.ajuste_retroactivo');
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $f = $this->parseFecha($fecha);

        $datos = $request->validate([
            'motivo'                       => ['required', 'string', 'min:5', 'max:500'],
            'asiento.cuenta_debe_id'       => ['required', 'integer', 'exists:erp_cuentas_contables,id'],
            'asiento.cuenta_haber_id'      => ['required', 'integer', 'exists:erp_cuentas_contables,id'],
            'asiento.importe'              => ['required', 'numeric', 'min:0.01'],
            'asiento.glosa'                => ['nullable', 'string', 'max:300'],
            'movimiento_origen_id'         => ['nullable', 'integer', 'exists:erp_movimientos_bancarios,id'],
        ]);

        try {
            $ajuste = $this->svc->ajusteRetroactivo(
                $f, $empresaId, $datos['motivo'],
                $datos['asiento'], $request->user(),
                $datos['movimiento_origen_id'] ?? null
            );
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $ajuste->load(['asiento:id,numero,fecha,glosa'])], 201);
    }

    public function exportarLiber(string $fecha, Request $request): BinaryFileResponse
    {
        $this->mustHave($request, 'cierres.dia.exportar');
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $f = $this->parseFecha($fecha);

        $cuentasIds = CuentaBancaria::where('empresa_id', $empresaId)->pluck('id');
        $movs = MovimientoBancario::with(['cuentaBancaria:id,codigo,nombre'])
            ->whereIn('cuenta_bancaria_id', $cuentasIds)
            ->whereDate('fecha', $f->toDateString())
            ->orderBy('cuenta_bancaria_id')->orderBy('id')
            ->get();

        $sp = new Spreadsheet();
        $s = $sp->getActiveSheet();
        $s->setTitle('Cierre '.$f->format('Y-m-d'));

        $s->setCellValue('A1', 'Cierre diario · Logística Argentina SRL · '.$f->format('d/m/Y'));
        $s->mergeCells('A1:I1');
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $headers = ['Fecha', 'Cuenta', 'Concepto', 'Comprobante', 'Débito', 'Crédito', 'Saldo', 'Estado', 'Etiqueta'];
        $s->fromArray($headers, null, 'A3');
        $s->getStyle('A3:I3')->getFont()->setBold(true);
        $s->getStyle('A3:I3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EEF5');

        $r = 4;
        foreach ($movs as $m) {
            $s->setCellValue('A'.$r, $f->format('d/m/Y'));
            $s->setCellValue('B'.$r, $m->cuentaBancaria?->codigo ?? '');
            $s->setCellValue('C'.$r, $m->concepto);
            $s->setCellValue('D'.$r, $m->comprobante_banco ?? '');
            $s->setCellValue('E'.$r, (float) $m->debito);
            $s->setCellValue('F'.$r, (float) $m->credito);
            $s->setCellValue('G'.$r, $m->saldo !== null ? (float) $m->saldo : null);
            $s->setCellValue('H'.$r, $m->estado);
            $s->setCellValue('I'.$r, $m->etiqueta_sugerida ?? '');
            $r++;
        }
        $s->getStyle('E4:G'.$r)->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'I') as $c) {
            $s->getColumnDimension($c)->setAutoSize(true);
        }

        $dir = storage_path('app/cierres/exports');
        if (! is_dir($dir)) @mkdir($dir, 0775, recursive: true);
        $filename = sprintf('cierre_%s.xlsx', $f->format('Y-m-d'));
        $path = $dir.'/'.$filename;
        (new XlsxWriter($sp))->save($path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportarPdf(string $fecha, Request $request): Response
    {
        $this->mustHave($request, 'cierres.dia.exportar');
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $f = $this->parseFecha($fecha);

        $dia = DiaContable::with('cerrador:id,name')
            ->where('empresa_id', $empresaId)->where('fecha', $f->toDateString())->first();
        if (! $dia) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'DIA_NO_INICIADO']], 404));
        }

        $cuentas = CuentaBancaria::where('empresa_id', $empresaId)->orderBy('codigo')->get();
        $movs = MovimientoBancario::with('cuentaBancaria:id,codigo,nombre')
            ->whereIn('cuenta_bancaria_id', $cuentas->pluck('id'))
            ->whereDate('fecha', $f->toDateString())
            ->orderBy('cuenta_bancaria_id')->orderBy('id')->get();

        $html = view('cierres.dia', compact('dia', 'cuentas', 'movs'))->render();
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function parseFecha(string $f): Carbon
    {
        try {
            return Carbon::parse($f)->startOfDay();
        } catch (\Throwable) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'FECHA_INVALIDA']], 422));
        }
    }

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
}
