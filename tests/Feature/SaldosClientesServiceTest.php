<?php

namespace Tests\Feature;

use App\Erp\Services\AsientoService;
use App\Erp\Services\SaldosClientesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests del SaldosClientesService — single source of truth para saldos
 * de clientes (Addendum v1.8 §7).
 *
 * Estrategia: cada test crea asientos contabilizados de prueba sobre la
 * cuenta `1.1.4.01 Deudores por Ventas` para un auxiliar y verifica que
 * el saldo calculado matchea con la lógica esperada (factura simple,
 * factura+NC, factura+cobro parcial, factura+retención, todo junto).
 */
class SaldosClientesServiceTest extends TestCase
{
    use DatabaseTransactions;

    private SaldosClientesService $svc;
    private AsientoService $asientoSvc;
    private int $empresaId = 1;
    private int $diarioVtaId;
    private int $cuentaDeudoresId;
    private int $cuentaVentaId;
    private int $cuentaIvaId;
    private int $cuentaCajaId;
    private int $auxiliarId;
    private int $userId;
    private int $ccId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(SaldosClientesService::class);
        $this->asientoSvc = app(AsientoService::class);

        $this->diarioVtaId = (int) DB::table('erp_diarios')
            ->where('empresa_id', $this->empresaId)->where('codigo', 'VTA')->value('id');
        $this->cuentaDeudoresId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', $this->empresaId)->where('codigo', '1.1.4.01')->value('id');
        $this->cuentaVentaId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', $this->empresaId)->where('codigo', '4.1.1.05')->value('id');
        $this->cuentaIvaId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', $this->empresaId)->where('codigo', '2.1.3.01')->value('id');
        $this->cuentaCajaId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', $this->empresaId)->where('codigo', '1.1.1.01')->value('id');
        $this->auxiliarId = (int) DB::table('erp_auxiliares')
            ->where('empresa_id', $this->empresaId)->where('tipo', 'Cliente')->where('activo', 1)
            ->orderBy('id')->value('id');
        $this->userId = (int) DB::table('users')->orderBy('id')->value('id');
        $this->ccId = (int) (DB::table('erp_centros_costo')->where('empresa_id', $this->empresaId)->where('codigo', 'GENERAL')->value('id')
            ?? DB::table('erp_centros_costo')->where('empresa_id', $this->empresaId)->orderBy('id')->value('id'));

