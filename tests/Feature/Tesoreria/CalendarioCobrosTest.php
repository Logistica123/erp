<?php

namespace Tests\Feature\Tesoreria;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Calendario de cobros proyectados (pedido 2026-07-15):
 *  1. panel de plazos por cliente (días desde la FECHA DE FACTURA);
 *  2. factura impaga → fecha factura + plazo del cliente;
 *  3. cheque en cartera → fecha de vencimiento del recibo (fecha_pago).
 */
class CalendarioCobrosTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $clienteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first();
        $this->clienteId = (int) DB::table('erp_auxiliares')->insertGetId([
            'empresa_id' => 1, 'tipo' => 'Cliente', 'codigo' => 'ZZCAL-'.substr(uniqid(), -5),
            'nombre' => 'Cliente Calendario Test', 'activo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($this->user, ['*']);
    }

    private function crearFacturaVenta(float $total, string $fechaEmision): int
    {
        $tipoId = (int) DB::table('erp_tipos_comprobante')->where('signo', 1)
            ->where('letra', 'A')->orderBy('id')->value('id');
        $pvId = (int) DB::table('erp_puntos_venta')->value('id');
        $condId = (int) DB::table('erp_condiciones_iva')->value('id');

        return (int) DB::table('erp_facturas_venta')->insertGetId([
            'empresa_id' => 1, 'tipo_comprobante_id' => $tipoId, 'punto_venta_id' => $pvId,
            'numero' => 990000 + random_int(1, 9999), 'fecha_emision' => $fechaEmision,
            'auxiliar_id' => $this->clienteId, 'condicion_iva_id' => $condId,
            'doc_tipo_afip' => 80, 'doc_nro' => '30111222333', 'moneda_id' => 1,
            'cotizacion' => 1, 'concepto_afip' => 2, 'imp_neto_gravado' => $total / 1.21,
            'imp_iva' => $total - $total / 1.21, 'imp_total' => $total,
            'origen' => 'MANUAL', 'estado' => 'EMITIDA',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_panel_de_plazos_guarda_y_valida(): void
    {
        $this->putJson("/api/erp/tesoreria/plazos-cobro/{$this->clienteId}", ['dias' => 45])->assertOk();
        $this->assertSame(45, (int) DB::table('erp_auxiliares')->where('id', $this->clienteId)->value('plazo_cobro_dias'));

        // Se puede limpiar (vuelve a "sin plazo").
        $this->putJson("/api/erp/tesoreria/plazos-cobro/{$this->clienteId}", ['dias' => null])->assertOk();
        $this->assertNull(DB::table('erp_auxiliares')->where('id', $this->clienteId)->value('plazo_cobro_dias'));

        // Un proveedor no admite plazo de cobro.
        $prov = (int) DB::table('erp_auxiliares')->where('empresa_id', 1)->where('tipo', 'Proveedor')->value('id');
        $this->putJson("/api/erp/tesoreria/plazos-cobro/{$prov}", ['dias' => 10])->assertStatus(422);

        // El panel lista el cliente.
        $lista = $this->getJson('/api/erp/tesoreria/plazos-cobro')->assertOk()->json('data');
        $this->assertNotNull(collect($lista)->firstWhere('auxiliar_id', $this->clienteId));
    }

    public function test_factura_impaga_se_proyecta_por_fecha_factura_mas_plazo(): void
    {
        DB::table('erp_auxiliares')->where('id', $this->clienteId)->update(['plazo_cobro_dias' => 30]);
        $hoy = now()->startOfDay();
        $fechaFactura = $hoy->copy()->addDays(5)->toDateString();     // emitida "a futuro" para test limpio
        $facturaId = $this->crearFacturaVenta(121000, $fechaFactura);

        $desde = $hoy->toDateString();
        $hasta = $hoy->copy()->addDays(90)->toDateString();
        $data = $this->getJson("/api/erp/tesoreria/calendario-cobros?desde={$desde}&hasta={$hasta}")
            ->assertOk()->json('data');

        $item = collect($data['items'])->firstWhere('id', $facturaId);
        $this->assertNotNull($item, 'la factura impaga debe estar en el calendario');
        $this->assertSame('FACTURA', $item['tipo']);
        // fecha de FACTURA (no vto) + 30 días del cliente.
        $this->assertSame($hoy->copy()->addDays(35)->toDateString(), $item['fecha']);
        $this->assertEqualsWithDelta(121000, $item['importe'], 0.01);

        // Si se cobra por completo (cobro real + item), desaparece.
        $cobroId = (int) DB::table('erp_cobros')->insertGetId([
            'empresa_id' => 1, 'numero' => 'ZZC-'.random_int(10000, 99999),
            'fecha' => now()->toDateString(), 'auxiliar_id' => $this->clienteId,
            'moneda_id' => 1, 'importe_total' => 121000,
            'creado_por_user_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_cobro_items')->insert([
            'cobro_id' => $cobroId, 'tipo_item' => 'FACTURA_VENTA', 'factura_id' => $facturaId,
            'concepto' => 'test', 'importe' => 121000,
        ]);
        $data2 = $this->getJson("/api/erp/tesoreria/calendario-cobros?desde={$desde}&hasta={$hasta}")
            ->assertOk()->json('data');
        $this->assertNull(collect($data2['items'])->firstWhere('id', $facturaId), 'pagada no aparece');
    }

    public function test_cliente_sin_plazo_va_al_bucket_sin_plazo(): void
    {
        $facturaId = $this->crearFacturaVenta(50000, now()->toDateString());

        $data = $this->getJson('/api/erp/tesoreria/calendario-cobros')->assertOk()->json('data');
        $this->assertNotNull(collect($data['sin_plazo'])->firstWhere('id', $facturaId),
            'sin plazo cargado, la factura va a sin_plazo para completar el panel');
    }

    public function test_cheque_en_cartera_aparece_por_su_vencimiento(): void
    {
        $vto = now()->addDays(12)->toDateString();
        // El cheque nace de un recibo (v1.31) — recibo mínimo del cliente.
        $facturaId = $this->crearFacturaVenta(250000, now()->toDateString());
        $reciboId = (int) DB::table('erp_recibos')->insertGetId([
            'empresa_id' => 1, 'numero_correlativo' => 'ZZR-'.random_int(10000, 99999),
            'cliente_auxiliar_id' => $this->clienteId, 'factura_venta_id' => $facturaId,
            'fecha_emision' => now()->toDateString(), 'monto_cobrable' => 250000,
            'total_factura' => 250000, 'saldo_factura_post' => 0,
            'estado' => 'EMITIDO', 'created_by_user_id' => $this->user->id,
            'created_at' => now(),
        ]);
        $chequeId = (int) DB::table('erp_cheques_recibidos')->insertGetId([
            'empresa_id' => 1, 'recibo_id' => $reciboId, 'numero_cheque' => 'ZZ'.random_int(1000, 9999),
            'banco_emisor' => 'Banco Test', 'librador_nombre' => 'Librador Test',
            'cuit_librador' => '30111222333', 'importe' => 250000,
            'fecha_emision' => now()->toDateString(), 'fecha_pago' => $vto,
            'estado' => 'EN_CARTERA', 'created_by_user_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson('/api/erp/tesoreria/calendario-cobros')->assertOk()->json('data');
        $item = collect($data['items'])->firstWhere('referencia', fn ($r) => false) ?? collect($data['items'])
            ->first(fn ($i) => $i['tipo'] === 'CHEQUE' && $i['id'] === $chequeId);
        $this->assertNotNull($item, 'el cheque en cartera debe estar');
        $this->assertSame($vto, $item['fecha']);
        $this->assertEqualsWithDelta(250000, $item['importe'], 0.01);
    }
}
