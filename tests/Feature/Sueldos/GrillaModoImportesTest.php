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
 * Pedido 2 (testeo Matías 14/07) — la grilla opera en IMPORTES: el
 * tesorero fija Formal($)/MT($) exactos por empleado; Efectivo es
 * residual y no editable. CP-dual-1..4.
 */
class GrillaModoImportesTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-09';

    private User $user;
    private int $empleadoId;
    private Liquidacion $liq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        // Composición default 0/100/0 (100% efectivo) — CP-dual-1.
        $this->empleadoId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZIMP', 'apellido' => 'Test', 'nombre' => 'Importes',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'MIXTO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $this->empleadoId, 'basico_total' => 1736000,
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

    private function fila(): array
    {
        $data = $this->getJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla")->assertOk()->json('data');

        return collect($data['filas'])->firstWhere('empleado_id', $this->empleadoId);
    }

    public function test_cp_dual_1_y_2_formal_exacto_y_efectivo_residual(): void
    {
        $antes = $this->fila();
        $neto = $antes['neto'];

        $this->putJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla", ['filas' => [[
            'empleado_id' => $this->empleadoId,
            'reparto_importes' => ['formal' => 500000, 'mt' => null],
        ]]])->assertOk();

        $f = $this->fila();
        $this->assertEqualsWithDelta(500000, $f['formal'], 0.001, 'FORMAL clavado exacto al peso');
        $this->assertEqualsWithDelta($neto - 500000, $f['efectivo'], 0.001, 'efectivo residual');
        $this->assertEqualsWithDelta($neto, $f['neto'], 0.001, 'el neto no cambia');
        $this->assertEqualsWithDelta(500000, $f['reparto_importes']['formal'], 0.001);
    }

    public function test_cp_dual_3_vaciar_formal_vuelve_todo_al_efectivo(): void
    {
        $this->putJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla", ['filas' => [[
            'empleado_id' => $this->empleadoId,
            'reparto_importes' => ['formal' => 500000, 'mt' => null],
        ]]])->assertOk();

        $this->putJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla", ['filas' => [[
            'empleado_id' => $this->empleadoId,
            'reparto_importes' => ['formal' => null, 'mt' => null],
        ]]])->assertOk();

        $f = $this->fila();
        $this->assertEqualsWithDelta(0, $f['formal'], 0.001, 'sin monto: vuelve al default 100% efectivo');
        $this->assertEqualsWithDelta($f['neto'], $f['efectivo'], 0.001);
        $this->assertNull($f['reparto_importes']['formal']);
    }

    public function test_cp_dual_4_formal_mas_mt_mayor_al_neto_rechaza(): void
    {
        $neto = $this->fila()['neto'];

        $resp = $this->putJson("/api/erp/sueldos/liquidaciones/{$this->liq->id}/grilla", ['filas' => [[
            'empleado_id' => $this->empleadoId,
            'reparto_importes' => ['formal' => $neto, 'mt' => 100000],
        ]]]);
        $resp->assertStatus(422);
        $this->assertSame('REPARTO_EXCEDE_NETO', $resp->json('error.code'));

        // La liquidación quedó intacta (rollback del recálculo).
        $f = $this->fila();
        $this->assertEqualsWithDelta($neto, $f['efectivo'], 0.01, 'sin cambios tras el rechazo');
    }
}
