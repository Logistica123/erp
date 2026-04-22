<?php

namespace Tests\Unit\Tesoreria;

use App\Erp\Models\Moneda;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Services\Tesoreria\Parsers\AbstractParser;
use App\Erp\Services\Tesoreria\Parsers\ParserIcbc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios del ParserIcbc (SPEC 02 §12). Los fixtures son los dos CSVs
 * reales que Matías subió el 2026-04-19 (copiados en tests/Fixtures/icbc/).
 */
class ParserIcbcTest extends TestCase
{
    private const FIXTURE_ARS = __DIR__.'/../../Fixtures/icbc/20260419_1808_00150535000210457356_movimientos.csv';
    private const FIXTURE_USD = __DIR__.'/../../Fixtures/icbc/20260419_1809_00150535001111194141_movimientos.csv';

    public static function importeProvider(): array
    {
        return [
            'positivo simple' => ['3441,19', 3441.19],
            'negativo' => ['-20,65', -20.65],
            'cero con coma' => [',00', 0.00],
            'vacio' => ['', null],
            'null' => [null, null],
            'con separador miles' => ['1.234.567,89', 1234567.89],
            'solo decimal' => ['0,01', 0.01],
        ];
    }

    #[DataProvider('importeProvider')]
    public function test_parsea_importe(?string $raw, ?float $esperado): void
    {
        $this->assertSame($esperado, AbstractParser::parsearImporte($raw));
    }

    public function test_normaliza_concepto_con_espacios_y_acentos(): void
    {
        $this->assertSame(
            'TRANSF CONNBKG CRÉDITO',
            AbstractParser::normalizarConcepto("  transf   connbkg  \tCrédito  ")
        );
    }

    public function test_parsea_fixture_usd_con_apertura(): void
    {
        $cuenta = $this->fakeCuenta('USD', 6);
        $parser = new ParserIcbc();
        $extracto = $parser->parse(self::FIXTURE_USD, $cuenta);

        $this->assertSame('USD', $extracto->monedaDetectada);
        $this->assertSame('0535/11111941/41', $extracto->numeroCuentaDetectado);

        // La fila APERTURA fija saldo_inicial = 0 y no se cuenta como movimiento.
        $this->assertSame(0.0, $extracto->saldoInicial);
        $this->assertGreaterThan(0, count($extracto->movimientos));
        foreach ($extracto->movimientos as $m) {
            $this->assertNotSame('656', $m->codConcepto, 'APERTURA no debe aparecer en movimientos');
        }
    }

    public function test_parsea_fixture_ars_deriva_saldo_inicial_sin_apertura(): void
    {
        $cuenta = $this->fakeCuenta('ARS', 1);
        $parser = new ParserIcbc();
        $extracto = $parser->parse(self::FIXTURE_ARS, $cuenta);

        $this->assertSame('ARS', $extracto->monedaDetectada);
        $this->assertNotNull($extracto->saldoInicial, 'debe derivar saldo_inicial del primer movimiento del archivo');

        // Sin fila APERTURA, saldo_inicial se deriva del primer movimiento
        // cronológico del archivo tal como vino (no necesariamente = saldo
        // oficial del cierre del mes anterior, si el archivo no incluye el
        // día 1 completo). Verificamos que siga la fórmula.
        $primerMov = $extracto->movimientos[0];
        $calculado = round(
            $primerMov->saldo - (($primerMov->credito ?? 0) - ($primerMov->debito ?? 0)),
            2
        );
        $this->assertEqualsWithDelta($calculado, $extracto->saldoInicial, 0.01);
    }

    public function test_hash_archivo_es_sha256_deterministico(): void
    {
        $hash1 = AbstractParser::hashArchivo(self::FIXTURE_USD);
        $hash2 = AbstractParser::hashArchivo(self::FIXTURE_USD);

        $this->assertSame($hash1, $hash2);
        $this->assertSame(64, strlen($hash1));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash1);
    }

    public function test_hash_linea_deterministico_entre_ejecuciones(): void
    {
        $cuenta = $this->fakeCuenta('USD', 6);
        $parser = new ParserIcbc();
        $a = $parser->parse(self::FIXTURE_USD, $cuenta);
        $b = $parser->parse(self::FIXTURE_USD, $cuenta);

        foreach ($a->movimientos as $i => $ma) {
            $this->assertSame($ma->hashLinea, $b->movimientos[$i]->hashLinea);
            $this->assertNotEmpty($ma->hashLinea);
        }
    }

    public function test_rechaza_moneda_del_header_distinta_a_la_cuenta(): void
    {
        $cuenta = $this->fakeCuenta('USD', 6);
        $parser = new ParserIcbc();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/FORMATO_INVALIDO/');
        // ARS fixture contra una cuenta USD → debe rechazar
        $parser->parse(self::FIXTURE_ARS, $cuenta);
    }

    public function test_verifica_saldo_corrido_consistente_en_fixture_real(): void
    {
        $cuenta = $this->fakeCuenta('USD', 6);
        $parser = new ParserIcbc();
        $extracto = $parser->parse(self::FIXTURE_USD, $cuenta);

        // El fixture real debe pasar la validación (es un extracto oficial).
        $this->assertEmpty(
            $extracto->errores,
            'Fixture real ICBC debe validar saldo corrido sin errores: '.implode(' | ', $extracto->errores)
        );
    }

    private function fakeCuenta(string $codigoMoneda, int $monedaId): CuentaBancaria
    {
        $moneda = new Moneda(['codigo' => $codigoMoneda, 'nombre' => $codigoMoneda]);
        $moneda->id = $monedaId;

        $cuenta = new CuentaBancaria([
            'empresa_id' => 1,
            'codigo' => 'FIXTURE',
            'moneda_id' => $monedaId,
        ]);
        $cuenta->id = 999;
        $cuenta->setRelation('moneda', $moneda);

        return $cuenta;
    }
}
