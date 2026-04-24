<?php

namespace Tests\Feature\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\GananciaAnticipo;
use App\Erp\Models\Impuestos\GananciaLiquidacion;
use App\Erp\Services\Impuestos\GananciasAnticiposService;
use App\Erp\Services\Impuestos\GananciasCalculator;
use App\Erp\Services\Impuestos\GananciasF713Service;
use App\Erp\Services\Impuestos\PeriodoFiscalService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests de Ganancias F.713 + anticipos (RN-55, RN-56, RN-57).
 */
class GananciasTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('erp_ganancias_liquidacion') || ! Schema::hasTable('erp_ganancias_escala')) {
            $this->markTestSkipped('DDL_05 H1+H5 no aplicado');
        }

        if (DB::table('erp_ganancias_escala')->where('vigente_desde', '2024-01-01')->count() === 0) {
            $this->markTestSkipped('Seed escala Ganancias H5 no aplicado');
        }

        $this->user = User::firstOrCreate(
            ['email' => 'test.h5@logistica.local'],
            ['name' => 'Test H5', 'password' => bcrypt('irrelevante')]
        );
    }

    // ----- Escala (RN-56) -----

    public function test_escala_tramo_1_base_5M(): void
    {
        // base 5M está en tramo 1 (0 → 14.3M, 25%)
        $res = app(GananciasCalculator::class)->aplicarEscala(5_000_000, '2025-12-31');

        $this->assertEqualsWithDelta(1_250_000.00, $res['impuesto'], 0.01);  // 5M * 25%
        $this->assertCount(1, $res['tramos']);
        $this->assertEquals(1, $res['tramos'][0]['tramo']);
    }

    public function test_escala_tramo_2_base_50M(): void
    {
        // base 50M está en tramo 2 (14.3M → 143M)
        // impuesto = 3.575.302,30 + (50M - 14.301.209,21) * 30%
        $res = app(GananciasCalculator::class)->aplicarEscala(50_000_000, '2025-12-31');

        $esperado = 3_575_302.30 + (50_000_000 - 14_301_209.21) * 0.30;
        $this->assertEqualsWithDelta($esperado, $res['impuesto'], 0.01);
        $this->assertEquals(2, $res['tramos'][0]['tramo']);
    }

    public function test_escala_tramo_3_base_200M(): void
    {
        // base 200M en tramo 3 (>143M, 35%)
        $res = app(GananciasCalculator::class)->aplicarEscala(200_000_000, '2025-12-31');

        $esperado = 42_188_566.56 + (200_000_000 - 143_012_092.08) * 0.35;
        $this->assertEqualsWithDelta($esperado, $res['impuesto'], 0.01);
        $this->assertEquals(3, $res['tramos'][0]['tramo']);
    }

    public function test_escala_base_cero_no_imputa(): void
    {
        $res = app(GananciasCalculator::class)->aplicarEscala(0, '2025-12-31');
        $this->assertEquals(0.0, $res['impuesto']);
        $this->assertCount(0, $res['tramos']);
    }

    public function test_escala_base_negativa_no_imputa(): void
    {
        $res = app(GananciasCalculator::class)->aplicarEscala(-1_000_000, '2025-12-31');
        $this->assertEquals(0.0, $res['impuesto']);
    }

    // ----- Calculator completo + ajustes -----

    public function test_agregar_ajuste_mas_incrementa_impositivo(): void
    {
        [$ejercicio, $periodo, $liq] = $this->seedLiquidacion(2075, 10_000_000);

        $this->assertEqualsWithDelta(10_000_000.00, (float) $liq->resultado_impositivo, 0.01);
        $this->assertEqualsWithDelta(2_500_000.00, (float) $liq->impuesto_determinado, 0.01); // 25%

        $liq = app(GananciasCalculator::class)->agregarAjuste($liq, [
            'tipo' => 'MAS', 'concepto' => 'MULTAS_SANCIONES', 'importe' => 2_000_000,
        ], $this->user);

        $this->assertEqualsWithDelta(12_000_000.00, (float) $liq->resultado_impositivo, 0.01);
        $this->assertEqualsWithDelta(3_000_000.00, (float) $liq->impuesto_determinado, 0.01);
    }

    public function test_saldo_a_favor_cuando_retenciones_superan_impuesto(): void
    {
        [$ejercicio, $periodo, $liq] = $this->seedLiquidacion(2074, 1_000_000, [
            'retenciones_sufridas' => 500_000,
        ]);

        $this->assertEqualsWithDelta(250_000.00, (float) $liq->impuesto_determinado, 0.01);
        $this->assertEquals(0.00, (float) $liq->saldo_a_pagar);
        $this->assertEqualsWithDelta(250_000.00, (float) $liq->saldo_a_favor, 0.01);
    }

    // ----- Anticipos (RN-57) -----

    public function test_anticipos_10_cuotas_con_primera_al_25_porciento(): void
    {
        [$ejercicio, , $liq] = $this->seedLiquidacion(2073, 10_000_000);
        $this->crearEjercicio(2074, $ejercicio->fecha_cierre);

        $anticipos = app(GananciasAnticiposService::class)->generar($liq, $this->user);

        $this->assertCount(10, $anticipos);
        $this->assertEquals(25.00, (float) $anticipos[0]->porcentaje);
        $this->assertEqualsWithDelta(8.33, (float) $anticipos[1]->porcentaje, 0.001);

        $base = (float) $liq->impuesto_determinado;
        $suma = array_sum(array_map(fn ($a) => (float) $a->importe, $anticipos));
        $this->assertEqualsWithDelta($base, $suma, 0.10);
    }

    public function test_anticipos_pagados_no_se_regeneran(): void
    {
        [$ejercicio, , $liq] = $this->seedLiquidacion(2072, 10_000_000);
        $this->crearEjercicio(2073, $ejercicio->fecha_cierre);

        $lista = app(GananciasAnticiposService::class)->generar($liq, $this->user);
        $primerAnticipo = $lista[0];
        $importeOriginal = (float) $primerAnticipo->importe;

        GananciaAnticipo::where('id', $primerAnticipo->id)->update([
            'estado' => 'PAGADO', 'fecha_pago' => '2073-08-14',
        ]);

        app(GananciasCalculator::class)->agregarAjuste($liq, [
            'tipo' => 'MAS', 'concepto' => 'OTROS', 'importe' => 5_000_000,
        ], $this->user);
        $lista2 = app(GananciasAnticiposService::class)->generar($liq->fresh(), $this->user);

        $this->assertEquals($importeOriginal, (float) $lista2[0]->importe);
        $this->assertEquals('PAGADO', $lista2[0]->estado);
        // El 2do anticipo sí se recalcula con la nueva base (mayor que el original del mismo tramo).
        $this->assertGreaterThan((float) $lista[1]->importe, (float) $lista2[1]->importe);
    }

    // ----- F.713 generator -----

    public function test_generar_f713_persiste_archivo(): void
    {
        Storage::fake('local');

        [, $periodo, $liq] = $this->seedLiquidacion(2071, 1_000_000);

        $res = app(GananciasF713Service::class)->generar($periodo, $liq, $this->user);

        $this->assertTrue(Storage::disk('local')->exists($res['path']));
        $this->assertEquals(64, strlen($res['hash']));
        $contenido = Storage::disk('local')->get($res['path']);
        $this->assertStringContainsString('RESULTADO_CONTABLE=1000000.00', $contenido);
        $this->assertStringContainsString('IMPUESTO_DETERMINADO=250000.00', $contenido);
    }

    // ------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------

    private function crearEjercicio(int $numero, $fechaInicio = null): Ejercicio
    {
        $inicio = $fechaInicio ? date('Y-m-d', strtotime("{$fechaInicio} +1 day"))
                                : sprintf('%04d-01-01', $numero);
        $cierre = date('Y-m-d', strtotime($inicio.' +1 year -1 day'));

        return Ejercicio::create([
            'empresa_id' => $this->empresaId,
            'numero' => $numero,
            'nombre' => "Ejercicio {$numero}",
            'fecha_inicio' => $inicio,
            'fecha_cierre' => $cierre,
            'estado' => 'ABIERTO',
        ]);
    }

    /**
     * Crea ejercicio + período fiscal GAN_ANUAL + liquidación con
     * `resultado_contable` fijo. Evita el trigger de balance de
     * asientos (que impide insertar movimientos atómicamente desde
     * PHP) aislando la capa fiscal de la contable.
     *
     * @return array{0:Ejercicio, 1:\App\Erp\Models\Impuestos\PeriodoFiscal, 2:GananciaLiquidacion}
     */
    private function seedLiquidacion(int $numero, float $resultadoContable, array $contexto = []): array
    {
        $ejercicio = $this->crearEjercicio($numero);

        $periodo = app(PeriodoFiscalService::class)->crear([
            'empresa_id' => 1, 'impuesto' => 'GAN_ANUAL',
            'anio' => $numero + 1, 'mes' => null, 'ejercicio_id' => $ejercicio->id,
        ], $this->user);

        // Insertamos la liquidación directo en BD con resultado_contable fijo
        // para evitar leer de asientos (dependencia bloqueada por trigger).
        $liq = GananciaLiquidacion::create([
            'periodo_id'             => $periodo->id,
            'ejercicio_id'           => $ejercicio->id,
            'resultado_contable'     => $resultadoContable,
            'ajustes_fiscales_mas'   => 0,
            'ajustes_fiscales_menos' => 0,
            'resultado_impositivo'   => $resultadoContable,
            'impuesto_determinado'   => 0,
            'retenciones_sufridas'   => $contexto['retenciones_sufridas'] ?? 0,
            'percepciones_sufridas'  => $contexto['percepciones_sufridas'] ?? 0,
            'anticipos_computados'   => $contexto['anticipos_computados'] ?? 0,
            'ajusta_por_inflacion'   => false,
            'ajuste_inflacion_importe' => 0,
        ]);

        // Recalc vía service para aplicar escala correctamente.
        $breakdown = app(GananciasCalculator::class)->aplicarEscala($resultadoContable, $ejercicio->fecha_cierre);
        $impDet = round($breakdown['impuesto'], 2);
        $neto = round(
            $impDet - (float) $liq->retenciones_sufridas
            - (float) $liq->percepciones_sufridas - (float) $liq->anticipos_computados,
            2
        );
        $liq->update([
            'impuesto_determinado' => $impDet,
            'alicuota_escalonada'  => ['breakdown_tramos' => $breakdown['tramos'], 'ajustes' => []],
            'saldo_a_pagar'        => max(0, $neto),
            'saldo_a_favor'        => max(0, -$neto),
        ]);

        return [$ejercicio, $periodo, $liq->fresh()];
    }
}
