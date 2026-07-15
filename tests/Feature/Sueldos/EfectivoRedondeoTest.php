<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Erp\Services\Sueldos\PagosSueldosService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 1 — G-01: redondeo del efectivo a múltiplos
 * de 500 hacia arriba (CEILING del Excel) + reporte "efectivo a preparar".
 *
 * El pago en efectivo entrega billetes: se redondea ceil(x/500)*500, la
 * diferencia queda contabilizada explícita (cuenta configurable) y el
 * tesorero puede pedir ANTES de pagar cuánto efectivo preparar.
 */
class EfectivoRedondeoTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-05';

    private User $user;
    private int $empleadoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        $this->empleadoId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZG01', 'apellido' => 'Test', 'nombre' => 'G01',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Básico elegido para que el neto NO sea múltiplo de 500.
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $this->empleadoId, 'basico_total' => 456789,
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

    private function liquidacionAprobada(): Liquidacion
    {
        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        $svc = app(LiquidacionService::class);
        $svc->calcular($liq->fresh(), $this->user->id);

        return $svc->aprobar($liq->fresh(), $this->user->id);
    }

    private function netoEfectivo(Liquidacion $liq): float
    {
        return round((float) DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->where('i.liquidacion_id', $liq->id)
            ->where('i.empleado_id', $this->empleadoId)
            ->where('i.componente', 'EFECTIVO')
            ->selectRaw("SUM(CASE WHEN c.signo='HABER' THEN i.importe ELSE -i.importe END) neto")
            ->value('neto'), 2);
    }

    public function test_pago_efectivo_redondea_a_500_y_contabiliza_la_diferencia(): void
    {
        $liq = $this->liquidacionAprobada();
        $neto = $this->netoEfectivo($liq);
        $this->assertGreaterThan(0, $neto);
        $esperadoEntregado = ceil($neto / 500) * 500;
        $esperadaDif = round($esperadoEntregado - $neto, 2);
        $this->assertGreaterThan(0, $esperadaDif, 'el básico de fixture debe generar diferencia de redondeo');

        $cajaId = (int) DB::table('erp_cajas')->where('activo', 1)->value('id');
        DB::table('erp_cajas')->where('id', $cajaId)->update(['saldo_actual' => 50000000]);
        $resultado = app(PagosSueldosService::class)->pagarEfectivo(
            $liq->fresh(), $cajaId, now()->toDateString(),
            [['empleado_id' => $this->empleadoId, 'recibido_por' => 'Test G01', 'dni_recibio' => '11222333']],
            $this->user->id,
        );

        // El resultado informa lo entregado y la diferencia.
        $this->assertArrayHasKey('efectivo', $resultado);
        $fila = collect($resultado['efectivo'])->firstWhere('empleado_id', $this->empleadoId);
        $this->assertNotNull($fila);
        $this->assertEqualsWithDelta($esperadoEntregado, $fila['entregado'], 0.01);
        $this->assertEqualsWithDelta($esperadaDif, $fila['diferencia_redondeo'], 0.01);

        // El asiento acredita la caja por lo ENTREGADO y la diferencia va
        // a la cuenta configurada, explícita.
        $pagoId = $resultado['pagos'][0];
        $asientoId = (int) DB::table('erp_emp_pagos')->where('id', $pagoId)->value('asiento_id');
        $cajaCuentaId = (int) DB::table('erp_cajas')->where('id', $cajaId)->value('cuenta_contable_id');
        $haberCaja = (float) DB::table('erp_movimientos_asiento')
            ->where('asiento_id', $asientoId)->where('cuenta_id', $cajaCuentaId)->sum('haber');
        $this->assertEqualsWithDelta($esperadoEntregado, $haberCaja, 0.01, 'la caja entrega billetes redondeados');

        $ctaDif = (string) config('erp.sueldos.cuenta_dif_redondeo');
        $ctaDifId = (int) DB::table('erp_cuentas_contables')->where('codigo', $ctaDif)->value('id');
        $debeDif = (float) DB::table('erp_movimientos_asiento')
            ->where('asiento_id', $asientoId)->where('cuenta_id', $ctaDifId)->sum('debe');
        $this->assertEqualsWithDelta($esperadaDif, $debeDif, 0.01, 'la diferencia queda contabilizada explícita');

        // La liquidación cierra igual (el pago salda el neto).
        $this->assertSame('PAGADA', $liq->fresh()->estado);
    }

    public function test_endpoint_efectivo_a_preparar(): void
    {
        $liq = $this->liquidacionAprobada();
        $neto = $this->netoEfectivo($liq);
        $esperadoEntregado = ceil($neto / 500) * 500;

        Sanctum::actingAs($this->user, ['*']);
        $resp = $this->getJson("/api/erp/sueldos/liquidaciones/{$liq->id}/efectivo-a-preparar");
        $resp->assertOk();

        $data = $resp->json('data');
        $this->assertEqualsWithDelta($esperadoEntregado, $data['total_a_preparar'], 0.01);
        $this->assertCount(1, $data['empleados']);
        $this->assertEqualsWithDelta($neto, $data['empleados'][0]['neto_efectivo'], 0.01);
        $this->assertEqualsWithDelta($esperadoEntregado, $data['empleados'][0]['a_entregar'], 0.01);
    }
}
