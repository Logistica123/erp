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
 * Workstream Sueldos, Bloque 2 — G-07 (P1 Matías): % fijo default +
 * override manual mensual por empleado. El override vive por
 * (liquidación, empleado), NO toca el maestro, y recalcular lo respeta.
 */
class RepartoOverrideTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-08';

    private User $user;
    private int $empleadoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        // Default del maestro: 100% EFECTIVO.
        $this->empleadoId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZG07', 'apellido' => 'Test', 'nombre' => 'G07',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'MIXTO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $this->empleadoId, 'basico_total' => 1000000,
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

    private function porcFormalDeLaLiq(int $liqId): float
    {
        // Se mide sobre el BASICO (afecta los 3 componentes) — otros
        // conceptos con flags parciales (presentismo solo-formal)
        // distorsionarían el porcentaje.
        $totales = DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->where('i.liquidacion_id', $liqId)->where('i.empleado_id', $this->empleadoId)
            ->whereIn('c.codigo', ['BASICO', 'BASICO_PROP'])
            ->selectRaw("i.componente, SUM(i.importe) total")
            ->groupBy('i.componente')->pluck('total', 'componente');
        $todo = (float) $totales->sum();

        return $todo > 0 ? round(((float) ($totales['FORMAL'] ?? 0)) / $todo * 100, 1) : 0.0;
    }

    public function test_override_pisa_la_composicion_solo_en_esa_liquidacion(): void
    {
        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);
        $this->assertSame(0.0, $this->porcFormalDeLaLiq($liq->id), 'default del maestro: 0% formal');

        Sanctum::actingAs($this->user, ['*']);
        $this->putJson("/api/erp/sueldos/liquidaciones/{$liq->id}/reparto/{$this->empleadoId}", [
            'porc_formal' => 40, 'porc_efectivo' => 60, 'porc_mt' => 0,
            'observaciones' => 'Pidió más por recibo este mes',
        ])->assertOk();

        // El endpoint recalcula: ahora 40% formal SOLO en esta liquidación.
        $this->assertSame(40.0, $this->porcFormalDeLaLiq($liq->id));

        // El maestro no cambió.
        $this->assertSame(100.0, (float) DB::table('erp_emp_composicion_sueldo')
            ->where('empleado_id', $this->empleadoId)->whereNull('vigencia_hasta')->value('porc_efectivo'));

        // Quitar el override vuelve al default.
        $this->deleteJson("/api/erp/sueldos/liquidaciones/{$liq->id}/reparto/{$this->empleadoId}")->assertOk();
        $this->assertSame(0.0, $this->porcFormalDeLaLiq($liq->id));
    }

    public function test_valida_suma_100_y_estado_editable(): void
    {
        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        $svc = app(LiquidacionService::class);
        $svc->calcular($liq->fresh(), $this->user->id);

        Sanctum::actingAs($this->user, ['*']);
        $this->putJson("/api/erp/sueldos/liquidaciones/{$liq->id}/reparto/{$this->empleadoId}", [
            'porc_formal' => 40, 'porc_efectivo' => 40, 'porc_mt' => 0,
        ])->assertStatus(422);

        $svc->aprobar($liq->fresh(), $this->user->id);
        $this->putJson("/api/erp/sueldos/liquidaciones/{$liq->id}/reparto/{$this->empleadoId}", [
            'porc_formal' => 40, 'porc_efectivo' => 60, 'porc_mt' => 0,
        ])->assertStatus(422);
    }
}
