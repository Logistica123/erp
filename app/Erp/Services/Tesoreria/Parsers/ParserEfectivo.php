<?php

namespace App\Erp\Services\Tesoreria\Parsers;

class ParserEfectivo extends ParserStubPendiente
{
    public const CODIGO = 'EFECTIVO';

    public function codigoParser(): string
    {
        return self::CODIGO;
    }
}
