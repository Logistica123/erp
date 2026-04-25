<?php

namespace Tests\Feature\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\BpParticipacion;
use App\Erp\Models\Impuestos\EmpresaSocio;
use App\Erp\Services\Impuestos\BpCalculator;
use App\Erp\Services\Impuestos\BpF2000Service;
use App\Erp\Services\Impuestos\PeriodoFiscalService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests de Bienes Personales F.2000 — VPP por socio (RN-58).
 *
 * Aislamos del contable como en GananciasTest: pasamos el PN via
 * `pn_ajustado_override` para no depender de movimientos asentados
 * (el trigger `sp_recalc_asiento` impide insertar pares atomicamente).
 */
class BpTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('erp_bp_participaciones') || ! Schema::hasTable('erp_empresa_socios')) {
            $this->markTestSkipped('DDL_05 H1+H6 no aplicado');
        }

        if (DB::table('erp_bp_alicuotas')->where('vigente_desde', '2024-01-01')->count() === 0) {
            $this->markTestSkipped('Seed alícuotas BP H5 no aplicado');
        }

        $this->user = User::firstOrCreate(
            ['email' => 'test.h6@logistica.local'],
            ['name' => 'Test H6', 'password' => bcrypt('irrelevante')]
        );
    }

    public function test_alicuota_vigente_es_05_porciento(): void
    {
        $alic = app(BpCalculator::class)->alicuotaVigente('2025-12-31');
        $this->assertEqualsWithDelta(0.005, $alic, 0.0001);
    }

    public function test_calcular_distribuye_pn_entre_socios_y_aplica_alicuota(): void
    {
        $ejercicio = $this->crearEjercicio(2070);

        // 2 socios: 60% / 40%
        EmpresaSocio::create([
            'empresa_id' => 1, 'cuit' => '20111111119', 'nombre' => 'Socio A',
            'tipo' => 'PERSONA_FISICA', 'porcentaje_participacion' => 60,
            'fecha_alta' => '2020-01-01', 'activo' => 1,
        ]);
        EmpresaSocio::create([
            'empresa_id' => 1, 'cuit' => '27222222226', 'nombre' => 'Socio B',
            'tipo' => 'PERSONA_FISICA', 'porcentaje_participacion' => 40,
            'fecha_alta' => '2020-01-01', 'activo' => 1,
        ]);

        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'BP_PART',
            'anio' => 2071, 'mes' => null, 'ejercicio_id' => $ejercicio->id,
        ], $this->user);

        $bp = app(BpCalculator::class)->calcular($periodo, $ejercicio, $this->user, [
            'pn_ajustado_override' => 10_000_000,
        ]);

        $this->assertEqualsWithDelta(10_000_000.00, (float) $bp->patrimonio_neto_ajustado, 0.01);
        $this->assertEqualsWithDelta(0.005, (float) $bp->alicuota, 0.0001);

        // Impuesto total = 10M * 0.5% = 50.000
        $this->assertEqualsWithDelta(50_000.00, (float) $bp->impuesto_total, 0.01);

        $detalle = $bp->socios_detalle;
        $this->assertCount(2, $detalle);

        $a = collect($detalle)->firstWhere('cuit', '20111111119');
        $this->assertEqualsWithDelta(6_000_000.00, (float) $a['vpp'], 0.01);   // 10M * 60%
        $this->assertEqualsWithDelta(30_000.00,    (float) $a['impuesto'], 0.01);

        $b = collect($detalle)->firstWhere('cuit', '27222222226');
        $this->assertEqualsWithDelta(4_000_000.00, (float) $b['vpp'], 0.01);
        $this->assertEqualsWithDelta(20_000.00,    (float) $b['impuesto'], 0.01);
    }

    public function test_falla_si_porcentajes_no_suman_100(): void
    {
        $ejercicio = $this->crearEjercicio(2069);
        EmpresaSocio::create([
            'empresa_id' => 1, 'cuit' => '20111111119', 'nombre' => 'Socio A',
            'tipo' => 'PERSONA_FISICA', 'porcentaje_participacion' => 60,
            'fecha_alta' => '2020-01-01', 'activo' => 1,
        ]);
        // Falta el 40% → suma 60%
        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'BP_PART',
            'anio' => 2070, 'mes' => null, 'ejercicio_id' => $ejercicio->id,
        ], $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/BP_PARTICIPACION_INVALIDA/');
        app(BpCalculator::class)->calcular($periodo, $ejercicio, $this->user, [
            'pn_ajustado_override' => 1_000_000,
        ]);
    }

    public function test_falla_sin_socios(): void
    {
        $ejercicio = $this->crearEjercicio(2068);
        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'BP_PART',
            'anio' => 2069, 'mes' => null, 'ejercicio_id' => $ejercicio->id,
        ], $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/BP_SIN_SOCIOS/');
        app(BpCalculator::class)->calcular($periodo, $ejercicio, $this->user, [
            'pn_ajustado_override' => 1_000_000,
        ]);
    }

    public function test_falla_pn_no_positivo(): void
    {
        $ejercicio = $this->crearEjercicio(2067);
        EmpresaSocio::create([
            'empresa_id' => 1, 'cuit' => '20111111119', 'nombre' => 'Socio',
            'tipo' => 'PERSONA_FISICA', 'porcentaje_participacion' => 100,
            'fecha_alta' => '2020-01-01', 'activo' => 1,
        ]);
        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'BP_PART',
            'anio' => 2068, 'mes' => null, 'ejercicio_id' => $ejercicio->id,
        ], $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/BP_PN_NO_POSITIVO/');
        app(BpCalculator::class)->calcular($periodo, $ejercicio, $this->user, [
            'pn_ajustado_override' => -1000,
        ]);
    }

    public function test_socios_dados_de_baja_no_computan(): void
    {
        $ejercicio = $this->crearEjercicio(2066);

        // Socio A activo 100%, socio B dado de baja antes del cierre.
        EmpresaSocio::create([
            'empresa_id' => 1, 'cuit' => '20111111119', 'nombre' => 'Socio A',
            'tipo' => 'PERSONA_FISICA', 'porcentaje_participacion' => 100,
            'fecha_alta' => '2020-01-01', 'activo' => 1,
        ]);
        EmpresaSocio::create([
            'empresa_id' => 1, 'cuit' => '27222222226', 'nombre' => 'Ex-Socio',
            'tipo' => 'PERSONA_FISICA', 'porcentaje_participacion' => 50,
            'fecha_alta' => '2020-01-01', 'fecha_baja' => '2065-06-15', 'activo' => 0,
        ]);

        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'BP_PART',
            'anio' => 2067, 'mes' => null, 'ejercicio_id' => $ejercicio->id,
        ], $this->user);

        $bp = app(BpCalculator::class)->calcular($periodo, $ejercicio, $this->user, [
            'pn_ajustado_override' => 1_000_000,
        ]);

        $this->assertCount(1, $bp->socios_detalle);
        $this->assertEquals('20111111119', $bp->socios_detalle[0]['cuit']);
    }

    public function test_recalcula_idempotente(): void
    {
        $ejercicio = $this->crearEjercicio(2065);
        EmpresaSocio::create([
            'empresa_id' => 1, 'cuit' => '20111111119', 'nombre' => 'Socio',
            'tipo' => 'PERSONA_FISICA', 'porcentaje_participacion' => 100,
            'fecha_alta' => '2020-01-01', 'activo' => 1,
        ]);
        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'BP_PART',
            'anio' => 2066, 'mes' => null, 'ejercicio_id' => $ejercicio->id,
        ], $this->user);

        app(BpCalculator::class)->calcular($periodo, $ejercicio, $this->user, ['pn_ajustado_override' => 1_000_000]);
        app(BpCalculator::class)->calcular($periodo, $ejercicio, $this->user, ['pn_ajustado_override' => 2_000_000]);

        $this->assertEquals(1, BpParticipacion::where('ejercicio_id', $ejercicio->id)->count());
        $bp = BpParticipacion::where('ejercicio_id', $ejercicio->id)->first();
        $this->assertEqualsWithDelta(2_000_000.00, (float) $bp->patrimonio_neto_ajustado, 0.01);
    }

    public function test_generar_f2000_persiste_archivo(): void
    {
        Storage::fake('local');

        $ejercicio = $this->crearEjercicio(2064);
        EmpresaSocio::create([
            'empresa_id' => 1, 'cuit' => '20111111119', 'nombre' => 'Socio Único',
            'tipo' => 'PERSONA_FISICA', 'porcentaje_participacion' => 100,
            'fecha_alta' => '2020-01-01', 'activo' => 1,
        ]);
        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'BP_PART',
            'anio' => 2065, 'mes' => null, 'ejercicio_id' => $ejercicio->id,
        ], $this->user);

        $bp = app(BpCalculator::class)->calcular($periodo, $ejercicio, $this->user, [
            'pn_ajustado_override' => 5_000_000,
        ]);
        $res = app(BpF2000Service::class)->generar($periodo, $bp, $this->user);

        $this->assertTrue(Storage::disk('local')->exists($res['path']));
        $this->assertEquals(64, strlen($res['hash']));
        $this->assertEqualsWithDelta(25_000.00, $res['impuesto_total'], 0.01);

        $contenido = Storage::disk('local')->get($res['path']);
        $this->assertStringContainsString('PATRIMONIO_NETO_AJUST=5000000.00', $contenido);
        $this->assertStringContainsString('SOCIO_1_CUIT=20111111119', $contenido);
    }

    private function crearEjercicio(int $numero): Ejercicio
    {
        return Ejercicio::create([
            'empresa_id' => $this->empresaId,
            'numero' => $numero, 'nombre' => "Ejercicio {$numero}",
            'fecha_inicio' => sprintf('%04d-01-01', $numero),
            'fecha_cierre' => sprintf('%04d-12-31', $numero),
            'estado' => 'ABIERTO',
        ]);
    }
}
