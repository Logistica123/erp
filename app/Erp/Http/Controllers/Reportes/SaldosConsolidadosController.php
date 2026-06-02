<?php

namespace App\Erp\Http\Controllers\Reportes;

use App\Erp\Services\Reportes\SaldosConsolidadosService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * v1.37 — Endpoints del reporte de saldos consolidados.
 *
 *   GET /api/erp/reportes/saldos-consolidados
 *   GET /api/erp/reportes/saldos-consolidados/auxiliar/{id}
 *
 * Permisos:
 *   - reportes.saldos_consolidados.ver           → ver totales y aging (sin desglose efectivo)
 *   - reportes.saldos_consolidados.ver_efectivo  → además ver desglose "de los cuales en efectivo"
 *
 * Cache: 5 minutos por combinación de filtros + empresa. Botón "actualizar"
 * del frontend agrega ?nocache=1 para forzar recálculo.
 */
class SaldosConsolidadosController extends Controller
{
    public function __construct(
        private readonly SaldosConsolidadosService $svc,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'reportes.saldos_consolidados.ver');
        $verEfectivo = $this->tienePermiso($request, 'reportes.saldos_consolidados.ver_efectivo');

        $filtros = $this->extraerFiltros($request);
        // Cache key incluye los filtros relevantes para evitar mezclas.
        $key = $this->cacheKey('saldos_cons', $filtros);

        try {
            $data = $request->boolean('nocache')
                ? $this->svc->calcular($filtros)
                : Cache::remember($key, 300, fn () => $this->svc->calcular($filtros));
        } catch (DomainException $e) {
            return response()->json(['ok' => false, 'error' => [
                'code' => explode(':', $e->getMessage(), 2)[0] ?? 'DOMAIN',
                'message' => $e->getMessage(),
            ]], 422);
        }

        // Si el usuario NO tiene permiso ver_efectivo, ocultamos los desgloses.
        if (! $verEfectivo) {
            $data = $this->ocultarEfectivo($data);
        }
        $data['permisos'] = ['ver_efectivo' => $verEfectivo];

