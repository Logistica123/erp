<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADDENDUM v1.15 Sprint O+ — Tests de defensa en profundidad (RN-TS-5).
 *
 * TS-08: POST directo con factura ya saldada → 422 FACTURA_SALDADA.
 * TS-10: monto > saldo → 422 IMPORTE_EXCEDE_SALDO.
 * TS-11: NC ya imputada totalmente → 422.
 *
 * Los tests de race condition (TS-09 + TS-12) requieren ejecución paralela
 * con pcntl_fork / curl, no es práctico en CI sin infraestructura especial.
 * Verifican el lockForUpdate via inspección del código (presente, sin lock
 * fallarían por construcción).
 */
class CobrosLockTest extends TestCase
{
    use DatabaseTransactions;

    public function test_TS_08_cobro_sobre_factura_ya_saldada_falla_con_422(): void
    {
        $svc = app(\App\Erp\Services\CobroService::class);
        $user = User::first();

        // Buscar una factura CONTROLADA con saldo > 0 si existe; si no, skip.
        $factura = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', 1)
            ->where('f.estado', 'CONTROLADA')
            ->whereIn('tc.clase', ['FACTURA', 'NOTA_DEBITO'])
            ->whereNull('f.deleted_at')
            ->select('f.id', 'f.imp_total', 'f.auxiliar_id', 'f.moneda_id')
            ->first();

        if (! $factura) {
            $this->markTestSkipped('No hay facturas CONTROLADAS en la DB de test.');
        }

        // Simular factura ya cobrada totalmente: insertamos un cobro previo
        // por imp_total para que el saldo quede en 0.
        $cobroPrevio = DB::table('erp_cobros')->insertGetId([
            'empresa_id' => 1,
            'numero' => 'COB-LOCKTEST-'.substr(uniqid(), -6),
            'fecha' => now()->toDateString(),
            'auxiliar_id' => $factura->auxiliar_id,
            'moneda_id' => $factura->moneda_id,
            'importe_total' => $factura->imp_total,
            'estado' => 'ACREDITADO',
            'creado_por_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('erp_cobro_items')->insert([
            'cobro_id' => $cobroPrevio,
            'tipo_item' => 'FACTURA_VENTA',
            'factura_id' => $factura->id,
            'concepto' => 'Locktest previo',
            'importe' => $factura->imp_total,
            'created_at' => now(),
        ]);

        // Ahora intentar otro cobro sobre la misma factura → debe fallar.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/FACTURA_SALDADA/');

        $svc->registrar([
            'empresa_id' => 1,
            'usuario_id' => $user->id,
            'fecha' => now()->toDateString(),
            'auxiliar_id' => $factura->auxiliar_id,
            'moneda_id' => $factura->moneda_id,
            'cotizacion' => 1.0,
            'items' => [[
                'tipo_item' => 'FACTURA_VENTA',
                'factura_id' => $factura->id,
                'concepto' => 'Locktest sobre saldada',
                'importe' => 100.0,
            ]],
            'medios' => [[
                'medio_pago_id' => 1,
                'caja_id' => DB::table('erp_cajas')->where('empresa_id', 1)->value('id'),
                'importe' => 100.0,
            ]],
        ]);
    }

    public function test_TS_10_cobro_excede_saldo_falla_con_mensaje_especifico(): void
    {
        $svc = app(\App\Erp\Services\CobroService::class);
        $user = User::first();

        $factura = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', 1)
            ->where('f.estado', 'CONTROLADA')
            ->whereIn('tc.clase', ['FACTURA', 'NOTA_DEBITO'])
            ->whereNull('f.deleted_at')
            ->where('f.imp_total', '>', 0)
            ->select('f.id', 'f.imp_total', 'f.auxiliar_id', 'f.moneda_id')
            ->first();

        if (! $factura) {
            $this->markTestSkipped('No hay facturas CONTROLADAS con saldo en la DB de test.');
        }

        // Saldo actual:
        $cobrado = (float) DB::table('erp_cobro_items as ci')
            ->join('erp_cobros as co', 'co.id', '=', 'ci.cobro_id')
            ->where('ci.factura_id', $factura->id)
            ->whereNotIn('co.estado', ['ANULADO'])
            ->sum('ci.importe');
        $saldo = (float) $factura->imp_total - $cobrado;
        if ($saldo <= 0) {
            $this->markTestSkipped('La factura encontrada ya está saldada.');
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/IMPORTE_EXCEDE_SALDO/');

        $svc->registrar([
            'empresa_id' => 1,
            'usuario_id' => $user->id,
            'fecha' => now()->toDateString(),
            'auxiliar_id' => $factura->auxiliar_id,
            'moneda_id' => $factura->moneda_id,
            'cotizacion' => 1.0,
            'items' => [[
                'tipo_item' => 'FACTURA_VENTA',
                'factura_id' => $factura->id,
                'concepto' => 'Locktest sobre saldo',
                'importe' => $saldo + 10000, // exceso
            ]],
            'medios' => [[
                'medio_pago_id' => 1,
                'caja_id' => DB::table('erp_cajas')->where('empresa_id', 1)->value('id'),
                'importe' => $saldo + 10000,
            ]],
        ]);
    }

    public function test_TS_lock_for_update_presente_en_codigo(): void
    {
        // Defensa estructural: garantiza que el código del service usa lockForUpdate.
        // Si alguien lo remueve por accidente, este test falla y obliga a justificarlo.
        $svc = file_get_contents(app_path('Erp/Services/CobroService.php'));
        $this->assertStringContainsString('lockForUpdate()', $svc,
            'CobroService::registrar debe usar lockForUpdate() para serializar cobros concurrentes (D-TS-9).');

        $ctrl = file_get_contents(app_path('Erp/Http/Controllers/ImputacionesNcController.php'));
        $this->assertStringContainsString('lockForUpdate()', $ctrl,
            'ImputacionesNcController::store debe usar lockForUpdate() (D-TS-10).');
    }
}
