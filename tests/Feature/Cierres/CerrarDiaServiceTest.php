<?php

namespace Tests\Feature\Cierres;

use App\Erp\Models\Cierres\AjusteRetroactivo;
use App\Erp\Models\Cierres\DiaContable;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Cierres\CerrarDiaService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tests del workflow CerrarDiaService:
 *   - encadenado de saldos (RN-CD-2)
 *   - bloqueo por huecos (RN-CD-1)
 *   - estampado de movs al sellar (RN-CD-5)
 *   - ajuste retroactivo (RN-CD-6)
 */
class CerrarDiaServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CerrarDiaService $svc;
    private User $user;
    private CuentaBancaria $c1;
    private CuentaBancaria $c2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CerrarDiaService::class);
        $this->user = User::first() ?? User::factory()->create();
        $cuentas = CuentaBancaria::where('empresa_id', 1)->where('activo', 1)->take(2)->get();
        $this->assertGreaterThanOrEqual(2, $cuentas->count());
        [$this->c1, $this->c2] = [$cuentas[0], $cuentas[1]];
    }

    private function diaCerrado(string $fecha, array $saldosCierre): DiaContable
    {
        return DiaContable::create([
            'empresa_id' => 1, 'fecha' => $fecha, 'estado' => 'CERRADO',
            'saldos_apertura' => $saldosCierre, // simplificado, irrelevante para los tests
            'saldos_cierre'   => $saldosCierre,
            'cerrado_por' => $this->user->id, 'cerrado_at' => now()->subDay(),
        ]);
    }

    private function crearMov(int $cuentaId, string $fecha, float $debito, float $credito, string $hash): MovimientoBancario
    {
        $ext = ExtractoBancario::create([
            'cuenta_bancaria_id' => $cuentaId,
            'fecha_desde' => $fecha, 'fecha_hasta' => $fecha,
            'hash_archivo' => substr(md5($hash), 0, 64),
            'nombre_archivo' => 't.csv', 'ruta_archivo' => '/tmp/t.csv',
            'saldo_inicial' => 0, 'saldo_final' => 0, 'cant_movimientos' => 0,
            'importado_por_user_id' => 1, 'importado_at' => now(),
        ]);
        return MovimientoBancario::create([
            'extracto_id' => $ext->id, 'cuenta_bancaria_id' => $cuentaId,
            'fecha' => $fecha, 'concepto' => 'Test',
            'debito' => $debito, 'credito' => $credito, 'saldo' => 0,
            'estado' => 'CONCILIADO', 'hash_linea' => str_pad($hash, 64, 'X'),
        ]);
    }

    public function test_iniciar_encadena_saldo_apertura_del_dia_anterior(): void
    {
        $cid1 = (string) $this->c1->id;
        $cid2 = (string) $this->c2->id;
        $this->diaCerrado('2026-04-01', [$cid1 => 1500, $cid2 => 4800]);

        $dia = $this->svc->iniciar(Carbon::parse('2026-04-02'), 1);

        $this->assertSame('EN_PROCESO', $dia->estado);
        $this->assertEquals(1500, $dia->saldos_apertura[$cid1]);
        $this->assertEquals(4800, $dia->saldos_apertura[$cid2]);
    }

    public function test_sellar_calcula_saldos_cierre_y_estampa_movs(): void
    {
        $cid1 = (string) $this->c1->id;
        $cid2 = (string) $this->c2->id;
        $this->diaCerrado('2026-04-01', [$cid1 => 1500, $cid2 => 4800]);

        $this->crearMov($this->c1->id, '2026-04-02', 200, 0, 'm1'); // -200
        $this->crearMov($this->c1->id, '2026-04-02', 0, 500, 'm2'); // +500
        $this->crearMov($this->c2->id, '2026-04-02', 100, 0, 'm3'); // -100

        $this->svc->iniciar(Carbon::parse('2026-04-02'), 1);
        $dia = $this->svc->sellar(Carbon::parse('2026-04-02'), 1, $this->user, true);

        $this->assertSame('CERRADO', $dia->estado);
        // c1: 1500 + 500 - 200 = 1800
        $this->assertEquals(1800, $dia->saldos_cierre[$cid1]);
        // c2: 4800 - 100 = 4700
        $this->assertEquals(4700, $dia->saldos_cierre[$cid2]);

        // Movs estampados con dia_contable_id.
        $estampados = MovimientoBancario::whereDate('fecha', '2026-04-02')
            ->whereNotNull('dia_contable_id')->count();
        $this->assertSame(3, $estampados);
    }

    public function test_huecos_bloquean_iniciar(): void
    {
        $cid1 = (string) $this->c1->id;
        $this->diaCerrado('2026-04-01', [$cid1 => 1000]);
        // Saltamos 02 y 03; al iniciar 04 debe bloquearse.

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/HUECOS_NO_PERMITIDOS.*02\/04\/2026.*03\/04\/2026/');
        $this->svc->iniciar(Carbon::parse('2026-04-04'), 1);
    }

    public function test_dia_ya_cerrado_no_se_puede_iniciar_otra_vez(): void
    {
        $cid1 = (string) $this->c1->id;
        $this->diaCerrado('2026-04-01', [$cid1 => 1500]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/DIA_YA_CERRADO/');
        $this->svc->iniciar(Carbon::parse('2026-04-01'), 1);
    }

    public function test_ajuste_retroactivo_no_modifica_dia_original(): void
    {
        $cid1 = (string) $this->c1->id;
        $diaOriginal = $this->diaCerrado('2026-04-01', [$cid1 => 1000]);
        $movsBefore = $diaOriginal->total_movimientos;

        $cuenta1Id = (int) (\App\Erp\Models\CuentaContable::where('empresa_id', 1)->where('imputable', 1)->first()?->id ?? 1);
        $cuenta2Id = (int) (\App\Erp\Models\CuentaContable::where('empresa_id', 1)->where('imputable', 1)->skip(1)->first()?->id ?? 2);

        $ajuste = $this->svc->ajusteRetroactivo(
            Carbon::parse('2026-04-01'), 1,
            'Olvido carga mov Brubank por $5000',
            ['cuenta_debe_id' => $cuenta1Id, 'cuenta_haber_id' => $cuenta2Id, 'importe' => 5000.00],
            $this->user
        );

        $this->assertInstanceOf(AjusteRetroactivo::class, $ajuste);
        $this->assertEquals('2026-04-01', $ajuste->fecha_dia_afectado->toDateString());
        $this->assertNotEquals('2026-04-01', $ajuste->fecha_asiento_ajuste->toDateString(),
            'El asiento debe tener fecha de hoy, NO la del día afectado.');

        // Día original no se tocó.
        $diaOriginal->refresh();
        $this->assertSame('CERRADO', $diaOriginal->estado);
        $this->assertSame((int) $movsBefore, (int) $diaOriginal->total_movimientos);
    }

    public function test_ajuste_retroactivo_falla_sobre_dia_no_cerrado(): void
    {
        DiaContable::create([
            'empresa_id' => 1, 'fecha' => '2026-04-01',
            'estado' => 'EN_PROCESO',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/DIA_NO_CERRADO/');
        $this->svc->ajusteRetroactivo(
            Carbon::parse('2026-04-01'), 1, 'motivo válido cinco+',
            ['cuenta_debe_id' => 1, 'cuenta_haber_id' => 2, 'importe' => 100],
            $this->user
        );
    }

    public function test_motivo_corto_falla(): void
    {
        $cid1 = (string) $this->c1->id;
        $this->diaCerrado('2026-04-01', [$cid1 => 1000]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/MOTIVO_REQUERIDO/');
        $this->svc->ajusteRetroactivo(
            Carbon::parse('2026-04-01'), 1, 'no',  // < 5 chars
            ['cuenta_debe_id' => 1, 'cuenta_haber_id' => 2, 'importe' => 100],
            $this->user
        );
    }
}
