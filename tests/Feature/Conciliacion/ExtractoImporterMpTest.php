<?php

namespace Tests\Feature\Conciliacion;

use App\Erp\Models\Tesoreria\Banco;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Tesoreria\ExtractoImporterService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test integración del ExtractoImporter con MatchingContraparteService +
 * detección pasante MP usando el fixture real 2026-03_account_statement.csv.
 */
class ExtractoImporterMpTest extends TestCase
{
    use DatabaseTransactions;

    public function test_importa_csv_mp_real_y_detecta_pares_pasantes(): void
    {
        // Usa o crea un Banco con codigo_parser=MP (la tabla erp_bancos no
        // tiene empresa_id — es global).
        $banco = Banco::query()->where('codigo_parser', 'MERCADO_PAGO')->first()
            ?? Banco::query()->create([
                'codigo' => 'MP-TEST', 'nombre' => 'MercadoPago Test',
                'codigo_parser' => 'MERCADO_PAGO',
            ]);

        $cuentaContableId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)->where('imputable', 1)->value('id');
        $cuenta = CuentaBancaria::create([
            'empresa_id' => 1,
            'banco_id' => $banco->id,
            'cuenta_contable_id' => $cuentaContableId,
            'moneda_id' => 1,
            'codigo' => 'MP'.substr(uniqid(), -6),
            'nombre' => 'MP Test',
            'tipo' => 'CC',
            'numero_cuenta' => '999',
            'saldo_actual' => 0,
            'activo' => 1,
        ]);

        $fixture = base_path('tests/Fixtures/cierres/mp/2026-03_account_statement.csv');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'mp_');
        copy($fixture, $tmp);

        $svc = app(ExtractoImporterService::class);
        $user = User::first() ?? User::factory()->create();
        $r = $svc->importar($tmp, $cuenta, $user, '2026-03_account_statement.csv');

        $this->assertGreaterThan(0, $r['movimientos_importados']);

        // Pasantes detectados: en el fixture hay al menos un par
        // "Ingreso de dinero" + "Pago de servicio" con mismo REFERENCE_ID.
        $pasantes = MovimientoBancario::where('extracto_id', $r['extracto_id'])
            ->where('etiqueta_sugerida', 'PASANTE_MP')
            ->count();
        $this->assertGreaterThanOrEqual(2, $pasantes,
            'Debería haber al menos 2 movs marcados como PASANTE_MP (1 par)');

        // Rendimientos auto-conciliados por la regla seedada en CB-2-bis.
        // (Si la regla está activa en la DB de tests, con confianza_match >= 80).
        $rendimientos = MovimientoBancario::where('extracto_id', $r['extracto_id'])
            ->where('concepto', 'LIKE', 'Rendimientos%')
            ->whereNotNull('regla_aplicada_id')
            ->count();
        $this->assertGreaterThan(0, $rendimientos,
            'Debería haber al menos un Rendimientos matcheado por regla');

        @unlink($tmp);
    }
}
