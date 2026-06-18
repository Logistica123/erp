<?php

namespace App\Erp\Services\Seguros;

/**
 * Parser de endosos/pólizas de SAN CRISTOBAL S.M.S.G. (CUIT 34-50004533-9).
 *
 * Lee el bloque "Costos del Seguro" y lo mapea al Libro IVA Compras con el
 * mismo criterio que La Segunda (confirmado por el contador):
 *   - Neto gravado 21% = BASE IMPONIBLE   (= Prima + Rec. Financiero)
 *   - IVA 21%          = I.V.A.
 *   - Percepción IVA   = I.V.A. R.G. 3337
 *   - Otros tributos   = IMPUESTOS/TASAS + SELLADO + CUOTA SOCIAL ART.8 +
 *                        PERC. TSeH LA PLATA + PERC. IB. TOTAL
 *   - Total            = Premio
 *   - Tipo cbte        = 90 (Premio negativo / baja-NC) | 99 (positivo)
 *
 * PV = últimos 5 dígitos del último bloque del N° Póliza/Factura
 *      (01-06-06-30035710 → 35710). Número = N° de Endoso (197).
 * Importes en formato es-AR: "$-2.217.577,38" (punto miles, coma decimal).
 */
class ParserSeguroSanCristobal implements ParserSeguroInterface
{
    public const CUIT_ASEGURADORA = '34500045339';
    public const NOMBRE = 'SAN CRISTOBAL S.M.S.G.';

    public function aseguradora(): string { return self::NOMBRE; }

    public function reconoce(string $texto): bool
    {
        return str_contains(mb_strtoupper($texto), 'SAN CRISTOBAL')
            || str_contains(preg_replace('/\D/', '', $texto), self::CUIT_ASEGURADORA);
    }

    /** @return array<string,mixed> */
    public function parse(string $texto): array
    {
        if (! preg_match('/Costos del Seguro/iu', $texto) || ! preg_match('/Premio/u', $texto)) {
            throw new \DomainException('FORMATO_NO_SOPORTADO: el PDF no tiene "Costos del Seguro" — no es un comprobante facturable de San Cristóbal.');
        }

        // Código N° Póliza/Factura + Endoso (van juntos: "01-06-06-30035710  197").
        if (! preg_match('/(\d{2}-\d{2}-\d{2}-\d{6,})\s+(\d+)/u', $texto, $mc)) {
            throw new \DomainException('COMPROBANTE_NO_ENCONTRADO: no se encontró el N° de Póliza/Factura y Endoso.');
        }
        $codigo = $mc[1];
        $endoso = (int) $mc[2];
        $ultimoBloque = preg_replace('/\D/', '', explode('-', $codigo)[3] ?? '');
        $pv = (int) substr($ultimoBloque, -5);
        $numero = $endoso;

        // Filas de "Costos del Seguro": etiquetas en una línea, valores en la
        // siguiente, alineados por columna (orden fijo).
        $r1 = $this->valoresFila($texto, 'PRIMA');               // [prima, recFin, base, iva, ivaRG]
        $r2 = $this->valoresFila($texto, 'PERC. IB. TOTAL');     // [percIb, impTasas, sellado, cuotaSoc, percTseh]
        $premio = $this->montoPremio($texto);

        $base   = $this->n($r1[2] ?? '');   // BASE IMPONIBLE
        $iva    = $this->n($r1[3] ?? '');   // I.V.A.
        $ivaRg  = $this->n($r1[4] ?? '');   // I.V.A. R.G. 3337
        $impTas = $this->n($r2[1] ?? '');
        $sell   = $this->n($r2[2] ?? '');
        $cuota  = $this->n($r2[3] ?? '');
        $tseh   = $this->n($r2[4] ?? '');
        $percIb = $this->n($r2[0] ?? '');

        $neto21    = round(abs($base), 2);
        $totalIva  = round(abs($iva), 2);
        $percepIva = round(abs($ivaRg), 2);
        $otrosTrib = round(abs($impTas) + abs($sell) + abs($cuota) + abs($tseh) + abs($percIb), 2);
        $total     = round(abs($premio), 2);
        $esBaja    = $premio < 0;

        return [
            'aseguradora' => self::NOMBRE,
            'cuit_aseguradora' => self::CUIT_ASEGURADORA,
            'fecha_emision' => $this->fecha($texto),
            'poliza' => $codigo,
            'comprobante_ref' => $codigo.' - End '.$endoso,
            'punto_venta' => $pv,
            'numero' => $numero,
            'tipo_comprobante_id' => $esBaja ? 90 : 99,
            'tipo_label' => $esBaja ? 'Nota de Crédito (090)' : 'Factura/Otros (099)',
            'es_baja' => $esBaja,
            'imp_neto_gravado_21' => $neto21,
            'imp_iva_21' => $totalIva,
            'imp_percepciones_iva' => $percepIva,
            'imp_otros_tributos' => $otrosTrib,
            'imp_total' => $total,
            'crudos' => [
                'base_imponible' => $base, 'iva' => $iva, 'iva_rg_3337' => $ivaRg,
                'impuestos_tasas' => $impTas, 'sellado' => $sell, 'cuota_social' => $cuota,
                'perc_tseh' => $tseh, 'perc_ib' => $percIb, 'premio' => $premio,
            ],
            'control_cuadra' => abs(($neto21 + $totalIva + $percepIva + $otrosTrib) - $total) < 0.10,
        ];
    }

    /**
     * Devuelve los valores de la fila inmediatamente posterior a la línea que
     * contiene $ancla (split por 2+ espacios = columnas).
     * @return array<int,string>
     */
    private function valoresFila(string $texto, string $ancla): array
    {
        $lines = explode("\n", $texto);
        foreach ($lines as $i => $l) {
            if (str_contains($l, $ancla)) {
                for ($j = $i + 1; $j < count($lines); $j++) {
                    if (str_contains($lines[$j], '$')) {
                        return preg_split('/\s{2,}/', trim($lines[$j])) ?: [];
                    }
                }
            }
        }
        return [];
    }

    private function montoPremio(string $texto): float
    {
        if (preg_match('/Premio[^\$\d-]*\$?\s*(-?[\d.,]+)/u', $texto, $m)) {
            return $this->n($m[1]);
        }
        return 0.0;
    }

    /** Número es-AR: "$-2.217.577,38" → -2217577.38. */
    private function n(string $raw): float
    {
        $s = str_replace(['$', ' ', '.'], '', trim($raw)); // saca $, espacios, miles
        $s = str_replace(',', '.', $s);                     // coma decimal → punto
        if ($s === '' || ! is_numeric($s)) return 0.0;
        return (float) $s;
    }

    private function fecha(string $texto): ?string
    {
        // "LUGAR Y FECHA EMISIÓN: Resistencia, 08/05/2026" → ciudad, dd/mm/yyyy.
        if (preg_match('/[A-Za-zÁÉÍÓÚáéíóúñ\.]+,\s*(\d{2})\/(\d{2})\/(\d{4})/u', $texto, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $texto, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }
}
