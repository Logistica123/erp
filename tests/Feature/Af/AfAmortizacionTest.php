<?php

namespace Tests\Feature\Af;

use App\Erp\Models\Af\AfAmortizacion;
use App\Erp\Models\Af\AfBien;
use App\Erp\Models\Af\AfCategoria;
use App\Erp\Models\Af\AfMovimiento;
use App\Erp\Services\Af\AfAmortizacionService;
use App\Erp\Services\Af\AfBienService;
use App\Erp\Services\Af\AfMovimientoService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests de amortización mensual dual (RN-73, RN-74) y movimientos contables
 * (mejora RN-78, revalúo RN-80, baja RN-81).
 */
class AfAmortizacionTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('erp_af_amortizaciones')) {
            $this->markTestSkipped('DDL_06 I1 no aplicado');
        }
        if (DB::table('erp_af_categorias')->count() === 0) {
            $this->markTestSkipped('Seed AF categorías no aplicado');
        }
        $this->user = User::firstOrCreate(
            ['email' => 'test.i2@logistica.local'],
            ['name' => 'Test I2', 'password' => bcrypt('irrelevante')]
        );
    }

    // ----- Amortización -----

    public function test_amortizacion_calcula_dual_contable_y_fiscal(): void
    {
        // INFORMATICA: VU 36 contable / 36 fiscal por default — la categoría
        // tiene mismas. Para diferenciar uso override.
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_080_000, '2090-01-15', [
            'vida_util_contable_meses' => 36,
            'vida_util_fiscal_meses'   => 24,  // fiscal más rápido
        ]);

        // Mes 02/2090 — primer mes posterior al alta (el de alta no amortiza).
        $res = app(AfAmortizacionService::class)->generar(2090, 2, $this->user);

        $row = collect($res['amortizaciones'])->firstWhere('bien_id', $bien->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(30000.00, $row->amort_contable_mes, 0.01); // 1.080.000/36
        $this->assertEqualsWithDelta(45000.00, $row->amort_fiscal_mes, 0.01);   // 1.080.000/24
        // Diferencia generada por columna calculada (15.000 fiscal − contable)
        $this->assertEqualsWithDelta(-15000.00, (float) $row->fresh()->diferencia_mes, 0.01);
    }

    public function test_amortizacion_no_excede_base(): void
    {
        // VU 12 meses, valor 120.000 → cuota 10.000.
        // En el mes 13 ya no debe amortizar (base agotada).
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 120_000, '2089-01-15', [
            'vida_util_contable_meses' => 12,
            'vida_util_fiscal_meses'   => 12,
        ]);

        // Generar 13 meses consecutivos.
        for ($mes = 2; $mes <= 12; $mes++) {
            app(AfAmortizacionService::class)->generar(2089, $mes, $this->user);
        }
        app(AfAmortizacionService::class)->generar(2090, 1, $this->user);  // mes 13
        app(AfAmortizacionService::class)->generar(2090, 2, $this->user);  // mes 14

        $ultima = AfAmortizacion::where('bien_id', $bien->id)
            ->orderByDesc('periodo_anio')->orderByDesc('periodo_mes')->first();

        // El acumulado no puede pasar de 120.000 (tolerancia mínima por redondeos).
        $this->assertLessThanOrEqual(120_000.50, (float) $ultima->amort_contable_acum);
    }

    public function test_amortizacion_excluye_bienes_dados_de_baja(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 800_000, '2088-01-15');
        $bien->update(['estado' => 'BAJA']);

        $res = app(AfAmortizacionService::class)->generar(2088, 6, $this->user);
        $ids = array_column((array) $res['amortizaciones'], 'bien_id');
        $this->assertNotContains($bien->id, $ids);
    }

    public function test_amortizacion_idempotente_recalcula(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_080_000, '2087-01-15');

        app(AfAmortizacionService::class)->generar(2087, 2, $this->user);
        app(AfAmortizacionService::class)->generar(2087, 2, $this->user);
        $count = AfAmortizacion::where('bien_id', $bien->id)
            ->where('periodo_anio', 2087)->where('periodo_mes', 2)->count();
        $this->assertEquals(1, $count);
    }

    public function test_dry_run_no_persiste(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_080_000, '2086-01-15');

        $res = app(AfAmortizacionService::class)->generar(2086, 2, $this->user, dryRun: true);
        $this->assertGreaterThan(0, count($res['amortizaciones']));

        $this->assertEquals(0, AfAmortizacion::where('bien_id', $bien->id)->count());
    }

    public function test_amortizacion_agrupa_por_cuenta_cc_para_asiento(): void
    {
        $cc1 = (int) DB::table('erp_centros_costo')->where('empresa_id', 1)->value('id');
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $this->crearBien($cat, 800_000, '2085-01-10', ['centro_costo_id' => $cc1]);
        $this->crearBien($cat, 1_500_000, '2085-01-20', ['centro_costo_id' => $cc1]);

        $res = app(AfAmortizacionService::class)->generar(2085, 2, $this->user);
        $this->assertGreaterThan(0, count($res['por_cuenta_cc']));
        // Todas las cuotas del mismo CC se agrupan en una sola entrada.
        $entrada = collect($res['por_cuenta_cc'])->firstWhere('cc_id', $cc1);
        $this->assertNotNull($entrada);
        $this->assertGreaterThan(0, $entrada['total']);
    }

    // ----- Mejora (RN-78) -----

    public function test_mejora_incrementa_valor_origen_y_propone_asiento(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_000_000, '2084-01-15');

        $res = app(AfMovimientoService::class)->mejora($bien, [
            'importe' => 200_000, 'descripcion' => 'RAM upgrade',
        ], $this->user);

        $this->assertEquals(1_200_000.00, (float) $bien->fresh()->valor_origen);
        $this->assertEquals('MEJORA', $res['movimiento']->tipo);
        $this->assertArrayHasKey('propuesta_asiento', $res);
        $this->assertEquals(200_000.0, $res['propuesta_asiento']['lineas'][0]['debe']);
    }

    public function test_mejora_extiende_vida_util_si_se_pide(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_000_000, '2083-01-15', [
            'vida_util_contable_meses' => 36,
            'vida_util_fiscal_meses'   => 36,
        ]);

        app(AfMovimientoService::class)->mejora($bien, [
            'importe' => 200_000, 'vu_extension_meses' => 12,
        ], $this->user);

        $bien = $bien->fresh();
        $this->assertEquals(48, $bien->vuContable());
        $this->assertEquals(48, $bien->vuFiscal());
    }

    // ----- Revalúo (RN-80) -----

    public function test_revaluo_cambia_valor_y_propone_asiento_correcto(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_000_000, '2082-01-15');

        $res = app(AfMovimientoService::class)->revaluo($bien, [
            'nuevo_valor' => 1_500_000,
        ], $this->user);

        $this->assertEquals(1_500_000.00, (float) $bien->fresh()->valor_origen);
        $this->assertEquals(500_000.00, (float) $res['movimiento']->importe);
        // Diferencia positiva → DEBE en cuenta del bien.
        $this->assertEquals(500_000.0, $res['propuesta_asiento']['lineas'][0]['debe']);
    }

    public function test_revaluo_sin_diferencia_falla(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_000_000, '2081-01-15');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/AF_REVALUO_SIN_DIFERENCIA/');
        app(AfMovimientoService::class)->revaluo($bien, ['nuevo_valor' => 1_000_000], $this->user);
    }

    // ----- Baja (RN-81) -----

    public function test_baja_calcula_resultado_positivo(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 5_000_000, '2080-01-15');

        // Simular amort acum 2.100.000 (insertando una amortización fake).
        AfAmortizacion::create([
            'bien_id' => $bien->id, 'periodo_anio' => 2080, 'periodo_mes' => 6,
            'base_amort_contable' => 5_000_000, 'amort_contable_mes' => 2_100_000, 'amort_contable_acum' => 2_100_000,
            'base_amort_fiscal'   => 5_000_000, 'amort_fiscal_mes'   => 2_100_000, 'amort_fiscal_acum'   => 2_100_000,
        ]);

        $res = app(AfMovimientoService::class)->baja($bien, [
            'motivo' => 'Venta a Juan Pérez', 'valor_recupero' => 3_000_000,
        ], $this->user);

        $this->assertEquals(2_100_000.00, $res['amort_acumulada']);
        $this->assertEquals(2_900_000.00, $res['valor_residual']);
        $this->assertEquals(100_000.00, $res['resultado_baja']);  // 3M − 2.9M = +100k
        $this->assertEquals('BAJA', $bien->fresh()->estado);
    }

    public function test_baja_calcula_resultado_negativo(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 5_000_000, '2079-01-15');

        AfAmortizacion::create([
            'bien_id' => $bien->id, 'periodo_anio' => 2079, 'periodo_mes' => 6,
            'base_amort_contable' => 5_000_000, 'amort_contable_mes' => 1_000_000, 'amort_contable_acum' => 1_000_000,
            'base_amort_fiscal'   => 5_000_000, 'amort_fiscal_mes'   => 1_000_000, 'amort_fiscal_acum'   => 1_000_000,
        ]);

        $res = app(AfMovimientoService::class)->baja($bien, [
            'motivo' => 'Robo', 'valor_recupero' => 0,
        ], $this->user);

        $this->assertEquals(4_000_000.00, $res['valor_residual']);
        $this->assertEquals(-4_000_000.00, $res['resultado_baja']);
    }

    public function test_baja_doble_falla(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_000_000, '2078-01-15');

        app(AfMovimientoService::class)->baja($bien, ['motivo' => 'X', 'valor_recupero' => 0], $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/AF_BIEN_YA_DADO_DE_BAJA/');
        app(AfMovimientoService::class)->baja($bien->fresh(), ['motivo' => 'Y', 'valor_recupero' => 0], $this->user);
    }

    // ------------------------------------------------------------------------

    private function crearBien(AfCategoria $cat, float $valor, string $fecha, array $extra = []): AfBien
    {
        $datos = array_merge([
            'empresa_id' => 1, 'categoria_id' => $cat->id,
            'nro_inventario' => 'IT-AMORT-'.uniqid(),
            'descripcion' => 'Test', 'fecha_alta' => $fecha,
            'valor_origen' => $valor,
        ], $extra);
        return app(AfBienService::class)->alta($datos, $this->user);
    }
}
