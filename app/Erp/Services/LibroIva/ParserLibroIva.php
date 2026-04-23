<?php

namespace App\Erp\Services\LibroIva;

use DomainException;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;

/**
 * Parser flexible del Excel del Libro IVA Digital ARCA.
 *
 * ARCA exporta como XLSX con encabezados en las primeras 1-3 filas. Mapeo
 * de columnas por nombre normalizado (lowercase + sin acentos + sin dobles
 * espacios) para tolerar variaciones entre exports.
 *
 * Soporta Libro IVA COMPRAS y VENTAS (mismas columnas, signo contable en
 * el código de comprobante: NC reduce, factura suma).
 */
class ParserLibroIva
{
    /** Mapeo de nombres de header (normalizados) → campo lógico. */
    private const HEADER_MAP = [
        // Fecha
        'fecha' => 'fecha',
        'fecha emision' => 'fecha',
        'fecha de emision' => 'fecha',
        'fecha cbte' => 'fecha',
        // Tipo comprobante (código AFIP)
        'tipo' => 'tipo_cbte',
        'tipo comprobante' => 'tipo_cbte',
        'codigo tipo cbte' => 'tipo_cbte',
        // Pto venta
        'pto vta' => 'pto_vta',
        'punto venta' => 'pto_vta',
        'punto de venta' => 'pto_vta',
        // Nro cbte
        'numero' => 'nro_cbte',
        'numero desde' => 'nro_cbte',
        'nro desde' => 'nro_cbte',
        'nro cbte' => 'nro_cbte',
        'numero cbte' => 'nro_cbte',
        // CUIT y razón
        'cuit' => 'cuit_contraparte',
        'nro doc' => 'cuit_contraparte',
        'cuit vendedor' => 'cuit_contraparte',
        'cuit comprador' => 'cuit_contraparte',
        'cuit contraparte' => 'cuit_contraparte',
        'denominacion' => 'razon_social',
        'razon social' => 'razon_social',
        // Importes
        'imp neto gravado' => 'imp_neto_gravado',
        'importe neto gravado' => 'imp_neto_gravado',
        'neto gravado' => 'imp_neto_gravado',
        'imp no gravado' => 'imp_no_gravado',
        'importe no gravado' => 'imp_no_gravado',
        'no gravado' => 'imp_no_gravado',
        'imp op exentas' => 'imp_exento',
        'operaciones exentas' => 'imp_exento',
        'exento' => 'imp_exento',
        'iva' => 'imp_iva',
        'imp iva' => 'imp_iva',
        'importe iva' => 'imp_iva',
        'imp perc iva' => 'imp_percepciones',
        'percepciones iva' => 'imp_percepciones',
        'otros tributos' => 'imp_percepciones',
        'imp total' => 'imp_total',
        'importe total' => 'imp_total',
        'total' => 'imp_total',
        // CAE
        'cae' => 'cae',
        'codigo autorizacion' => 'cae',
        'cod aut' => 'cae',
        'fecha vto cae' => 'fecha_vto_cae',
        'fecha vto' => 'fecha_vto_cae',
    ];

    /**
     * @return array<int, FilaLibroIva>
     */
    public function parse(string $path): array
    {
        if (! is_readable($path)) {
            throw new DomainException("FORMATO_INVALIDO: no se pudo abrir {$path}");
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getActiveSheet();
        } catch (\Throwable $e) {
            throw new DomainException('FORMATO_INVALIDO: no es un XLSX/CSV válido — '.$e->getMessage());
        }

        $rows = $sheet->toArray(null, true, false, false);
        if (empty($rows)) {
            throw new DomainException('FORMATO_INVALIDO: archivo vacío');
        }

        [$headerRowIdx, $headerMap] = $this->detectarHeader($rows);
        if ($headerRowIdx === null) {
            throw new DomainException('FORMATO_INVALIDO: no se detectaron columnas conocidas (fecha/tipo/importe)');
        }

        $filas = [];
        $total = count($rows);
        for ($i = $headerRowIdx + 1; $i < $total; $i++) {
            $raw = $rows[$i];
            $fila = $this->parsearFila($raw, $headerMap);
            if ($fila !== null) {
                $filas[] = $fila;
            }
        }

        if (empty($filas)) {
            throw new DomainException('FORMATO_INVALIDO: no se encontraron filas de comprobantes');
        }

        return $filas;
    }

