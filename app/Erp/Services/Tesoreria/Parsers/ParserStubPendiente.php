<?php

namespace App\Erp\Services\Tesoreria\Parsers;

use App\Erp\Models\Tesoreria\CuentaBancaria;
use DomainException;

/**
 * Stub para bancos cuya especificación de archivo aún no está documentada.
 * Mantiene la infraestructura (factory, controller, UI) funcionando sin
 * bloqueo: al intentar importar, devuelve un error explícito pidiendo que
 * se provea un archivo de muestra y se complete la spec.
 *
 * Cada banco pendiente extiende esta clase y define su codigoParser().
 * Cuando la spec se documente, se reemplaza por una implementación real
 * que extiende AbstractParser (ver ParserIcbc como referencia).
 */
abstract class ParserStubPendiente extends AbstractParser
{
    public function parse(string $path, CuentaBancaria $cuenta): ExtractoParseado
    {
        throw new DomainException(sprintf(
            'FORMATO_NO_DOCUMENTADO: parser %s pendiente de especificación. '.
            'Adjuntar archivo de muestra a /arquitecturas/09_Datos_Reales/extractos_bancarios/%s/ '.
            'y documentar en FORMATO_*.md (ver ICBC como referencia).',
            $this->codigoParser(),
            $this->codigoParser()
        ));
    }
}
