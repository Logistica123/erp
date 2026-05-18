<?php

namespace Tests\Unit;

use App\Erp\Services\GeneradorF8001Service;
use App\Erp\Support\AuditLogger;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * ADDENDUM v1.11 — Tests round-trip byte-perfect contra fixture real LIBER.
 *
 * Fixtures: F8001_marzo_2026_CBTE.txt (421 líneas × 325 chars) y
 *           F8001_marzo_2026_ALICUOTAS.txt (117 líneas × 84 chars).
 *
 * El round-trip parsea cada línea del fixture → reconstruye un objeto factura
 * → regenera la línea con el servicio → compara byte a byte.
 *
 * Es un test de unidad puro (PHPUnit\Framework\TestCase, no Laravel) porque
 * `lineaCbte` y `lineaAlicuotas` son funciones puras cuando se les pasa el
 * arreglo de alícuotas precalculado.
 */
class GeneradorF8001ServiceTest extends TestCase
{
    private GeneradorF8001Service $svc;
    private string $cbteFixture;
    private string $alicFixture;

    protected function setUp(): void
    {
        parent::setUp();
        // AuditLogger no se usa en lineaCbte/lineaAlicuotas — solo en generar().
        $this->svc = new GeneradorF8001Service(new AuditLogger());
        $base = __DIR__.'/../Fixtures/libro_iva_compras';
        $this->cbteFixture = $base.'/F8001_marzo_2026_CBTE.txt';
        $this->alicFixture = $base.'/F8001_marzo_2026_ALICUOTAS.txt';
    }

    public function test_fixtures_existen(): void
    {
        $this->assertFileExists($this->cbteFixture);
        $this->assertFileExists($this->alicFixture);
    }

    public function test_fixture_cbte_tiene_421_lineas_de_325_chars_crlf(): void
    {
        $raw = file_get_contents($this->cbteFixture);
        $lineas = explode("\r\n", rtrim($raw, "\r\n"));
        $this->assertCount(421, $lineas);
        foreach ($lineas as $i => $l) {
            $this->assertSame(325, strlen($l), "línea ".($i + 1)." no tiene 325 chars");
        }
    }

    public function test_fixture_alicuotas_tiene_117_lineas_de_84_chars_crlf(): void
    {
        $raw = file_get_contents($this->alicFixture);
        $lineas = explode("\r\n", rtrim($raw, "\r\n"));
        $this->assertCount(117, $lineas);
        foreach ($lineas as $i => $l) {
            $this->assertSame(84, strlen($l), "línea ".($i + 1)." no tiene 84 chars");
        }
    }

    /**
     * Round-trip CBTE: parsea cada línea del fixture → reconstruye factura
     * → regenera con el servicio → compara byte a byte.
     */
    public function test_round_trip_cbte_byte_perfect(): void
    {
        $raw = file_get_contents($this->cbteFixture);
        $lineas = explode("\r\n", rtrim($raw, "\r\n"));

        $alicuotasPorCbte = $this->indexarAlicuotasPorCbte();

        $totalDiffs = 0;
        $primerasDiffs = [];
        foreach ($lineas as $i => $original) {
            $f = $this->parsearLineaCbte($original);
            $key = $f->tipo_comprobante_id.'|'.$f->punto_venta.'|'.$f->numero.'|'.$f->cuit_emisor;
            $alicuotas = $alicuotasPorCbte[$key] ?? [];

            $regenerada = $this->svc->lineaCbte($f, $alicuotas);
            if ($regenerada !== $original) {
                $totalDiffs++;
                if (count($primerasDiffs) < 3) {
                    $primerasDiffs[] = sprintf(
                        "línea %d:\n  exp: %s\n  got: %s\n  pos: %s",
                        $i + 1, $original, $regenerada,
                        $this->primeraDiff($original, $regenerada)
                    );
                }
            }
        }

        $this->assertSame(0, $totalDiffs,
            "Diffs: $totalDiffs/421\n".implode("\n", $primerasDiffs));
    }

    /**
     * Round-trip ALICUOTAS: parsea cada línea del fixture → reconstruye
     * factura+alic → regenera → compara.
     */
    public function test_round_trip_alicuotas_byte_perfect(): void
    {
        $raw = file_get_contents($this->alicFixture);
        $lineas = explode("\r\n", rtrim($raw, "\r\n"));

        $totalDiffs = 0;
        $primerasDiffs = [];
        foreach ($lineas as $i => $original) {
            [$f, $alic] = $this->parsearLineaAlicuotas($original);
            $regenerada = $this->svc->lineaAlicuotas($f, $alic);
            if ($regenerada !== $original) {
                $totalDiffs++;
                if (count($primerasDiffs) < 3) {
                    $primerasDiffs[] = sprintf(
                        "línea %d:\n  exp: %s\n  got: %s\n  pos: %s",
                        $i + 1, $original, $regenerada,
                        $this->primeraDiff($original, $regenerada)
                    );
                }
            }
        }

        $this->assertSame(0, $totalDiffs,
            "Diffs: $totalDiffs/117\n".implode("\n", $primerasDiffs));
    }

    public function test_cuit_valido_check_digit(): void
    {
        // CUITs reales del fixture
        $this->assertTrue($this->svc->cuitValido('30718251040'));
        $this->assertTrue($this->svc->cuitValido('30711069360'));
        $this->assertTrue($this->svc->cuitValido('30715898329'));
        // Invalid: digito verificador alterado
        $this->assertFalse($this->svc->cuitValido('30718251041'));
        // Invalid: longitud
        $this->assertFalse($this->svc->cuitValido('123'));
        $this->assertFalse($this->svc->cuitValido(''));
    }

