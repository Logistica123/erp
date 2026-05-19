<?php

namespace App\Erp\Services\Pdf;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * v1.41 — Extractor de datos desde PDF de Factura de Venta de AFIP.
 *
 * Estrategia: `pdftotext -layout` saca el texto del PDF preservando columnas;
 * luego una serie de regex busca los labels estándar del PDF generado por
 * AFIP (Mis Comprobantes / Comprobante en Línea).
 *
 * Cobertura esperada: ~80%. Los campos que no se detecten quedan null y el
 * operador los completa a mano en el wizard. Los que sí se detectan se
 * autofillean en el form, editables si hay error.
 *
 * Limitaciones conocidas (a iterar):
 *  - PDFs no-AFIP (impresos por sistemas custom) pueden no matchear los
 *    labels y devolver casi todo null.
 *  - Layouts modernos de AFIP con columnas raras (multi-alícuota IVA) solo
 *    extraen una alícuota — la primera detectada.
 *  - PDFs escaneados (raster) no tienen texto extraíble — pdftotext devuelve
 *    string vacío. Esto se detecta y se devuelve `texto_extraido_vacio: true`.
 */
class VentaPdfExtractor
{
    /**
     * @return array{
     *   ok: bool,
     *   campos: array{
     *     codigo_afip: ?int,
     *     letra: ?string,
     *     punto_venta: ?int,
     *     numero: ?int,
     *     fecha_emision: ?string,
     *     cuit_cliente: ?string,
     *     razon_social_cliente: ?string,
     *     imp_neto_gravado: ?float,
     *     imp_iva: ?float,
     *     imp_total: ?float,
     *     alicuota: ?float,
     *     cae: ?string,
     *     fecha_vto_cae: ?string,
     *     periodo_trabajado_texto: ?string,
     *   },
     *   tipo_comprobante_id: ?int,
     *   raw_excerpt: string,
     *   warning: ?string,
     * }
     */
    public function extraer(string $pdfPath): array
    {
        $texto = $this->runPdftotext($pdfPath);
        if (trim($texto) === '') {
            return $this->respuestaVacia(
                'texto_extraido_vacio: el PDF parece ser una imagen escaneada (sin texto OCR).'
            );
        }

        $campos = [
            'codigo_afip' => null,
            'letra' => null,
            'punto_venta' => null,
            'numero' => null,
            'fecha_emision' => null,
            'cuit_cliente' => null,
            'razon_social_cliente' => null,
            'imp_neto_gravado' => null,
            'imp_iva' => null,
            'imp_total' => null,
            'alicuota' => null,
            'cae' => null,
            'fecha_vto_cae' => null,
            'periodo_trabajado_texto' => null,
        ];

        // COD. 01 / COD. 06 / COD. 11 / COD. 51, etc.
        if (preg_match('/COD\.\s*0?(\d{1,3})/u', $texto, $m)) {
            $campos['codigo_afip'] = (int) $m[1];
        }

        // Letra (A/B/C/M). El layout AFIP la pone en una "caja" arriba a la izquierda
        // — pdftotext la separa en su propia línea. Buscamos una línea con solo
        // 1 letra mayúscula entre las primeras 30 líneas (heurística).
        $primerasLineas = array_slice(preg_split("/\r?\n/", $texto), 0, 30);
        foreach ($primerasLineas as $linea) {
            $l = trim($linea);
            if (preg_match('/^[ABCM]$/', $l)) {
                $campos['letra'] = $l;
                break;
            }
        }

        if (preg_match('/Punto\s+de\s+Venta:\s*0*(\d+)/iu', $texto, $m)) {
            $campos['punto_venta'] = (int) $m[1];
        }
        if (preg_match('/Comp\.?\s*Nro\.?:?\s*0*(\d+)/iu', $texto, $m)) {
            $campos['numero'] = (int) $m[1];
        }
        if (preg_match('/Fecha\s+de\s+Emisi[oó]n:\s*(\d{2}\/\d{2}\/\d{4})/iu', $texto, $m)) {
            $campos['fecha_emision'] = $this->parseFechaArg($m[1]);
        }

        // CUIT del cliente: aparecen 2 CUITs en el PDF (emisor + receptor). El
        // del receptor suele estar después del label "Apellido y Nombre / Razón Social"
        // o cerca del label "CUIT:" del segundo bloque. Heurística: buscar
        // "Apellido y Nombre / Razón Social:" y dentro de las 5 líneas siguientes
        // el primer CUIT de 11 dígitos.
        if (preg_match('/Apellido\s+y\s+Nombre\s*\/?\s*Raz[oó]n\s+Social:?\s*([^\n\r]+)/iu', $texto, $m)) {
            $campos['razon_social_cliente'] = trim($m[1]);
        }
        // CUITs en el texto, lo agarramos del bloque tras el label "Apellido y Nombre"
        // (que es siempre el cliente). Fallback: segundo CUIT del documento.
        $cuits = [];
        if (preg_match_all('/(?:CUIT|C\.U\.I\.T\.):?\s*(\d{11})/u', $texto, $mm)) {
            $cuits = $mm[1];
        }
        if (count($cuits) >= 2) {
            // Asumimos que el primero es el emisor (LOGISTICA) y el segundo el cliente.
            $campos['cuit_cliente'] = $cuits[1];
        } elseif (count($cuits) === 1) {
            // Si solo encontró 1, ese es el cliente (raro pero posible — emisor sin label).
            $campos['cuit_cliente'] = $cuits[0];
        }

        // Total: buscar variantes (Total, Importe Total, Subtotal c/IVA si es la última línea).
        // Preferimos el "Importe Total" si está, sino el "Total".
        if (preg_match('/Importe\s+Total:?\s*\$?\s*([\d.,]+)/iu', $texto, $m)) {
            $campos['imp_total'] = $this->parseImporte($m[1]);
        } elseif (preg_match('/Total:?\s*\$?\s*([\d.,]+)/iu', $texto, $m)) {
            $campos['imp_total'] = $this->parseImporte($m[1]);
        }

        // Neto gravado: AFIP suele rotularlo como "Importe Neto Gravado" o
        // "Subtotal" (en facturas A — antes del IVA).
        if (preg_match('/Importe\s+Neto\s+Gravado:?\s*\$?\s*([\d.,]+)/iu', $texto, $m)) {
            $campos['imp_neto_gravado'] = $this->parseImporte($m[1]);
        } elseif (preg_match('/Subtotal:?\s*\$?\s*([\d.,]+)/iu', $texto, $m)) {
            $campos['imp_neto_gravado'] = $this->parseImporte($m[1]);
        }

        // IVA: "IVA 21%" o "Importe Total IVA" o similar.
        if (preg_match('/IVA\s+(?:Inscripto\s+)?(\d{1,2}(?:[,.]\d+)?)\s*%?:?\s*\$?\s*([\d.,]+)/iu', $texto, $m)) {
            $campos['alicuota'] = (float) str_replace(',', '.', $m[1]);
            $campos['imp_iva'] = $this->parseImporte($m[2]);
        }
        // Fallback: si encontramos alícuota suelta pero no monto, lo derivamos del neto.
        if ($campos['alicuota'] === null && preg_match('/Al[ií]cuota\s+IVA[:\s]+(\d{1,2}(?:[,.]\d+)?)\s*%/iu', $texto, $m)) {
            $campos['alicuota'] = (float) str_replace(',', '.', $m[1]);
        }
        if ($campos['imp_iva'] === null && $campos['alicuota'] !== null && $campos['imp_neto_gravado'] !== null) {
            $campos['imp_iva'] = round($campos['imp_neto_gravado'] * $campos['alicuota'] / 100, 2);
        }

        // CAE: 14 dígitos típicamente al final del PDF.
        if (preg_match('/C\.?A\.?E\.?\s*N[°º]?:?\s*(\d{14})/iu', $texto, $m)) {
            $campos['cae'] = $m[1];
        }
        if (preg_match('/(?:Fecha\s+(?:de\s+)?V(?:enc|to)\.?\s+(?:de\s+)?CAE|Vencimiento\s+CAE):?\s*(\d{2}\/\d{2}\/\d{4})/iu', $texto, $m)) {
            $campos['fecha_vto_cae'] = $this->parseFechaArg($m[1]);
        }

        // Período trabajado: a veces el PDF tiene "Período Facturado Desde: dd/mm/yyyy
        // Hasta: dd/mm/yyyy". Tomamos el mes de "Desde" como YYYY-MM por convención.
        if (preg_match('/Per[ií]odo\s+Facturado\s+Desde:?\s*(\d{2})\/(\d{2})\/(\d{4})/iu', $texto, $m)) {
            $campos['periodo_trabajado_texto'] = $m[3].'-'.$m[2];
        }

        // Lookup tipo_comprobante_id en BD usando el código AFIP.
        $tipoCbteId = null;
        if ($campos['codigo_afip']) {
            $tipoCbteId = DB::table('erp_tipos_comprobante')
                ->where('id', $campos['codigo_afip'])
                ->value('id');
        }

        $extracto = mb_substr($texto, 0, 600);

        return [
            'ok' => true,
            'campos' => $campos,
            'tipo_comprobante_id' => $tipoCbteId ? (int) $tipoCbteId : null,
            'raw_excerpt' => $extracto,
            'warning' => null,
        ];
    }

