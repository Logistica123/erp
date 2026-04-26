<?php

namespace Tests\Feature\Cierres;

use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Services\Tesoreria\Parsers\ParserBrubankCc;
use App\Erp\Services\Tesoreria\Parsers\ParserBrubankRem;
use App\Erp\Services\Tesoreria\Parsers\ParserIcbc;
use App\Erp\Services\Tesoreria\Parsers\ParserMercadoPago;
use Tests\TestCase;

/**
 * Tests de los 3 parsers contra fixtures reales (marzo 2026).
 * Validan: cantidad de movs, saldo inicial, saldo final, fechas extremas
 * y casos edge específicos del banco (APERTURA en ICBC USD, separación
 * de cuentas en Brubank, balance explícito en MP).
 */
class ParsersTest extends TestCase
{
    private function fixturePath(string $rel): string
    {
        return base_path('tests/Fixtures/cierres/'.$rel);
    }

    private function cuentaMock(string $codigo, string $moneda = 'ARS', int $id = 999): CuentaBancaria
    {
        $c = new CuentaBancaria();
        $c->forceFill([
            'id' => $id, 'empresa_id' => 1, 'codigo' => $codigo,
            'cuenta_contable_id' => 1, 'moneda_id' => $moneda === 'ARS' ? 1 : 2,
        ]);
        $c->setRelation('moneda', (object) ['codigo' => $moneda]);
        return $c;
    }

    public function test_icbc_cc_ars_parsea_541_movs_marzo_2026(): void
    {
        $parser = new ParserIcbc();
        $r = $parser->parse(
            $this->fixturePath('icbc/20260419_1808_00150535000210457356_movimientos.csv'),
            $this->cuentaMock('ICBC-CC')
        );

        $this->assertCount(541, $r->movimientos);
        $this->assertSame('ARS', $r->monedaDetectada);
        $this->assertSame('0535/02104573/56', $r->numeroCuentaDetectado);
        $this->assertEquals('2026-03-02', $r->fechaDesde->toDateString());
        $this->assertEquals('2026-03-31', $r->fechaHasta->toDateString());
        $this->assertEquals(952397.61, round($r->saldoInicial, 2));
        $this->assertEquals(5781522.17, round($r->saldoFinal, 2));
    }

    public function test_icbc_ce_usd_detecta_apertura_y_4_movs(): void
    {
        $parser = new ParserIcbc();
        $r = $parser->parse(
            $this->fixturePath('icbc/20260419_1809_00150535001111194141_movimientos.csv'),
            $this->cuentaMock('ICBC-CE-USD', 'USD', 998)
        );

        $this->assertCount(4, $r->movimientos);
        $this->assertSame('USD', $r->monedaDetectada);
        $this->assertEquals(0.0, $r->saldoInicial); // APERTURA → saldo_inicial = 0
        $this->assertEquals(98.25, round($r->saldoFinal, 2));
    }

    public function test_brubank_cc_filtra_solo_cuenta_corriente(): void
    {
        $parser = new ParserBrubankCc();
        $r = $parser->parse(
            $this->fixturePath('brubank/2026-03_estado_cuenta.csv'),
            $this->cuentaMock('BR-CC')
        );

        $this->assertCount(127, $r->movimientos);
        $this->assertEquals('2026-03-02', $r->fechaDesde->toDateString());
        $this->assertEquals('2026-03-31', $r->fechaHasta->toDateString());
        $this->assertEquals(976834.03, round($r->saldoInicial, 2));
        $this->assertEquals(124815503.34, round($r->saldoFinal, 2));
    }

    public function test_brubank_rem_filtra_solo_cuenta_remunerada(): void
    {
        $parser = new ParserBrubankRem();
        $r = $parser->parse(
            $this->fixturePath('brubank/2026-03_estado_cuenta.csv'),
            $this->cuentaMock('BR-REM', 'ARS', 901)
        );

        $this->assertCount(59, $r->movimientos);
        $this->assertEquals(44443123.69, round($r->saldoInicial, 2));
        $this->assertEquals(109562165.75, round($r->saldoFinal, 2));
    }

    public function test_brubank_total_186_filas_sumando_cc_y_rem(): void
    {
        // El CSV combinado tiene header + 186 filas de datos.
        $cc = (new ParserBrubankCc())->parse(
            $this->fixturePath('brubank/2026-03_estado_cuenta.csv'),
            $this->cuentaMock('BR-CC')
        );
        $rem = (new ParserBrubankRem())->parse(
            $this->fixturePath('brubank/2026-03_estado_cuenta.csv'),
            $this->cuentaMock('BR-REM', 'ARS', 901)
        );
        $this->assertSame(186, count($cc->movimientos) + count($rem->movimientos));
    }

    public function test_mercadopago_lee_balance_inicial_explicito(): void
    {
        $parser = new ParserMercadoPago();
        $r = $parser->parse(
            $this->fixturePath('mp/2026-03_account_statement.csv'),
            $this->cuentaMock('MP', 'ARS', 800)
        );

        // El bloque resumen del CSV: INITIAL_BALANCE=16868.97, FINAL_BALANCE=2514.02.
        $this->assertEquals(16868.97, round($r->saldoInicial, 2));
        $this->assertEquals(2514.02, round($r->saldoFinal, 2));
        $this->assertCount(69, $r->movimientos);
        $this->assertEmpty($r->errores, 'Validación INITIAL+CREDITS+DEBITS=FINAL debe pasar');
    }

    public function test_mercadopago_detecta_par_pasante_por_reference_id(): void
    {
        $parser = new ParserMercadoPago();
        $r = $parser->parse(
            $this->fixturePath('mp/2026-03_account_statement.csv'),
            $this->cuentaMock('MP', 'ARS', 800)
        );

        // Operación pasante: REFERENCE_ID 148626258246 aparece 2 veces
        // (Ingreso de dinero $26.607,50 + Pago de servicio Aguas $-26.607,50).
        $pasantes = array_filter($r->movimientos, fn ($m) => $m->referencia === '148626258246');
        $this->assertCount(2, $pasantes, 'MP pasante: par crédito + débito con mismo REFERENCE_ID');

        $debitos = array_filter($pasantes, fn ($m) => $m->debito !== null);
        $creditos = array_filter($pasantes, fn ($m) => $m->credito !== null);
        $this->assertCount(1, $debitos);
        $this->assertCount(1, $creditos);
        $this->assertEquals(26607.5, (float) array_values($debitos)[0]->debito);
        $this->assertEquals(26607.5, (float) array_values($creditos)[0]->credito);
    }
}
