<?php

namespace App\Erp\Services\Tesoreria\Parsers;

class ParserMercadoPago extends ParserStubPendiente
{
    public const CODIGO = 'MERCADO_PAGO';

    public function codigoParser(): string
    {
        return self::CODIGO;
    }
}
