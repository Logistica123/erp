<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 1 — G-03: hash de integridad del cierre.
 *
 * Equivalente al .bat del Excel que congela fórmulas → valores, con la
 * garantía extra del patrón de asientos (RN-6): al APROBAR se calcula un
 * SHA-256 del snapshot (cabecera + ítems ordenados) y queda persistido.
 * verificarIntegridad() recalcula y compara — cualquier alteración
 * posterior del snapshot se detecta.
 */
class LiquidacionHashIntegridadTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-02';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::firstOrCreate(
            ['email' => 'test.sueldos.g03@logistica.local'],
            ['name' => 'Test G03', 'password' => bcrypt('irrelevante')]
        );
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        $empId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZG03', 'apellido' => 'Test', 'nombre' => 'G03',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $empId, 'basico_total' => 500000,
            'vigencia_desde' => '2029-01-01', 'vigencia_hasta' => null,
            'motivo' => 'INGRESO', 'aprobado_por_id' => $this->user->id,
            'fecha_aprobacion' => now(), 'created_at' => now(),
        ]);
        DB::table('erp_emp_composicion_sueldo')->insert([
            'empleado_id' => $empId, 'porc_formal' => 0, 'porc_efectivo' => 100,
            'porc_mt' => 0, 'vigencia_desde' => '2029-01-01',
            'vigencia_hasta' => null, 'created_at' => now(),
        ]);
    }

    private function liquidacionAprobada(): Liquidacion
    {
        $liq = Liquidacion::create([
            'periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR',
        ]);
        $svc = app(LiquidacionService::class);
        $svc->calcular($liq->fresh(), $this->user->id);

        return $svc->aprobar($liq->fresh(), $this->user->id);
    }

    public function test_aprobar_calcula_y_persiste_hash_sha256(): void
    {
        $liq = $this->liquidacionAprobada();

        $this->assertSame('APROBADA', $liq->estado);
        $this->assertNotNull($liq->hash_integridad, 'aprobar debe sellar el snapshot');
        $this->assertSame(64, strlen($liq->hash_integridad), 'SHA-256 = 64 hex chars');
    }

    public function test_verificar_integridad_detecta_alteracion_del_snapshot(): void
    {
        $svc = app(LiquidacionService::class);
        $liq = $this->liquidacionAprobada();

        $this->assertTrue($svc->verificarIntegridad($liq->fresh()), 'recién aprobada debe verificar OK');

        // Alteración post-cierre: borrar un ítem por DB directa (el trigger
        // RN-113 solo bloquea UPDATE — el DELETE es el vector que el hash cubre).
        DB::table('erp_emp_liquidaciones_items')
            ->where('liquidacion_id', $liq->id)->limit(1)->delete();

        $this->assertFalse($svc->verificarIntegridad($liq->fresh()),
            'la verificación debe detectar el snapshot alterado');
    }
}