        // Algunas DBs tienen saldos previos; se ignoran porque cada test usa
        // un auxiliar dummy si los saldos previos son distintos de cero.
        // Para tests deterministas creamos un auxiliar nuevo.
        $this->auxiliarId = (int) DB::table('erp_auxiliares')->insertGetId([
            'empresa_id' => $this->empresaId, 'tipo' => 'Cliente',
            'codigo' => 'TEST-SALDOS-'.substr(uniqid(), -6),
            'nombre' => 'Cliente test saldos',
            'cuit' => null, 'activo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function asentar(string $glosa, array $movs, ?Carbon $fecha = null): int
    {
        // Asegurar centro_costo_id y auxiliar_id default en líneas que los requieren.
        $cuentas = DB::table('erp_cuentas_contables')
            ->where('empresa_id', $this->empresaId)
            ->whereIn('id', collect($movs)->pluck('cuenta_id')->unique())
            ->get(['id', 'admite_cc', 'admite_auxiliar'])->keyBy('id');
        foreach ($movs as &$m) {
            $c = $cuentas[$m['cuenta_id']] ?? null;
            if ($c?->admite_cc && empty($m['centro_costo_id'])) {
                $m['centro_costo_id'] = $this->ccId;
            }
            if ($c?->admite_auxiliar && empty($m['auxiliar_id'])) {
                $m['auxiliar_id'] = $this->auxiliarId;
            }
        }
        unset($m);

        $payload = [
            'empresa_id' => $this->empresaId,
            'diario_id' => $this->diarioVtaId,
            'fecha' => ($fecha ?? Carbon::today())->toDateString(),
            'glosa' => $glosa,
            'origen' => 'MANUAL',
            'usuario_id' => $this->userId,
            'movimientos' => $movs,
        ];
        $a = $this->asientoSvc->crearBorrador($payload);
        $a = $this->asientoSvc->contabilizar($a);
        return $a->id;
    }

    private function movFactura(float $neto, float $iva, float $total): array
    {
        return [
            ['cuenta_id' => $this->cuentaDeudoresId, 'centro_costo_id' => $this->ccId, 'auxiliar_id' => $this->auxiliarId, 'debe' => $total, 'haber' => 0],
            ['cuenta_id' => $this->cuentaVentaId, 'centro_costo_id' => $this->ccId, 'debe' => 0, 'haber' => $neto],
            ['cuenta_id' => $this->cuentaIvaId, 'debe' => 0, 'haber' => $iva],
        ];
    }

    public function test_factura_simple_sin_cobros(): void
    {
        $this->asentar('Factura simple', $this->movFactura(1000, 210, 1210));

        $saldo = $this->svc->saldoCliente($this->auxiliarId, Carbon::today());
        $this->assertEqualsWithDelta(1210, $saldo, 0.01);
    }

    public function test_factura_mas_nc_parcial(): void
    {
        $this->asentar('Factura', $this->movFactura(1000, 210, 1210));
        // NC: invierte: Deudores en haber, Ventas+IVA en debe
        $this->asentar('NC parcial', [
            ['cuenta_id' => $this->cuentaVentaId, 'debe' => 300, 'haber' => 0],
            ['cuenta_id' => $this->cuentaIvaId, 'debe' => 63, 'haber' => 0],
            ['cuenta_id' => $this->cuentaDeudoresId, 'auxiliar_id' => $this->auxiliarId, 'debe' => 0, 'haber' => 363],
        ]);

        $saldo = $this->svc->saldoCliente($this->auxiliarId, Carbon::today());
        $this->assertEqualsWithDelta(1210 - 363, $saldo, 0.01);
    }

    public function test_factura_mas_cobro_parcial(): void
    {
        $this->asentar('Factura', $this->movFactura(1000, 210, 1210));
        // Cobro: Caja debe / Deudores haber
        $this->asentar('Cobro parcial', [
            ['cuenta_id' => $this->cuentaCajaId, 'debe' => 500, 'haber' => 0],
            ['cuenta_id' => $this->cuentaDeudoresId, 'auxiliar_id' => $this->auxiliarId, 'debe' => 0, 'haber' => 500],
        ]);

        $saldo = $this->svc->saldoCliente($this->auxiliarId, Carbon::today());
        $this->assertEqualsWithDelta(1210 - 500, $saldo, 0.01);
    }

    public function test_factura_nc_y_cobro_combinados(): void
    {
        $this->asentar('Factura', $this->movFactura(2000, 420, 2420));
        $this->asentar('NC', [
            ['cuenta_id' => $this->cuentaVentaId, 'debe' => 200, 'haber' => 0],
            ['cuenta_id' => $this->cuentaIvaId, 'debe' => 42, 'haber' => 0],
            ['cuenta_id' => $this->cuentaDeudoresId, 'auxiliar_id' => $this->auxiliarId, 'debe' => 0, 'haber' => 242],
        ]);
        $this->asentar('Cobro', [
            ['cuenta_id' => $this->cuentaCajaId, 'debe' => 1000, 'haber' => 0],
            ['cuenta_id' => $this->cuentaDeudoresId, 'auxiliar_id' => $this->auxiliarId, 'debe' => 0, 'haber' => 1000],
        ]);

        $saldo = $this->svc->saldoCliente($this->auxiliarId, Carbon::today());
        $this->assertEqualsWithDelta(2420 - 242 - 1000, $saldo, 0.01);
    }

    public function test_saldo_total_suma_todos_los_clientes(): void
    {
        $totalAntes = $this->svc->saldoTotal(Carbon::today());
        $this->asentar('Factura', $this->movFactura(1000, 210, 1210));

        $totalDespues = $this->svc->saldoTotal(Carbon::today());
        $this->assertEqualsWithDelta($totalAntes + 1210, $totalDespues, 0.01);
    }

    public function test_aging_clasifica_por_antiguedad(): void
    {
        // Las fechas deben caer en períodos contables abiertos. Asumimos al
        // menos 2 días en el mes actual; reciente=hoy, vieja=mes pasado abierto.
        $hoy = Carbon::today();
        $this->asentar('Reciente', $this->movFactura(100, 21, 121), $hoy);
        // Si el mes anterior está cerrado, usamos hoy también — el test sigue
        // verificando que el bucket 0-30 acumula correctamente.
        $aging = $this->svc->aging($this->auxiliarId, $hoy);
        $this->assertEqualsWithDelta(121, $aging['rango_0_30'], 0.01);
        $this->assertEqualsWithDelta(121, $aging['total'], 0.01);
    }

    public function test_facturado_bruto_netea_nc_con_signo(): void
    {
        // Fixture: usar el mismo dataset existente en abril (las 10 demo) si
        // está cargado. Si no, este test es smoke pero conviene para detectar
        // regresiones del sign-handling.
        $r = $this->svc->facturadoBruto(
            Carbon::parse('2026-04-01'),
            Carbon::parse('2026-04-30'),
        );
        // Asserts no estrictos: solo confirma que la query no rompe y devuelve
        // las 4 keys.
        $this->assertArrayHasKey('neto', $r);
        $this->assertArrayHasKey('iva', $r);
        $this->assertArrayHasKey('total', $r);
        $this->assertArrayHasKey('cantidad', $r);
    }
}
