<?php

namespace App\Erp\Services\Tesoreria\Parsers;

use DomainException;

/**
 * Resuelve un parser concreto a partir de codigo_parser (columna en
 * erp_bancos). Si se agrega un banco nuevo, solo hace falta seedar
 * erp_bancos y registrar el mapeo acá.
 */
class ParserFactory
{
    /**
     * @var array<string, class-string<ParserInterface>>
     */
    private const MAP = [
        ParserIcbc::CODIGO => ParserIcbc::class,
        ParserGalicia::CODIGO => ParserGalicia::class,
        ParserBrubankCc::CODIGO => ParserBrubankCc::class,
        ParserBrubankRem::CODIGO => ParserBrubankRem::class,
        ParserMercadoPago::CODIGO => ParserMercadoPago::class,
        ParserEfectivo::CODIGO => ParserEfectivo::class,
    ];

    public function make(string $codigoParser): ParserInterface
    {
        $class = self::MAP[$codigoParser] ?? null;
        if (! $class) {
            throw new DomainException("PARSER_DESCONOCIDO: no hay parser registrado para codigo={$codigoParser}");
        }

        return new $class();
    }

    /**
     * @return array<int, string>
     */
    public function codigosSoportados(): array
    {
        return array_keys(self::MAP);
    }
}
