<?php

namespace App\Erp\Services\Tesoreria\Parsers;

use App\Erp\Models\Tesoreria\CuentaBancaria;

/**
 * Contrato común de los parsers bancarios.
 *
 * Cada banco es una clase concreta (ParserIcbc, ParserBrubankCc, ...)
 * seleccionada por ParserFactory a partir de erp_bancos.codigo_parser.
 *
 * Responsabilidades:
 *  - Leer el archivo en su formato nativo (CSV, XLSX, etc).
 *  - Validar cabecera y consistencia interna (saldo corrido).
 *  - Devolver un ExtractoParseado con N MovimientoParseado listos para
 *    persistirse, sin tocar la DB.
 *  - Lanzar DomainException con un código prefijo identificable
 *    (FORMATO_INVALIDO:, SALDO_CORRIDO_INCONSISTENTE:, etc.) cuando la
 *    situación impide continuar.
 */
interface ParserInterface
{
    public function codigoParser(): string;

    public function parse(string $path, CuentaBancaria $cuenta): ExtractoParseado;
}
