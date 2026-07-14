<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 3 — G-09: días trabajados.
 *  - Prorrateo automático por fecha de ingreso a mitad de mes (Excel
 *    §3.1: días efectivos desde el ingreso, sobre base 30).
 *  - Override manual por (liquidación, empleado) — la columna "Días
 *    Trab." editable del Excel.
 */
class DiasTrabajadosTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);
    }

    private function crearEmpleado(string $legajo, string $ingreso): int
    {
        $id = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => $legajo, 'apellido' => 'Test', 'nombre' => $legajo,
            'fecha_ingreso' => $ingreso, 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $id, 'basico_total' => 900000,
            'vigencia_desde' => $ingreso, 'vigencia_hasta' => null,
            'motivo' => 'INGRESO', 'aprobado_por_id' => $this->user->id,
            'fecha_aprobacion' => now(), 'created_at' => now(),
        ]);
        DB::table('erp_emp_composicion_sueldo')->insert([
            'empleado_id' => $id, 'porc_formal' => 0, 'porc_efectivo' => 100,
            'porc_mt' => 0, 'vigencia_desde' => $ingreso, 'vigencia_hasta' => null,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function basicoLiquidado(int $liqId, int $empId): float
    {
        return round((float) DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->where('i.liquidacion_id', $liqId)->where('i.empleado_id', $empId)
            ->where('c.codigo', 'BASICO')->sum('i.importe'), 2);
    }

    public function test_ingreso_a_mitad_de_mes_prorratea_sobre_base_30(): void
    {
        // Ingresó el 16/09: días 16..fin de mes sobre base 30 → 30-15 = 15.
        // Básico 900k × 15/30 = 450k. (Antes: mes completo = 900k.)
        $emp = $this->crearEmpleado('ZZG09A', '2030-09-16');

        $liq = Liquidacion::create(['periodo' => '2030-09', 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        $this->assertEqualsWithDelta(450000, $this->basicoLiquidado($liq->id, $emp), 0.01);
    }

    public function test_override_manual_de_dias_gana(): void
    {
        $emp = $this->crearEmpleado('ZZG09B', '2029-01-01');

        $liq = Liquidacion::create(['periodo' => '2030-09', 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        DB::table('erp_emp_liquidacion_reparto_override')->insert([
            'liquidacion_id' => $liq->id, 'empleado_id' => $emp,
            'porc_formal' => 0, 'porc_efectivo' => 0, 'porc_mt' => 0, // sin reparto: usa maestro
            'dias_trabajados' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        // 900k × 20/30 = 600k, y el reparto sigue siendo el del maestro
        // (el override 0/0/0 no debe interpretarse como reparto).
        $this->assertEqualsWithDelta(600000, $this->basicoLiquidado($liq->id, $emp), 0.01);
    }
}
