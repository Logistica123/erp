<?php

namespace Tests\Feature;

use App\Erp\Models\VentasCompras\LibroIvaComprasImport;
use App\Erp\Services\LibroIvaComprasImportService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADDENDUM v1.9 — Tests críticos del import enriquecido del Libro IVA Compras.
 *
 * Fixture real: tests/Fixtures/libro_iva_compras/AFIP_marzo_2026_compras.csv
 * (CSV crudo de AFIP "Mis Comprobantes" con 421 facturas, encoding ISO-8859-1).
 */
class LibroIvaComprasImportTest extends TestCase
{
    use DatabaseTransactions;

    private LibroIvaComprasImportService $svc;
    private User $user;
    private int $empresaId = 1;
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(LibroIvaComprasImportService::class);
        $this->user = User::first() ?? User::factory()->create();
        $this->fixture = base_path('tests/Fixtures/libro_iva_compras/AFIP_marzo_2026_compras.csv');
    }

    public function test_preview_csv_crudo_de_AFIP_cuenta_421_filas(): void
    {
        $this->assertFileExists($this->fixture);

        $r = $this->svc->preview($this->fixture, 'AFIP_marzo_2026_compras.csv', $this->empresaId);

        $this->assertSame(421, $r['filas_totales']);
        // Sin columna Tomado, todas se asumen tomadas.
        $this->assertSame(421, $r['filas_con_tomado_si']);
        $this->assertSame(0, $r['filas_con_tomado_no']);
        // periodo_afip se detecta del nombre del archivo si tiene patrón YYYYMM consecutivo
        // (ej: comprobantes_periodo_202603.csv). Si no, queda null y el operador elige.
        $this->assertTrue(is_null($r['periodo_afip']) || $r['periodo_afip'] === '202603');
        $this->assertSame([], $r['columnas_extras_detectadas']);
        $this->assertNull($r['import_existente']);
        $this->assertSame(64, strlen($r['hash']));
    }

    public function test_preview_archivo_con_columnas_extras_las_detecta(): void
    {
        // Genero un CSV con header enriquecido en runtime.
        // v1.14: "Período pagado" → "Período trabajado" (renombrado, era typo
        // del v1.13). Sumamos también la columna nueva "Jurisdicción".
        $tmp = tempnam(sys_get_temp_dir(), 'liva_').'.csv';
        $content = '"Fecha de Emisión";"Tipo de Comprobante";"Punto de Venta";"Número de Comprobante";"Importe Total";"Tomado";"Cliente";"Período trabajado";"Jurisdicción";"Tipo";"Observaciones"'."\r\n"
                 . '2026-03-15;1;1;100;1000,00;SI;OCASA;2026-03;902;Combustible;Test'."\r\n"
                 . '2026-03-16;1;1;101;500,00;NO;Loginter;2026-03-Q1;901;Otros;'."\r\n";
        file_put_contents($tmp, mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8'));

        $r = $this->svc->preview($tmp, 'enriquecido.csv', $this->empresaId);

        $this->assertSame(2, $r['filas_totales']);
        $this->assertSame(1, $r['filas_con_tomado_si']);
        $this->assertSame(1, $r['filas_con_tomado_no']);
        $this->assertContains('tomado', $r['columnas_extras_detectadas']);
        $this->assertContains('cliente', $r['columnas_extras_detectadas']);
        $this->assertContains('periodo trabajado', $r['columnas_extras_detectadas']);
        $this->assertContains('jurisdiccion', $r['columnas_extras_detectadas']);
        $this->assertContains('tipo', $r['columnas_extras_detectadas']);
        $this->assertContains('observaciones', $r['columnas_extras_detectadas']);
        @unlink($tmp);
    }

    public function test_preview_idempotencia_archivo_duplicado(): void
    {
        // Crear un import "ficticio" con hash conocido y verificar que preview lo detecta.
        $hash = hash_file('sha256', $this->fixture);
        $periodoId = (int) DB::table('erp_periodos')->orderBy('id')->value('id');

        if (! $periodoId) {
            $this->markTestSkipped('Necesito al menos un período en la DB de test.');
        }

        LibroIvaComprasImport::create([
            'empresa_id' => $this->empresaId,
            'archivo_nombre' => 'previo.csv',
            'archivo_hash' => $hash,
            'periodo_imputacion_id' => $periodoId,
            'importado_por' => $this->user->id,
            'importado_at' => now(),
            'estado' => 'COMPLETO',
        ]);

        $r = $this->svc->preview($this->fixture, 'AFIP_marzo_2026_compras.csv', $this->empresaId);

        $this->assertNotNull($r['import_existente']);
        $this->assertSame('COMPLETO', $r['import_existente']['estado']);
    }

    public function test_normalizar_periodo_acepta_varios_formatos(): void
    {
        $svc = $this->svc;
        $ref = new \ReflectionMethod($svc, 'normalizarPeriodo');
        $ref->setAccessible(true);

        $this->assertSame('2026-03', $ref->invoke($svc, '2026-03'));
        $this->assertSame('2026-03', $ref->invoke($svc, '03/2026'));
        $this->assertSame('2026-03', $ref->invoke($svc, '03-2026'));
        $this->assertSame('2026-03', $ref->invoke($svc, '2026/3'));
        $this->assertNull($ref->invoke($svc, ''));
    }

    public function test_parsear_float_acepta_formato_AFIP(): void
    {
        $svc = $this->svc;
        $ref = new \ReflectionMethod($svc, 'parsearFloat');
        $ref->setAccessible(true);

        $this->assertEqualsWithDelta(167556.44, $ref->invoke($svc, '167556,44'), 0.01);
        $this->assertEqualsWithDelta(1234567.89, $ref->invoke($svc, '1.234.567,89'), 0.01);
        $this->assertSame(0.0, $ref->invoke($svc, ''));
        $this->assertSame(0.0, $ref->invoke($svc, null));
    }

    /**
     * v1.19 ENC-03 — el archivo real de Sebastián (período 202604) viene
     * en ISO-8859-1. Antes rompía el parser. Ahora se detecta automáticamente
     * y todas las filas se procesan.
     */
    public function test_v19_preview_csv_ISO_8859_1_real_AFIP_202604(): void
    {
        $fixture = base_path('tests/Fixtures/libro_iva_compras/comprobantes_periodo_202604_compras_AFIP_real.csv');
        $this->assertFileExists($fixture);

        $r = $this->svc->preview($fixture, 'comprobantes_periodo_202604_compras_AFIP_real.csv', $this->empresaId);

        $this->assertSame('ISO-8859-1', $r['encoding_detectado'],
            'El parser debe detectar el encoding del archivo real de AFIP como ISO-8859-1');
        // 464 filas reales (el wc-l incluye el header).
        $this->assertGreaterThan(400, $r['filas_totales'],
            'Debe contar más de 400 filas — el archivo real tiene 464');
        // El archivo trae columna Tomado, todas SI por default si está vacía.
        $this->assertContains('tomado', $r['columnas_extras_detectadas']);
    }

    public function test_v19_preview_csv_UTF8_con_BOM(): void
    {
        // Crear un CSV UTF-8 con BOM para verificar que se descarta correctamente.
        $tmp = tempnam(sys_get_temp_dir(), 'utf8_bom_').'.csv';
        $content = "\xEF\xBB\xBF\"Fecha de Emisión\";\"Tipo de Comprobante\";\"Punto de Venta\";\"Número de Comprobante\";\"Importe Total\"\r\n"
            . "2026-04-15;1;1;100;1000,00\r\n";
        file_put_contents($tmp, $content);

        $r = $this->svc->preview($tmp, 'utf8_bom.csv', $this->empresaId);
        $this->assertSame('UTF-8', $r['encoding_detectado']);
        $this->assertSame(1, $r['filas_totales']);
        @unlink($tmp);
    }
}
