<?php

namespace Tests\Feature\Cierres;

use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Cierres\DetectorTransferenciasInternasService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tests del detector de transferencias internas cross-banco (RN-CD-8).
 */
class DetectorTransferenciasInternasTest extends TestCase
{
    use DatabaseTransactions;

    private DetectorTransferenciasInternasService $svc;
    private CuentaBancaria $c1;
    private CuentaBancaria $c2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(DetectorTransferenciasInternasService::class);
        $cuentas = CuentaBancaria::where('empresa_id', 1)->where('activo', 1)->take(2)->get();
        $this->assertGreaterThanOrEqual(2, $cuentas->count(), 'Necesito 2 cuentas bancarias activas');
        [$this->c1, $this->c2] = [$cuentas[0], $cuentas[1]];
    }

    private function crearExtracto(int $cuentaId, string $hash): ExtractoBancario
    {
        return ExtractoBancario::create([
            'cuenta_bancaria_id' => $cuentaId,
            'fecha_desde' => '2026-04-01', 'fecha_hasta' => '2026-04-01',
            'hash_archivo' => $hash, 'nombre_archivo' => 'test.csv',
            'ruta_archivo' => '/tmp/test.csv',
            'saldo_inicial' => 0, 'saldo_final' => 0, 'cant_movimientos' => 0,
            'importado_por_user_id' => 1, 'importado_at' => now(),
        ]);
    }

    private function crearMov(int $extractoId, int $cuentaId, float $debito, float $credito, string $hash, string $estado = 'ETIQUETADO'): MovimientoBancario
    {
        return MovimientoBancario::create([
            'extracto_id' => $extractoId, 'cuenta_bancaria_id' => $cuentaId,
            'fecha' => '2026-04-01', 'concepto' => 'Test',
            'debito' => $debito, 'credito' => $credito, 'saldo' => 1000,
            'estado' => $estado, 'hash_linea' => $hash,
        ]);
    }

    public function test_par_perfecto_detectado_y_conciliado(): void
    {
        $ext1 = $this->crearExtracto($this->c1->id, str_repeat('a', 64));
        $ext2 = $this->crearExtracto($this->c2->id, str_repeat('b', 64));
        $mDebe  = $this->crearMov($ext1->id, $this->c1->id, 50000, 0, str_repeat('1', 64));
        $mHaber = $this->crearMov($ext2->id, $this->c2->id, 0, 50000, str_repeat('2', 64));

        $res = $this->svc->detectarYConciliar(Carbon::parse('2026-04-01'), 1, null);

        $this->assertSame(1, $res['pares']);
        $this->assertCount(1, $res['transferencias']);

        $mDebe->refresh(); $mHaber->refresh();
        $this->assertSame('CONCILIADO', $mDebe->estado);
        $this->assertSame('CONCILIADO', $mHaber->estado);
        $this->assertNotNull($mDebe->asiento_id);
        $this->assertSame($mDebe->asiento_id, $mHaber->asiento_id, 'Mismo asiento para ambas patas');
    }

    public function test_huerfano_sin_par_no_se_toca(): void
    {
        $ext = $this->crearExtracto($this->c1->id, str_repeat('c', 64));
        $mHuerfano = $this->crearMov($ext->id, $this->c1->id, 30000, 0, str_repeat('3', 64), 'PENDIENTE');

        $res = $this->svc->detectarYConciliar(Carbon::parse('2026-04-01'), 1, null);

        $this->assertSame(0, $res['pares']);
        $mHuerfano->refresh();
        $this->assertSame('PENDIENTE', $mHuerfano->estado);
        $this->assertNull($mHuerfano->asiento_id);
    }

    public function test_misma_cuenta_no_genera_par(): void
    {
        // 2 movs con mismo importe pero misma cuenta — NO es transferencia interna.
        $ext = $this->crearExtracto($this->c1->id, str_repeat('d', 64));
        $mD = $this->crearMov($ext->id, $this->c1->id, 1000, 0, str_repeat('4', 64));
        $mH = $this->crearMov($ext->id, $this->c1->id, 0, 1000, str_repeat('5', 64));

        $res = $this->svc->detectarYConciliar(Carbon::parse('2026-04-01'), 1, null);

        $this->assertSame(0, $res['pares']);
        $mD->refresh(); $mH->refresh();
        $this->assertNotSame('CONCILIADO', $mD->estado);
        $this->assertNotSame('CONCILIADO', $mH->estado);
    }

    public function test_idempotencia_al_correr_dos_veces(): void
    {
        $ext1 = $this->crearExtracto($this->c1->id, str_repeat('e', 64));
        $ext2 = $this->crearExtracto($this->c2->id, str_repeat('f', 64));
        $this->crearMov($ext1->id, $this->c1->id, 7777, 0, str_repeat('6', 64));
        $this->crearMov($ext2->id, $this->c2->id, 0, 7777, str_repeat('7', 64));

        $r1 = $this->svc->detectarYConciliar(Carbon::parse('2026-04-01'), 1, null);
        $r2 = $this->svc->detectarYConciliar(Carbon::parse('2026-04-01'), 1, null);

        $this->assertSame(1, $r1['pares']);
        $this->assertSame(0, $r2['pares'], 'Re-correr no debe duplicar (movs ya CONCILIADOS se filtran)');
    }
}
