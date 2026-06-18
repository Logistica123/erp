<?php

namespace App\Erp\Services\Seguros;

/**
 * Parser de pólizas/suplementos de MAPFRE ARGENTINA SEGUROS DE VIDA S.A.
 * (CUIT 33-70089372-9).
 *
 * Lee el bloque "DESGLOSE DEL PREMIO - FACTURA" y lo mapea al Libro IVA Compras
 * con el mismo criterio que las demás aseguradoras:
 *   - Neto gravado 21% = PRIMA + Recargo Financiero
 *   - IVA 21%          = IVA Tasa Básica
 *   - Percepción IVA   = 0 (Mapfre no discrimina percepción de IVA en este formato)
 *   - Otros tributos   = IMPUESTOS Y SELLADOS
 *   - Total            = PREMIO TOTAL
 *   - Tipo cbte        = 90 (Premio negativo / baja-NC) | 99 (positivo)
 *
 * PV = últimos 5 dígitos del bloque principal del N° de Póliza
 *      (152-02297608-01 → 97608). Número = N° de Suplemento (18).
 * Importes en formato es-AR ("-61.058,59"). Etiqueta y $valor en la misma línea.
 */
class ParserSeguroMapfre implements ParserSeguroInterface
{
    public const CUIT_ASEGURADORA = '33700893729';
    public const NOMBRE = 'MAPFRE ARGENTINA SEGUROS DE VIDA S.A.';

    public function aseguradora(): string { return self::NOMBRE; }

    public function reconoce(string $texto): bool
    {
        return str_contains(mb_strtoupper($texto), 'MAPFRE')
            || str_contains(preg_replace('/\D/', '', $texto), self::CUIT_ASEGURADORA);
    }

    /** @return array<string,mixed> */
    public function parse(string $texto): array
    {
        if (! preg_match('/DESGLOSE DEL PREMIO/iu', $texto) || ! preg_match('/PREMIO\s+TOTAL/iu', $texto)) {
            throw new \DomainException('FORMATO_NO_SOPORTADO: el PDF no tiene "DESGLOSE DEL PREMIO" — no es un comprobante facturable de Mapfre.');
        }

        if (! preg_match('/POLIZA\s*N[°º:\s]+\s*([\d-]+)/iu', $texto, $mp)) {
            throw new \DomainException('COMPROBANTE_NO_ENCONTRADO: no se encontró el N° de Póliza.');
        }
        $poliza = trim($mp[1], '- ');
        if (! preg_match('/SUPLEMENTO\s*N[°º:\s]+\s*(\d+)/iu', $texto, $ms)) {
            throw new \DomainException('COMPROBANTE_NO_ENCONTRADO: no se encontró el N° de Suplemento.');
        }
        $suplemento = (int) $ms[1];

        // PV = últimos 5 del bloque numérico más largo del N° de póliza.
        $bloques = preg_split('/[^0-9]+/', $poliza);
        usort($bloques, fn ($a, $b) => strlen($b) <=> strlen($a));
        $pv = (int) substr($bloques[0] ?? '', -5);
        $numero = $suplemento;

        $prima   = abs($this->monto($texto, 'PRIMA'));
        $recFin  = abs($this->monto($texto, 'Recargo Financiero'));
        $impSell = abs($this->monto($texto, 'IMPUESTOS Y SELLADOS'));
        $iva     = abs($this->monto($texto, 'IVA Tasa B[áa]sica'));
        $premio  = $this->monto($texto, 'PREMIO\s+TOTAL');

        $neto21    = round($prima + $recFin, 2);
        $totalIva  = round($iva, 2);
        $percepIva = 0.0;
        $otrosTrib = round($impSell, 2);
        $total     = round(abs($premio), 2);
        $esBaja    = $premio < 0;

        return [
            'aseguradora' => self::NOMBRE,
            'cuit_aseguradora' => self::CUIT_ASEGURADORA,
            'fecha_emision' => $this->fecha($texto),
            'poliza' => $poliza,
            'comprobante_ref' => $poliza.' - Supl '.$suplemento,
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
                'prima' => $this->monto($texto, 'PRIMA'),
                'recargo_financiero' => $this->monto($texto, 'Recargo Financiero'),
                'impuestos_sellados' => $this->monto($texto, 'IMPUESTOS Y SELLADOS'),
                'iva_tasa_basica' => $this->monto($texto, 'IVA Tasa B[áa]sica'),
                'premio_total' => $premio,
            ],
            'control_cuadra' => abs(($neto21 + $totalIva + $percepIva + $otrosTrib) - $total) < 0.10,
        ];
    }

    /** Primer $valor después de la etiqueta (la columna DESGLOSE está antes de PLAN DE PAGOS). */
    private function monto(string $texto, string $label): float
    {
        if (preg_match('/'.$label.'[^\$\n]*\$\s*(-?[\d.,]+)/iu', $texto, $m)) {
            return $this->n($m[1]);
        }
        return 0.0;
    }

    /** es-AR: "-61.058,59" → -61058.59. */
    private function n(string $raw): float
    {
        $s = str_replace(['$', ' ', '.'], '', trim($raw));
        $s = str_replace(',', '.', $s);
        return ($s === '' || ! is_numeric($s)) ? 0.0 : (float) $s;
    }

    private function fecha(string $texto): ?string
    {
        if (preg_match('/EMISION[:\s]+(\d{2})\/(\d{2})\/(\d{4})/iu', $texto, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $texto, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }
}
