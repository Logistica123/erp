<?php

namespace App\Erp\Services\ControlFacturas;

use Illuminate\Support\Facades\Log;

/**
 * v1.44 — Extrae los datos críticos de una factura electrónica argentina
 * desde un PDF. Estrategia:
 *
 *  1. QR (RG 4290/2018) leído con zbarimg. Si lo lee, parsea el JSON base64
 *     de AFIP — la fuente más confiable.
 *  2. Fallback OCR: pdftotext del PDF (si tiene texto seleccionable) o
 *     tesseract sobre imagen renderizada con pdftoppm. Después regex sobre
 *     el texto para encontrar CUIT, PV-Nro, CAE, fecha, importe.
 *
 * Devuelve siempre el shape consistente:
 * {
 *   metodo: 'QR'|'OCR'|'MIXTO'|'FALLO',
 *   qr_detectado: bool,
 *   ocr_aplicado: bool,
 *   campos: { cuit_emisor, cuit_receptor, tipo_comprobante, punto_venta,
 *             numero, fecha_emision, importe_total, cae, moneda, tipo_doc_receptor },
 *   campos_faltantes: [...],
 *   raw_qr: ?string, raw_texto: ?string,
 * }
 */
class PdfFacturaExtractorService
{
    /** Comandos del sistema; configurables vía env si hace falta. */
    private const BIN_ZBARIMG = 'zbarimg';
    private const BIN_PDFTOTEXT = 'pdftotext';
    private const BIN_PDFTOPPM = 'pdftoppm';
    private const BIN_TESSERACT = 'tesseract';

    /** @return array<string,mixed> */
    public function extraer(string $pdfPath): array
    {
        $resultado = [
            'metodo' => 'FALLO',
            'qr_detectado' => false,
            'ocr_aplicado' => false,
            'campos' => $this->camposVacios(),
            'campos_faltantes' => array_keys($this->camposVacios()),
            'raw_qr' => null,
            'raw_texto' => null,
        ];

        // 1. Intentar QR.
        $qrJson = $this->intentarQR($pdfPath);
        if ($qrJson) {
            $resultado['qr_detectado'] = true;
            $resultado['raw_qr'] = $qrJson;
            $campos = $this->parsearQRRG4290($qrJson);
            if ($campos) {
                $resultado['campos'] = array_merge($resultado['campos'], $campos);
                $resultado['campos_faltantes'] = $this->faltantes($resultado['campos']);
                $resultado['metodo'] = 'QR';
                if (empty($resultado['campos_faltantes'])) return $resultado;
            }
        }

        // 2. Fallback OCR.
        $texto = $this->extraerTexto($pdfPath);
        if ($texto) {
            $resultado['ocr_aplicado'] = true;
            $resultado['raw_texto'] = $texto;
            $camposOcr = $this->parsearTextoPorPatrones($texto);
            // Si vino del QR, mezclamos (QR pisa OCR para los campos que tiene).
            foreach ($camposOcr as $k => $v) {
                if (empty($resultado['campos'][$k]) && ! empty($v)) {
                    $resultado['campos'][$k] = $v;
                }
            }
            $resultado['campos_faltantes'] = $this->faltantes($resultado['campos']);
            $resultado['metodo'] = $resultado['qr_detectado'] ? 'MIXTO' : 'OCR';
        }

        return $resultado;
    }

