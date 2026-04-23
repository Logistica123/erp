<?php

namespace Tests\Feature\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Services\Impuestos\PeriodoFiscalService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests del PeriodoFiscalService: ciclo de vida + RN-44 + rectificativa.
 */
class PeriodoFiscalServiceTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private PeriodoFiscalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('erp_periodos_fiscales')) {
            $this->markTestSkipped('DDL_05 H1 no aplicado');
        }

        $this->user = User::firstOrCreate(
            ['email' => 'test.h1@logistica.local'],
            ['name' => 'Test H1', 'password' => bcrypt('irrelevante')]
        );

        $this->service = app(PeriodoFiscalService::class);
    }

    public function test_crear_periodo_iva_estado_abierto_y_vencimiento_calendario(): void
    {
        // Sembrar vencimiento para asegurar el lookup del calendario.
        DB::table('erp_calendario_vencimientos')->updateOrInsert(
            ['anio' => 2099, 'impuesto' => 'IVA', 'periodo_identificador' => '06', 'terminacion_cuit' => null],
            ['fecha_vencimiento' => '2099-07-20']
        );

        $periodo = $this->service->crear([
            'empresa_id' => 1,
            'impuesto'   => 'IVA',
            'anio'       => 2099,
            'mes'        => 6,
        ], $this->user);

        $this->assertEquals('ABIERTO', $periodo->estado);
        $this->assertEquals('2099-07-20', $periodo->fecha_vencimiento->toDateString());
    }

    public function test_no_permite_duplicar_periodo_no_rectificativa(): void
    {
        $this->service->crear([
            'empresa_id' => 1, 'impuesto' => 'IVA', 'anio' => 2098, 'mes' => 1,
        ], $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/PERIODO_DUPLICADO/');

        $this->service->crear([
            'empresa_id' => 1, 'impuesto' => 'IVA', 'anio' => 2098, 'mes' => 1,
        ], $this->user);
    }

    public function test_iva_requiere_mes(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/PERIODO_MES_REQUERIDO/');

        $this->service->crear([
            'empresa_id' => 1, 'impuesto' => 'IVA', 'anio' => 2098,
        ], $this->user);
    }

    public function test_transicion_valida_abierto_a_en_revision(): void
    {
        $p = $this->service->crear([
            'empresa_id' => 1, 'impuesto' => 'IVA', 'anio' => 2097, 'mes' => 1,
        ], $this->user);

        $p = $this->service->transicionar($p, 'EN_REVISION', $this->user);
        $this->assertEquals('EN_REVISION', $p->estado);
        $this->assertEquals($this->user->id, $p->revisor_user_id);
    }

    public function test_transicion_invalida_abierto_a_cerrado(): void
    {
        $p = $this->service->crear([
            'empresa_id' => 1, 'impuesto' => 'IVA', 'anio' => 2096, 'mes' => 1,
        ], $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/PERIODO_TRANSICION_INVALIDA/');
        $this->service->transicionar($p, 'CERRADO', $this->user);
    }

    public function test_presentado_requiere_nro_tramite(): void
    {
        $p = $this->service->crear([
            'empresa_id' => 1, 'impuesto' => 'IVA', 'anio' => 2095, 'mes' => 1,
        ], $this->user);
        $p = $this->service->transicionar($p, 'EN_REVISION', $this->user);
        $p = $this->service->transicionar($p, 'APROBADO', $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/PERIODO_PRESENTADO_REQUIERE_TRAMITE/');
        $this->service->transicionar($p, 'PRESENTADO', $this->user, []);
    }

    public function test_rectificativa_solo_sobre_periodo_cerrado(): void
    {
        $p = $this->service->crear([
            'empresa_id' => 1, 'impuesto' => 'IVA', 'anio' => 2094, 'mes' => 1,
        ], $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/PERIODO_NO_RECTIFICABLE/');
        $this->service->rectificar($p, 'cargué un comprobante mal', $this->user);
    }

    public function test_rectificativa_genera_nuevo_periodo_con_referencia(): void
    {
        $p = $this->service->crear([
            'empresa_id' => 1, 'impuesto' => 'IVA', 'anio' => 2093, 'mes' => 1,
        ], $this->user);
        $p = $this->service->transicionar($p, 'EN_REVISION', $this->user);
        $p = $this->service->transicionar($p, 'APROBADO', $this->user);
        $p = $this->service->transicionar($p, 'PRESENTADO', $this->user, ['nro_tramite' => '0000-1111']);

        $rect = $this->service->rectificar($p, 'Olvidé NC del cliente X', $this->user);

        $this->assertEquals('RECTIFICATIVA', $rect->estado);
        $this->assertEquals($p->id, $rect->rectifica_a_id);
        // Original sigue intacto
        $this->assertEquals('PRESENTADO', PeriodoFiscal::find($p->id)->estado);
    }
}
