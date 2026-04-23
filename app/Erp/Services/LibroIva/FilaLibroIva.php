<?php

namespace App\Erp\Services\LibroIva;

use Illuminate\Support\Carbon;

/**
 * DTO de una fila parseada del Libro IVA (ARCA). Inmutable.
 * Los importes vienen en signo contable del libro (positivos para facturas,
 * negativos implícitos para NCs según tipo_cbte).
 */
final class FilaLibroIva
{
    public function __construct(
        public readonly Carbon $fecha,
        public readonly int $tipoCbte,        // código AFIP (1=FA, 6=FB, etc.)
        public readonly int $ptoVta,
        public readonly int $nroCbte,
        public readonly string $cuitContraparte,
        public readonly ?string $razonSocial,
        public readonly float $impNetoGravado,
        public readonly float $impNoGravado,
        public readonly float $impExento,
        public readonly float $impIva,
        public readonly float $impPercepciones,
        public readonly float $impTotal,
        public readonly ?string $cae,
        public readonly ?Carbon $fechaVtoCae,
        public readonly array $rawRow,
    ) {}

    public function toArray(): array
    {
        return [
            'fecha' => $this->fecha->toDateString(),
            'tipo_cbte' => $this->tipoCbte,
            'pto_vta' => $this->ptoVta,
            'nro_cbte' => $this->nroCbte,
            'cuit_contraparte' => $this->cuitContraparte,
            'razon_social' => $this->razonSocial,
            'imp_neto_gravado' => $this->impNetoGravado,
            'imp_no_gravado' => $this->impNoGravado,
            'imp_exento' => $this->impExento,
            'imp_iva' => $this->impIva,
            'imp_percepciones' => $this->impPercepciones,
            'imp_total' => $this->impTotal,
            'cae' => $this->cae,
            'fecha_vto_cae' => $this->fechaVtoCae?->toDateString(),
        ];
    }
}
