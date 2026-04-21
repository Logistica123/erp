<?php

namespace Tests\Feature;

use App\Erp\Models\Asiento;
use App\Erp\Models\Ejercicio;
use App\Erp\Models\Permiso;
use App\Erp\Models\Rol;
use App\Erp\Models\UsuarioPerfil;
use App\Erp\Policies\AsientoPolicy;
use App\Erp\Policies\EjercicioPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifica que las policies bloquean correctamente a usuarios sin el permiso
 * correspondiente (403 vs 200/201/204) y permiten al super_admin.
 */
class PoliciesTest extends TestCase
{
    use DatabaseTransactions;

    private AsientoPolicy $asientoPolicy;
    private EjercicioPolicy $ejercicioPolicy;
    private User $userSinPermiso;
    private User $userSuperAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asientoPolicy = new AsientoPolicy();
        $this->ejercicioPolicy = new EjercicioPolicy();

        // Super admin (reusa user existente de dev)
        $this->userSuperAdmin = User::firstOrCreate(
            ['email' => 'fmorell@logisticaargentinasrl.com.ar'],
            ['name' => 'Francisco Morell', 'password' => bcrypt('x')]
        );

        // User sin permisos: creamos uno con un rol vacío
        $this->userSinPermiso = User::firstOrCreate(
            ['email' => 'test.sinpermiso@logistica.local'],
            ['name' => 'Sin Permisos', 'password' => bcrypt('x')]
        );

        $perfil = UsuarioPerfil::firstOrCreate(
            ['user_id' => $this->userSinPermiso->id, 'empresa_id' => 1],
            ['legajo' => 'SIN-P', 'mfa_habilitado' => false, 'acceso_erp' => true]
        );

        // Rol vacío: se crea sin permisos asignados
        $rolVacio = Rol::firstOrCreate(
            ['codigo' => 'test_sin_permisos'],
            ['nombre' => 'Rol de test sin permisos', 'nivel_jerarquia' => 99, 'protegido' => 0, 'activo' => 1]
        );
        $perfil->roles()->sync([$rolVacio->id => ['asignado_en' => now()]]);
    }

    public function test_super_admin_puede_crear_asientos(): void
    {
        $this->assertTrue(
            $this->asientoPolicy->create($this->userSuperAdmin),
            'super_admin debe tener contabilidad.asientos.crear'
        );
    }

    public function test_user_sin_permiso_no_puede_crear_asientos(): void
    {
        $this->assertFalse(
            $this->asientoPolicy->create($this->userSinPermiso),
            'user sin permiso debe recibir false en create()'
        );
    }

    public function test_user_sin_permiso_no_puede_contabilizar(): void
    {
        $asiento = Asiento::first() ?? $this->crearAsientoMock();

        $this->assertFalse(
            $this->asientoPolicy->contabilizar($this->userSinPermiso, $asiento),
            'sin permiso contabilidad.asientos.contabilizar → false'
        );
    }

    public function test_super_admin_puede_cerrar_ejercicio(): void
    {
        $ej = Ejercicio::first();
        $this->assertNotNull($ej);
        $this->assertTrue($this->ejercicioPolicy->cerrar($this->userSuperAdmin, $ej));
    }

    public function test_user_sin_permiso_no_puede_cerrar_ejercicio(): void
    {
        $ej = Ejercicio::first();
        $this->assertFalse($this->ejercicioPolicy->cerrar($this->userSinPermiso, $ej));
    }

    public function test_user_con_permiso_especifico_puede_la_accion_correspondiente(): void
    {
        // Asigno solo el permiso "ver" al rol del user sin permisos
        $permisoVer = Permiso::where('codigo', 'contabilidad.asientos.ver')->first();
        $this->assertNotNull($permisoVer);

        $rolVacio = Rol::where('codigo', 'test_sin_permisos')->firstOrFail();
        DB::table('erp_rol_permiso')->insert([
            'rol_id' => $rolVacio->id,
            'permiso_id' => $permisoVer->id,
            'created_at' => now(),
        ]);

        // Ahora debería poder viewAny() pero no create()
        $this->assertTrue(
            $this->asientoPolicy->viewAny($this->userSinPermiso->fresh()),
            'con permiso ver debería poder viewAny'
        );
        $this->assertFalse(
            $this->asientoPolicy->create($this->userSinPermiso->fresh()),
            'aún sin permiso crear no puede create'
        );
    }

    public function test_policy_valida_misma_empresa(): void
    {
        // Creo un ejercicio ficticio de empresa_id diferente (99)
        $ejOtraEmpresa = new Ejercicio([
            'empresa_id' => 99,
            'numero' => 99,
            'nombre' => 'Test',
            'fecha_inicio' => '2099-01-01',
            'fecha_cierre' => '2099-12-31',
            'estado' => 'ABIERTO',
        ]);

        // super_admin tiene el permiso pero su perfil es empresa_id=1, no 99
        $this->assertFalse(
            $this->ejercicioPolicy->cerrar($this->userSuperAdmin, $ejOtraEmpresa),
            'el usuario no puede tocar ejercicios de otra empresa aunque tenga el permiso'
        );
    }

    private function crearAsientoMock(): Asiento
    {
        $a = new Asiento();
        $a->id = 999;
        $a->empresa_id = 1;
        $a->estado = Asiento::ESTADO_BORRADOR;
        return $a;
    }
}
