<?php

namespace Tests\Feature\ControlFacturas;

use App\Erp\Services\ControlFacturas\ControlFacturaService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Auditoría 2026-07-12, bug #3: si el WS de padrón APOC está caído
 * (apoc='ERROR'), el resultado global NO puede ser VALIDA — el propósito
 * del módulo es anti-fraude y "no pude consultar la lista de apócrifos"
 * debe quedar como REVISAR para que el operador decida.
 */
class ConsolidarResultadoTest extends TestCase
{
    private function consolidar(string $wscdc, string $apoc): string
    {
        $svc = app(ControlFacturaService::class);
        $m = new ReflectionMethod($svc, 'consolidar');
        $m->setAccessible(true);

        return $m->invoke($svc, $wscdc, $apoc);
    }

    public function test_wscdc_ok_y_apoc_limpio_es_valida(): void
    {
        $this->assertSame('VALIDA', $this->consolidar('A', 'NO_APOC'));
    }

    public function test_apoc_caido_NO_es_valida_sino_revisar(): void
    {
        // Bug original: WSCDC A + APOC ERROR devolvía VALIDA.
        $this->assertSame('REVISAR', $this->consolidar('A', 'ERROR'));
    }

    public function test_cuit_en_apoc_es_apocrifa_aunque_wscdc_apruebe(): void
    {
        $this->assertSame('APOCRIFA', $this->consolidar('A', 'EN_APOC'));
    }

    public function test_wscdc_rechazado_es_invalida(): void
    {
        $this->assertSame('INVALIDA', $this->consolidar('R', 'NO_APOC'));
    }

    public function test_wscdc_error_sigue_siendo_error(): void
    {
        $this->assertSame('ERROR', $this->consolidar('ERROR', 'NO_APOC'));
    }
}
