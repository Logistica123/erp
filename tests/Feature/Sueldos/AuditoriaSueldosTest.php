<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Models\Sueldos\Prestamo;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Erp\Services\Sueldos\PagosSueldosService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 4 — G-04: auditoría transversal. El módulo
 * no logueaba NADA (hallazgo del gap analysis); el spec §5.4 exige que
 * toda acción quede en el audit log con usuario y descripción.
 */
class AuditoriaSueldosTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-12';

    private User $user;
    private int $empleadoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        $this->empleadoId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZG04', 'apellido' => 'Test', 'nombre' => 'G04',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $this->empleadoId, 'basico_total' => 700000,
            'vigencia_desde' => '2029-01-01', 'vigencia_hasta' => null,
            'motivo' => 'INGRESO', 'aprobado_por_id' => $this->user->id,
            'fecha_aprobacion' => now(), 'created_at' => now(),
        ]);
        DB::table('erp_emp_composicion_sueldo')->insert([
            'empleado_id' => $this->empleadoId, 'porc_formal' => 0,
            'porc_efectivo' => 100, 'porc_mt' => 0,
            'vigencia_desde' => '2029-01-01', 'vigencia_hasta' => null,
            'created_at' => now(),
        ]);
    }

    private function acciones(): array
    {
        return DB::table('erp_audit_log')->where('modulo', 'sueldos')
            ->pluck('accion')->all();
    }

    public function test_flujo_completo_queda_auditado(): void
    {
        $svc = app(LiquidacionService::class);
        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        $svc->calcular($liq->fresh(), $this->user->id);
        $svc->aprobar($liq->fresh(), $this->user->id);

        $cajaId = (int) DB::table('erp_cajas')->where('activo', 1)->value('id');
        DB::table('erp_cajas')->where('id', $cajaId)->update(['saldo_actual' => 50000000]);
        app(PagosSueldosService::class)->pagarEfectivo(
            $liq->fresh(), $cajaId, now()->toDateString(),
            [['empleado_id' => $this->empleadoId, 'recibido_por' => 'Test', 'dni_recibio' => '1']],
            $this->user->id,
        );

        $acciones = $this->acciones();
        foreach (['LIQUIDACION_CALCULADA', 'LIQUIDACION_APROBADA', 'SUELDOS_PAGO_EFECTIVO'] as $esperada) {
            $this->assertContains($esperada, $acciones, "falta auditar {$esperada}");
        }
    }

    public function test_prestamos_y_basicos_quedan_auditados(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        // Alta de préstamo por endpoint.
        $this->postJson('/api/erp/sueldos/prestamos', [
            'empleado_id' => $this->empleadoId, 'capital' => 90000,
            'cuotas_total' => 3, 'cuota_mensual' => 30000,
            'fecha_otorgamiento' => '2030-11-01', 'primera_cuota_periodo' => '2030-12',
        ])->assertStatus(201);

        $prestamoId = (int) DB::table('erp_emp_prestamos')->where('empleado_id', $this->empleadoId)->value('id');
        $this->postJson("/api/erp/sueldos/prestamos/{$prestamoId}/pausar", ['motivo' => 'Prueba G-04'])->assertOk();

        // Cambio de sueldo básico por endpoint.
        $this->postJson("/api/erp/sueldos/empleados/{$this->empleadoId}/basicos", [
            'basico_total' => 800000, 'vigencia_desde' => '2031-01-01',
            'motivo' => 'AUMENTO_GERENCIAL',
        ])->assertStatus(201);

        $acciones = $this->acciones();
        foreach (['PRESTAMO_EMP_OTORGADO', 'PRESTAMO_EMP_PAUSADO', 'BASICO_APROBADO'] as $esperada) {
            $this->assertContains($esperada, $acciones, "falta auditar {$esperada}");
        }
    }
}
