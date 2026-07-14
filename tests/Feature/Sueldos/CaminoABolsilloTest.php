<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 2 — Camino A (decisión P2 de Matías,
 * validada con criterio técnico): el ERP liquida "neto de bolsillo".
 * Los recibos formales AFIP y sus descuentos legales (JUB 11%, OS 3%,
 * ley 19032, sindicato) los sigue calculando LIBER — el ERP NO los
 * aplica sobre el componente FORMAL.
 *
 * Config erp.sueldos.aplicar_descuentos_legales (default false = Camino
 * A). Si algún día se reemplaza LIBER completo (Camino B), se prende.
 */
class CaminoABolsilloTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-07';

    private User $user;
    private int $empleadoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        // Empleado MIXTO 50/50 — el caso donde los legales pegaban.
        $this->empleadoId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZP2', 'apellido' => 'Test', 'nombre' => 'CaminoA',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'MIXTO',
            'jornada_formal_pct' => 50, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $this->empleadoId, 'basico_total' => 1000000,
            'vigencia_desde' => '2029-01-01', 'vigencia_hasta' => null,
            'motivo' => 'INGRESO', 'aprobado_por_id' => $this->user->id,
            'fecha_aprobacion' => now(), 'created_at' => now(),
        ]);
        DB::table('erp_emp_composicion_sueldo')->insert([
            'empleado_id' => $this->empleadoId, 'porc_formal' => 50,
            'porc_efectivo' => 50, 'porc_mt' => 0,
            'vigencia_desde' => '2029-01-01', 'vigencia_hasta' => null,
            'created_at' => now(),
        ]);
    }

    private function itemsLegales(int $liqId): int
    {
        return (int) DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->where('i.liquidacion_id', $liqId)
            ->whereIn('c.codigo', ['JUB_11', 'OS_3', 'LEY_19032', 'SINDICATO', 'GANANCIAS_4TA'])
            ->count();
    }

    public function test_camino_a_no_aplica_descuentos_legales_sobre_formal(): void
    {
        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        $this->assertSame(0, $this->itemsLegales($liq->id),
            'Camino A: el ERP no debe generar ítems de descuentos legales (los liquida LIBER)');

        // El neto de bolsillo nunca baja del básico: no hay retención legal
        // (los haberes automáticos como presentismo pueden sumarlo).
        $this->assertGreaterThanOrEqual(1000000, (float) $liq->fresh()->total_neto);
        $this->assertEqualsWithDelta(0, (float) $liq->fresh()->total_descuentos, 0.01,
            'sin préstamos ni novedades, el bolsillo no tiene descuentos');
    }

    public function test_camino_b_configurable_los_reactiva(): void
    {
        config(['erp.sueldos.aplicar_descuentos_legales' => true]);

        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        $this->assertGreaterThan(0, $this->itemsLegales($liq->id),
            'con la config prendida (Camino B futuro) los legales vuelven');
    }
}
