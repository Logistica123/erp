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
 * Workstream Sueldos, Bloque 4 — G-06: dashboard "TOTALES POR MES" del
 * Excel + export XLSX de reportes.
 */
class DashboardSueldosTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dashboard_y_export_xlsx(): void
    {
        $user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        $empId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZG06', 'apellido' => 'Test', 'nombre' => 'G06',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $empId, 'basico_total' => 600000,
            'vigencia_desde' => '2029-01-01', 'vigencia_hasta' => null,
            'motivo' => 'INGRESO', 'aprobado_por_id' => $user->id,
            'fecha_aprobacion' => now(), 'created_at' => now(),
        ]);
        DB::table('erp_emp_composicion_sueldo')->insert([
            'empleado_id' => $empId, 'porc_formal' => 0, 'porc_efectivo' => 100,
            'porc_mt' => 0, 'vigencia_desde' => '2029-01-01',
            'vigencia_hasta' => null, 'created_at' => now(),
        ]);

        $svc = app(LiquidacionService::class);
        foreach (['2031-01', '2031-02'] as $periodo) {
            $liq = Liquidacion::create(['periodo' => $periodo, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
            $svc->calcular($liq->fresh(), $user->id);
            $svc->aprobar($liq->fresh(), $user->id);
        }

        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson('/api/erp/sueldos/reportes/dashboard?anio=2031');
        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertCount(2, $data['meses']);
        $this->assertSame('2031-01', $data['meses'][0]['periodo']);
        $this->assertGreaterThan(0, $data['meses'][0]['neto']);
        // Comparación: feb vs ene (mismo básico → 0%).
        $this->assertEqualsWithDelta(0, (float) $data['meses'][1]['variacion_neto_pct'], 0.01);
        $this->assertEqualsWithDelta($data['meses'][0]['neto'] * 2, $data['acumulado']['neto'], 0.02);

        $x = $this->get('/api/erp/sueldos/reportes/export-xlsx?anio=2031&tipo=dashboard');
        $x->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $x->headers->get('content-type'));

        $x2 = $this->get('/api/erp/sueldos/reportes/export-xlsx?anio=2031&tipo=costo-laboral');
        $x2->assertOk();
    }
}
