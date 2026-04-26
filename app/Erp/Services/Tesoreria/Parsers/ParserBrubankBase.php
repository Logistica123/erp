<?php

namespace App\Erp\Services\Tesoreria\Parsers;

use App\Erp\Models\Tesoreria\CuentaBancaria;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Base compartida para los 2 parsers de Brubank (CC + Remunerada).
 * Especificación: /arquitecturas/09_Datos_Reales/extractos_bancarios/Brubank/FORMATO_BRUBANK.md
 *
 * Características:
 *  - CSV UTF-8, separador `;`, decimal `,`, miles `.`, prefijo `$ ` en importes.
 *  - Fecha `DD-MM-YY` (2 dígitos de año) → 26 = 2026 (umbral 50: <50 = 20XX, >=50 = 19XX).
 *  - 8 columnas. Importe ausente representado como `-`.
 *  - El CSV mezcla 2 cuentas (Cuenta corriente + Cuenta remunerada);
 *    cada subclase filtra por la suya y deriva su propio saldo_inicial.
 *  - Pares de transferencias internas con misma `Referencia` (no se procesan
 *    acá — la detección cross-cuenta vive en el detector de transferencias).
 */
abstract class ParserBrubankBase extends AbstractParser
{
    /** Etiqueta esperada en la columna `Cuenta` (subclase): "Cuenta corriente" o "Cuenta remunerada". */
    abstract protected function nombreCuenta(): string;

    public function parse(string $path, CuentaBancaria $cuenta): ExtractoParseado
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new DomainException("FORMATO_INVALIDO: no se pudo abrir el archivo {$path}");
        }

        $headerRaw = fgetcsv($handle, 0, ';');
        if ($headerRaw === false || count($headerRaw) < 8) {
            fclose($handle);
            throw new DomainException('FORMATO_INVALIDO: header de columnas Brubank inválido');
        }
        $headerEsperado = ['Fecha','Referencia','Descripcion','Moneda','Creditos','Debitos','Saldo','Cuenta'];
        for ($i = 0; $i < 8; $i++) {
            if (mb_strtolower(trim($headerRaw[$i] ?? '')) !== mb_strtolower($headerEsperado[$i])) {
                fclose($handle);
                throw new DomainException(sprintf(
                    'FORMATO_INVALIDO: header Brubank col %d esperaba "%s" recibió "%s"',
                    $i + 1, $headerEsperado[$i], $headerRaw[$i] ?? '(vacío)'
                ));
            }
        }

        // Lee y filtra por nombre de cuenta. El CSV viene reverse-cronológico
        // dentro de cada cuenta — almacenamos tal cual y reordenamos al final.
        $filas = [];
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            while (count($row) < 8) {
                $row[] = null;
            }
            $cuentaCsv = trim((string) $row[7]);
            if (mb_strtolower($cuentaCsv) !== mb_strtolower($this->nombreCuenta())) {
                continue;
            }
            $filas[] = $row;
        }
        fclose($handle);

        if (empty($filas)) {
            throw new DomainException(sprintf(
                'FORMATO_INVALIDO: no hay filas de "%s" en el archivo', $this->nombreCuenta()
            ));
        }

        $movimientos = [];
        $fechaDesde = null;
        $fechaHasta = null;

        foreach ($filas as $row) {
            [$fechaStr, $referencia, $descripcion, $moneda, $credStr, $debStr, $saldoStr, /* cuenta */] = $row;
            if (! $fechaStr) {
                continue;
            }
            $fecha = $this->parsearFecha($fechaStr);
            $credito = $this->parsearImporteBrubank($credStr);
            $debito  = $this->parsearImporteBrubank($debStr);
            $saldo   = $this->parsearImporteBrubank($saldoStr);

            $mov = new MovimientoParseado(
                fecha: $fecha,
                concepto: trim((string) $descripcion),
                debito: $debito ?: null,
                credito: $credito ?: null,
                saldo: $saldo,
                comprobanteBanco: null,
                referencia: $referencia ? trim((string) $referencia) : null,
            );
            $mov->hashLinea = self::hashLinea($cuenta->id, $mov);
            $movimientos[] = $mov;

            if ($fechaDesde === null || $fecha->lt($fechaDesde)) $fechaDesde = $fecha;
            if ($fechaHasta === null || $fecha->gt($fechaHasta)) $fechaHasta = $fecha;
        }

        // Reordenar por (fecha asc, saldo asc) — el CSV viene inverso.
        usort($movimientos, function (MovimientoParseado $a, MovimientoParseado $b) {
            $c = $a->fecha->timestamp <=> $b->fecha->timestamp;
            if ($c !== 0) return $c;
            return ($a->saldo ?? 0) <=> ($b->saldo ?? 0);
        });

        // Derivar saldo_inicial del primer movimiento (Brubank no trae línea explícita).
        $primero = $movimientos[0];
        $saldoInicial = $primero->saldo !== null
            ? round($primero->saldo - (($primero->credito ?? 0) - ($primero->debito ?? 0)), 2)
            : null;

        $ultimo = end($movimientos);
        $saldoFinal = $ultimo->saldo;

        return new ExtractoParseado(
            hashArchivo: self::hashArchivo($path),
            nombreArchivo: basename($path),
            fechaDesde: $fechaDesde,
            fechaHasta: $fechaHasta,
            saldoInicial: $saldoInicial,
            saldoFinal: $saldoFinal,
            movimientos: $movimientos,
            errores: [],
            monedaDetectada: 'ARS',
            numeroCuentaDetectado: null,
        );
    }

    /**
     * Brubank trae importes con prefijo "$ " y "-" como ausente. Adapta
     * antes de delegar al helper genérico parsearImporte().
     */
    private function parsearImporteBrubank(?string $raw): ?float
    {
        if ($raw === null) return null;
        $s = trim($raw);
        if ($s === '' || $s === '-') return null;
        // Quitar prefijo $ con o sin espacios.
        $s = preg_replace('/^\$\s*/u', '', $s) ?? $s;
        return self::parsearImporte($s);
    }

    /**
     * Brubank: `DD-MM-YY` con 2 dígitos de año.
     * Convención: <50 → 20XX, >=50 → 19XX (umbral configurable si hace falta).
     */
    private function parsearFecha(string $s): Carbon
    {
        $s = trim($s);
        if (! preg_match('/^(\d{2})-(\d{2})-(\d{2})$/', $s, $m)) {
            throw new DomainException("FORMATO_INVALIDO: fecha Brubank '{$s}' no matchea DD-MM-YY");
        }
        $yy = (int) $m[3];
        $year = $yy < 50 ? 2000 + $yy : 1900 + $yy;
        try {
            return Carbon::create($year, (int) $m[2], (int) $m[1])->startOfDay();
        } catch (\Throwable) {
            throw new DomainException("FORMATO_INVALIDO: fecha Brubank inválida '{$s}'");
        }
    }
}