    private function intentarQR(string $pdfPath): ?string
    {
        // Renderizar la 1ra página a PNG con pdftoppm (resolución 200dpi).
        $tmp = tempnam(sys_get_temp_dir(), 'qrpdf_');
        $base = $tmp . '_p';
        @unlink($tmp);
        $cmd = sprintf('%s -r 200 -f 1 -l 1 -png %s %s 2>&1',
            self::BIN_PDFTOPPM, escapeshellarg($pdfPath), escapeshellarg($base));
        @shell_exec($cmd);
        $png = $base . '-1.png';
        if (! file_exists($png)) return null;

        $out = @shell_exec(sprintf('%s --raw -q %s 2>/dev/null',
            self::BIN_ZBARIMG, escapeshellarg($png)));
        @unlink($png);
        $out = trim((string) $out);
        if ($out === '') return null;

        // El QR de AFIP es una URL: https://www.afip.gob.ar/fe/qr/?p=BASE64
        if (preg_match('~[?&]p=([A-Za-z0-9+/_=-]+)~', $out, $m)) {
            $b64 = strtr($m[1], '-_', '+/');
            $pad = strlen($b64) % 4;
            if ($pad) $b64 .= str_repeat('=', 4 - $pad);
            $json = base64_decode($b64, true);
            return $json ?: null;
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function parsearQRRG4290(string $json): ?array
    {
        $d = json_decode($json, true);
        if (! is_array($d)) return null;

        // Estructura RG 4290: ver, fecha, cuit, ptoVta, tipoCmp, nroCmp, importe,
        // moneda, ctz, tipoDocRec, nroDocRec, tipoCodAut, codAut
        return [
            'cuit_emisor' => isset($d['cuit']) ? str_replace('-', '', (string) $d['cuit']) : null,
            'cuit_receptor' => isset($d['nroDocRec']) ? str_replace('-', '', (string) $d['nroDocRec']) : null,
            'tipo_comprobante' => isset($d['tipoCmp']) ? (int) $d['tipoCmp'] : null,
            'punto_venta' => isset($d['ptoVta']) ? (int) $d['ptoVta'] : null,
            'numero' => isset($d['nroCmp']) ? (int) $d['nroCmp'] : null,
            'fecha_emision' => $d['fecha'] ?? null,
            'importe_total' => isset($d['importe']) ? (float) $d['importe'] : null,
            'cae' => isset($d['codAut']) ? (string) $d['codAut'] : null,
            'moneda' => $d['moneda'] ?? 'PES',
            'tipo_doc_receptor' => isset($d['tipoDocRec']) ? (int) $d['tipoDocRec'] : 80,
        ];
    }

    private function extraerTexto(string $pdfPath): string
    {
        // Probar pdftotext primero (rapidísimo si el PDF tiene texto).
        $texto = @shell_exec(sprintf('%s -layout %s - 2>/dev/null',
            self::BIN_PDFTOTEXT, escapeshellarg($pdfPath)));
        if (strlen(trim((string) $texto)) > 200) return (string) $texto;

        // PDF imagen → renderizar + tesseract spa.
        $tmp = tempnam(sys_get_temp_dir(), 'ocrpdf_');
        $base = $tmp . '_p';
        @unlink($tmp);
        @shell_exec(sprintf('%s -r 200 -png %s %s 2>&1',
            self::BIN_PDFTOPPM, escapeshellarg($pdfPath), escapeshellarg($base)));
        $textoOcr = '';
        $paginas = glob($base . '-*.png') ?: [];
        foreach ($paginas as $png) {
            $textoOcr .= "\n" . (string) @shell_exec(sprintf('%s %s - -l spa 2>/dev/null',
                self::BIN_TESSERACT, escapeshellarg($png)));
            @unlink($png);
        }
        return $textoOcr;
    }

    /** @return array<string,mixed> */
    private function parsearTextoPorPatrones(string $texto): array
    {
        $c = $this->camposVacios();
        $t = preg_replace('/[ \t]+/', ' ', $texto);

        // CUIT emisor: típicamente el primero que aparece.
        if (preg_match_all('/\b(\d{2}[-]?\d{8}[-]?\d{1})\b/', $t, $cuits)) {
            $cuit = preg_replace('/[^0-9]/', '', $cuits[1][0] ?? '');
            if (strlen($cuit) === 11) $c['cuit_emisor'] = $cuit;
            if (isset($cuits[1][1])) {
                $cuit2 = preg_replace('/[^0-9]/', '', $cuits[1][1]);
                if (strlen($cuit2) === 11) $c['cuit_receptor'] = $cuit2;
            }
        }

        // Tipo + PV-Nro: "FACTURA A 00001-00000123" o "FA A 0001-00000123"
        if (preg_match('/\b(\d{4,5})\s*[-]\s*(\d{8})\b/', $t, $m)) {
            $c['punto_venta'] = (int) $m[1];
            $c['numero'] = (int) $m[2];
        }
        // Letra del comprobante → mapeo aproximado (default FA=1).
        if (preg_match('/FACTURA\s+([ABCEMT])\b/iu', $t, $m)) {
            $c['tipo_comprobante'] = match (strtoupper($m[1])) {
                'A' => 1, 'B' => 6, 'C' => 11, 'E' => 19, 'M' => 51, 'T' => 81,
                default => null,
            };
        }

        // CAE: 14 dígitos.
        if (preg_match('/CAE[^\d]{0,12}(\d{14})\b/i', $t, $m)) {
            $c['cae'] = $m[1];
        } elseif (preg_match('/\b(\d{14})\b/', $t, $m)) {
            // backup: cualquier 14-dígitos seguidos (riesgo de falso match).
            $c['cae'] = $m[1];
        }

        // Fecha emisión: dd/mm/yyyy o yyyy-mm-dd cerca de "EMISION" / "FECHA".
        if (preg_match('/FECHA\s*(?:DE\s*)?EMISI[ÓO]N[^\d]{0,12}(\d{2})[\/\-](\d{2})[\/\-](\d{4})/iu', $t, $m)) {
            $c['fecha_emision'] = sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        } elseif (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\b/', $t, $m)) {
            $c['fecha_emision'] = sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }

        // Importe total: número con coma decimal después de "TOTAL".
        if (preg_match('/TOTAL[^\d\-\$]{0,12}\$?\s*([\d.]+,\d{2})/iu', $t, $m)) {
            $c['importe_total'] = (float) str_replace(',', '.', str_replace('.', '', $m[1]));
        }

        // Moneda y tipo_doc_receptor: defaults.
        if ($c['cuit_receptor'] && empty($c['tipo_doc_receptor'])) $c['tipo_doc_receptor'] = 80;
        if (empty($c['moneda'])) $c['moneda'] = 'PES';

        return $c;
    }

    /** @return array<string,mixed> */
    private function camposVacios(): array
    {
        return [
            'cuit_emisor' => null, 'cuit_receptor' => null,
            'tipo_comprobante' => null, 'punto_venta' => null, 'numero' => null,
            'fecha_emision' => null, 'importe_total' => null, 'cae' => null,
            'moneda' => 'PES', 'tipo_doc_receptor' => 80,
        ];
    }

    /** @return list<string> */
    private function faltantes(array $campos): array
    {
        $criticos = ['cuit_emisor', 'tipo_comprobante', 'punto_venta', 'numero', 'cae', 'fecha_emision', 'importe_total'];
        return array_values(array_filter($criticos, fn ($k) => empty($campos[$k])));
    }
}
