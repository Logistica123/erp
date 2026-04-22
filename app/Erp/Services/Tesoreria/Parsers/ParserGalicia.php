<?php

namespace App\Erp\Services\Tesoreria\Parsers;

class ParserGalicia extends ParserStubPendiente
{
    public const CODIGO = 'GALICIA';

    public function codigoParser(): string
    {
        return self::CODIGO;
    }
}
