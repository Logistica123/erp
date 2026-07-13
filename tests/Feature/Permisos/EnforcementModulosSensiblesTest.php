<?php

namespace Tests\Feature\Permisos;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Item 8 Fase 2A — enforcement en los 6 módulos de escritura sensible.
 *
 * Por módulo se verifica el contrato de 3 patas pedido por Matías:
 *   (a) usuario sin permiso → 403 PERMISO_REQUERIDO
 *   (b) usuario con el rol/permiso correcto → NO 403 (opera normal)
 *   (c) super_admin → NO 403 (no pierde acceso a nada)
 * Más: modo 'log' no bloquea, y el bypass apagado no bloquea a super_admin
 * porque la matriz le asigna los permisos igualmente (B.7).
 */
class EnforcementModulosSensiblesTest extends TestCase
{
    use DatabaseTransactions;

    private User $sinPermisos;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.permisos_modo' => 'enforce', 'erp.superadmin_bypass' => true]);

        $this->sinPermisos = $this->crearUsuario('sin.permisos@test.local', null);
        $this->superAdmin = $this->crearUsuario('sa.test@test.local', 'super_admin');
    }

    private function crearUsuario(string $email, ?string $rolCodigo): User
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => $email, 'password' => bcrypt('irrelevante-larga')]);
        $perfilId = DB::table('erp_usuario_perfil')->where('user_id', $user->id)->value('id')
            ?? DB::table('erp_usuario_perfil')->insertGetId([
                'user_id' => $user->id, 'empresa_id' => 1, 'acceso_erp' => 1,
                'mfa_habilitado' => 0, 'intentos_fallidos' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        DB::table('erp_usuario_rol')->where('usuario_perfil_id', $perfilId)->delete();
        if ($rolCodigo) {
            $rolId = DB::table('erp_roles')->where('codigo', $rolCodigo)->value('id');
            DB::table('erp_usuario_rol')->insert([
                'usuario_perfil_id' => $perfilId, 'rol_id' => $rolId,
                'asignado_por' => $user->id, 'asignado_en' => now(),
            ]);
        }

        return $user;
    }

    /**
     * [ruta GET representativa, rol con permiso] por módulo. Se usan GETs
     * para no depender de payloads válidos: la pregunta es el GATE (403 o
     * no), no la lógica del endpoint.
     */
    public static function modulosProvider(): array
    {
        return [
            'inversiones' => ['/api/erp/inversiones', 'tesorero'],            // D-8
            'prestamos' => ['/api/erp/prestamos', 'tesorero'],                // D-8
            'saldos_iniciales' => ['/api/erp/tesoreria/cargas-saldo-inicial', 'contador'],
            'impuestos' => ['/api/erp/impuestos/periodos', 'contador'],
            'activos_fijos' => ['/api/erp/af/bienes', 'contador'],
            'presupuestos' => ['/api/erp/presupuestos', 'direccion'],         // D-9
            'cierres_diarios' => ['/api/erp/cierres-diarios', 'contador'],
        ];
    }

    #[DataProvider('modulosProvider')]
    public function test_sin_permiso_recibe_403(string $ruta, string $rol = ''): void
    {
        Sanctum::actingAs($this->sinPermisos, ['*']);
        $r = $this->getJson($ruta);
        $r->assertStatus(403);
        $this->assertSame('PERMISO_REQUERIDO', $r->json('error.code'));
    }

    #[DataProvider('modulosProvider')]
    public function test_con_rol_correcto_no_es_bloqueado(string $ruta, string $rol): void
    {
        Sanctum::actingAs($this->crearUsuario("rol.{$rol}@test.local", $rol), ['*']);
        $r = $this->getJson($ruta);
        $this->assertNotSame(403, $r->status(), "El rol {$rol} debería pasar el gate de {$ruta} (status: {$r->status()})");
    }

    #[DataProvider('modulosProvider')]
    public function test_super_admin_no_pierde_acceso(string $ruta, string $rol = ''): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);
        $r = $this->getJson($ruta);
        $this->assertNotSame(403, $r->status(), "super_admin bloqueado en {$ruta}");
    }

    public function test_modo_log_no_bloquea_pero_loguea(): void
    {
        config(['erp.permisos_modo' => 'log']);
        Sanctum::actingAs($this->sinPermisos, ['*']);

        $r = $this->getJson('/api/erp/inversiones');
        $this->assertNotSame(403, $r->status(), 'En modo log el gate no debe bloquear');
    }

    public function test_bypass_apagado_super_admin_pasa_por_matriz(): void
    {
        // B.7 — robustez: sin bypass, super_admin pasa porque la matriz le
        // asigna los permisos (el seeder los asigna aunque el bypass exista).
        config(['erp.superadmin_bypass' => false]);
        Sanctum::actingAs($this->superAdmin, ['*']);

        foreach (['/api/erp/inversiones', '/api/erp/prestamos', '/api/erp/tesoreria/cargas-saldo-inicial'] as $ruta) {
            $r = $this->getJson($ruta);
            $this->assertNotSame(403, $r->status(), "Bache de matriz: super_admin sin bypass bloqueado en {$ruta}");
        }
    }

    public function test_mi_permisos_mantiene_shape_y_agrega_roles(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);
        $r = $this->getJson('/api/erp/mi-permisos');
        $r->assertOk();
        $this->assertIsArray($r->json('data'));                       // shape original intacto
        $this->assertArrayHasKey('codigo', $r->json('data.0') ?? ['codigo' => null]);
        $this->assertTrue($r->json('es_super_admin'));                // extensión aditiva
        $this->assertSame('super_admin', $r->json('roles.0.codigo'));
    }
}
