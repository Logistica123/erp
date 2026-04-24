<?php

namespace Tests\Feature\Impuestos;

use App\Erp\Models\Impuestos\IibbCmDeclaracion;
use App\Erp\Models\Impuestos\IibbCoeficiente;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Services\Impuestos\IibbAtribucionService;
use App\Erp\Services\Impuestos\IibbCm03Calculator;
use App\Erp\Services\Impuestos\IibbCm05Calculator;
use App\Erp\Services\Impuestos\IibbJurisdiccionLocalService;
use App\Erp\Services\Impuestos\PeriodoFiscalService;
use App\Erp\Services\Impuestos\SifereGeneratorService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests de IIBB — atribución por jurisdicción, CM05 (coeficientes anuales),
 * CM03 (liquidación mensual) y servicios jurisdiccionales locales
 * (ARCIBA CABA / ARBA PBA).
 */
class IibbTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;
    private int $clienteId;
    private int $proveedorId;
    private int $alicuota21Id;
    private int $tipoFAId;
    private int $puntoVentaId;
    private int $condIvaRiId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('erp_iibb_cm_declaracion') || ! Schema::hasTable('erp_iibb_coeficientes')) {
            $this->markTestSkipped('DDL_05 H1 no aplicado');
        }

        $this->user = User::firstOrCreate(
            ['email' => 'test.h4@logistica.local'],
            ['name' => 'Test H4', 'password' => bcrypt('irrelevante')]
        );

        $this->clienteId = (int) DB::table('erp_auxiliares')->where('empresa_id', 1)->where('tipo', 'Cliente')->value('id');
        $this->proveedorId = (int) DB::table('erp_auxiliares')->where('empresa_id', 1)->whereIn('tipo', ['Proveedor', 'Distribuidor'])->value('id');
        if (! $this->clienteId || ! $this->proveedorId) {
            $this->markTestSkipped('Faltan auxiliares seedeados');
        }

        DB::table('erp_alicuotas_iva')->updateOrInsert(['id' => 5],
            ['codigo_interno' => 'IVA_21', 'nombre' => 'IVA 21%', 'tasa' => 0.21, 'activo' => 1]);
        $this->alicuota21Id = 5;

        DB::table('erp_tipos_comprobante')->updateOrInsert(['id' => 1],
            ['codigo_interno' => 'FA', 'nombre' => 'Factura A', 'letra' => 'A', 'clase' => 'FACTURA',
             'signo' => 1, 'es_fce' => 0, 'discrimina_iva' => 1, 'activo' => 1]);
        $this->tipoFAId = 1;

        DB::table('erp_puntos_venta')->updateOrInsert(
            ['empresa_id' => 1, 'numero' => 1],
            ['nombre' => 'PV-Test H4', 'tipo_emision' => 'CAE', 'activo' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        $this->puntoVentaId = (int) DB::table('erp_puntos_venta')->where('empresa_id', 1)->where('numero', 1)->value('id');

        $this->condIvaRiId = (int) DB::table('erp_condiciones_iva')->where('codigo_interno', 'RI')->value('id');
        if (! $this->condIvaRiId) {
            DB::table('erp_condiciones_iva')->insert(['id' => 1, 'codigo_interno' => 'RI', 'nombre' => 'RI',
                'letra_default' => 'A', 'acepta_fce' => 1, 'activo' => 1]);
            $this->condIvaRiId = 1;
        }
    }

    // ----- Atribución -----

    public function test_atribucion_separa_por_jurisdiccion_linea(): void
    {
        $fac1 = $this->crearFacturaVentaMulti(2089, '2089-04-10', [
            ['neto' => 600, 'jurisdiccion_iibb' => '901'],
            ['neto' => 400, 'jurisdiccion_iibb' => '902'],
        ]);

        $res = app(IibbAtribucionService::class)->recalcularRango(1, '2089-04-01', '2089-04-30', $this->user);

        $this->assertEquals(600.00, $res['por_jurisdiccion']['901']['ingresos']);
        $this->assertEquals(400.00, $res['por_jurisdiccion']['902']['ingresos']);
    }

    public function test_atribucion_sin_override_va_al_default(): void
    {
        $this->crearFacturaVentaMulti(2088, '2088-05-10', [['neto' => 1000]]);

        $res = app(IibbAtribucionService::class)->recalcularRango(1, '2088-05-01', '2088-05-31', $this->user);

        $this->assertEquals(1000.00, $res['por_jurisdiccion']['901']['ingresos']);
    }

    public function test_atribucion_idempotente(): void
    {
        $this->crearFacturaVentaMulti(2087, '2087-06-10', [['neto' => 500]]);

        $a = app(IibbAtribucionService::class)->recalcularRango(1, '2087-06-01', '2087-06-30', $this->user);
        $b = app(IibbAtribucionService::class)->recalcularRango(1, '2087-06-01', '2087-06-30', $this->user);

        $this->assertEquals($a['filas'], $b['filas']);
        $this->assertEquals(1, DB::table('erp_iibb_jurisdiccion_mov')
            ->where('empresa_id', 1)->whereBetween('fecha', ['2087-06-01', '2087-06-30'])->count());
    }

    // ----- CM05 coeficientes -----

    public function test_cm05_calcula_coeficientes_ingresos_gastos_50_50(): void
    {
        // Ingresos ejercicio 2085-04..2086-03: 60% CABA, 40% PBA
        $this->crearFacturaVentaMulti(20851, '2085-05-10', [
            ['neto' => 6000, 'jurisdiccion_iibb' => '901'],
            ['neto' => 4000, 'jurisdiccion_iibb' => '902'],
        ]);
        // Gastos mismo rango: todo default (901)
        $this->crearFacturaCompra(20852, '2085-06-10', 2000);

        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'IIBB_CM', 'anio' => 2086,
            'ejercicio_id' => null, 'mes' => 5,  // CM05 se trata como periódico informativo
        ], $this->user);

        $res = app(IibbCm05Calculator::class)->calcular($periodo, $this->user, 'abril_marzo');

        // Ingresos: 6000/10000=0.6 CABA, 4000/10000=0.4 PBA
        // Gastos: 2000/2000=1.0 CABA
        // Coef CABA = 0.5*0.6 + 0.5*1.0 = 0.8
        // Coef PBA  = 0.5*0.4 + 0.5*0.0 = 0.2
        $this->assertEqualsWithDelta(0.80, $res['coeficientes']['901'], 0.001);
        $this->assertEqualsWithDelta(0.20, $res['coeficientes']['902'], 0.001);
    }

    public function test_cm05_aprobar_pasa_draft_a_vigente(): void
    {
        IibbCoeficiente::create([
            'anio_vigencia' => 2084, 'jurisdiccion' => '901',
            'coeficiente' => 0.5, 'origen' => 'CM05', 'estado' => 'DRAFT',
        ]);
        IibbCoeficiente::create([
            'anio_vigencia' => 2084, 'jurisdiccion' => '902',
            'coeficiente' => 0.5, 'origen' => 'CM05', 'estado' => 'DRAFT',
        ]);

        $n = app(IibbCm05Calculator::class)->aprobar(2084, $this->user);

        $this->assertEquals(2, $n);
        $this->assertEquals(2, IibbCoeficiente::where('anio_vigencia', 2084)
            ->where('estado', 'VIGENTE')->count());
    }

    public function test_cm05_ajuste_manual_sobre_vigente_rechaza(): void
    {
        IibbCoeficiente::create([
            'anio_vigencia' => 2083, 'jurisdiccion' => '901',
            'coeficiente' => 0.5, 'origen' => 'CM05', 'estado' => 'VIGENTE',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/CM05_COEF_VIGENTE/');
        app(IibbCm05Calculator::class)->ajustarManual(2083, '901', 0.6, $this->user);
    }

    // ----- CM03 mensual -----

    public function test_cm03_liquida_por_jurisdiccion_con_coeficientes_vigentes(): void
    {
        // Coeficientes vigentes: 0.7 CABA, 0.3 PBA
        IibbCoeficiente::create(['anio_vigencia' => 2082, 'jurisdiccion' => '901',
            'coeficiente' => 0.7, 'origen' => 'CM05', 'estado' => 'VIGENTE']);
        IibbCoeficiente::create(['anio_vigencia' => 2082, 'jurisdiccion' => '902',
            'coeficiente' => 0.3, 'origen' => 'CM05', 'estado' => 'VIGENTE']);

        // Ingresos mes 2082-04: 10000 total (atribuidos a 901 y 902 por default).
        $this->crearFacturaVentaMulti(20824, '2082-04-05', [['neto' => 10000]]);

        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'IIBB_CM', 'anio' => 2082, 'mes' => 4,
        ], $this->user);

        $res = app(IibbCm03Calculator::class)->calcular($periodo, $this->user);

        $this->assertEqualsWithDelta(10000.00, $res['base_mes'], 0.01);

        $dCaba = collect($res['por_jurisdiccion'])->firstWhere('jurisdiccion', '901');
        $dPba  = collect($res['por_jurisdiccion'])->firstWhere('jurisdiccion', '902');

        $this->assertEqualsWithDelta(7000.00, (float) $dCaba['base_atribuida'], 0.01);  // 10000 * 0.7
        $this->assertEqualsWithDelta(3000.00, (float) $dPba['base_atribuida'], 0.01);   // 10000 * 0.3
        // Alícuotas default: CABA=0.04, PBA=0.035
        $this->assertEqualsWithDelta(280.00, (float) $dCaba['impuesto_determinado'], 0.01);  // 7000*0.04
        $this->assertEqualsWithDelta(105.00, (float) $dPba['impuesto_determinado'], 0.01);   // 3000*0.035
    }

    public function test_cm03_falla_sin_coeficientes_vigentes(): void
    {
        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'IIBB_CM', 'anio' => 2081, 'mes' => 4,
        ], $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/CM03_SIN_COEFICIENTES/');
        app(IibbCm03Calculator::class)->calcular($periodo, $this->user);
    }

    // ----- ARCIBA (CABA) -----

    public function test_arciba_calcula_ingresos_caba(): void
    {
        $this->crearFacturaVentaMulti(20805, '2080-05-10', [
            ['neto' => 5000, 'jurisdiccion_iibb' => '901'],
            ['neto' => 3000, 'jurisdiccion_iibb' => '902'],   // no debe sumar
        ]);

        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'IIBB_CABA', 'anio' => 2080, 'mes' => 5,
        ], $this->user);

        $res = app(IibbJurisdiccionLocalService::class)->calcular($periodo, $this->user);

        $this->assertEquals('901', $res['jurisdiccion']);
        $this->assertEqualsWithDelta(5000.00, $res['base'], 0.01);
        // Alicuota default CABA = 0.04 → 200
        $this->assertEqualsWithDelta(200.00, $res['impuesto'], 0.01);
    }

    // ----- Generador SIFERE -----

    public function test_generar_sifere_persiste_archivo(): void
    {
        Storage::fake('local');

        IibbCoeficiente::create(['anio_vigencia' => 2079, 'jurisdiccion' => '901',
            'coeficiente' => 1.0, 'origen' => 'CM05', 'estado' => 'VIGENTE']);

        $this->crearFacturaVentaMulti(20795, '2079-05-10', [['neto' => 1000]]);

        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'IIBB_CM', 'anio' => 2079, 'mes' => 5,
        ], $this->user);
        app(IibbCm03Calculator::class)->calcular($periodo, $this->user);
        $res = app(SifereGeneratorService::class)->generar($periodo, $this->user);

        $this->assertTrue(Storage::disk('local')->exists($res['path']));
        $this->assertEquals(64, strlen($res['hash']));
        $this->assertGreaterThan(0, $res['filas']);
    }

    // ------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------

    /**
     * Crea factura venta con N líneas (una por jurisdicción distinta).
     * Cada línea: ['neto' => n, 'jurisdiccion_iibb' => '9XX'?]
     */
    private function crearFacturaVentaMulti(int $numero, string $fecha, array $lineas): int
    {
        $totalNeto = array_sum(array_column($lineas, 'neto'));
        $iva = round($totalNeto * 0.21, 2);
        $total = $totalNeto + $iva;

        $facturaId = DB::table('erp_facturas_venta')->insertGetId([
            'empresa_id' => $this->empresaId, 'tipo_comprobante_id' => $this->tipoFAId,
            'punto_venta_id' => $this->puntoVentaId, 'numero' => $numero,
            'cae' => '11111111111111', 'fecha_vto_cae' => $fecha,
            'fecha_emision' => $fecha, 'auxiliar_id' => $this->clienteId,
            'condicion_iva_id' => $this->condIvaRiId, 'doc_tipo_afip' => 80,
            'doc_nro' => '30-12345678-9', 'moneda_id' => 1, 'cotizacion' => 1,
            'concepto_afip' => 2, 'imp_neto_gravado' => $totalNeto, 'imp_iva' => $iva,
            'imp_total' => $total, 'origen' => 'MANUAL', 'estado' => 'EMITIDA',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($lineas as $idx => $l) {
            DB::table('erp_factura_venta_items')->insert([
                'factura_id' => $facturaId, 'nro_linea' => $idx + 1,
                'concepto' => 'Item '.($idx + 1), 'cantidad' => 1,
                'precio_unitario' => $l['neto'], 'descuento_pct' => 0,
                'alicuota_iva_id' => $this->alicuota21Id,
                'imp_neto' => $l['neto'], 'imp_iva' => round($l['neto'] * 0.21, 2),
                'jurisdiccion_iibb' => $l['jurisdiccion_iibb'] ?? null,
            ]);
        }

        DB::table('erp_factura_venta_iva')->insert([
            'factura_id' => $facturaId, 'alicuota_iva_id' => $this->alicuota21Id,
            'base_imponible' => $totalNeto, 'importe_iva' => $iva,
        ]);
        DB::table('erp_factura_venta_cae')->insert([
            'factura_venta_id' => $facturaId, 'cae' => '11111111111111',
            'fecha_vto_cae' => $fecha, 'resultado' => 'A',
            'idempotency_key' => "h4-fv-{$numero}", 'reintentos' => 0,
            'emitida_at' => now(), 'created_at' => now(),
        ]);

        return $facturaId;
    }

    private function crearFacturaCompra(int $numero, string $fecha, float $neto): int
    {
        return DB::table('erp_facturas_compra')->insertGetId([
            'empresa_id' => $this->empresaId, 'tipo_comprobante_id' => $this->tipoFAId,
            'punto_venta' => 1, 'numero' => $numero,
            'fecha_emision' => $fecha, 'fecha_recepcion' => $fecha,
            'auxiliar_id' => $this->proveedorId,
            'cuit_emisor' => '30123456789', 'razon_social_emisor' => 'Proveedor',
            'condicion_iva_id' => $this->condIvaRiId, 'moneda_id' => 1, 'cotizacion' => 1,
            'imp_neto_gravado' => $neto, 'imp_iva' => round($neto * 0.21, 2),
            'imp_total' => $neto + round($neto * 0.21, 2),
            'origen' => 'MANUAL', 'estado' => 'CONTROLADA', 'constatacion_estado' => 'PENDIENTE',
            'created_by_user_id' => $this->user->id,
        ]);
    }
}