    private function runPdftotext(string $pdfPath): string
    {
        // -layout preserva el orden visual aproximado (mejor para extraer labels).
        // Output a stdout (-) para no crear archivos temporales.
        $proc = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $pdfPath, '-']);
        $proc->setTimeout(15);
        $proc->run();

        if (! $proc->isSuccessful()) {
            Log::warning('PDFTOTEXT_FALLO', [
                'path' => $pdfPath,
                'exit_code' => $proc->getExitCode(),
                'stderr' => $proc->getErrorOutput(),
            ]);
            return '';
        }
        return (string) $proc->getOutput();
    }

    /** dd/mm/yyyy → yyyy-mm-dd. */
    private function parseFechaArg(string $s): ?string
    {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', trim($s), $m)) {
            return $m[3].'-'.$m[2].'-'.$m[1];
        }
        return null;
    }

    /**
     * Importes en formato es-AR: "4.549.360,00" o "4549360.00" o "1.234,56".
     * Heurística: si tiene tanto "." como ",", el separador decimal es el
     * último de los dos. Si tiene solo uno, hay que distinguir miles vs decimales:
     * más de 2 dígitos después del separador → es de miles.
     */
    private function parseImporte(string $s): ?float
    {
        $s = trim(str_replace(['$', ' '], '', $s));
        if ($s === '') return null;

        $tienePunto = strpos($s, '.') !== false;
        $tieneComa = strpos($s, ',') !== false;

        if ($tienePunto && $tieneComa) {
            // El último separador es el decimal.
            $lastPunto = strrpos($s, '.');
            $lastComa = strrpos($s, ',');
            if ($lastComa > $lastPunto) {
                // Decimal es ",", miles es ".".
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                // Decimal es ".", miles es ",".
                $s = str_replace(',', '', $s);
            }
        } elseif ($tieneComa) {
            // Solo coma: si hay 1-2 dígitos después, es decimal. Más → miles (raro en es-AR).
            $partes = explode(',', $s);
            $sufijo = end($partes);
            if (strlen($sufijo) <= 2 && count($partes) === 2) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($tienePunto) {
            // Solo punto: si hay 1-2 dígitos después es decimal. Más → es de miles
            // (típico es-AR sin decimales: "4.549.360").
            $partes = explode('.', $s);
            $sufijo = end($partes);
            if (strlen($sufijo) <= 2 && count($partes) === 2) {
                // Decimal punto.
            } else {
                $s = str_replace('.', '', $s);
            }
        }
        return is_numeric($s) ? (float) $s : null;
    }

    private function respuestaVacia(string $warning): array
    {
        return [
            'ok' => false,
            'campos' => array_fill_keys([
                'codigo_afip', 'letra', 'punto_venta', 'numero', 'fecha_emision',
                'cuit_cliente', 'razon_social_cliente', 'imp_neto_gravado',
                'imp_iva', 'imp_total', 'alicuota', 'cae', 'fecha_vto_cae',
                'periodo_trabajado_texto',
            ], null),
            'tipo_comprobante_id' => null,
            'raw_excerpt' => '',
            'warning' => $warning,
        ];
    }
}
