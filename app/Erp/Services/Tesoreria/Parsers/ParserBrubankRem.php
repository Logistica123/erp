<?php

namespace App\Erp\Services\Tesoreria\Parsers;

class ParserBrubankRem extends ParserStubPendiente
{
    public const CODIGO = 'BRUBANK_REM';

    public function codigoParser(): string
    {
        return self::CODIGO;
    }
}
