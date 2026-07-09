<?php

namespace App\Erp\Services\Prestamos;

/**
 * Parser del PDF "Mis Facilidades" de ARCA/AFIP (plan de facilidades de pago).
 *
 * Extrae la cabecera (CUIT, nombre, fecha de consolidación, número de plan) y el
 * cronograma de cuotas. El PDF trae 2 vencimientos por cuota: el 1° (sin interés
 * resarcitorio) y el 2° (con resarcitorio). Tomamos SOLO el 1° vencimiento como
 * cronograma canónico (el resarcitorio se carga aparte si se paga tarde).
 *
 * Formato de la fila del 1° vencimiento (pdftotext):
 *   <nroCuota> <capital> <interésFinanciero> - <total> <dd/mm/yyyy>
 * Importes en es-AR (punto de miles, coma decimal); el capital a veces viene sin
 * separador de miles. El "-" en la columna resarcitorio = 0.
 */
class ParserPlanAfip
{
    /** @return array<string,mixed> */
    public function parse(string $texto): array
    {
        if (! preg_match('/N[úu]mero de Plan/iu', $texto)) {
            throw new \DomainException('FORMATO_NO_SOPORTADO: el PDF no parece un plan "Mis Facilidades" de ARCA/AFIP (falta "Número de Plan").');
        }

        $numeroPlan = $this->buscar('/N[úu]mero de Plan:?\s*([A-Za-z0-9]+)/u', $texto);
        if (! $numeroPlan) {
            throw new \DomainException('PLAN_NO_ENCONTRADO: no se pudo leer el número de plan.');
        }
        $cuit = $this->buscar('/CUIT:?\s*(\d{11})/u', $texto);
        $nombre = $this->buscar('/Nombre y Apellido:?\s*(.+?)\s*(?:N[úu]mero de Plan|Fh\.|$)/iu', $texto);
        $nombre = $nombre ? rtrim(trim($nombre), ', ') : null;
        $fechaConsol = $this->fecha($this->buscar('/Consolidaci[óo]n:?\s*(\d{1,2}\/\d{1,2}\/\d{4})/u', $texto));

        // Filas del 1° vencimiento: empiezan con el nro de cuota.
        //   grupos: 1=cuota 2=capital 3=interés financiero 4=total 5=fecha
        // (la columna resarcitorio — "-" o un importe — es no-capturada).
        $re = '/^\s*(\d{1,3})\s+([\d.,]+)\s+([\d.,]+)\s+(?:-|[\d.,]+)\s+([\d.,]+)\s+(\d{1,2}\/\d{1,2}\/\d{4})/m';
        preg_match_all($re, $texto, $m, PREG_SET_ORDER);

        $cuotas = [];
        foreach ($m as $row) {
            $cuotas[] = [
                'numero' => (int) $row[1],
                'capital' => $this->n($row[2]),
                'interes' => $this->n($row[3]),
                'total' => $this->n($row[4]),
                'fecha_venc' => $this->fecha($row[5]),
            ];
        }
        if (! $cuotas) {
            throw new \DomainException('SIN_CUOTAS: no se encontraron filas de cuotas en el PDF.');
        }
        // Orden por número de cuota + dedup (por las dudas).
        usort($cuotas, fn ($a, $b) => $a['numero'] <=> $b['numero']);
        $cuotas = array_values(array_reduce($cuotas, function ($acc, $c) {
            $acc[$c['numero']] = $c;
            return $acc;
        }, []));

        $totalCapital = round(array_sum(array_column($cuotas, 'capital')), 2);
        $totalInteres = round(array_sum(array_column($cuotas, 'interes')), 2);
        $totalCuotas = round(array_sum(array_column($cuotas, 'total')), 2);

        return [
            'numero_plan' => $numeroPlan,
            'cuit' => $cuit,
            'nombre' => $nombre,
            'fecha_consolidacion' => $fechaConsol,
            'cuotas' => $cuotas,
            'total_capital' => $totalCapital,
            'total_interes' => $totalInteres,
            'total' => $totalCuotas,
        ];
    }

    private function buscar(string $re, string $texto): ?string
    {
        return preg_match($re, $texto, $m) ? trim($m[1]) : null;
    }

    /** es-AR: "760.458,99" → 760458.99 ; "4477013,07" → 4477013.07 */
    private function n(string $raw): float
    {
        $s = str_replace(['.', ' '], '', trim($raw));
        $s = str_replace(',', '.', $s);
        return ($s === '' || ! is_numeric($s)) ? 0.0 : (float) $s;
    }

    private function fecha(?string $ddmmyyyy): ?string
    {
        if (! $ddmmyyyy || ! preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $ddmmyyyy, $m)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
}
