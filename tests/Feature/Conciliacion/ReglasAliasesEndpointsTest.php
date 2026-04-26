<?php

namespace Tests\Feature\Conciliacion;

use App\Erp\Models\Tesoreria\AliasContraparte;
use App\Erp\Models\Tesoreria\ConciliacionRegla;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smoke tests de los endpoints REST de CM-4: CRUD de reglas y aliases.
 */
class ReglasAliasesEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private const H = ['X-Empresa-Id' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first() ?? User::factory()->create();
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_listar_reglas_devuelve_solo_de_mi_empresa(): void
    {
        ConciliacionRegla::create([
            'empresa_id' => 1, 'codigo' => 'TEST-LIS',
            'descripcion' => 'Listado',
            'tipo' => 'CONCEPTO_REGEX', 'patron_concepto' => 'Foo',
            'orden_prioridad' => 100, 'activa' => 1, 'signo' => 'AMBOS',
        ]);

        $r = $this->withHeaders(self::H)->getJson('/api/erp/conciliacion-reglas');
        $r->assertOk();
        $codigos = collect($r->json('data'))->pluck('codigo');
        $this->assertContains('TEST-LIS', $codigos);
    }

    public function test_crear_actualizar_y_borrar_regla(): void
    {
        $cuentaContableId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)->where('imputable', 1)->value('id');

        $r = $this->withHeaders(self::H)->postJson('/api/erp/conciliacion-reglas', [
            'codigo' => 'TEST-CRUD',
            'descripcion' => 'Regla CRUD',
            'tipo' => 'CONCEPTO_REGEX',
            'patron_concepto' => 'PATRON',
            'cuenta_contable_id' => $cuentaContableId,
            'orden_prioridad' => 50,
            'activa' => true,
            'signo' => 'CREDITO',
            'confianza' => 85,
        ]);
        $r->assertCreated();
        $id = $r->json('data.id');
        $this->assertNotNull($id);

        $r = $this->withHeaders(self::H)->patchJson("/api/erp/conciliacion-reglas/{$id}", [
            'descripcion' => 'Modificada',
            'activa' => false,
        ]);
        $r->assertOk();
        $this->assertSame('Modificada', $r->json('data.descripcion'));
        $this->assertEquals(0, $r->json('data.activa'));

        $this->withHeaders(self::H)->deleteJson("/api/erp/conciliacion-reglas/{$id}")->assertOk();
        $this->assertDatabaseMissing('erp_conciliacion_reglas', ['id' => $id]);
    }

    public function test_alias_store_normaliza_y_es_idempotente(): void
    {
        $r1 = $this->withHeaders(self::H)->postJson('/api/erp/alias-contraparte', [
            'alias' => '  Sr. Juan   Perez ',
            'persona_id' => 99,
        ]);
        $r1->assertCreated();

        $r2 = $this->withHeaders(self::H)->postJson('/api/erp/alias-contraparte', [
            'alias' => 'JUAN PEREZ',
            'persona_id' => 100,
        ]);
        $r2->assertCreated();

        $this->assertSame($r1->json('data.id'), $r2->json('data.id'));
        $this->assertSame(100, $r2->json('data.persona_id'));
    }

    public function test_alias_rechaza_sin_contraparte(): void
    {
        $r = $this->withHeaders(self::H)->postJson('/api/erp/alias-contraparte', [
            'alias' => 'NADIE',
        ]);
        $r->assertStatus(422)
            ->assertJsonPath('error.code', 'CONTRAPARTE_REQUERIDA');
    }
}
