<?php

namespace Tests\Feature\Presupuesto;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Presupuesto\Presupuesto;
use App\Erp\Models\Presupuesto\PresupuestoItem;
use App\Erp\Services\Presupuesto\PresupuestoService;
use App\Erp\Services\Presupuesto\VariacionesService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests del módulo Presupuestos (RN-85/86/87/88).
 */
class PresupuestoTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('erp_presupuestos')) {
            $this->markTestSkipped('DDL_06 I1 no aplicado');
        }
        $this->user = User::firstOrCreate(
            ['email' => 'test.i4@logistica.local'],
            ['name' => 'Test I4', 'password' => bcrypt('irrelevante')]
        );
    }

    // ----- CRUD + transiciones -----

    public function test_crear_presupuesto_borrador(): void
    {
        $ej = $this->crearEjercicio(2069);
        $p = app(PresupuestoService::class)->crear([
            'empresa_id' => 1, 'ejercicio_id' => $ej->id,
            'nombre' => 'Presupuesto 2069',
        ], $this->user);

        $this->assertEquals('BORRADOR', $p->estado);
        $this->assertFalse($p->es_reforecast);
        $this->assertEquals($this->user->id, $p->creado_por);
    }

    public function test_transicion_BORRADOR_a_APROBADO_a_VIGENTE(): void
    {
        $p = $this->crear(2068);
        $svc = app(PresupuestoService::class);
        $p = $svc->transicionar($p, 'APROBADO', $this->user);
        $this->assertEquals('APROBADO', $p->estado);
        $this->assertEquals($this->user->id, $p->aprobado_por);

        $p = $svc->transicionar($p, 'VIGENTE', $this->user);
        $this->assertEquals('VIGENTE', $p->estado);
    }

    public function test_RN85_segundo_VIGENTE_pasa_anterior_a_HISTORICO(): void
    {
        $svc = app(PresupuestoService::class);

        $p1 = $this->crear(2067);
        $p1 = $svc->transicionar($p1, 'APROBADO', $this->user);
        $p1 = $svc->transicionar($p1, 'VIGENTE', $this->user);

        // Segundo presupuesto del mismo ejercicio.
        $p2 = app(PresupuestoService::class)->crear([
            'empresa_id' => 1, 'ejercicio_id' => $p1->ejercicio_id,
            'nombre' => 'Presupuesto 2067 v2',
        ], $this->user);
        $p2 = $svc->transicionar($p2, 'APROBADO', $this->user);
        $p2 = $svc->transicionar($p2, 'VIGENTE', $this->user);

        $this->assertEquals('HISTORICO', $p1->fresh()->estado);
        $this->assertEquals('VIGENTE', $p2->fresh()->estado);
    }

    public function test_transicion_invalida_falla(): void
    {
        $p = $this->crear(2066);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/PRESUPUESTO_TRANSICION_INVALIDA/');
        app(PresupuestoService::class)->transicionar($p, 'VIGENTE', $this->user);
    }

    // ----- Items + RN-88 -----

    public function test_bulk_items_persiste_y_actualiza(): void
    {
        $p = $this->crear(2065);
        $cuenta = $this->cuentaImputable();
        $cc = (int) DB::table('erp_centros_costo')->where('empresa_id', 1)->value('id');

        $res = app(PresupuestoService::class)->bulkItems($p, [
            ['cuenta_id' => $cuenta, 'centro_costo_id' => $cc, 'mes' => 1, 'importe' => 100_000],
            ['cuenta_id' => $cuenta, 'centro_costo_id' => $cc, 'mes' => 2, 'importe' => 110_000],
        ], $this->user);

        $this->assertEquals(2, $res['insertadas']);
        $this->assertEquals(0, $res['actualizadas']);

        // Re-bulk con mismos items + uno modificado.
        $res = app(PresupuestoService::class)->bulkItems($p, [
            ['cuenta_id' => $cuenta, 'centro_costo_id' => $cc, 'mes' => 1, 'importe' => 200_000],
            ['cuenta_id' => $cuenta, 'centro_costo_id' => $cc, 'mes' => 3, 'importe' => 130_000],
        ], $this->user);

        $this->assertEquals(1, $res['insertadas']);
        $this->assertEquals(1, $res['actualizadas']);
    }

    public function test_RN88_cuenta_no_imputable_rechaza(): void
    {
        $p = $this->crear(2064);
        $cuentaNoImputable = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)->where('imputable', 0)->value('id');
        if (! $cuentaNoImputable) {
            $this->markTestSkipped('Sin cuentas no-imputables seedeadas');
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/PRESUPUESTO_CUENTA_NO_IMPUTABLE/');
        app(PresupuestoService::class)->bulkItems($p, [
            ['cuenta_id' => $cuentaNoImputable, 'mes' => 1, 'importe' => 1000],
        ], $this->user);
    }

    public function test_no_se_pueden_editar_items_si_no_es_BORRADOR(): void
    {
        $svc = app(PresupuestoService::class);
        $p = $this->crear(2063);
        $p = $svc->transicionar($p, 'APROBADO', $this->user);
        $p = $svc->transicionar($p, 'VIGENTE', $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/PRESUPUESTO_NO_EDITABLE/');
        $svc->bulkItems($p, [['cuenta_id' => $this->cuentaImputable(), 'mes' => 1, 'importe' => 100]], $this->user);
    }

    // ----- Reforecast (RN-86) -----

    public function test_reforecast_clona_items_y_apunta_al_base(): void
    {
        $svc = app(PresupuestoService::class);

        $base = $this->crear(2062);
        $cuenta = $this->cuentaImputable();
        $svc->bulkItems($base, [
            ['cuenta_id' => $cuenta, 'mes' => 1, 'importe' => 100_000],
            ['cuenta_id' => $cuenta, 'mes' => 2, 'importe' => 110_000],
        ], $this->user);
        $base = $svc->transicionar($base, 'APROBADO', $this->user);
        $base = $svc->transicionar($base, 'VIGENTE', $this->user);

        $reforecast = $svc->reforecast($base, 'Reforecast Q2 2062', $this->user);

        $this->assertTrue($reforecast->es_reforecast);
        $this->assertEquals($base->id, $reforecast->forecast_base_id);
        $this->assertEquals('BORRADOR', $reforecast->estado);

        $itemsClonados = PresupuestoItem::where('presupuesto_id', $reforecast->id)->count();
        $this->assertEquals(2, $itemsClonados);
    }

    public function test_reforecast_requiere_VIGENTE(): void
    {
        $p = $this->crear(2061);  // BORRADOR
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/PRESUPUESTO_REFORECAST_REQUIERE_VIGENTE/');
        app(PresupuestoService::class)->reforecast($p, 'Reforecast', $this->user);
    }

    // ----- Variaciones (RN-87) -----

    public function test_variaciones_devuelve_estructura(): void
    {
        $svc = app(PresupuestoService::class);
        $ej = $this->crearEjercicio(2060);
        $p = app(PresupuestoService::class)->crear([
            'empresa_id' => 1, 'ejercicio_id' => $ej->id,
            'nombre' => 'Pres 2060',
        ], $this->user);
        $cuenta = $this->cuentaImputable();
        $svc->bulkItems($p, [
            ['cuenta_id' => $cuenta, 'mes' => 1, 'importe' => 50_000],
            ['cuenta_id' => $cuenta, 'mes' => 2, 'importe' => 60_000],
        ], $this->user);

        $res = app(VariacionesService::class)->detalle($p);
        $this->assertCount(2, $res['filas']);
        $this->assertArrayHasKey('totales', $res);
        $this->assertEqualsWithDelta(110_000.0, $res['totales']['presupuesto'], 0.01);
        // Sin asientos reales del ejercicio, real=0 → variación = -110.000.
        $this->assertEqualsWithDelta(0.0, $res['totales']['real'], 0.01);
    }

    public function test_resumen_agrupa_por_cuenta_o_cc(): void
    {
        $svc = app(PresupuestoService::class);
        $ej = $this->crearEjercicio(2059);
        $p = $svc->crear(['empresa_id' => 1, 'ejercicio_id' => $ej->id, 'nombre' => 'P59'], $this->user);
        $cuenta = $this->cuentaImputable();
        $svc->bulkItems($p, [
            ['cuenta_id' => $cuenta, 'mes' => 1, 'importe' => 100],
            ['cuenta_id' => $cuenta, 'mes' => 2, 'importe' => 200],
        ], $this->user);

        $resumen = app(VariacionesService::class)->resumen($p, 'cuenta');
        $this->assertEquals('cuenta', $resumen['agrupado_por']);
        $this->assertCount(1, $resumen['filas']);  // misma cuenta los 2 meses
        $this->assertEqualsWithDelta(300.0, $resumen['filas'][0]['presupuesto'], 0.01);
    }

    public function test_ejecucion_devuelve_semaforo(): void
    {
        $svc = app(PresupuestoService::class);
        $ej = $this->crearEjercicio(2058);
        $p = $svc->crear(['empresa_id' => 1, 'ejercicio_id' => $ej->id, 'nombre' => 'P58'], $this->user);
        $cuenta = $this->cuentaImputable();
        $svc->bulkItems($p, [
            ['cuenta_id' => $cuenta, 'mes' => 1, 'importe' => 100],
        ], $this->user);

        $res = app(VariacionesService::class)->ejecucion($p, 1);
        $this->assertCount(1, $res['filas']);
        $this->assertArrayHasKey('semaforo', $res['filas'][0]);
    }

    // ------------------------------------------------------------------------

    private function crearEjercicio(int $numero): Ejercicio
    {
        return Ejercicio::create([
            'empresa_id' => 1, 'numero' => $numero,
            'nombre' => "Ej {$numero}",
            'fecha_inicio' => sprintf('%04d-01-01', $numero),
            'fecha_cierre' => sprintf('%04d-12-31', $numero),
            'estado' => 'ABIERTO',
        ]);
    }

    private function crear(int $numero): Presupuesto
    {
        $ej = $this->crearEjercicio($numero);
        return app(PresupuestoService::class)->crear([
            'empresa_id' => 1, 'ejercicio_id' => $ej->id,
            'nombre' => "Pres {$numero}",
        ], $this->user);
    }

    private function cuentaImputable(): int
    {
        return (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)
            ->where('imputable', 1)
            ->where('tipo', 'RN')
            ->value('id');
    }
}