    /**
     * Busca en las primeras 5 filas la que tiene más headers conocidos.
     *
     * @return array{0:?int, 1:array<string,int>}  [rowIdx, map campo→columna]
     */
    private function detectarHeader(array $rows): array
    {
        $mejor = [null, []];
        $maxMatch = 0;

        $limit = min(5, count($rows));
        for ($i = 0; $i < $limit; $i++) {
            $map = [];
            foreach ($rows[$i] as $colIdx => $cell) {
                $norm = $this->normalizarHeader((string) $cell);
                if ($norm === '') {
                    continue;
                }
                $campo = self::HEADER_MAP[$norm] ?? null;
                if ($campo && ! isset($map[$campo])) {
                    $map[$campo] = $colIdx;
                }
            }
            if (count($map) > $maxMatch) {
                $maxMatch = count($map);
                $mejor = [$i, $map];
            }
        }

        // Exigimos al menos fecha + tipo_cbte + imp_total para considerar válido.
        [$idx, $map] = $mejor;
        if (! isset($map['fecha'], $map['tipo_cbte'], $map['imp_total'])) {
            return [null, []];
        }

        return [$idx, $map];
    }

    private function parsearFila(array $raw, array $headerMap): ?FilaLibroIva
    {
        $get = fn (string $campo, $default = null) => isset($headerMap[$campo]) ? ($raw[$headerMap[$campo]] ?? $default) : $default;

        $fechaCell = $get('fecha');
        if ($fechaCell === null || $fechaCell === '') {
            return null;
        }
        try {
            $fecha = $this->parsearFecha($fechaCell);
        } catch (\Throwable) {
            return null;
        }

        $tipoCbte = (int) $this->parsearNum($get('tipo_cbte', 0));
        if ($tipoCbte === 0) {
            return null;
        }

        $cuit = preg_replace('/[^0-9]/', '', (string) $get('cuit_contraparte', ''));

        $fechaVto = null;
        if ($vto = $get('fecha_vto_cae')) {
            try {
                $fechaVto = $this->parsearFecha($vto);
            } catch (\Throwable) {
                $fechaVto = null;
            }
        }

        return new FilaLibroIva(
            fecha: $fecha,
            tipoCbte: $tipoCbte,
            ptoVta: (int) $this->parsearNum($get('pto_vta', 0)),
            nroCbte: (int) $this->parsearNum($get('nro_cbte', 0)),
            cuitContraparte: $cuit,
            razonSocial: $get('razon_social') ? trim((string) $get('razon_social')) : null,
            impNetoGravado: $this->parsearNum($get('imp_neto_gravado', 0)),
            impNoGravado: $this->parsearNum($get('imp_no_gravado', 0)),
            impExento: $this->parsearNum($get('imp_exento', 0)),
            impIva: $this->parsearNum($get('imp_iva', 0)),
            impPercepciones: $this->parsearNum($get('imp_percepciones', 0)),
            impTotal: $this->parsearNum($get('imp_total', 0)),
            cae: $get('cae') ? trim((string) $get('cae')) : null,
            fechaVtoCae: $fechaVto,
            rawRow: $raw,
        );
    }

    private function parsearFecha(mixed $v): Carbon
    {
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance(
                $v instanceof \DateTime ? $v : \DateTime::createFromImmutable($v)
            );
        }
        if (is_numeric($v)) {
            // Serial fecha de Excel (días desde 1900-01-01)
            $unix = ((int) $v - 25569) * 86400;

            return Carbon::createFromTimestampUTC($unix)->startOfDay();
        }

        $s = trim((string) $v);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Ymd'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $s)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }
        throw new DomainException("FECHA_INVALIDA: '{$s}'");
    }

    private function parsearNum(mixed $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        // es-AR: "1.234.567,89" → "1234567.89"
        $s = str_replace(['.', ','], ['', '.'], $s);

        return is_numeric($s) ? (float) $s : 0.0;
    }

    private function normalizarHeader(string $s): string
    {
        $s = trim(mb_strtolower($s));
        $s = preg_replace('/\s+/u', ' ', $s);
        // Quita acentos básicos
        $s = strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n',
        ]);
        // Quita caracteres no alfanuméricos excepto espacio
        $s = preg_replace('/[^a-z0-9 ]/', '', $s);

        return (string) $s;
    }
}
