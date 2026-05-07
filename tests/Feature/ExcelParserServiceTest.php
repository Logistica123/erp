<?php

namespace Tests\Feature;

use App\Erp\Services\ExcelParserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * ADDENDUM v1.10 — Tests RP-01 a RP-04 sobre ExcelParserService.
 *
 * Fixtures generados en runtime (XLSX y CSV) con filas vacías intermedias y
 * al final, para verificar que iterarFilasNoVacias() salta vacías sin
 * detenerse ni perder datos.
 */
class ExcelParserServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ExcelParserService $svc;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ExcelParserService::class);
        $this->tmpDir = sys_get_temp_dir();
    }

    private function generarXlsx(array $filas, string $nombre = null): string
    {
        $path = $this->tmpDir.'/'.($nombre ?? 'test_'.uniqid().'.xlsx');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($filas as $i => $valores) {
            foreach ($valores as $j => $v) {
                $coord = Coordinate::stringFromColumnIndex($j + 1).($i + 1);
                $sheet->setCellValue($coord, $v);
            }
        }
        (new XlsxWriter($spreadsheet))->save($path);
        return $path;
    }

    public function test_RP_01_xlsx_con_fila_vacia_intermedia(): void
    {
        $path = $this->generarXlsx([
            ['Header A', 'Header B'],   // fila 1 (header)
            ['v1', 100],                // fila 2
            ['v2', 200],                // fila 3
            ['', ''],                   // fila 4 vacía intermedia
            ['v3', 300],                // fila 5
            ['v4', 400],                // fila 6
        ]);

        $filas = iterator_to_array($this->svc->iterarFilasNoVacias($path, 2));
        $this->assertCount(4, $filas);
        $this->assertSame(2, $filas[0]['row_number']);
        $this->assertSame(5, $filas[2]['row_number']); // fila 5 después de saltar la 4
        $this->assertSame('v3', $filas[2]['values'][0]);
        @unlink($path);
    }

    public function test_RP_02_xlsx_con_filas_vacias_al_final(): void
    {
        $path = $this->generarXlsx([
            ['Header'],
            ['v1'],
            ['v2'],
            [''],
            [''],
        ]);

        $filas = iterator_to_array($this->svc->iterarFilasNoVacias($path, 2));
        $this->assertCount(2, $filas);
        @unlink($path);
    }

    public function test_RP_03_xlsx_con_fila_malformada_sigue_iterando(): void
    {
        // El service mismo no valida tipos de campo — solo skipea vacías.
        // La validación específica (fecha mal formada, etc) la hace el caller.
        // Lo que aseguramos acá: una fila con datos "raros" sigue yieldeándose
        // y el caller decide si la procesa o la marca error (RN-RP-3).
        $path = $this->generarXlsx([
            ['Header A', 'Header B'],
            ['v1', 100],
            ['fila-mala', 'no-es-numero'],  // datos malformados pero no vacíos
            ['v3', 300],
        ]);

        $filas = iterator_to_array($this->svc->iterarFilasNoVacias($path, 2));
        $this->assertCount(3, $filas);
        $this->assertSame('fila-mala', $filas[1]['values'][0]);
        @unlink($path);
    }

    public function test_RP_04_csv_con_linea_vacia_final(): void
    {
        $path = $this->tmpDir.'/test_'.uniqid().'.csv';
        file_put_contents($path, "header_a,header_b\nv1,100\nv2,200\n\nv3,300\n\n");

        $filas = iterator_to_array($this->svc->iterarFilasNoVaciasCsv($path, ',', null, 2));
        $this->assertCount(3, $filas);
        $this->assertSame('v1', $filas[0]['values'][0]);
        $this->assertSame('v3', $filas[2]['values'][0]);
        @unlink($path);
    }

    public function test_contarFilasArchivo_devuelve_max_row(): void
    {
        $path = $this->generarXlsx([
            ['h'], ['a'], [''], ['b'],
        ]);
        $this->assertSame(4, $this->svc->contarFilasArchivo($path));
        @unlink($path);
    }
}
