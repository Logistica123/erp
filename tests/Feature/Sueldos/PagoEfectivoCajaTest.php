<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Erp\Services\Sueldos\PagosSueldosService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 4 — G-12: el pago de sueldos en efectivo
 * tiene que impactar la CAJA REAL del módulo Cajas (v1.42): baja
 * saldo_actual por lo ENTREGADO en billetes (redondeado), para que el
 * próximo arqueo cierre. Antes solo se generaba el asiento contable y
 * el arqueo veía un faltante fantasma.
 */
class PagoEfectivoCajaTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-11';

    public function test_pagar_efectivo_descuenta_lo_entregado_de_la_caja(): void
    {
        $user = User::first();
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        $empId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZG12', 'apellido' => 'Test', 'nombre' => 'G12',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $empId, 'basico_total' => 456789,
            'vigencia_desde' => '2029-01-01', 'vigencia_hasta' => null,
            'motivo' => 'INGRESO', 'aprobado_por_id' => $user->id,
            'fecha_aprobacion' => now(), 'created_at' => now(),
        ]);
        DB::table('erp_emp_composicion_sueldo')->insert([
            'empleado_id' => $empId, 'porc_formal' => 0, 'porc_efectivo' => 100,
            'porc_mt' => 0, 'vigencia_desde' => '2029-01-01',
            'vigencia_hasta' => null, 'created_at' => now(),
        ]);

        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        $svc = app(LiquidacionService::class);
        $svc->calcular($liq->fresh(), $user->id);
        $svc->aprobar($liq->fresh(), $user->id);

        $cajaId = (int) DB::table('erp_cajas')->where('activo', 1)->value('id');
        DB::table('erp_cajas')->where('id', $cajaId)->update(['saldo_actual' => 10000000]);

        $resultado = app(PagosSueldosService::class)->pagarEfectivo(
            $liq->fresh(), $cajaId, now()->toDateString(),
            [['empleado_id' => $empId, 'recibido_por' => 'Test G12', 'dni_recibio' => '99887766']],
            $user->id,
        );

        $entregado = (float) $resultado['efectivo'][0]['entregado'];
        $this->assertGreaterThan(0, $entregado);

        $saldoFinal = (float) DB::table('erp_cajas')->where('id', $cajaId)->value('saldo_actual');
        $this->assertEqualsWithDelta(10000000 - $entregado, $saldoFinal, 0.01,
            'la caja debe bajar por lo ENTREGADO (billetes redondeados) — G-12');
    }
}
