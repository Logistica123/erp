<?php

namespace Tests\Feature\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\Tesoreria\ArqueoCaja;
use App\Erp\Models\Tesoreria\Caja;
use App\Erp\Services\ArqueoCajaService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests del arqueo de caja (SPEC 02 RN-16, RN-22, RN-23).
 */
class ArqueoCajaServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ArqueoCajaService $service;
    private Caja $caja;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ArqueoCajaService::class);
        $this->user = User::firstOrCreate(
            ['email' => 'test.arqueo@logistica.local'],
            ['name' => 'Test arqueo', 'password' => bcrypt('irrelevante')]
        );
        $this->caja = Caja::where('empresa_id', 1)->firstOrFail();
    }

    public function test_arqueo_sin_diferencia_no_genera_asiento(): void
    {
        // Fijamos saldo conocido
        $this->caja->update(['saldo_actual' => 1000]);

        $arqueo = $this->service->registrar([
            'caja_id' => $this->caja->id,
            'fecha' => now()->toDateString(),
            'saldo_fisico' => 1000,
            'usuario_id' => $this->user->id,
        ]);

        $this->assertEqualsWithDelta(0, (float) $arqueo->diferencia, 0.01);
        $this->assertNull($arqueo->asiento_ajuste_id);
    }

    public function test_arqueo_con_sobrante_genera_asiento_RN23(): void
    {
        $this->caja->update(['saldo_actual' => 500]);

        $arqueo = $this->service->registrar([
            'caja_id' => $this->caja->id,
            'fecha' => now()->toDateString(),
            'saldo_fisico' => 550, // +50 sobrante
            'motivo' => 'Vuelto mal tipeado',
            'usuario_id' => $this->user->id,
        ]);

        $this->assertEqualsWithDelta(50.00, (float) $arqueo->diferencia, 0.01);
        $this->assertNotNull($arqueo->asiento_ajuste_id);

        $asiento = Asiento::find($arqueo->asiento_ajuste_id);
        $this->assertSame(Asiento::ESTADO_CONTABILIZADO, $asiento->estado);
        $this->assertEqualsWithDelta(50.00, (float) $asiento->total_debe, 0.01);

        // Verifica línea contra 4.2.07 Sobrante de Caja
        $cuentaSobrante = (int) DB::table('erp_cuentas_contables')->where('codigo', '4.2.07')->value('id');
        $this->assertTrue($asiento->movimientos()->where('cuenta_id', $cuentaSobrante)->exists());
    }

    public function test_arqueo_con_faltante_imputa_a_5_4_09(): void
    {
        $this->caja->update(['saldo_actual' => 1000]);

        $arqueo = $this->service->registrar([
            'caja_id' => $this->caja->id,
            'fecha' => now()->toDateString(),
            'saldo_fisico' => 970, // -30 faltante
            'motivo' => 'Diferencia no identificada',
            'usuario_id' => $this->user->id,
        ]);

        $this->assertEqualsWithDelta(-30.00, (float) $arqueo->diferencia, 0.01);

        $cuentaFaltante = (int) DB::table('erp_cuentas_contables')->where('codigo', '5.4.09')->value('id');
        $asiento = Asiento::find($arqueo->asiento_ajuste_id);
        $this->assertTrue($asiento->movimientos()->where('cuenta_id', $cuentaFaltante)->exists());
    }

    public function test_diferencia_sin_motivo_rechaza(): void
    {
        $this->caja->update(['saldo_actual' => 100]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/ARQUEO_MOTIVO_REQUERIDO/');

        $this->service->registrar([
            'caja_id' => $this->caja->id,
            'fecha' => now()->toDateString(),
            'saldo_fisico' => 90,
            'usuario_id' => $this->user->id,
        ]);
    }

    public function test_arqueo_duplicado_mismo_dia_rechaza(): void
    {
        $this->caja->update(['saldo_actual' => 100]);
        $fecha = now()->toDateString();

        $this->service->registrar([
            'caja_id' => $this->caja->id,
            'fecha' => $fecha,
            'saldo_fisico' => 100,
            'usuario_id' => $this->user->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/ARQUEO_DUPLICADO/');

        $this->service->registrar([
            'caja_id' => $this->caja->id,
            'fecha' => $fecha,
            'saldo_fisico' => 100,
            'usuario_id' => $this->user->id,
        ]);
    }
}
