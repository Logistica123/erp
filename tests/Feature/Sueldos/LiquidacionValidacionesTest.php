<?php

namespace Tests\Feature\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Models\Sueldos\Prestamo;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 1 — G-10: validaciones del spec §8.
 * "No se puede cerrar el mes si algún empleado tiene neto negativo" —
 * antes, un neto negativo pasaba silenciosamente (el componente después
 * se salteaba al pagar sin ninguna alerta).
 */
class LiquidacionValidacionesTest extends TestCase
{
    use DatabaseTransactions;

    private const PERIODO = '2030-03';

    private User $user;
    private int $empleadoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::firstOrCreate(
            ['email' => 'test.sueldos.g10@logistica.local'],
            ['name' => 'Test G10', 'password' => bcrypt('irrelevante')]
        );
        DB::table('erp_emp_empleados')->update(['activo' => 0]);

        $this->empleadoId = (int) DB::table('erp_emp_empleados')->insertGetId([
            'legajo' => 'ZZG10', 'apellido' => 'Test', 'nombre' => 'G10',
            'fecha_ingreso' => '2029-01-01', 'regimen' => 'EFECTIVO_PURO',
            'jornada_formal_pct' => 0, 'es_vendedor' => 0, 'paga_sac' => 1,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_emp_basicos_historial')->insert([
            'empleado_id' => $this->empleadoId, 'basico_total' => 100000,
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

    public function test_aprobar_rechaza_liquidacion_con_neto_negativo(): void
    {
        // Cuota de préstamo (300k) > básico (100k) → neto negativo.
        Prestamo::create([
            'empleado_id' => $this->empleadoId, 'fecha_otorgamiento' => '2029-12-01',
            'capital' => 900000, 'cuotas_total' => 3, 'cuotas_pagadas' => 0,
            'cuota_mensual' => 300000, 'saldo_capital' => 900000,
            'primera_cuota_periodo' => self::PERIODO,
            'estado' => Prestamo::ESTADO_VIGENTE, 'aprobado_por_id' => $this->user->id,
        ]);

        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        $svc = app(LiquidacionService::class);
        $svc->calcular($liq->fresh(), $this->user->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/NETO_NEGATIVO.*ZZG10/');

        $svc->aprobar($liq->fresh(), $this->user->id);
    }

    public function test_aprobar_acepta_neto_positivo(): void
    {
        $liq = Liquidacion::create(['periodo' => self::PERIODO, 'tipo' => 'MENSUAL', 'estado' => 'BORRADOR']);
        $svc = app(LiquidacionService::class);
        $svc->calcular($liq->fresh(), $this->user->id);

        $liq = $svc->aprobar($liq->fresh(), $this->user->id);
        $this->assertSame('APROBADA', $liq->estado);
    }
}
