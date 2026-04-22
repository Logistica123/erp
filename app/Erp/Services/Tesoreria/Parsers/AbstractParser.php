<?php

namespace App\Erp\Services\Tesoreria\Parsers;

abstract class AbstractParser implements ParserInterface
{
    /**
     * SHA-256 del contenido del archivo (RN-12: idempotencia).
     */
    public static function hashArchivo(string $path): string
    {
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            throw new \RuntimeException("No se pudo calcular el hash del archivo: {$path}");
        }

        return $hash;
    }

    /**
     * Normaliza el concepto a upper/trim/collapse para que cambios de
     * whitespace del banco no generen hash diferentes.
     */
    public static function normalizarConcepto(string $s): string
    {
        $s = trim($s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);

        return mb_strtoupper($s, 'UTF-8');
    }

    /**
     * Parsea un importe en formato latinoamericano: "3.441,19" o "3441,19" o
     * "-20,65" o "" (vacío). Devuelve null para vacío.
     */
    public static function parsearImporte(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Remover separador de miles y convertir decimal.
        $normalizado = str_replace(['.', ','], ['', '.'], $raw);
        if (! is_numeric($normalizado)) {
            return null;
        }

        return (float) $normalizado;
    }

    /**
     * Calcula el hash de una línea usado como deduplicación por
     * (cuenta_bancaria_id, hash_linea). Determinístico: las mismas entradas
     * producen el mismo hash entre ejecuciones.
     */
    public static function hashLinea(int $cuentaBancariaId, MovimientoParseado $m): string
    {
        $payload = implode('|', [
            $cuentaBancariaId,
            $m->fecha->toDateString(),
            $m->codConcepto ?? '',
            self::normalizarConcepto($m->concepto),
            (string) ($m->debito ?? 0),
            (string) ($m->credito ?? 0),
            (string) ($m->saldo ?? ''),
            $m->comprobanteBanco ?? '',
            $m->cbuContraparte ?? '',
            $m->cuitContraparte ?? '',
        ]);

        return hash('sha256', $payload);
    }

    /**
     * Verifica el saldo corrido de una lista de movimientos cronológicos
     * (más antiguo primero). Devuelve lista de errores (vacía si OK).
     */
    public static function verificarSaldoCorrido(array $movsCronologicos, ?float $saldoInicial, float $tolerancia = 0.01): array
    {
        if ($saldoInicial === null || empty($movsCronologicos)) {
            return [];
        }

        $errores = [];
        $saldoCalc = $saldoInicial;
        foreach ($movsCronologicos as $idx => $m) {
            /** @var MovimientoParseado $m */
            $saldoCalc = round($saldoCalc + ($m->credito ?? 0) - ($m->debito ?? 0), 2);
            if ($m->saldo !== null && abs($saldoCalc - $m->saldo) > $tolerancia) {
                $errores[] = sprintf(
                    'Saldo corrido inconsistente en mov #%d: esperado=%.2f encontrado=%.2f (dif=%.2f)',
                    $idx + 1,
                    $saldoCalc,
                    $m->saldo,
                    $m->saldo - $saldoCalc
                );
                break; // No tiene sentido seguir reportando el resto
            }
        }

        return $errores;
    }
}
