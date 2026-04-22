<?php

namespace App\Erp\Services\Tesoreria\Parsers;

use App\Erp\Models\Tesoreria\CuentaBancaria;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Parser del CSV de movimientos de ICBC Homebanking.
 * Especificación: /arquitecturas/09_Datos_Reales/extractos_bancarios/ICBC/FORMATO_CSV_ICBC.md
 *
 * Características:
 *  - Encoding latin-1 / ISO-8859-1
 *  - Separador ; · decimales , · line ending CRLF
 *  - 17 columnas fijas, líneas con largo variable (10-17)
 *  - Orden cronológicamente inverso (más reciente primero)
 *  - Fila APERTURA (cod 656) marca saldo_inicial = 0 y no genera movimiento
 */
class ParserIcbc extends AbstractParser
{
    public const CODIGO = 'ICBC';

    public function codigoParser(): string
    {
        return self::CODIGO;
    }

    public function parse(string $path, CuentaBancaria $cuenta): ExtractoParseado
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new DomainException("FORMATO_INVALIDO: no se pudo abrir el archivo {$path}");
        }
        stream_filter_append($handle, 'convert.iconv.ISO-8859-1/UTF-8//TRANSLIT');

        // Línea 1 — header de cuenta
        $linea1 = fgets($handle);
        if ($linea1 === false) {
            fclose($handle);
            throw new DomainException('FORMATO_INVALIDO: archivo vacío');
        }
        $header = $this->parsearHeaderCuenta(trim($linea1));

        // Línea 2 — header de columnas (validamos nombres clave)
        $fila2 = fgetcsv($handle, 0, ';');
        if ($fila2 === false || count($fila2) < 6) {
            fclose($handle);
            throw new DomainException('FORMATO_INVALIDO: falta header de columnas');
        }
        if (! str_contains(mb_strtolower($fila2[0]), 'fecha') || ! str_contains(mb_strtolower($fila2[1]), 'concepto')) {
            fclose($handle);
            throw new DomainException('FORMATO_INVALIDO: header de columnas no coincide con formato ICBC');
        }

        // Líneas 3..N — movimientos (orden del archivo: más reciente → más antiguo)
        $filas = [];
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) === 1 && ($row[0] === null || trim((string) $row[0]) === '')) {
                continue; // línea vacía final
            }
            // Pad a 17 columnas
            while (count($row) < 17) {
                $row[] = null;
            }
            $filas[] = $row;
        }
        fclose($handle);

        // El archivo ICBC viene mayormente en orden inverso pero NO es
        // estricto: dentro del mismo día los movimientos pueden estar
        // mezclados. Parseamos tal cual y reordenamos al final por (fecha,
        // saldo) — sort estable — antes de derivar saldo_inicial.
        $aperturaDetectada = false;
        $movimientos = [];
        $fechaDesde = null;
        $fechaHasta = null;

        foreach ($filas as $row) {
            [$fechaStr, $codConcepto, $concepto, $debitoStr, $creditoStr, $saldoStr,
                $infoComp, $nroCheque, $sucursal, $canal,
                $banco, $cbu, $tipoTrf, $referencia, $nombre, $tipoDoc, $nroDoc] = $row;

            if (! $fechaStr || ! $codConcepto) {
                continue; // fila sin datos mínimos
            }

            $fecha = $this->parsearFecha($fechaStr);

            // Fila APERTURA (cod 656): arranque de cuenta
            if ($codConcepto === '656') {
                $aperturaDetectada = true;
                continue;
            }

            $debito = self::parsearImporte($debitoStr);
            $credito = self::parsearImporte($creditoStr);
            $saldo = self::parsearImporte($saldoStr);

            // El signo negativo en débito es redundante (la columna ya lo dice).
            if ($debito !== null && $debito < 0) {
                $debito = abs($debito);
            }

            // Exactamente uno de debito/credito tiene valor operativo
            $mov = new MovimientoParseado(
                fecha: $fecha,
                concepto: trim((string) $concepto),
                debito: $debito ?: null,
                credito: $credito ?: null,
                saldo: $saldo,
                codConcepto: (string) $codConcepto,
                comprobanteBanco: $nroCheque ? trim((string) $nroCheque) : null,
                canal: $canal ? trim((string) $canal) : null,
                bancoContraparte: $banco ? trim((string) $banco) : null,
                cbuContraparte: $cbu ? trim((string) $cbu) : null,
                cuitContraparte: $nroDoc ? trim((string) $nroDoc) : null,
                nombreContraparte: $nombre ? trim((string) $nombre) : null,
                referencia: $referencia ? trim((string) $referencia) : null,
                infoComplementaria: $infoComp ? trim((string) $infoComp) : null,
            );
            $mov->hashLinea = self::hashLinea($cuenta->id, $mov);
            $movimientos[] = $mov;

            if ($fechaDesde === null || $fecha->lt($fechaDesde)) {
                $fechaDesde = $fecha;
            }
            if ($fechaHasta === null || $fecha->gt($fechaHasta)) {
                $fechaHasta = $fecha;
            }
        }

        if (empty($movimientos)) {
            throw new DomainException('FORMATO_INVALIDO: no se encontraron movimientos operativos');
        }

        // Re-ordenar por (fecha, saldo asc) para persistir en orden cronológico.
        // ICBC a veces mezcla movimientos del mismo día; ordenar por saldo
        // resulta en la secuencia válida en la mayoría de casos prácticos.
        usort($movimientos, function (MovimientoParseado $a, MovimientoParseado $b) {
            $c = $a->fecha->timestamp <=> $b->fecha->timestamp;
            if ($c !== 0) {
                return $c;
            }

            return ($a->saldo ?? 0) <=> ($b->saldo ?? 0);
        });

        // Derivar saldo_inicial ya con los movimientos ordenados.
        //  · APERTURA → saldo_inicial = 0 (caso A de la spec).
        //  · sin APERTURA → saldo_anterior_al_primer_movimiento del período.
        if ($aperturaDetectada) {
            $saldoInicial = 0.0;
        } else {
            $primero = $movimientos[0];
            $saldoInicial = $primero->saldo !== null
                ? round($primero->saldo - (($primero->credito ?? 0) - ($primero->debito ?? 0)), 2)
                : null;
        }

        $ultimo = end($movimientos);
        $saldoFinal = $ultimo->saldo;

        // Saldo corrido queda como validación opcional en AbstractParser; los
        // archivos reales mezclan filas del mismo día y el reordenamiento
        // por saldo resuelve la secuencia "mejor esfuerzo". Sin errores
        // bloqueantes por este motivo.
        $errores = [];

        // Compatibilidad cruzada: si la moneda del header no coincide con la
        // configurada en la cuenta, abortamos — el operador seleccionó la
        // cuenta equivocada al importar.
        $codMonedaCuenta = $cuenta->moneda?->codigo;
        if ($codMonedaCuenta && $header['moneda'] && $header['moneda'] !== $codMonedaCuenta) {
            throw new DomainException(sprintf(
                'FORMATO_INVALIDO: el archivo es %s pero la cuenta destino %s es %s',
                $header['moneda'],
                $cuenta->codigo,
                $codMonedaCuenta
            ));
        }

        return new ExtractoParseado(
            hashArchivo: self::hashArchivo($path),
            nombreArchivo: basename($path),
            fechaDesde: $fechaDesde,
            fechaHasta: $fechaHasta,
            saldoInicial: $saldoInicial,
            saldoFinal: $saldoFinal,
            movimientos: $movimientos,
            errores: $errores,
            monedaDetectada: $header['moneda'],
            numeroCuentaDetectado: $header['numero'],
        );
    }

    /**
     * @return array{tipo:?string, moneda:?string, numero:?string}
     */
    private function parsearHeaderCuenta(string $linea): array
    {
        $regex = '/^Movimientos de (?P<tipo>CC|CA|CE)\s+(?P<moneda_raw>\$|U\$S)\s+(?P<numero>\d{4}\/\d{8}\/\d{2})/u';
        if (! preg_match($regex, $linea, $m)) {
            return ['tipo' => null, 'moneda' => null, 'numero' => null];
        }

        return [
            'tipo' => $m['tipo'],
            'moneda' => $m['moneda_raw'] === 'U$S' ? 'USD' : 'ARS',
            'numero' => $m['numero'],
        ];
    }

    private function parsearFecha(string $s): Carbon
    {
        try {
            return Carbon::createFromFormat('d/m/Y', trim($s))->startOfDay();
        } catch (\Throwable) {
            throw new DomainException("FORMATO_INVALIDO: fecha inválida '{$s}'");
        }
    }
}
