<?php

namespace App\Erp\Services\Tesoreria\Parsers;

/**
 * Parser Brubank — Cuenta remunerada (FCI / fondo común de inversiones).
 * Filtra del CSV las filas con `Cuenta=Cuenta remunerada`.
 */
class ParserBrubankRem extends ParserBrubankBase
{
    public const CODIGO = 'BRUBANK_REM';

    public function codigoParser(): string
    {
        return self::CODIGO;
    }

    protected function nombreCuenta(): string
    {
        return 'Cuenta remunerada';
    }
}
