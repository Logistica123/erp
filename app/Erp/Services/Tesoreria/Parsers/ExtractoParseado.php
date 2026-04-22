<?php

namespace App\Erp\Services\Tesoreria\Parsers;

use Illuminate\Support\Carbon;

/**
 * Resultado estructurado de un parser bancario. Inmutable.
 *
 * errores contiene advertencias blandas (ej. "faltó cotización USD", "fila 42
 * con saldo inconsistente"). Si el parser decide abortar por inconsistencia
 * grave, lanza DomainException antes de devolver el ExtractoParseado.
 */
final class ExtractoParseado
{
    /**
     * @param  array<int, MovimientoParseado>  $movimientos
     * @param  array<int, string>  $errores
     */
    public function __construct(
        public readonly string $hashArchivo,
        public readonly string $nombreArchivo,
        public readonly Carbon $fechaDesde,
        public readonly Carbon $fechaHasta,
        public readonly ?float $saldoInicial,
        public readonly ?float $saldoFinal,
        public readonly array $movimientos,
        public readonly array $errores = [],
        public readonly ?string $monedaDetectada = null,
        public readonly ?string $numeroCuentaDetectado = null,
    ) {}
}
