<?php

namespace Tests\Feature;

use App\Erp\Services\SaldosService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Auditoría 2026-07-12 bug #5 — recomputarCuentaPeriodo() (fine-grained,
 * invocado tras contabilizar cada asiento) pisaba saldo_inicial=0,
 * destruyendo el encadenamiento que propagarSaldosAlSiguientePeriodo()
 * había dejado al cerrar el período anterior. recomputarPeriodo() (bulk)
 * lo preservaba — inconsistencia entre ambos caminos.
 */
class SaldosEncadenadosTest extends TestCase
{
    use DatabaseTransactions;

    public function test_recompute_fino_preserva_saldo_inicial_encadenado(): void
    {
        $empresaId = 1;
        $periodoId = (int) DB::table('erp_periodos')->orderBy('id')->value('id');
        $cuentaId = (int) DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)
            ->where('imputable', 1)->orderBy('id')->value('id');

        // Simula el encadenado que dejó el cierre del período anterior.
        DB::table('erp_saldos_cuenta')->updateOrInsert(
            ['empresa_id' => $empresaId, 'cuenta_id' => $cuentaId, 'periodo_id' => $periodoId],
            ['saldo_inicial' => 500.00, 'debitos' => 0, 'creditos' => 0, 'actualizado_en' => now()],
        );

        app(SaldosService::class)->recomputarCuentaPeriodo($cuentaId, $periodoId);

        $fila = DB::table('erp_saldos_cuenta')
            ->where(['empresa_id' => $empresaId, 'cuenta_id' => $cuentaId, 'periodo_id' => $periodoId])
            ->first();

        $this->assertNotNull($fila);
        $this->assertEquals(500.00, (float) $fila->saldo_inicial,
            'El recompute fino no debe pisar el saldo_inicial heredado del período anterior');
    }

    public function test_recompute_fino_de_cuenta_sin_fila_previa_arranca_en_cero(): void
    {
        $empresaId = 1;
        $periodoId = (int) DB::table('erp_periodos')->orderBy('id')->value('id');
        $cuentaId = (int) DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)
            ->where('imputable', 1)->orderByDesc('id')->value('id');

        DB::table('erp_saldos_cuenta')
            ->where(['empresa_id' => $empresaId, 'cuenta_id' => $cuentaId, 'periodo_id' => $periodoId])
            ->delete();

        app(SaldosService::class)->recomputarCuentaPeriodo($cuentaId, $periodoId);

        $fila = DB::table('erp_saldos_cuenta')
            ->where(['empresa_id' => $empresaId, 'cuenta_id' => $cuentaId, 'periodo_id' => $periodoId])
            ->first();

        $this->assertNotNull($fila, 'El insert de la fila nueva debe seguir funcionando');
        $this->assertEquals(0.0, (float) $fila->saldo_inicial, 'Sin herencia previa, arranca en 0 (default de la columna)');
    }
}
