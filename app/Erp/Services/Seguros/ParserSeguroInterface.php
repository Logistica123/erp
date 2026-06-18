<?php

namespace App\Erp\Services\Seguros;

interface ParserSeguroInterface
{
    public function aseguradora(): string;

    /** ¿Este parser reconoce el PDF (por CUIT o nombre de la aseguradora)? */
    public function reconoce(string $texto): bool;

    /** @return array<string,mixed> datos extraídos + mapeo Libro IVA Compras */
    public function parse(string $texto): array;
}
