<?php

namespace Tests\Feature\Reportes;

use App\Erp\Services\Reportes\AgingService;
use App\Erp\Services\Reportes\ComparativoService;
use App\Erp\Services\Reportes\CtaCorrienteService;
use App\Erp\Services\Reportes\DiarioService;
use App\Erp\Services\Reportes\MayorService;
use App\Erp\Services\Reportes\SumasSaldosService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests de los servicios de reportes (RN-66, RN-68, RN-69).
 *
 * Estos tests trabajan sobre datos reales seedeados (plan de cuentas,
 * facturas existentes) — verifican estructura del payload, no totales.
 * Los totales dependen del estado de la base.
 */
class ReportesContablesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('erp_movimientos_asiento') || ! Schema::hasTable('erp_cuentas_contables')) {
            $this->markTestSkipped('DDL_01/02 no aplicado');
        }
    }

    public function test_mayor_devuelve_estructura_aunque_no_haya_movimientos(): void
    {
        $cuentaId = (int) DB::table('erp_cuentas_contables')->where('empresa_id', 1)
            ->where('imputable', 1)->value('id');
        if (! $cuentaId) {
            $this->markTestSkipped('Sin cuentas imputables seedeadas');
        }

        $res = app(MayorService::class)->calcular(1, $cuentaId, '2099-01-01', '2099-12-31');

        $this->assertArrayHasKey('cuenta', $res);
        $this->assertArrayHasKey('saldo_inicial', $res);
        $this->assertArrayHasKey('movimientos', $res);
        $this->assertArrayHasKey('totales', $res);
        $this->assertEquals(0.0, $res['totales']['debe']);
        $this->assertEquals(0.0, $res['totales']['haber']);
    }

    public function test_mayor_cuenta_inexistente_devuelve_null_estructurado(): void
    {
        $res = app(MayorService::class)->calcular(1, 99999999, '2099-01-01', '2099-12-31');
        $this->assertNull($res['cuenta']);
        $this->assertEmpty($res['movimientos']);
    }

    public function test_diario_estructura_vacio(): void
    {
        $res = app(DiarioService::class)->calcular(1, '2099-01-01', '2099-12-31');
        $this->assertEquals(0, $res['totales']['cantidad']);
        $this->assertEmpty($res['asientos']);
    }

    public function test_sumas_y_saldos_estructura(): void
    {
        $res = app(SumasSaldosService::class)->calcular(1, '2099-01-01', '2099-12-31');
        $this->assertArrayHasKey('filas', $res);
        $this->assertArrayHasKey('totales', $res);
        $this->assertEquals(0.0, $res['totales']['debe']);
    }

    public function test_aging_clientes_estructura_buckets(): void
    {
        $res = app(AgingService::class)->calcular(1, 'clientes', '2099-12-31');

        $this->assertEquals('clientes', $res['tipo']);
        $this->assertEquals('2099-12-31', $res['fecha_corte']);

        foreach (['corriente', 'rango_1_30', 'rango_31_60', 'rango_61_90', 'rango_91_plus', 'total'] as $k) {
            $this->assertArrayHasKey($k, $res['totales']);
        }
    }

    public function test_aging_tipo_invalido_lanza_argumentexception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(AgingService::class)->calcular(1, 'cualquiera-otra-cosa');
    }

    public function test_cc_clientes_estructura(): void
    {
        $cliente = DB::table('erp_auxiliares')->where('empresa_id', 1)
            ->where('tipo', 'Cliente')->first();
        if (! $cliente) {
            $this->markTestSkipped('Sin clientes seedeados');
        }

        $res = app(CtaCorrienteService::class)->clientes(1, (int) $cliente->id, '2099-12-31');
        $this->assertArrayHasKey('facturas', $res);
        $this->assertArrayHasKey('totales', $res);
    }

    public function test_cc_proveedores_estructura(): void
    {
        $prov = DB::table('erp_auxiliares')->where('empresa_id', 1)
            ->whereIn('tipo', ['Proveedor', 'Distribuidor'])->first();
        if (! $prov) {
            $this->markTestSkipped('Sin proveedores seedeados');
        }

        $res = app(CtaCorrienteService::class)->proveedores(1, (int) $prov->id, '2099-12-31');
        $this->assertArrayHasKey('facturas', $res);
        $this->assertArrayHasKey('totales', $res);
    }

    public function test_comparativo_requiere_2_periodos_min(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/COMPARATIVO_MIN_2_PERIODOS/');
        app(ComparativoService::class)->calcular(1, 'resultado', ['2025-03']);
    }

    public function test_comparativo_periodo_invalido(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/COMPARATIVO_PERIODO_INVALIDO/');
        app(ComparativoService::class)->calcular(1, 'resultado', ['2025-03', 'no-valido']);
    }

    public function test_comparativo_estructura_devuelve_filas_y_variaciones(): void
    {
        $res = app(ComparativoService::class)->calcular(1, 'resultado', ['2098-01', '2099-01']);

        $this->assertEquals('resultado', $res['reporte']);
        $this->assertCount(2, $res['periodos']);
        $this->assertArrayHasKey('filas', $res);
        // El rango se calcula como del 1 al fin de mes
        $this->assertEquals('2098-01-01', $res['rangos']['2098-01']['desde']);
        $this->assertEquals('2098-01-31', $res['rangos']['2098-01']['hasta']);
    }

    public function test_comparativo_balance_acumula_desde_inicio_anio(): void
    {
        $res = app(ComparativoService::class)->calcular(1, 'balance', ['2098-06', '2099-06']);
        // En balance, el 'desde' es siempre 1 de enero del año.
        $this->assertEquals('2098-01-01', $res['rangos']['2098-06']['desde']);
        $this->assertEquals('2098-06-30', $res['rangos']['2098-06']['hasta']);
    }
}
