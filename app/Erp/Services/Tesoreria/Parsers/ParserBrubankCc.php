<?php

namespace App\Erp\Services\Tesoreria\Parsers;

/**
 * Parser Brubank — Cuenta corriente (CC).
 * Filtra del CSV las filas con `Cuenta=Cuenta corriente`.
 */
class ParserBrubankCc extends ParserBrubankBase
{
    public const CODIGO = 'BRUBANK_CC';

    public function codigoParser(): string
    {
        return self::CODIGO;
    }

    protected function nombreCuenta(): string
    {
        return 'Cuenta corriente';
    }
}
