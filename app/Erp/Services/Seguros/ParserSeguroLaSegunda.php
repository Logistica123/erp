<?php

namespace App\Erp\Services\Seguros;

/**
 * Parser de pólizas de LA SEGUNDA Coop. Ltda. de Seguros Generales
 * (CUIT 30-50001770-4) para el módulo de Procesamiento de Seguro.
 *
 * Lee el "Detalle de facturación" del PDF (extraído con pdftotext -layout) y lo
 * mapea a las columnas del Libro IVA Compras según el criterio confirmado:
 *   - Neto gravado 21% = Prima + Recargo Financiero
 *   - IVA 21%          = IVA 21% + IVA s/R.Financ.
 *   - Percepción IVA   = IVA 3%
 *   - Otros tributos   = Imp. y Tasas + C.S. art. 11b
 *   - Total            = Premio final
 *   - Tipo comprobante = 90 (negativo / baja-NC) | 99 (positivo / alta-factura)
 *
 * Los importes del PDF vienen en formato US (coma de miles, punto decimal):
 * "-276,513.10". Se toman en valor absoluto (el signo define el tipo).
 */
class ParserSeguroLaSegunda implements ParserSeguroInterface
{
    public const CUIT_ASEGURADORA = '30500017704';
    public const NOMBRE = 'LA SEGUNDA COOPERATIVA LIMITADA DE SEGUROS GENERALES';

    public function aseguradora(): string { return self::NOMBRE; }

    public function reconoce(string $texto): bool
    {
        return str_contains(mb_strtoupper($texto), 'LA SEGUNDA')
            || str_contains(preg_replace('/\D/', '', $texto), self::CUIT_ASEGURADORA);
    }

    /** @return array<string,mixed> */
    public function parse(string $texto): array
    {
        // Sólo se procesan recibos/pólizas con "Detalle de facturación" (Prima /
        // Premio final). Otros documentos de La Segunda (ej. Certificado de
        // Coberturas) se rechazan para no cargar comprobantes en cero.
        if (! preg_match('/Detalle de facturaci/iu', $texto) || ! preg_match('/Premio\s+final/iu', $texto)) {
            throw new \DomainException('FORMATO_NO_SOPORTADO: el PDF no tiene "Detalle de facturación" (Prima/Premio) — no es un comprobante facturable (¿certificado de cobertura?).');
        }
        // Debe traer el código de comprobante (PV + número) en el formato esperado.
        $refCheck = $this->comprobanteRef($texto);
        if (! $refCheck || ! preg_match('/(\d+)-(\d+)-(\d+)-(\d+)/', $refCheck)) {
            throw new \DomainException('COMPROBANTE_NO_ENCONTRADO: no se encontró el número de comprobante (punto de venta y número) en la ubicación esperada.');
        }

        $prima      = $this->monto($texto, 'Prima');
        $recargo    = $this->monto($texto, 'Recargo Financiero');
        $iva21      = $this->monto($texto, 'IVA 21%');
        $ivaRFinanc = $this->monto($texto, 'IVA s\/R\. ?Financ\.?');
        $iva3       = $this->monto($texto, 'IVA 3%');
        $impTasas   = $this->monto($texto, 'Imp\. y Tasas');
        $csArt11b   = $this->monto($texto, 'C\.S\. art\. ?11b\.?');
        $premioFinal = $this->monto($texto, 'Premio final');

        // El signo del Premio final define alta (99) vs baja/NC (90).
        $esBaja = $premioFinal['raw'] < 0;

        // Punto de venta + número del código del comprobante del PDF.
        // Código: 001-001-0067743063-000054
        //   - PV = los ÚLTIMOS 5 dígitos del bloque largo (0067743063 → 43063)
        //   - Número = último grupo (000054 → 54), es el número de endoso.
        $ref = $this->comprobanteRef($texto);
        $pv = 0; $numero = 0;
        if ($ref && preg_match('/(\d+)-(\d+)-(\d+)-(\d+)/', $ref, $mm)) {
            $pv = (int) substr($mm[3], -5);
            $numero = (int) $mm[4];
        }

        $netoGravado21 = round(abs($prima['val']) + abs($recargo['val']), 2);
        $totalIva21    = round(abs($iva21['val']) + abs($ivaRFinanc['val']), 2);
        $percepIva     = round(abs($iva3['val']), 2);
        $otrosTributos = round(abs($impTasas['val']) + abs($csArt11b['val']), 2);
        $total         = round(abs($premioFinal['val']), 2);

        return [
            'aseguradora' => self::NOMBRE,
            'cuit_aseguradora' => self::CUIT_ASEGURADORA,
            'fecha_emision' => $this->fecha($texto),
            'poliza' => $this->poliza($texto),
            'comprobante_ref' => $ref,
            'punto_venta' => $pv,
            'numero' => $numero,
            'tipo_comprobante_id' => $esBaja ? 90 : 99,
            'tipo_label' => $esBaja ? 'Nota de Crédito (090)' : 'Factura/Otros (099)',
            'es_baja' => $esBaja,
            // mapeo Libro IVA Compras
            'imp_neto_gravado_21' => $netoGravado21,
            'imp_iva_21' => $totalIva21,
            'imp_percepciones_iva' => $percepIva,
            'imp_otros_tributos' => $otrosTributos,
            'imp_total' => $total,
            // crudos (para la tabla de revisión / trazabilidad)
            'crudos' => [
                'prima' => $prima['val'], 'recargo_financiero' => $recargo['val'],
                'iva_21' => $iva21['val'], 'iva_s_r_financ' => $ivaRFinanc['val'],
                'iva_3' => $iva3['val'], 'imp_y_tasas' => $impTasas['val'],
                'cs_art_11b' => $csArt11b['val'], 'premio_final' => $premioFinal['val'],
            ],
            // control: neto*21% ≈ iva21 y neto+iva+perc+trib ≈ total
            'control_cuadra' => abs(($netoGravado21 + $totalIva21 + $percepIva + $otrosTributos) - $total) < 0.10,
        ];
    }

    /** @return array{val:float,raw:float} */
    private function monto(string $texto, string $label): array
    {
        // "Label:  -276,513.10"  (coma=miles, punto=decimal)
        if (preg_match('/'.$label.'\s*:?\s*(-?[\d.,]+)/i', $texto, $m)) {
            $s = $m[1];
            $s = str_replace(',', '', $s);           // saca separador de miles
            $v = (float) $s;
            return ['val' => $v, 'raw' => $v];
        }
        return ['val' => 0.0, 'raw' => 0.0];
    }

    private function fecha(string $texto): ?string
    {
        $meses = ['enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
            'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12];
        if (preg_match('/a los (\d{1,2}) d[ií]as del mes de (\w+) del (\d{4})/iu', $texto, $m)) {
            $mes = $meses[mb_strtolower($m[2])] ?? null;
            if ($mes) return sprintf('%04d-%02d-%02d', (int) $m[3], $mes, (int) $m[1]);
        }
        // fallback: primera fecha dd/mm/yyyy (vigencia desde)
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $texto, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }

    private function poliza(string $texto): ?string
    {
        // "N° Póliza ... 67.743.063"
        if (preg_match('/(\d{2}\.\d{3}\.\d{3})/', $texto, $m)) return str_replace('.', '', $m[1]);
        return null;
    }

    private function comprobanteRef(string $texto): ?string
    {
        if (preg_match('/(\d{3}-\d{3}-\d{10}-\d{6})/', $texto, $m)) return $m[1];
        return null;
    }
}
