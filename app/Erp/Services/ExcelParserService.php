<?php

namespace App\Erp\Services;

use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ADDENDUM v1.10 — Parser de archivos Excel/CSV con tolerancia a filas
 * vacías intermedias (RN-RP-1).
 *
 * Patrón estándar del proyecto: TODOS los importadores deben iterar
 * vía iterarFilasNoVacias()/iterarFilasNoVaciasCsv() en lugar de loops ad-hoc.
 * Eso garantiza que filas vacías intermedias (separadores visuales del usuario,
 * exportes de Excel con espaciado, etc.) no detengan el parser silenciosamente.
 *
 * Uso:
 *   foreach ($svc->iterarFilasNoVacias($path, filaInicio: 2) as $fila) {
 *       // $fila['row_number'] tiene el numero original (1-based, util para
 *       //   reportes de error: "fila 287: fecha invalida").
 *       // $fila['values'] tiene un array indexado de los valores no vacios.
 *   }
 *
 * Reporte de import (RN-RP-2): el caller debe acumular cuántas filas se
 * yieldaron y cuántas se skipearon vacías (delta = maxRow − filaInicio + 1
 * − filas_yieldeadas) para que la pantalla del usuario muestre el resumen.
 */
class ExcelParserService
{
    /**
     * Itera filas de un Excel saltando las completamente vacías.
     *
     * @return Generator<int, array{row_number:int, values:array<int, mixed>}>
     */
    public function iterarFilasNoVacias(string $path, int $filaInicio = 1): Generator
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $maxRow = $sheet->getHighestRow();
        $maxCol = $sheet->getHighestColumn();

        for ($row = $filaInicio; $row <= $maxRow; $row++) {
            $values = $this->extraerFila($sheet, $row, $maxCol);
            if ($this->filaTieneDatos($values)) {
                yield ['row_number' => $row, 'values' => $values];
            }
            // Si está vacía no yield: el caller no la ve, sigue iterando.
        }
    }

    /**
     * Equivalente para CSV. Lee linea por linea, parsea con fgetcsv,
     * y skipea lineas donde todos los campos son vacios o whitespace.
     *
     * @return Generator<int, array{row_number:int, values:array<int, mixed>}>
     */
    public function iterarFilasNoVaciasCsv(
        string $path,
        string $delimiter = ',',
        ?string $encoding = null,
        int $filaInicio = 1,
    ): Generator {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("CSV_NO_LEIBLE: {$path}");
        }

        try {
            $rowNum = 0;
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNum++;
                if ($rowNum < $filaInicio) continue;

                if ($encoding && $encoding !== 'UTF-8') {
                    $values = array_map(
                        fn ($v) => $v === null ? null : @mb_convert_encoding((string) $v, 'UTF-8', $encoding),
                        $values,
                    );
                }

                if ($this->filaTieneDatos($values)) {
                    yield ['row_number' => $rowNum, 'values' => $values];
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Helper: cuenta filas en un Excel (incluyendo vacias) — útil para el
     * resumen de import "X filas en el archivo / Y procesadas / Z skipped".
     */
    public function contarFilasArchivo(string $path): int
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        return $spreadsheet->getActiveSheet()->getHighestRow();
    }

    private function extraerFila(Worksheet $sheet, int $row, string $maxCol): array
    {
        $values = [];
        $colIdx = 1;
        $maxColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($maxCol);
        for ($colIdx = 1; $colIdx <= $maxColIdx; $colIdx++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $values[] = $sheet->getCell($colLetter.$row)->getValue();
        }
        return $values;
    }

    private function filaTieneDatos(array $values): bool
    {
        foreach ($values as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return true;
            }
        }
        return false;
    }
}
