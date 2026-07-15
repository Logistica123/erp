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
 * Workstream Sueldos, Bloque 1 — G-08 (decisión P4 de Matías):
 *  - estado PAUSADO: congela el préstamo (no descuenta cuota, no avanza)
 *    sin perder el registro; endpoints pausar/reanudar.
 *  - la auto-cancelación al cumplir cuotas queda como default PERO el
 *    pago devuelve la alerta de qué préstamos se cancelaron (para la
 *    confirmación visual del tesorero en la UI).
 */
class PrestamosPausadoTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-04';

    private User $user;
    private int $empleadoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        $this->empleadoId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZG08', 'apellido' => 'Test', 'nombre' => 'G08',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $this->empleadoId, 'basico_total' => 800000,
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

    private function crearPrestamo(string $estado, int $pagadas = 0, int $total = 3): Prestamo
    {
        return Prestamo::create([
            'empleado_id' => $this->empleadoId, 'fecha_otorgamiento' => '2029-12-01',
            'capital' => 30000 * $total, 'cuotas_total' => $total,
            'cuotas_pagadas' => $pagadas, 'cuota_mensual' => 30000,
            'saldo_capital' => 30000 * ($total - $pagadas),
            'primera_cuota_periodo' => self::PERIODO,
            'estado' => $estado, 'aprobado_por_id' => $this->user->id,
        ]);
    }

    public function test_prestamo_pausado_no_descuenta_cuota(): void
    {
        $this->crearPrestamo(Prestamo::ESTADO_PAUSADO);

        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        $items = DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->where('i.liquidacion_id', $liq->id)->where('c.codigo', 'PRESTAMO_CUOTA')
            ->count();
        $this->assertSame(0, $items, 'un préstamo PAUSADO no debe descontar cuota');
    }

    public function test_pausar_y_reanudar_via_endpoints(): void
    {
        $p = $this->crearPrestamo(Prestamo::ESTADO_VIGENTE);
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson("/api/erp/sueldos/prestamos/{$p->id}/pausar", ['motivo' => 'Licencia del empleado'])
            ->assertOk();
        $this->assertSame(Prestamo::ESTADO_PAUSADO, $p->fresh()->estado);

        // No se puede pausar dos veces.
        $this->postJson("/api/erp/sueldos/prestamos/{$p->id}/pausar", ['motivo' => 'Otra vez cualquiera'])
            ->assertStatus(422);

        $this->postJson("/api/erp/sueldos/prestamos/{$p->id}/reanudar")->assertOk();
        $this->assertSame(Prestamo::ESTADO_VIGENTE, $p->fresh()->estado);
    }

    public function test_pago_informa_prestamos_cancelados_al_cumplir_cuotas(): void
    {
        // Última cuota pendiente: al pagar la liquidación se auto-cancela
        // y el resultado lo tiene que INFORMAR (alerta para el tesorero).
        $p = $this->crearPrestamo(Prestamo::ESTADO_VIGENTE, pagadas: 2, total: 3);

        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        $svc = app(LiquidacionService::class);
        $svc->calcular($liq->fresh(), $this->user->id);
        $svc->aprobar($liq->fresh(), $this->user->id);

        $cajaId = (int) DB::table('erp_cajas')->where('activo', 1)->value('id');
        DB::table('erp_cajas')->where('id', $cajaId)->update(['saldo_actual' => 50000000]);
        $resultado = app(PagosSueldosService::class)->pagarEfectivo(
            $liq->fresh(), $cajaId, now()->toDateString(),
            [['empleado_id' => $this->empleadoId, 'recibido_por' => 'Test G08', 'dni_recibio' => '87654321']],
            $this->user->id,
        );

        $this->assertSame(Prestamo::ESTADO_CANCELADO, $p->fresh()->estado);
        $this->assertArrayHasKey('prestamos_cancelados', $resultado);
        $this->assertCount(1, $resultado['prestamos_cancelados']);
        $this->assertSame($p->id, $resultado['prestamos_cancelados'][0]['id']);
    }
}
