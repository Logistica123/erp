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
        // Portabilidad (2.1): en prod hay cajas desactivadas — sin el filtro
        // el firstOrFail puede elegir una inactiva (CAJA_INACTIVA).
        $this->caja = Caja::where('empresa_id', 1)->where('activo', 1)->firstOrFail();

        // v1.42: arquear exige que el usuario sea operador autorizado de la
        // caja (en prod la lista es real y el user de test no está).
        DB::table('erp_cajas_operadores')->updateOrInsert(
            ['caja_id' => $this->caja->id, 'user_id' => $this->user->id],
            ['fecha_alta' => now(), 'fecha_baja' => null, 'autorizado_por_user_id' => $this->user->id]
        );
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

    public function test_arqueo_con_sobrante_queda_pendiente_y_autorizar_genera_asiento(): void
    {
        // v1.42 Fase A (3 caminos): |dif| > $1 → PENDIENTE_AUTORIZACION sin
        // asiento; el asiento nace al autorizar con decision AJUSTAR.
        // (El test viejo esperaba asiento inmediato — contrato pre-Fase A.)
        $this->caja->update(['saldo_actual' => 500]);

        $arqueo = $this->service->registrar([
            'caja_id' => $this->caja->id,
            'fecha' => now()->toDateString(),
            'saldo_fisico' => 550, // +50 sobrante
            'motivo' => 'Vuelto mal tipeado',
            'usuario_id' => $this->user->id,
        ]);

        $this->assertEqualsWithDelta(50.00, (float) $arqueo->diferencia, 0.01);
        $this->assertSame('PENDIENTE_AUTORIZACION', $arqueo->estado);
        $this->assertNull($arqueo->asiento_ajuste_id);

        $arqueo = $this->service->autorizar($arqueo, [
            'decision' => 'AJUSTAR', 'usuario_id' => $this->user->id,
        ]);

        $this->assertSame('CERRADO_CON_AJUSTE', $arqueo->estado);
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
        // v1.42 Fase A: -30 supera la tolerancia $1 → requiere autorización.
        $this->assertSame('PENDIENTE_AUTORIZACION', $arqueo->estado);

        $arqueo = $this->service->autorizar($arqueo, [
            'decision' => 'AJUSTAR', 'usuario_id' => $this->user->id,
        ]);

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
        // CONFLICTO PENDIENTE DE DECISIÓN (PLAN_REMEDIACION_ESTADO.md):
        // D-42-3 permitió múltiples arqueos por día y el service ya no
        // rechaza, pero el esquema PROD conserva el UNIQUE
        // uk_arqueos_caja_fecha → el segundo arqueo del día muere con 500.
        // Hasta que Francisco decida (dropear el UNIQUE vs restaurar el
        // chequeo del service) este test no tiene contrato válido.
        $this->markTestSkipped(
            'D-42-3 (múltiples arqueos/día) contradice el UNIQUE uk_arqueos_caja_fecha vigente en prod — decisión pendiente.'
        );

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