        return response()->json(['ok' => true, 'data' => $data]);
    }

    /**
     * v1.37 Fase 2.4 — Export XLSX del reporte completo (5 hojas).
     */
    public function exportXlsx(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->mustHave($request, 'reportes.saldos_consolidados.ver');
        $this->mustHave($request, 'reportes.saldos_consolidados.export');
        $verEfectivo = $this->tienePermiso($request, 'reportes.saldos_consolidados.ver_efectivo');

        $filtros = $this->extraerFiltros($request);
        $data = $this->svc->calcular($filtros);
        if (! $verEfectivo) $data = $this->ocultarEfectivo($data);

        $filename = sprintf('saldos_consolidados_%s.xlsx', $data['fecha_corte']);
        $moneda = $data['moneda'];

        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // Hoja 1: Resumen
        $h1 = $ss->getActiveSheet();
        $h1->setTitle('Resumen');
        $h1->setCellValue('A1', 'Saldos consolidados');
        $h1->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $h1->setCellValue('A2', "Fecha de corte: {$data['fecha_corte']}  ·  Moneda: {$moneda}");
        $h1->setCellValue('A4', 'Concepto');
        $h1->setCellValue('B4', 'Total');
        if ($verEfectivo) $h1->setCellValue('C4', 'De los cuales en EFECTIVO');
        $h1->getStyle("A4:" . ($verEfectivo ? 'C4' : 'B4'))->getFont()->setBold(true);

        $w = $data['widgets'];
        $h1->setCellValue('A5', 'Deudores por ventas');
        $h1->setCellValue('B5', $w['deudores_ventas']['total']);
        if ($verEfectivo) $h1->setCellValue('C5', $w['deudores_ventas']['efectivo'] ?? 0);
        $h1->setCellValue('A6', 'Deuda con proveedores');
        $h1->setCellValue('B6', $w['deuda_compras']['total']);
        if ($verEfectivo) $h1->setCellValue('C6', $w['deuda_compras']['efectivo'] ?? 0);
        $h1->setCellValue('A7', 'Posición neta');
        $h1->setCellValue('B7', $w['posicion_neta']);
        $h1->getStyle('A7:B7')->getFont()->setBold(true);

        foreach (['B', 'C'] as $col) {
            $h1->getStyle("{$col}5:{$col}7")->getNumberFormat()->setFormatCode('#,##0.00');
        }
        $h1->getColumnDimension('A')->setAutoSize(true);
        $h1->getColumnDimension('B')->setWidth(20);
        if ($verEfectivo) $h1->getColumnDimension('C')->setWidth(22);

        // Hojas 2 y 3: Aging deudores / acreedores
        $this->volcarAgingXlsx($ss, 'Aging deudores', $data['aging_deudores'], $verEfectivo);
        $this->volcarAgingXlsx($ss, 'Aging acreedores', $data['aging_acreedores'], $verEfectivo);

        // Hojas 4 y 5: Top deudores / acreedores
        $this->volcarTopXlsx($ss, 'Top deudores', $data['top_deudores'], $verEfectivo);
        $this->volcarTopXlsx($ss, 'Top acreedores', $data['top_acreedores'], $verEfectivo);

        return response()->streamDownload(function () use ($ss) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * v1.37 Fase 2.4 — Export PDF del reporte (1 página A4 horizontal).
     */
    public function exportPdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->mustHave($request, 'reportes.saldos_consolidados.ver');
        $this->mustHave($request, 'reportes.saldos_consolidados.export');
        $verEfectivo = $this->tienePermiso($request, 'reportes.saldos_consolidados.ver_efectivo');

        $filtros = $this->extraerFiltros($request);
        $data = $this->svc->calcular($filtros);
        if (! $verEfectivo) $data = $this->ocultarEfectivo($data);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('erp.reportes.saldos_consolidados_pdf', [
            'data' => $data,
            'verEfectivo' => $verEfectivo,
        ])->setPaper('a4', 'landscape');

        return $pdf->download(sprintf('saldos_consolidados_%s.pdf', $data['fecha_corte']));
    }

    private function volcarAgingXlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $ss, string $titulo, array $buckets, bool $verEfectivo): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle(mb_substr($titulo, 0, 31)); // limite Excel
        $sheet->setCellValue('A1', $titulo);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $labels = [
            'corriente' => 'Corriente (no vencidas)',
            '1_30' => '1-30 días', '31_60' => '31-60 días',
            '61_90' => '61-90 días', 'mas_90' => 'Más de 90 días',
        ];
        $sheet->setCellValue('A3', 'Bucket');
        $sheet->setCellValue('B3', 'Total');
        if ($verEfectivo) $sheet->setCellValue('C3', 'Efectivo');
        $sheet->setCellValue($verEfectivo ? 'D3' : 'C3', '%');
        $sheet->setCellValue($verEfectivo ? 'E3' : 'D3', 'Cantidad');
        $sheet->getStyle('A3:' . ($verEfectivo ? 'E3' : 'D3'))->getFont()->setBold(true);

        $row = 4;
        foreach ($buckets as $key => $b) {
            $sheet->setCellValue("A{$row}", $labels[$key] ?? $key);
            $sheet->setCellValue("B{$row}", $b['total']);
            if ($verEfectivo) $sheet->setCellValue("C{$row}", $b['efectivo'] ?? 0);
            $sheet->setCellValue(($verEfectivo ? 'D' : 'C') . $row, ($b['pct'] ?? 0) / 100);
            $sheet->setCellValue(($verEfectivo ? 'E' : 'D') . $row, $b['cantidad'] ?? 0);
            $row++;
        }
        $sheet->getStyle("B4:" . ($verEfectivo ? 'C' : 'B') . ($row - 1))
            ->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle(($verEfectivo ? 'D' : 'C') . "4:" . ($verEfectivo ? 'D' : 'C') . ($row - 1))
            ->getNumberFormat()->setFormatCode('0.0%');
        foreach (range('A', $verEfectivo ? 'E' : 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function volcarTopXlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $ss, string $titulo, array $rows, bool $verEfectivo): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle(mb_substr($titulo, 0, 31));
        $sheet->setCellValue('A1', $titulo);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->setCellValue('A3', 'Auxiliar');
        $sheet->setCellValue('B3', 'CUIT');
        $sheet->setCellValue('C3', 'Saldo total');
        $colExt = 'D';
        if ($verEfectivo) { $sheet->setCellValue('D3', 'Efectivo'); $colExt = 'E'; }
        $sheet->setCellValue("{$colExt}3", 'Vencido');
        $colCant = chr(ord($colExt) + 1);
        $sheet->setCellValue("{$colCant}3", 'Ops');
        $sheet->getStyle("A3:{$colCant}3")->getFont()->setBold(true);

        $r = 4;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$r}", $row['nombre'] ?? '');
            $sheet->setCellValue("B{$r}", $row['cuit'] ?? '');
            $sheet->setCellValue("C{$r}", $row['saldo_total']);
            if ($verEfectivo) $sheet->setCellValue("D{$r}", $row['saldo_efectivo'] ?? 0);
            $sheet->setCellValue("{$colExt}{$r}", $row['saldo_vencido']);
            $sheet->setCellValue("{$colCant}{$r}", $row['cantidad']);
            $r++;
        }
        $sheet->getStyle("C4:{$colExt}" . ($r - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', $colCant) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    public function auxiliar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'reportes.saldos_consolidados.ver');
        $verEfectivo = $this->tienePermiso($request, 'reportes.saldos_consolidados.ver_efectivo');

        $filtros = $this->extraerFiltros($request);
        $key = $this->cacheKey("saldos_cons_aux_{$id}", $filtros);

        $data = $request->boolean('nocache')
            ? $this->svc->detalleAuxiliar($id, $filtros)
            : Cache::remember($key, 300, fn () => $this->svc->detalleAuxiliar($id, $filtros));

        if (! $verEfectivo && isset($data['operaciones'])) {
            // Filtra las operaciones EFECTIVO y elimina del total.
            $data['operaciones'] = array_values(array_filter(
                $data['operaciones'],
                fn ($op) => ($op->categoria ?? '') !== 'EFECTIVO'
            ));
            if (isset($data['totales'])) {
                $data['totales']['efectivo'] = 0.0;
                $data['totales']['total'] = array_sum(array_map(
                    fn ($op) => (float) ($op->saldo ?? 0),
                    $data['operaciones']
                ));
            }
        }

        $data['permisos'] = ['ver_efectivo' => $verEfectivo];

        return response()->json(['ok' => true, 'data' => $data]);
    }

    // ------------------------------------------------------------------------

    private function extraerFiltros(Request $request): array
    {
        return array_filter([
            'empresa_id'       => (int) ($request->header('X-Empresa-Id') ?: 1),
            'fecha_corte'      => $request->query('fecha_corte'),
            'moneda_codigo'    => $request->query('moneda_codigo', 'ARS'),
            'incluir_efectivo' => $request->boolean('incluir_efectivo', true),
            'top_n'            => $request->query('top_n'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function cacheKey(string $prefix, array $filtros): string
    {
        ksort($filtros);
        return "v1.37.{$prefix}." . md5(json_encode($filtros));
    }

    /**
     * Elimina los campos de desglose efectivo cuando el usuario no tiene
     * permiso. Conserva el total (todos pueden ver el total general).
     */
    private function ocultarEfectivo(array $data): array
    {
        foreach (['deudores_ventas', 'deuda_compras'] as $k) {
            if (isset($data['widgets'][$k])) {
                unset($data['widgets'][$k]['efectivo'], $data['widgets'][$k]['pct_efectivo']);
            }
        }
        foreach (['aging_deudores', 'aging_acreedores'] as $k) {
            if (! isset($data[$k])) continue;
            foreach ($data[$k] as $bucket => &$b) {
                unset($b['efectivo']);
            }
            unset($b);
        }
        foreach (['top_deudores', 'top_acreedores'] as $k) {
            if (! isset($data[$k])) continue;
            foreach ($data[$k] as &$row) {
                unset($row['saldo_efectivo']);
            }
            unset($row);
        }
        return $data;
    }

    private function mustHave(Request $request, string $codigo): void
    {
        if (! $this->tienePermiso($request, $codigo)) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => "Falta permiso {$codigo}",
            ]], 403));
        }
    }

    private function tienePermiso(Request $request, string $codigo): bool
    {
        $perfil = $request->user()?->erpPerfil;
        return $perfil && $perfil->tienePermiso($codigo);
    }
}
