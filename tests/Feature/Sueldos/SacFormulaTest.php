<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 2 — G-02: SAC con la fórmula de la LCT
 * (art. 121-123): mitad de la MEJOR remuneración mensual devengada del
 * semestre calendario (6 meses, config SAC_MESES), proporcional para
 * quienes ingresaron dentro del semestre, respetando paga_sac.
 *
 * La v1 usaba básico vigente / 2 — con un básico que BAJÓ en el semestre
 * pagaba de menos; sin proporcionalidad pagaba de más a los nuevos.
 */
class SacFormulaTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);
    }

    private function crearEmpleado(string $legajo, string $ingreso, bool $pagaSac = true): int
    {
        $id = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => $legajo, 'apellido' => 'Test', 'nombre' => $legajo,
            'fecha_ingreso' => $ingreso, 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => $pagaSac ? 1 : 0,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_composicion_sueldo')->insert([
            'empleado_id' => $id, 'porc_formal' => 0, 'porc_efectivo' => 100,
            'porc_mt' => 0, 'vigencia_desde' => $ingreso, 'vigencia_hasta' => null,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function basico(int $empId, float $monto, string $desde, ?string $hasta = null): void
    {
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $empId, 'basico_total' => $monto,
            'vigencia_desde' => $desde, 'vigencia_hasta' => $hasta,
            'motivo' => 'CORRECCION', 'aprobado_por_id' => $this->user->id,
            'fecha_aprobacion' => now(), 'created_at' => now(),
        ]);
    }

    private function sacDe(int $liqId, int $empId): float
    {
        return round((float) DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->where('i.liquidacion_id', $liqId)->where('i.empleado_id', $empId)
            ->where('c.codigo', 'SAC')->sum('i.importe'), 2);
    }

    public function test_sac_usa_el_mejor_basico_del_semestre_no_el_vigente(): void
    {
        // Básico 900k ene-abr 2030, BAJÓ a 600k desde mayo. El mejor del
        // 1er semestre es 900k → SAC = 450k (la v1 con vigente/2 daba 300k).
        $emp = $this->crearEmpleado('ZZSAC1', '2029-01-01');
        $this->basico($emp, 900000, '2029-01-01', '2030-04-30');
        $this->basico($emp, 600000, '2030-05-01');

        $liq = Liquidacion::create(['periodo' => '2030-06', 'tipo' => 'SAC', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        $this->assertEqualsWithDelta(450000, $this->sacDe($liq->id, $emp), 0.01);
    }

    public function test_sac_proporcional_para_ingreso_dentro_del_semestre(): void
    {
        // Ingresó el 01/04/2030: trabajó abr-may-jun = 3 de 6 meses.
        // SAC = (800k/2) × 3/6 = 200k.
        $emp = $this->crearEmpleado('ZZSAC2', '2030-04-01');
        $this->basico($emp, 800000, '2030-04-01');

        $liq = Liquidacion::create(['periodo' => '2030-06', 'tipo' => 'SAC', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        $this->assertEqualsWithDelta(200000, $this->sacDe($liq->id, $emp), 0.01);
    }

    public function test_paga_sac_false_queda_afuera(): void
    {
        $emp = $this->crearEmpleado('ZZSAC3', '2029-01-01', pagaSac: false);
        $this->basico($emp, 700000, '2029-01-01');

        $liq = Liquidacion::create(['periodo' => '2030-06', 'tipo' => 'SAC', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        $this->assertSame(0.0, $this->sacDe($liq->id, $emp),
            'empleado con paga_sac=0 no entra en la liquidación SAC');
    }
}
