<?php

namespace Tests\Feature\Cierres;

use App\Erp\Services\AsientoService;
use App\Erp\Services\Cierres\CerrarDiaService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Auditoría 2026-07-12 bug #6 — el asiento de ajuste retroactivo de los
 * cierres diarios se creaba por INSERT/UPDATE directo: sin hash de
 * integridad (fallaba verificarIntegridadAsientos), con numeración no
 * atómica (regex sobre el último id, colisionable) y diario_id=8 literal.
 * Debe pasar por AsientoService::crearBorrador+contabilizar como todo
 * el resto del sistema.
 */
class AsientoForwardIntegridadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_asiento_forward_tiene_hash_y_numerador_atomico(): void
    {
        $empresaId = 1;
        $user = User::firstOrCreate(
            ['email' => 'test.forward@logistica.local'],
            ['name' => 'Test forward', 'password' => bcrypt('irrelevante')],
        );

        // Dos cuentas imputables sin exigencia de CC/auxiliar para aislar el caso.
        [$debeId, $haberId] = DB::table('erp_cuentas_contables')
            ->where('empresa_id', $empresaId)->where('imputable', 1)
            ->where('admite_cc', 0)->where('admite_auxiliar', 0)
            ->limit(2)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertNotEmpty($haberId ?? null, 'Se necesitan 2 cuentas imputables simples para el test');

        $svc = app(CerrarDiaService::class);
        $m = new ReflectionMethod($svc, 'crearAsientoForward');
        $m->setAccessible(true);

        $asiento = $m->invoke($svc, $debeId, $haberId, 123.45, 'Ajuste retroactivo test', $empresaId, $user, Carbon::now());

        $this->assertSame('CONTABILIZADO', $asiento->estado);
        $this->assertNotNull($asiento->hash_integridad,
            'El asiento forward debe llevar hash de integridad (pasar por AsientoService::contabilizar)');

        // Numeración atómica del diario (RN-9), no regex global sobre erp_asientos.
        $diario = DB::table('erp_diarios')->where('id', $asiento->diario_id)->first();
        $this->assertContains($diario->codigo, ['AJ', 'GEN'],
            'El diario debe resolverse por código, no por id literal');

        // La verificación masiva de integridad no debe marcarlo como falla.
        $fallas = collect(app(AsientoService::class)->verificarIntegridadAsientos($empresaId))
            ->filter(fn ($f) => (int) $f['asiento_id'] === (int) $asiento->id);
        $this->assertCount(0, $fallas, 'verificarIntegridadAsientos no debe reportar el asiento forward');
    }
}
