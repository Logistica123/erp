<?php

namespace App\Erp\Services\Sueldos;

use App\Erp\Models\Sueldos\ExportLiber;
use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Models\Sueldos\LiquidacionItem;
use DomainException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Export FORMAL para LIBER (SPEC 08 §5.7).
 *
 * Genera XLSX con SOLO items componente=FORMAL de una liquidación APROBADA
 * o PAGADA. El archivo nunca incluye datos del componente EFECTIVO ni MT —
 * es lo que el contador externo (revisor_fiscal) usa para armar el F.931.
 *
 * - Path: storage/app/sueldos/liber/F931_{periodo}_{liq_id}_{ts}.xlsx
 * - Hash SHA256 del archivo persistido para trazabilidad (RN auditoría).
 * - Si ya existe un export para esa liquidación, rechaza con EXPORT_DUPLICADO
 *   (el ER tiene UK por liquidacion_id). Para regenerar, hay que borrar antes.
 */
class ExportLiberService
{
    public function generar(Liquidacion $liq, ?int $userId = null): ExportLiber
    {
        if (! in_array($liq->estado, [Liquidacion::ESTADO_APROBADA, Liquidacion::ESTADO_PAGADA], true)) {
            throw new DomainException('ESTADO_INVALIDO: solo se exporta liquidación APROBADA o PAGADA (actual: '.$liq->estado.').');
        }
        if (ExportLiber::where('liquidacion_id', $liq->id)->exists()) {
            throw new DomainException('EXPORT_DUPLICADO: ya existe un export para la liquidación #'.$liq->id.'. Borrar antes de regenerar.');
        }

        $rows = DB::table('erp_emp_liquidaciones_items as li')
            ->join('erp_emp_empleados as e', 'e.id', '=', 'li.empleado_id')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'li.concepto_id')
            ->where('li.liquidacion_id', $liq->id)
            ->where('li.componente', LiquidacionItem::COMPONENTE_FORMAL)
            ->orderBy('e.legajo')->orderBy('c.orden')
            ->select(
                'e.legajo', 'e.apellido', 'e.nombre', 'e.cuil', 'e.dni', 'e.fecha_ingreso',
                'c.codigo as concepto_codigo', 'c.nombre as concepto_nombre',
                'c.tipo as concepto_tipo', 'c.signo',
                'li.cantidad', 'li.importe_unitario', 'li.importe', 'li.base_calculo',
                'li.observaciones',
            )
            ->get();

        if ($rows->isEmpty()) {
            throw new DomainException('SIN_ITEMS_FORMAL: la liquidación no tiene items componente=FORMAL para exportar.');
        }

        $sp = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle('LIBER '.$liq->periodo);

        // Cabecera del archivo (filas 1-3).
        $sheet->setCellValue('A1', 'Export FORMAL para LIBER — Logística Argentina SRL');
        $sheet->mergeCells('A1:O1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue('A2', 'Período: '.$liq->periodo.'   Tipo: '.$liq->tipo.'   Liquidación: #'.$liq->id.'   Estado: '.$liq->estado);
        $sheet->mergeCells('A2:O2');
        $sheet->setCellValue('A3', 'Generado: '.now()->format('d/m/Y H:i:s').'   Total filas: '.$rows->count());
        $sheet->mergeCells('A3:O3');

        // Headers tabla (fila 5).
        $headers = [
            'Legajo', 'Apellido', 'Nombre', 'CUIL', 'DNI', 'Fecha Ingreso',
            'Concepto Cód.', 'Concepto', 'Tipo', 'Signo',
            'Cantidad', 'Unitario', 'Base', 'Importe', 'Observaciones',
        ];
        $sheet->fromArray($headers, null, 'A5');
        $sheet->getStyle('A5:O5')->getFont()->setBold(true);
        $sheet->getStyle('A5:O5')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E8EEF5');

        // Filas (desde fila 6).
        $r = 6;
        $totalImporte = 0.0;
        foreach ($rows as $row) {
            $signoMul = $row->signo === 'HABER' ? 1 : -1;
            $impFirmado = (float) $row->importe * $signoMul;
            $totalImporte += $impFirmado;

            $sheet->setCellValue('A'.$r, $row->legajo);
            $sheet->setCellValue('B'.$r, $row->apellido);
            $sheet->setCellValue('C'.$r, $row->nombre);
            $sheet->setCellValue('D'.$r, $row->cuil ?? '');
            $sheet->setCellValue('E'.$r, $row->dni ?? '');
            $sheet->setCellValue('F'.$r, $row->fecha_ingreso);
            $sheet->setCellValue('G'.$r, $row->concepto_codigo);
            $sheet->setCellValue('H'.$r, $row->concepto_nombre);
            $sheet->setCellValue('I'.$r, $row->concepto_tipo);
            $sheet->setCellValue('J'.$r, $row->signo);
            $sheet->setCellValue('K'.$r, (float) $row->cantidad);
            $sheet->setCellValue('L'.$r, $row->importe_unitario !== null ? (float) $row->importe_unitario : null);
            $sheet->setCellValue('M'.$r, $row->base_calculo !== null ? (float) $row->base_calculo : null);
            $sheet->setCellValue('N'.$r, (float) $row->importe);
            $sheet->setCellValue('O'.$r, $row->observaciones ?? '');
            $r++;
        }

        // Total al final.
        $sheet->setCellValue('M'.$r, 'NETO FORMAL');
        $sheet->setCellValue('N'.$r, $totalImporte);
        $sheet->getStyle('M'.$r.':N'.$r)->getFont()->setBold(true);
        $sheet->getStyle('N6:N'.$r)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('K6:M'.$r)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A5:O'.$r)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Persistir. Usamos storage_path() directo (no dependemos del disk config).
        $dir = 'sueldos/liber';
        $absDir = storage_path('app/'.$dir);
        if (! is_dir($absDir)) {
            @mkdir($absDir, 0775, recursive: true);
        }
        $filename = sprintf('F931_%s_liq%d_%s.xlsx', $liq->periodo, $liq->id, now()->format('YmdHis'));
        $relPath = $dir.'/'.$filename;
        $absPath = $absDir.'/'.$filename;

        $writer = new XlsxWriter($sp);
        $writer->save($absPath);

        $hash = hash_file('sha256', $absPath);

        $empleadosCount = DB::table('erp_emp_liquidaciones_items')
            ->where('liquidacion_id', $liq->id)
            ->where('componente', LiquidacionItem::COMPONENTE_FORMAL)
            ->distinct('empleado_id')->count('empleado_id');

        \App\Erp\Support\AuditoriaSueldos::log('EXPORT_LIBER_GENERADO', sprintf(
            'Export LIBER de liquidación #%d %s generado (hash %s…).',
            $liq->id, $liq->periodo, substr($hash, 0, 12)));

        return ExportLiber::create([
            'liquidacion_id'  => $liq->id,
            'periodo'         => $liq->periodo,
            'fecha_export'    => now(),
            'generado_por_id' => $userId,
            'total_exportado' => round($totalImporte, 2),
            'empleados_count' => $empleadosCount,
            'archivo_path'    => $relPath,
            'hash_sha256'     => $hash,
            'enviado_a_liber' => false,
        ]);
    }

    public function pathAbsoluto(ExportLiber $exp): string
    {
        return storage_path('app/'.$exp->archivo_path);
    }

    public function marcarEnviado(ExportLiber $exp): ExportLiber
    {
        $exp->update(['enviado_a_liber' => true, 'fecha_envio' => now()]);
        return $exp->fresh();
    }
}
