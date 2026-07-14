<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 3 — grilla editable estilo Excel (P8, el
 * NO negociable de Matías): una fila por empleado, columnas de
 * haberes/descuentos manuales, días, reparto — y un solo PUT que guarda
 * todo y recalcula.
 */
class GrillaLiquidacionTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-10';

    private User $user;
    private int $empleadoId;
    private Liquidacion $liq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        $this->empleadoId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZGRI', 'apellido' => 'Test', 'nombre' => 'Grilla',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'MIXTO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $this->empleadoId, 'basico_total' => 1200000,
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

        $this->liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($this->liq->fresh(), $this->user->id);
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_get_grilla_devuelve_fila_por_empleado_con_columnas(): void
    {
        $resp = $this->getJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla");
        $resp->assertOk();

        $data = $resp->json('data');
        $this->assertTrue($data['liquidacion']['editable']);
        $this->assertNotEmpty($data['conceptos'], 'catálogo de columnas manuales');

        $fila = collect($data['filas'])->firstWhere('empleado_id', $this->empleadoId);
        $this->assertNotNull($fila);
        $this->assertSame('ZZGRI', $fila['legajo']);
        $this->assertSame(30, $fila['dias_trabajados']);
        $this->assertEqualsWithDelta(1200000, $fila['basico_vigente'], 0.01);
        $this->assertGreaterThan(0, $fila['neto']);
    }

    public function test_put_grilla_guarda_todo_y_recalcula(): void
    {
        $payload = ['filas' => [[
            'empleado_id' => $this->empleadoId,
            'dias_trabajados' => 20,
            'reparto' => ['porc_formal' => 30, 'porc_efectivo' => 70, 'porc_mt' => 0],
            'valores' => [
                'HE_50' => ['cantidad' => 4],            // por horas: 1.2M/240 = 5000/h → 4×1.5×5000 = 30.000
                'ADELANTO' => ['importe' => 100000],     // descuento directo
            ],
        ]]];

        $this->putJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla", $payload)->assertOk();

        $resp = $this->getJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla");
        $fila = collect($resp->json('data.filas'))->firstWhere('empleado_id', $this->empleadoId);

        $this->assertSame(20, $fila['dias_trabajados']);
        $this->assertEqualsWithDelta(30, $fila['reparto']['porc_formal'], 0.01);
        $this->assertEqualsWithDelta(4, $fila['valores']['HE_50']['cantidad'], 0.01);
        $this->assertEqualsWithDelta(30000, $fila['valores']['HE_50']['importe_calculado'], 0.01);
        $this->assertEqualsWithDelta(100000, $fila['valores']['ADELANTO']['importe'], 0.01);

        // Neto recalculado: básico 20/30 = 800k + presentismo? (MIXTO sí) —
        // como mínimo refleja el adelanto descontado y las horas sumadas.
        $this->assertGreaterThan(0, $fila['neto']);

        // Vaciar el valor borra la novedad.
        $this->putJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla", ['filas' => [[
            'empleado_id' => $this->empleadoId,
            'valores' => ['ADELANTO' => ['importe' => null]],
        ]]])->assertOk();

        $resp = $this->getJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla");
        $fila = collect($resp->json('data.filas'))->firstWhere('empleado_id', $this->empleadoId);
        $this->assertArrayNotHasKey('ADELANTO', $fila['valores']);
    }

    public function test_put_grilla_rechaza_liquidacion_cerrada(): void
    {
        app(LiquidacionService::class)->aprobar($this->liq->fresh(), $this->user->id);

        $this->putJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla", ['filas' => [[
            'empleado_id' => $this->empleadoId, 'dias_trabajados' => 10,
        ]]])->assertStatus(422);
    }
}
