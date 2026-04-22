<?php

namespace App\Erp\Services\Tesoreria\Parsers;

use Illuminate\Support\Carbon;

/**
 * Movimiento individual devuelto por un parser, antes de persistirse en
 * erp_movimientos_bancarios. Los parsers NO tocan la DB directamente — se
 * limitan a transformar el archivo bancario en una lista de DTOs.
 *
 * hash_linea se calcula luego en AbstractParser::hashLinea() una vez que se
 * conoce cuenta_bancaria_id.
 */
final class MovimientoParseado
{
    public function __construct(
        public readonly Carbon $fecha,
        public readonly string $concepto,
        public readonly ?float $debito,
        public readonly ?float $credito,
        public readonly ?float $saldo,
        public readonly ?string $codConcepto = null,
        public readonly ?string $comprobanteBanco = null,
        public readonly ?string $canal = null,
        public readonly ?string $bancoContraparte = null,
        public readonly ?string $cbuContraparte = null,
        public readonly ?string $cuitContraparte = null,
        public readonly ?string $nombreContraparte = null,
        public readonly ?string $referencia = null,
        public readonly ?string $infoComplementaria = null,
        public ?string $hashLinea = null,
    ) {}

    public function toArray(): array
    {
        return [
            'fecha' => $this->fecha->toDateString(),
            'concepto' => $this->concepto,
            'debito' => $this->debito,
            'credito' => $this->credito,
            'saldo' => $this->saldo,
            'cod_concepto' => $this->codConcepto,
            'comprobante_banco' => $this->comprobanteBanco,
            'canal' => $this->canal,
            'banco_contraparte' => $this->bancoContraparte,
            'cbu_contraparte' => $this->cbuContraparte,
            'cuit_contraparte' => $this->cuitContraparte,
            'nombre_contraparte' => $this->nombreContraparte,
            'referencia' => $this->referencia,
            'info_complementaria' => $this->infoComplementaria,
            'hash_linea' => $this->hashLinea,
        ];
    }
}
