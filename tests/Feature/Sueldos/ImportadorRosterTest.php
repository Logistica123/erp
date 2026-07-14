<?php

namespace Tests\Feature\Sueldos;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workstream Sueldos, Bloque 2 — G-15: importador del roster real del
 * Excel (ANEXO §6/§7 al 2026-07-02): 30 empleados E001-E030 + 10
 * préstamos PR-001..PR-010 con sus IDs. Idempotente.
 */
class ImportadorRosterTest extends TestCase
{
    use DatabaseTransactions;

    public function test_importa_roster_completo_e_idempotente(): void
    {
        $exit = Artisan::call('sueldos:importar-roster');
        $this->assertSame(0, $exit, Artisan::output());

        // 30 empleados con sus legajos del Excel.
        $this->assertSame(30, DB::table('erp_emp_empleados')->where('legajo', 'like', 'E0%')->count());

        // Condiciones → régimen/composición: Barrios FORMAL_PURO, Zaira y
        // Morell MONOTRIBUTISTA, resto MIXTO.
        $this->assertSame('FORMAL_PURO', DB::table('erp_emp_empleados')->where('legajo', 'E024')->value('regimen'));
        $this->assertSame('MONOTRIBUTISTA', DB::table('erp_emp_empleados')->where('legajo', 'E016')->value('regimen'));
        $this->assertSame('MONOTRIBUTISTA', DB::table('erp_emp_empleados')->where('legajo', 'E021')->value('regimen'));
        $this->assertSame(27, DB::table('erp_emp_empleados')->where('legajo', 'like', 'E0%')->where('regimen', 'MIXTO')->count());

        // Básicos vigentes cargados — salvo Cecilia (E029) y Sofía (E030).
        $conBasico = DB::table('erp_emp_empleados as e')
            ->join('erp_emp_basicos_historial as b', 'b.empleado_id', '=', 'e.id')
            ->where('e.legajo', 'like', 'E0%')->whereNull('b.vigencia_hasta')
            ->count();
        $this->assertSame(28, $conBasico);
        $this->assertStringContainsString('REQUIERE COMPLETAR',
            (string) DB::table('erp_emp_empleados')->where('legajo', 'E029')->value('observaciones'));

        // 10 préstamos con código PR-*; Joel (E019) y Morell (E021) con 2 c/u.
        $this->assertSame(10, DB::table('erp_emp_prestamos')->where('codigo', 'like', 'PR-%')->count());
        $joel = (int) DB::table('erp_emp_empleados')->where('legajo', 'E019')->value('id');
        $this->assertSame(2, DB::table('erp_emp_prestamos')->where('empleado_id', $joel)->count());
        // Cuota combinada de Joel: 600.000 + 84.000.
        $this->assertEqualsWithDelta(684000, (float) DB::table('erp_emp_prestamos')
            ->where('empleado_id', $joel)->where('estado', 'VIGENTE')->sum('cuota_mensual'), 0.01);

        // Saldo derivado correcto (PR-003 Ariel: 901.549,98 − 3×150.258,33).
        $this->assertEqualsWithDelta(450774.99, (float) DB::table('erp_emp_prestamos')
            ->where('codigo', 'PR-003')->value('saldo_capital'), 0.05);

        // Idempotencia: segunda corrida no duplica nada.
        Artisan::call('sueldos:importar-roster');
        $this->assertSame(30, DB::table('erp_emp_empleados')->where('legajo', 'like', 'E0%')->count());
        $this->assertSame(10, DB::table('erp_emp_prestamos')->where('codigo', 'like', 'PR-%')->count());
    }
}
