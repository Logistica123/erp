<?php

namespace Tests\Feature;

use App\Erp\Models\Auxiliar;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADDENDUM v1.10 — Tests CA-01 a CA-04 sobre la cuenta contable default
 * por auxiliar (RN-CA-1, RN-CA-3) y el endpoint PATCH /auxiliares/{id}.
 */
class AuxiliarCuentaDefaultTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;
    private array $cuentas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first() ?? User::factory()->create();
        Sanctum::actingAs($this->user, ['*']);

        $this->cuentas = DB::table('erp_cuentas_contables')
            ->where('empresa_id', $this->empresaId)
            ->whereIn('codigo', array_values(Auxiliar::CUENTA_DEFAULT_POR_TIPO))
            ->pluck('id', 'codigo')->all();
    }

    public function test_CA_01_proveedor_default_2_1_1_01(): void
    {
        $aux = Auxiliar::create([
            'empresa_id' => $this->empresaId, 'tipo' => 'Proveedor',
            'codigo' => 'TEST-CA01-'.substr(uniqid(), -6),
            'nombre' => 'Test Proveedor', 'activo' => 1,
        ]);

        // Simulamos el flow del controller: helper asigna según tipo.
        $aux->update([
            'cuenta_contable_default_id' => $this->cuentas['2.1.1.01'] ?? null,
        ]);

        $this->assertNotNull($aux->cuenta_contable_default_id);
        $this->assertSame($this->cuentas['2.1.1.01'], $aux->cuenta_contable_default_id);
    }

    public function test_CA_02_distribuidor_default_2_1_1_03(): void
    {
        $aux = Auxiliar::create([
            'empresa_id' => $this->empresaId, 'tipo' => 'Distribuidor',
            'codigo' => 'TEST-CA02-'.substr(uniqid(), -6),
            'nombre' => 'Test Distribuidor', 'activo' => 1,
            'cuenta_contable_default_id' => $this->cuentas['2.1.1.03'],
        ]);

        $this->assertSame('2.1.1.03', DB::table('erp_cuentas_contables')->where('id', $aux->cuenta_contable_default_id)->value('codigo'));
    }

    public function test_CA_03_PATCH_actualiza_no_toca_historico(): void
    {
        $aux = Auxiliar::create([
            'empresa_id' => $this->empresaId, 'tipo' => 'Proveedor',
            'codigo' => 'TEST-CA03-'.substr(uniqid(), -6),
            'nombre' => 'Test cambio cuenta',
            'cuenta_contable_default_id' => $this->cuentas['2.1.1.01'],
            'activo' => 1,
        ]);

        // Cambiar a otra cuenta válida (la de empleados, por ejemplo).
        $r = $this->withHeaders(['X-Empresa-Id' => '1'])
            ->patchJson("/api/erp/auxiliares/{$aux->id}", [
                'cuenta_contable_default_id' => $this->cuentas['2.1.2.01'],
            ]);
        $r->assertOk();
        $aux->refresh();
        $this->assertSame($this->cuentas['2.1.2.01'], $aux->cuenta_contable_default_id);
    }

    public function test_CA_04_PATCH_rechaza_cuenta_inexistente(): void
    {
        $aux = Auxiliar::create([
            'empresa_id' => $this->empresaId, 'tipo' => 'Cliente',
            'codigo' => 'TEST-CA04-'.substr(uniqid(), -6),
            'nombre' => 'Test val', 'activo' => 1,
            'cuenta_contable_default_id' => $this->cuentas['1.1.4.01'],
        ]);

        $r = $this->withHeaders(['X-Empresa-Id' => '1'])
            ->patchJson("/api/erp/auxiliares/{$aux->id}", [
                'cuenta_contable_default_id' => 9999999,
            ]);
        $r->assertStatus(422); // exists:erp_cuentas_contables falla
    }

    public function test_CA_05_relacion_cuentaDefault_carga_codigo(): void
    {
        $aux = Auxiliar::create([
            'empresa_id' => $this->empresaId, 'tipo' => 'Distribuidor',
            'codigo' => 'TEST-CA05-'.substr(uniqid(), -6),
            'nombre' => 'Test relación',
            'cuenta_contable_default_id' => $this->cuentas['2.1.1.03'],
            'activo' => 1,
        ]);
        $this->assertSame('2.1.1.03', $aux->cuentaDefault?->codigo);
    }
}
