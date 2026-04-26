<?php

namespace App\Erp\Services\Tesoreria\Parsers;

use App\Erp\Models\Tesoreria\CuentaBancaria;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Parser Mercado Pago — reporte "Resumen de cuenta" (CSV/XLSX equivalente).
 * Especificación: /arquitecturas/09_Datos_Reales/extractos_bancarios/MercadoPago/FORMATO_MP.md
 *
 * Estructura del CSV:
 *   Líneas 1-2: bloque resumen del período.
 *     INITIAL_BALANCE;CREDITS;DEBITS;FINAL_BALANCE
 *     16.868,97;26.329.922,75;-26.344.277,70;2.514,02
 *   Línea 3: vacía.
 *   Línea 4: header del detalle.
 *     RELEASE_DATE;TRANSACTION_TYPE;REFERENCE_ID;TRANSACTION_NET_AMOUNT;PARTIAL_BALANCE
 *   Línea 5+: filas de movimientos. TRANSACTION_NET_AMOUNT viene con signo
 *   (negativo = débito).
 *
 * Características:
 *  - UTF-8, separador `;`, decimal `,`, miles `.`, fecha `DD-MM-YYYY`.
 *  - INITIAL_BALANCE explícito → no hay que derivar.
 *  - REFERENCE_ID puede repetirse (operaciones pasantes); por eso hashLinea
 *    incluye además importe + saldo corrido.
 *  - El XLSX detallado merge se hará en una v2; v1 procesa solo el CSV.
 */
class ParserMercadoPago extends AbstractParser
{
    public const CODIGO = 'MERCADO_PAGO';

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

        // Línea 1: header del bloque resumen.
        $h1 = fgetcsv($handle, 0, ';');
        if ($h1 === false || count($h1) < 4 ||
            mb_strtoupper(trim($h1[0])) !== 'INITIAL_BALANCE') {
            fclose($handle);
            throw new DomainException('FORMATO_INVALIDO: header MP bloque 1 inválido (esperaba INITIAL_BALANCE)');
        }

        // Línea 2: valores del bloque resumen.
        $r1 = fgetcsv($handle, 0, ';');
        if ($r1 === false || count($r1) < 4) {
            fclose($handle);
            throw new DomainException('FORMATO_INVALIDO: faltan valores del bloque resumen MP');
        }
        $initialBalance = self::parsearImporte($r1[0]);
        $totalCreditos  = self::parsearImporte($r1[1]);
        $totalDebitos   = self::parsearImporte($r1[2]);
        $finalBalance   = self::parsearImporte($r1[3]);

        // Línea 3: vacía. Línea 4: header del detalle.
        do {
            $h2 = fgetcsv($handle, 0, ';');
        } while ($h2 !== false && count($h2) === 1 && trim((string) $h2[0]) === '');

        if ($h2 === false || count($h2) < 5 ||
            mb_strtoupper(trim($h2[0])) !== 'RELEASE_DATE') {
            fclose($handle);
            throw new DomainException('FORMATO_INVALIDO: header detalle MP inválido (esperaba RELEASE_DATE)');
        }

        $movimientos = [];
        $fechaDesde = null;
        $fechaHasta = null;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') continue;
            while (count($row) < 5) $row[] = null;

            [$fechaStr, $tipo, $refId, $importeStr, $saldoStr] = $row;
            if (! $fechaStr) continue;

            $fecha = $this->parsearFecha($fechaStr);
            $importe = self::parsearImporte($importeStr);
            $saldo   = self::parsearImporte($saldoStr);
            if ($importe === null) continue;

            $debito  = $importe < 0 ? abs($importe) : null;
            $credito = $importe > 0 ? $importe : null;

            $mov = new MovimientoParseado(
                fecha: $fecha,
                concepto: trim((string) $tipo),
                debito: $debito,
                credito: $credito,
                saldo: $saldo,
                referencia: $refId ? trim((string) $refId) : null,
            );
            $mov->hashLinea = self::hashLinea($cuenta->id, $mov);
            $movimientos[] = $mov;

            if ($fechaDesde === null || $fecha->lt($fechaDesde)) $fechaDesde = $fecha;
            if ($fechaHasta === null || $fecha->gt($fechaHasta)) $fechaHasta = $fecha;
        }
        fclose($handle);

        if (empty($movimientos)) {
            throw new DomainException('FORMATO_INVALIDO: archivo MP sin movimientos');
        }

        // Validar saldo balance: INITIAL + CREDITS + DEBITS ≈ FINAL.
        $errores = [];
        if ($initialBalance !== null && $finalBalance !== null
            && $totalCreditos !== null && $totalDebitos !== null) {
            $check = round($initialBalance + $totalCreditos + $totalDebitos, 2);
            if (abs($check - $finalBalance) > 0.01) {
                $errores[] = sprintf(
                    'BALANCE_INCONSISTENTE: %.2f + %.2f + %.2f = %.2f no coincide con FINAL_BALANCE %.2f',
                    $initialBalance, $totalCreditos, $totalDebitos, $check, $finalBalance
                );
            }
        }

        // Reordenar por (fecha, saldo asc).
        usort($movimientos, function (MovimientoParseado $a, MovimientoParseado $b) {
            $c = $a->fecha->timestamp <=> $b->fecha->timestamp;
            if ($c !== 0) return $c;
            return ($a->saldo ?? 0) <=> ($b->saldo ?? 0);
        });

        return new ExtractoParseado(
            hashArchivo: self::hashArchivo($path),
            nombreArchivo: basename($path),
            fechaDesde: $fechaDesde,
            fechaHasta: $fechaHasta,
            saldoInicial: $initialBalance,
            saldoFinal: $finalBalance,
            movimientos: $movimientos,
            errores: $errores,
            monedaDetectada: 'ARS',
            numeroCuentaDetectado: null,
        );
    }

    private function parsearFecha(string $s): Carbon
    {
        try {
            return Carbon::createFromFormat('d-m-Y', trim($s))->startOfDay();
        } catch (\Throwable) {
            throw new DomainException("FORMATO_INVALIDO: fecha MP inválida '{$s}'");
        }
    }
}
