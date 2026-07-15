<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Models\Sueldos\Prestamo;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Erp\Services\Sueldos\PagosSueldosService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 1 — G-13 (bug real detectado en el gap
 * analysis 2026-07-13): `avanzarPrestamosCuotas` avanzaba las cuotas de
 * TODOS los préstamos VIGENTES de la empresa al pagarse una liquidación,
 * sin filtrar por los préstamos cuya cuota fue efectivamente descontada
 * en ESA liquidación. Un préstamo otorgado después del cálculo (o de un
 * empleado no liquidado) veía su cuota avanzada sin habérsele descontado
 * nada.
 *
 * Contrato correcto: solo avanzan los préstamos con ítem PRESTAMO_CUOTA
 * imputado en la liquidación pagada.
 */
class PrestamosAvanceCuotasTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-01';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::firstOrCreate(
            ['email' => 'test.sueldos.g13@logistica.local'],
            ['name' => 'Test G13', 'password' => bcrypt('irrelevante')]
        );
        // Aislamiento: ningún otro empleado activo debe entrar a la
        // liquidación de fixture (prod está virgen; por las dudas).
        DB::table('erp_emp_empleados')->update(['activo' => 0]);
    }

    private function crearEmpleado(string $legajo): int
    {
        $id = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => $legajo, 'apellido' => 'Test', 'nombre' => $legajo,
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $id, 'basico_total' => 900000,
            'vigencia_desde' => '2029-01-01', 'vigencia_hasta' => null,
            'motivo' => 'INGRESO', 'aprobado_por_id' => $this->user->id,
            'fecha_aprobacion' => now(), 'created_at' => now(),
        ]);
        DB::table('erp_emp_composicion_sueldo')->insert([
            'empleado_id' => $id, 'porc_formal' => 0, 'porc_efectivo' => 100,
            'porc_mt' => 0, 'vigencia_desde' => '2029-01-01',
            'vigencia_hasta' => null, 'created_at' => now(),
        ]);

        return $id;
    }

    private function crearPrestamo(int $empleadoId, float $cuota, int $cuotas): Prestamo
    {
        return Prestamo::create([
            'empleado_id' => $empleadoId, 'fecha_otorgamiento' => '2029-12-01',
            'capital' => $cuota * $cuotas, 'cuotas_total' => $cuotas,
            'cuotas_pagadas' => 0, 'cuota_mensual' => $cuota,
            'saldo_capital' => $cuota * $cuotas,
            'primera_cuota_periodo' => self::PERIODO,
            'estado' => Prestamo::ESTADO_VIGENTE,
            'aprobado_por_id' => $this->user->id,
        ]);
    }

    public function test_solo_avanzan_prestamos_con_cuota_imputada_en_la_liquidacion(): void
    {
        // Empleado A con préstamo A1: SU cuota entra en la liquidación.
        $empA = $this->crearEmpleado('ZZG13A');
        $prestamoA = $this->crearPrestamo($empA, 1000, 3);

        $liq = Liquidacion::create([
            'periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR',
        ]);
        app(LiquidacionService::class)->calcular($liq->fresh(), $this->user->id);

        // Préstamo B1 creado DESPUÉS del cálculo (empleado B ni liquidado):
        // su cuota NO está imputada en esta liquidación.
        $empB = $this->crearEmpleado('ZZG13B');
        $prestamoB = $this->crearPrestamo($empB, 5000, 4);

        // Sanidad: la liquidación tiene ítem PRESTAMO_CUOTA solo del préstamo A.
        $itemsPrestamo = DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->where('i.liquidacion_id', $liq->id)->where('c.codigo', 'PRESTAMO_CUOTA')
            ->pluck('i.observaciones');
        $this->assertNotEmpty($itemsPrestamo);
        $this->assertStringContainsString('#'.$prestamoA->id, implode(' ', $itemsPrestamo->all()));

        // Aprobar y pagar TODO el componente EFECTIVO (único activo).
        $liq->fresh()->update(['estado' => 'APROBADA', 'fecha_aprobacion' => now(), 'aprobado_por_id' => $this->user->id]);
        $cajaId = (int) DB::table('erp_cajas')->where('activo', 1)->value('id');
        DB::table('erp_cajas')->where('id', $cajaId)->update(['saldo_actual' => 50000000]);

        app(PagosSueldosService::class)->pagarEfectivo(
            $liq->fresh(), $cajaId, now()->toDateString(),
            [['empleado_id' => $empA, 'recibido_por' => 'Test G13', 'dni_recibio' => '12345678']],
            $this->user->id,
        );

        $this->assertSame('PAGADA', $liq->fresh()->estado, 'la liquidación debe quedar PAGADA');

        // El préstamo A avanzó su cuota…
        $this->assertSame(1, (int) $prestamoA->fresh()->cuotas_pagadas, 'préstamo A debe avanzar a 1/3');
        $this->assertEqualsWithDelta(2000, (float) $prestamoA->fresh()->saldo_capital, 0.01);

        // …y el préstamo B (sin cuota imputada) NO se toca. Este assert es
        // el que reproduce el bug: el código viejo lo avanzaba igual.
        $this->assertSame(0, (int) $prestamoB->fresh()->cuotas_pagadas,
            'préstamo B NO tuvo cuota en esta liquidación — no debe avanzar');
        $this->assertEqualsWithDelta(20000, (float) $prestamoB->fresh()->saldo_capital, 0.01);
    }
}
