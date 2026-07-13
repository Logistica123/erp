<?php

namespace Tests\Feature\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\TransferenciaInterna;
use App\Erp\Services\TransferenciaInternaService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests del flujo de TI (SPEC 02 §7.8, RN-20).
 */
class TransferenciaInternaServiceTest extends TestCase
{
    use DatabaseTransactions;

    private TransferenciaInternaService $service;
    private int $empresaId = 1;
    private int $origenId;
    private int $destinoId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TransferenciaInternaService::class);
        $this->user = User::firstOrCreate(
            ['email' => 'test.ti@logistica.local'],
            ['name' => 'Test TI', 'password' => bcrypt('irrelevante')]
        );

        // Portabilidad (2.1): el service usa CC CENTRAL como fallback.
        $this->asegurarCcCentral($this->empresaId);
        $cuentas = CuentaBancaria::where('empresa_id', $this->empresaId)->take(2)->pluck('id')->all();
        $this->origenId = (int) ($cuentas[0] ?? 0);
        $this->destinoId = (int) ($cuentas[1] ?? 0);
    }

    public function test_rechaza_cuentas_iguales(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/TI_CUENTAS_IGUALES/');

        $this->service->registrar([
            'empresa_id' => $this->empresaId,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'cuenta_origen_id' => $this->origenId,
            'cuenta_destino_id' => $this->origenId,
            'importe_origen' => 100,
        ]);
    }

    public function test_registra_TI_crea_dos_movimientos_bancarios(): void
    {
        $ti = $this->service->registrar([
            'empresa_id' => $this->empresaId,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'cuenta_origen_id' => $this->origenId,
            'cuenta_destino_id' => $this->destinoId,
            'importe_origen' => 1500,
            'concepto' => 'Fondeo Brubank',
        ]);

        $this->assertSame(TransferenciaInterna::ESTADO_PENDIENTE, $ti->estado);
        $this->assertNotNull($ti->movimiento_origen_id);
        $this->assertNotNull($ti->movimiento_destino_id);
    }

    public function test_contabilizar_genera_un_asiento_balanceado(): void
    {
        $ti = $this->service->registrar([
            'empresa_id' => $this->empresaId,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'cuenta_origen_id' => $this->origenId,
            'cuenta_destino_id' => $this->destinoId,
            'importe_origen' => 800,
        ]);

        $ti = $this->service->contabilizar($ti, $this->user);

        $this->assertSame(TransferenciaInterna::ESTADO_CONCILIADA, $ti->estado);
        $this->assertNotNull($ti->asiento_id);

        $asiento = Asiento::find($ti->asiento_id);
        $this->assertSame(Asiento::ESTADO_CONTABILIZADO, $asiento->estado);
        $this->assertEqualsWithDelta(800.00, (float) $asiento->total_debe, 0.01);
        $this->assertEqualsWithDelta(800.00, (float) $asiento->total_haber, 0.01);
    }
}
