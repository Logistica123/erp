<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 1 — G-14 (decisión P3 de Matías): el valor
 * hora es `básico / divisor` con divisor CONFIGURABLE, default 240 como
 * el Excel ((básico/30)/8). El módulo tenía 200 hardcodeado.
 */
class ValorHoraConfigurableTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-06';

    private User $user;
    private int $empleadoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        $this->empleadoId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZG14', 'apellido' => 'Test', 'nombre' => 'G14',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $this->empleadoId, 'basico_total' => 480000,
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

    public function test_horas_extra_usan_divisor_240_del_excel(): void
    {
        // 2 horas extra al 50%. Excel: valor hora = 480000/240 = 2000 →
        // HE_50 = 2000 × 1.5 × 2 = 6000. (Con el viejo /200 daría 7200.)
        $conceptoHe50 = (int) DB::table('erp_emp_conceptos')->where('codigo', 'HE_50')->value('id');
        DB::table('erp_emp_novedades')->insert([
            'empleado_id' => $this->empleadoId, 'periodo' => self::PERIODO,
            'concepto_id' => $conceptoHe50, 'cantidad' => 2, 'importe' => null,
            'creado_por_id' => $this->user->id, 'created_at' => now(),
        ]);

        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        $importeHe = (float) DB::table('erp_emp_liquidaciones_items')
            ->where('liquidacion_id', $liq->id)->where('empleado_id', $this->empleadoId)
            ->where('concepto_id', $conceptoHe50)->sum('importe');

        $this->assertEqualsWithDelta(6000.00, $importeHe, 0.01,
            'valor hora debe ser básico/240 (config), no /200');
    }

    public function test_divisor_es_configurable(): void
    {
        config(['erp.sueldos.divisor_valor_hora' => 200]);

        $conceptoHe50 = (int) DB::table('erp_emp_conceptos')->where('codigo', 'HE_50')->value('id');
        DB::table('erp_emp_novedades')->insert([
            'empleado_id' => $this->empleadoId, 'periodo' => self::PERIODO,
            'concepto_id' => $conceptoHe50, 'cantidad' => 1, 'importe' => null,
            'creado_por_id' => $this->user->id, 'created_at' => now(),
        ]);

        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        $importeHe = (float) DB::table('erp_emp_liquidaciones_items')
            ->where('liquidacion_id', $liq->id)->where('empleado_id', $this->empleadoId)
            ->where('concepto_id', $conceptoHe50)->sum('importe');

        // 480000/200 = 2400 × 1.5 × 1 = 3600.
        $this->assertEqualsWithDelta(3600.00, $importeHe, 0.01);
    }
}
