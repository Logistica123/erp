<?php

namespace App\Erp\Services\Seguros;

use DomainException;

/**
 * Resuelve el parser de seguro adecuado para un PDF según la aseguradora.
 * Para sumar una compañía nueva: crear su Parser que implemente
 * ParserSeguroInterface y registrarlo acá.
 */
class ParserSeguroFactory
{
    /** @return ParserSeguroInterface[] */
    private function parsers(): array
    {
        return [
            new ParserSeguroLaSegunda(),
        ];
    }

    public function resolver(string $texto): ParserSeguroInterface
    {
        foreach ($this->parsers() as $p) {
            if ($p->reconoce($texto)) return $p;
        }
        throw new DomainException('ASEGURADORA_NO_RECONOCIDA: el PDF no coincide con ninguna aseguradora soportada (hoy: La Segunda).');
    }

    /** @return string[] */
    public function soportadas(): array
    {
        return array_map(fn ($p) => $p->aseguradora(), $this->parsers());
    }
}
