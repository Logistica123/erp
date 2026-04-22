<?php

namespace App\Erp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Libro Diario (SPEC_01 §5.2).
 *   GET /api/erp/libro-diario?periodo_id=|desde=&hasta=&diario=&estado=&formato=json|csv|html
 *
 * Devuelve todas las líneas de asientos del rango en orden fecha→diario→numero→linea.
 * Los formatos no-json se envían como stream para ahorrar memoria en períodos con
 * alto volumen de movimientos.
 */
class LibroDiarioController
{
    public function index(Request $request): JsonResponse|StreamedResponse|Response
    {
        $data = $request->validate([
            'periodo_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'diario' => ['nullable', 'string'],
            'estado' => ['nullable', 'string'],
            'formato' => ['nullable', 'in:json,csv,html'],
            'empresa_id' => ['nullable', 'integer'],
        ]);

        $empresaId = $data['empresa_id']
            ?? $request->user()->erpPerfil?->empresa_id
            ?? 1;

        $query = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->join('erp_diarios as d', 'd.id', '=', 'a.diario_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->leftJoin('erp_auxiliares as ax', 'ax.id', '=', 'm.auxiliar_id')
            ->leftJoin('erp_centros_costo as cc', 'cc.id', '=', 'm.centro_costo_id')
            ->where('a.empresa_id', $empresaId)
            ->when($data['periodo_id'] ?? null, fn ($q, $v) => $q->where('a.periodo_id', $v))
            ->when($data['desde'] ?? null, fn ($q, $v) => $q->where('a.fecha', '>=', $v))
            ->when($data['hasta'] ?? null, fn ($q, $v) => $q->where('a.fecha', '<=', $v))
            ->when($data['diario'] ?? null, fn ($q, $v) => $q->where('d.codigo', $v))
            ->when($data['estado'] ?? null, fn ($q, $v) => $q->where('a.estado', $v), fn ($q) => $q->whereIn('a.estado', ['CONTABILIZADO', 'ANULADO']))
            ->orderBy('a.fecha')->orderBy('d.codigo')->orderBy('a.numero')->orderBy('m.linea')
            ->select([
                'a.fecha', 'd.codigo as diario', 'a.numero', 'a.glosa as asiento_glosa', 'a.estado',
                'm.linea', 'c.codigo as cuenta_codigo', 'c.nombre as cuenta_nombre',
                'm.glosa as linea_glosa', 'm.debe', 'm.haber',
                'ax.nombre as auxiliar', 'cc.codigo as centro_costo',
                'a.id as asiento_id',
            ]);

        $formato = $data['formato'] ?? 'json';

        return match ($formato) {
            'csv' => $this->streamCsv($query),
            'html' => $this->renderHtml($query, $data),
            default => $this->jsonResponse($query),
        };
    }

    private function jsonResponse($query): JsonResponse
    {
        $rows = $query->get();
        $totalDebe = $rows->sum('debe');
        $totalHaber = $rows->sum('haber');

        return response()->json([
            'ok' => true,
            'data' => [
                'movimientos' => $rows,
                'totales' => [
                    'debe' => round((float) $totalDebe, 2),
                    'haber' => round((float) $totalHaber, 2),
                    'lineas' => $rows->count(),
                ],
            ],
        ]);
    }

    private function streamCsv($query): StreamedResponse
    {
        $filename = 'libro_diario_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 para Excel ES
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Fecha', 'Diario', 'N°', 'Línea', 'Cuenta', 'Nombre cuenta',
                'Glosa asiento', 'Glosa línea', 'Auxiliar', 'CC',
                'Debe', 'Haber', 'Estado', 'Asiento ID',
            ], ';');

            foreach ($query->cursor() as $r) {
                fputcsv($out, [
                    $r->fecha, $r->diario, $r->numero, $r->linea,
                    $r->cuenta_codigo, $r->cuenta_nombre,
                    $r->asiento_glosa, $r->linea_glosa,
                    $r->auxiliar, $r->centro_costo,
                    number_format((float) $r->debe, 2, ',', '.'),
                    number_format((float) $r->haber, 2, ',', '.'),
                    $r->estado, $r->asiento_id,
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function renderHtml($query, array $filtros): Response
    {
        $rows = $query->get();
        $totalDebe = $rows->sum('debe');
        $totalHaber = $rows->sum('haber');

        $html = '<!doctype html><html lang="es-AR"><head><meta charset="utf-8">';
        $html .= '<title>Libro Diario</title>';
        $html .= '<style>
            body{font-family:Inter,Arial,sans-serif;font-size:11px;color:#1f3a5f;margin:20px}
            h1{font-size:16px;margin:0 0 4px 0}
            .meta{color:#555;margin-bottom:12px}
            table{width:100%;border-collapse:collapse}
            th,td{border:1px solid #ddd;padding:4px 6px;vertical-align:top}
            th{background:#f0f4fa;text-align:left}
            td.num{text-align:right;font-variant-numeric:tabular-nums}
            tr.anulado td{color:#aaa;text-decoration:line-through}
            tfoot td{font-weight:bold;background:#f8f8f8}
            @media print{.meta{font-size:10px}}
        </style></head><body>';
        $html .= '<h1>Libro Diario</h1>';
        $html .= '<div class="meta">';
        $html .= 'Desde: '.htmlspecialchars($filtros['desde'] ?? '-').' · Hasta: '.htmlspecialchars($filtros['hasta'] ?? '-').'<br>';
        $html .= 'Generado: '.now()->format('d/m/Y H:i').'</div>';
        $html .= '<table><thead><tr>';
        $html .= '<th>Fecha</th><th>Diario</th><th>Nº</th><th>Cuenta</th><th>Glosa</th><th>Aux.</th><th>CC</th><th>Debe</th><th>Haber</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $anulado = $r->estado === 'ANULADO' ? ' class="anulado"' : '';
            $html .= "<tr{$anulado}>";
            $html .= '<td>'.htmlspecialchars((string) $r->fecha).'</td>';
            $html .= '<td>'.htmlspecialchars((string) $r->diario).'</td>';
            $html .= '<td class="num">'.(int) $r->numero.'</td>';
            $html .= '<td>'.htmlspecialchars($r->cuenta_codigo.' '.$r->cuenta_nombre).'</td>';
            $html .= '<td>'.htmlspecialchars((string) ($r->linea_glosa ?? $r->asiento_glosa)).'</td>';
            $html .= '<td>'.htmlspecialchars((string) $r->auxiliar).'</td>';
            $html .= '<td>'.htmlspecialchars((string) $r->centro_costo).'</td>';
            $html .= '<td class="num">'.number_format((float) $r->debe, 2, ',', '.').'</td>';
            $html .= '<td class="num">'.number_format((float) $r->haber, 2, ',', '.').'</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody><tfoot><tr>';
        $html .= '<td colspan="7">Totales ('.count($rows).' líneas)</td>';
        $html .= '<td class="num">'.number_format((float) $totalDebe, 2, ',', '.').'</td>';
        $html .= '<td class="num">'.number_format((float) $totalHaber, 2, ',', '.').'</td>';
        $html .= '</tr></tfoot></table></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
