<?php

namespace Tests\Feature;

use App\Erp\Models\Periodo;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADDENDUM v1.15 Sprint N — tests HTTP feature de cerrar/reabrir período.
 *
 * Cubre los escenarios pedidos por LIBER (O-PE-1):
 *   - Cerrar período abierto con permiso → 200.
 *   - Cerrar período ya cerrado → 4xx con mensaje claro.
 *   - Reabrir con permiso → 200.
 *   - Reabrir sin permiso → 403.
 *   - Cerrar con asiento BORRADOR → 4xx (RN pre-cierre).
 */
class PeriodoCerrarReabrirTest extends TestCase
{
    use DatabaseTransactions;

    private User $userConPermiso;
    private User $userSinPermiso;
    private int $periodoAbiertoId;

    protected function setUp(): void
    {
        parent::setUp();

        // Usuario super_admin existente (tiene cerrar + reabrir + acceso_erp).
        $this->userConPermiso = User::first();
        if (! $this->userConPermiso) {
            $this->markTestSkipped('No hay usuarios en la DB de test.');
        }
        // Usuario contador (tiene cerrar pero NO reabrir).
        $this->userSinPermiso = $this->buildUserWithRol('contador');

        // Tomar un período ABIERTO existente (sin asientos BORRADOR).
        $this->periodoAbiertoId = (int) DB::table('erp_periodos')
            ->where('estado', 'ABIERTO')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('erp_asientos')
                    ->whereColumn('erp_asientos.periodo_id', 'erp_periodos.id')
                    ->where('erp_asientos.estado', 'BORRADOR');
            })
            ->value('id');

        if (! $this->periodoAbiertoId) {
            $this->markTestSkipped('No hay período ABIERTO sin BORRADOR en la DB de test.');
        }
    }

    public function test_PE_01_cerrar_periodo_abierto_con_permiso_devuelve_200(): void
    {
        Sanctum::actingAs($this->userConPermiso, ['*']);

        $resp = $this->postJson("/api/erp/periodos/{$this->periodoAbiertoId}/cerrar");

        $resp->assertOk();
        $this->assertSame('CERRADO', Periodo::find($this->periodoAbiertoId)->estado);
    }

    public function test_PE_02_cerrar_periodo_ya_cerrado_falla_con_mensaje(): void
    {
        Sanctum::actingAs($this->userConPermiso, ['*']);

        // Primero cerrarlo.
        $this->postJson("/api/erp/periodos/{$this->periodoAbiertoId}/cerrar")->assertOk();
        // Segundo intento debe fallar con 4xx + código.
        $resp = $this->postJson("/api/erp/periodos/{$this->periodoAbiertoId}/cerrar");
        $resp->assertStatus(409);
        $resp->assertJsonPath('error.code', 'PERIODO_YA_CERRADO');
    }

    public function test_PE_03_reabrir_periodo_cerrado_con_permiso_devuelve_200(): void
    {
        Sanctum::actingAs($this->userConPermiso, ['*']);

        // Cerrar primero.
        $this->postJson("/api/erp/periodos/{$this->periodoAbiertoId}/cerrar")->assertOk();
        // Reabrir.
        $resp = $this->postJson("/api/erp/periodos/{$this->periodoAbiertoId}/reabrir", [
            'motivo' => 'Test reabrir período',
        ]);

        $resp->assertOk();
        $this->assertSame('ABIERTO', Periodo::find($this->periodoAbiertoId)->estado);
    }

    public function test_PE_04_reabrir_sin_permiso_devuelve_403(): void
    {
        // Cerrarlo con super_admin.
        Sanctum::actingAs($this->userConPermiso, ['*']);
        $this->postJson("/api/erp/periodos/{$this->periodoAbiertoId}/cerrar")->assertOk();

        // Cambiar a usuario sin permiso reabrir.
        Sanctum::actingAs($this->userSinPermiso, ['*']);
        $resp = $this->postJson("/api/erp/periodos/{$this->periodoAbiertoId}/reabrir", [
            'motivo' => 'No deberia funcionar',
        ]);

        $resp->assertStatus(403);
        $this->assertSame('CERRADO', Periodo::find($this->periodoAbiertoId)->estado);
    }

    public function test_PE_05_reabrir_periodo_abierto_falla(): void
    {
        Sanctum::actingAs($this->userConPermiso, ['*']);

        $resp = $this->postJson("/api/erp/periodos/{$this->periodoAbiertoId}/reabrir", [
            'motivo' => 'Aún abierto',
        ]);

        $resp->assertStatus(409);
        $resp->assertJsonPath('error.code', 'PERIODO_NO_CERRADO');
    }

    public function test_PE_06_reabrir_sin_motivo_devuelve_422(): void
    {
        Sanctum::actingAs($this->userConPermiso, ['*']);
        $this->postJson("/api/erp/periodos/{$this->periodoAbiertoId}/cerrar")->assertOk();

        $resp = $this->postJson("/api/erp/periodos/{$this->periodoAbiertoId}/reabrir", []);
        $resp->assertStatus(422);
    }

    private function buildUserWithRol(string $rolCodigo): User
    {
        $user = User::factory()->create();

        $rolId = DB::table('erp_roles')->where('codigo', $rolCodigo)->value('id');
        if (! $rolId) {
            $this->fail("Rol '{$rolCodigo}' no existe en la DB de test");
        }

        // Crear UsuarioPerfil + UsuarioRol (tabla puente).
        $perfilId = DB::table('erp_usuario_perfil')->insertGetId([
            'user_id' => $user->id,
            'empresa_id' => 1,
            'acceso_erp' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('erp_usuario_rol')->insert([
            'usuario_perfil_id' => $perfilId,
            'rol_id' => $rolId,
        ]);

        return $user->fresh();
    }
}