    /**
     * Parsea una línea CBTE de 325 chars en un objeto factura compatible.
     *
     * NB: solo extrae los campos que `lineaCbte()` necesita para regenerar
     * el output. No reconstruye toda la semántica del comprobante.
     */
    private function parsearLineaCbte(string $linea): stdClass
    {
        $f = new stdClass();
        $f->fecha_emision      = substr($linea, 0, 4).'-'.substr($linea, 4, 2).'-'.substr($linea, 6, 2);
        $f->tipo_comprobante_id = (int) substr($linea, 8, 3);
        $f->punto_venta        = (int) substr($linea, 11, 5);
        $f->numero             = (int) substr($linea, 16, 20);
        // pos 37-52 despacho importación: siempre espacios en este fixture
        $f->cuit_emisor        = ltrim(substr($linea, 54, 20), '0');
        // El fixture viene en ISO-8859-1 (Ñ = byte 0xD1). En producción la
        // razón social viene de DB en UTF-8, así que convertimos para que
        // textpad() (que asume UTF-8 en input) la procese correctamente.
        $f->razon_social_emisor = rtrim(mb_convert_encoding(substr($linea, 74, 30), 'UTF-8', 'ISO-8859-1'));
        $f->imp_total          = $this->parseMonto(substr($linea, 104, 15));
        $f->imp_no_gravado     = $this->parseMonto(substr($linea, 119, 15));
        $f->imp_exento         = $this->parseMonto(substr($linea, 134, 15));
        // v1.28 — nombres alineados a las columnas reales de la BD (las
        // mismas que pobló el v1.24 al expandir erp_facturas_compra).
        $f->imp_percepciones_iva       = $this->parseMonto(substr($linea, 149, 15));
        $f->imp_percepciones_otros_nac = $this->parseMonto(substr($linea, 164, 15));
        $f->imp_percepciones_iibb      = $this->parseMonto(substr($linea, 179, 15));
        $f->imp_municipales            = $this->parseMonto(substr($linea, 194, 15));
        $f->imp_internos               = $this->parseMonto(substr($linea, 209, 15));
        // pos 225-227: moneda (siempre PES)
        $f->cotizacion         = ((int) substr($linea, 227, 10)) / 1000000.0;
        // pos 238: cantidad alícuotas (recalculada a partir de las alic adjuntas)
        // pos 239: cod op
        $f->imp_iva            = $this->parseMonto(substr($linea, 239, 15));
        // v1.28 — el generador prefiere `imp_otros_tributos` (v1.24) con
        // fallback a `imp_tributos` (legacy). Para el roundtrip basta con uno.
        $f->imp_otros_tributos = $this->parseMonto(substr($linea, 254, 15));
        return $f;
    }

    /**
     * Parsea una línea ALICUOTAS y retorna [factura_stub, alicuota_array].
     */
    private function parsearLineaAlicuotas(string $linea): array
    {
        $f = new stdClass();
        $f->tipo_comprobante_id = (int) substr($linea, 0, 3);
        $f->punto_venta = (int) substr($linea, 3, 5);
        $f->numero = (int) substr($linea, 8, 20);
        $f->cuit_emisor = ltrim(substr($linea, 30, 20), '0');

        $alic = [
            'base' => $this->parseMonto(substr($linea, 50, 15)),
            'codigo_afip' => substr($linea, 65, 4),
            'iva' => $this->parseMonto(substr($linea, 69, 15)),
        ];
        return [$f, $alic];
    }

    /**
     * Indexa alícuotas del fixture por clave "tipo|pv|numero|CUIT". Incluye
     * CUIT porque dos proveedores distintos pueden emitir comprobantes con
     * la misma tipo/PV/número (FB-001-00002-00077 de MEGALOG y FB-001-00002-00077
     * de otro proveedor son dos facturas diferentes en el universo AFIP).
     */
    private function indexarAlicuotasPorCbte(): array
    {
        $raw = file_get_contents($this->alicFixture);
        $lineas = explode("\r\n", rtrim($raw, "\r\n"));
        $idx = [];
        foreach ($lineas as $l) {
            [$f, $alic] = $this->parsearLineaAlicuotas($l);
            $key = $f->tipo_comprobante_id.'|'.$f->punto_venta.'|'.$f->numero.'|'.$f->cuit_emisor;
            $idx[$key] ??= [];
            $idx[$key][] = $alic;
        }
        return $idx;
    }

    /** Convierte 15-char ×100 numérico a float. */
    private function parseMonto(string $s): float
    {
        $signo = 1;
        if (strlen($s) > 0 && $s[0] === '-') {
            $signo = -1;
            $s = substr($s, 1);
        }
        return $signo * ((int) $s) / 100.0;
    }

    /** Devuelve "pos N: expected='X' got='Y'" para la primera diferencia. */
    private function primeraDiff(string $a, string $b): string
    {
        $len = max(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            $ca = $a[$i] ?? '';
            $cb = $b[$i] ?? '';
            if ($ca !== $cb) {
                return sprintf('pos %d: expected=%s got=%s', $i + 1,
                    var_export($ca, true), var_export($cb, true));
            }
        }
        return 'sin diferencias';
    }
}
