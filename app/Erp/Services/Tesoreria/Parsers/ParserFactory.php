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
        // GALICIA removido en v1.55 Bloque B — banco inactivo desde 2026-06-13.
        // Si se reactiva la cuenta, volver a registrar el parser acá.
        ParserBrubankCc::CODIGO => ParserBrubankCc::class,
        ParserBrubankRem::CODIGO => ParserBrubankRem::class,
        ParserMercadoPago::CODIGO => ParserMercadoPago::class,
        // EFECTIVO removido en v1.55 Bloque B (2ª pasada) — es pago en
        // efectivo puro, no existe extracto que importar. Los movimientos de
        // la caja van por Arqueos/Cierres, no por acá.
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
