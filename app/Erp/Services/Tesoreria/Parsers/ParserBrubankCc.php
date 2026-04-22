<?php

namespace App\Erp\Services\Tesoreria\Parsers;

class ParserBrubankCc extends ParserStubPendiente
{
    public const CODIGO = 'BRUBANK_CC';

    public function codigoParser(): string
    {
        return self::CODIGO;
    }
}
