<?php

namespace Tests\Feature\Tesoreria;

use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Services\OrdenPagoService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests del flujo de OP (SPEC 02 §OP, RN-17):
 *   BORRADOR → CARGADA_BANCO → LIBERADA → PAGADA
 */
class OrdenPagoServiceTest extends TestCase
{
    use DatabaseTransactions;

    private OrdenPagoService $service;
    private int $empresaId = 1;
    private int $auxId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrdenPagoService::class);

        $this->auxId = (int) (
            DB::table('erp_auxiliares')->where('empresa_id', $this->empresaId)->whereIn('tipo', ['Proveedor', 'Distribuidor'])->value('id')
                ?? DB::table('erp_auxiliares')->where('empresa_id', $this->empresaId)->value('id')
        );

        $this->user = User::firstOrCreate(
            ['email' => 'test.opservice@logistica.local'],
            ['name' => 'Test OP user', 'password' => bcrypt('irrelevante')]
        );
    }

    public function test_crea_OP_en_estado_BORRADOR(): void
    {
        $op = $this->service->crear($this->payloadBase(1000));

        $this->assertSame(OrdenPago::ESTADO_BORRADOR, $op->estado);
        $this->assertStringStartsWith('OP-', $op->numero);
        $this->assertEqualsWithDelta(1000.00, (float) $op->importe, 0.01);
    }

    public function test_crea_OP_con_items_y_medios(): void
    {
        $medioId = (int) DB::table('erp_medios_pago')->where('codigo', 'TRANSFERENCIA')->value('id');

        $op = $this->service->crear([
            ...$this->payloadBase(500),
            'items' => [
                ['tipo_item' => 'OTRO', 'concepto' => 'Adelanto', 'importe' => 500],
            ],
            'medios' => [
                ['medio_pago_id' => $medioId, 'importe' => 500, 'referencia' => 'TRF-1234'],
            ],
        ]);

        $this->assertCount(1, $op->items);
        $this->assertCount(1, $op->medios);
        $this->assertSame('TRF-1234', $op->medios->first()->referencia);
    }

    public function test_transicion_cargar_banco_desde_BORRADOR(): void
    {
        $op = $this->service->crear($this->payloadBase(100));

        $op = $this->service->cargarBanco($op, $this->user);

        $this->assertSame(OrdenPago::ESTADO_CARGADA_BANCO, $op->estado);
        $this->assertNotNull($op->fecha_carga_banco);
        $this->assertSame($this->user->id, (int) $op->cargado_por_user_id);
    }

    public function test_cargar_banco_rechaza_medios_desbalanceados(): void
    {
        $medioId = (int) DB::table('erp_medios_pago')->where('codigo', 'TRANSFERENCIA')->value('id');
        $op = $this->service->crear([
            ...$this->payloadBase(1000),
            'medios' => [
                ['medio_pago_id' => $medioId, 'importe' => 800], // 200 menos que el importe
            ],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/OP_MEDIOS_DESBALANCEADOS/');

        $this->service->cargarBanco($op, $this->user);
    }

    public function test_liberar_requiere_CARGADA_BANCO(): void
    {
        $op = $this->service->crear($this->payloadBase(100));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/OP_ESTADO_INVALIDO/');
        $this->service->liberar($op, $this->user);
    }

    public function test_flujo_completo_BORRADOR_a_LIBERADA(): void
    {
        $op = $this->service->crear($this->payloadBase(250));
        $op = $this->service->cargarBanco($op, $this->user);
        $op = $this->service->liberar($op, $this->user);

        $this->assertSame(OrdenPago::ESTADO_LIBERADA, $op->estado);
        $this->assertNotNull($op->fecha_liberacion);
        $this->assertSame($this->user->id, (int) $op->liberado_por_user_id);
    }

    public function test_pagar_desde_CARGADA_BANCO_sin_liberar_falla(): void
    {
        $op = $this->service->crear($this->payloadBase(100));
        $op = $this->service->cargarBanco($op, $this->user);
        $cuentaBancariaId = (int) DB::table('erp_cuentas_bancarias')->where('empresa_id', 1)->value('id');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/OP_FALTA_LIBERAR/');

        $this->service->pagar($op, $cuentaBancariaId, $this->user);
    }

    public function test_rechazar_desde_CARGADA_BANCO(): void
    {
        $op = $this->service->crear($this->payloadBase(50));
        $op = $this->service->cargarBanco($op, $this->user);

        $op = $this->service->rechazar($op, 'CBU inválido', $this->user);

        $this->assertSame(OrdenPago::ESTADO_RECHAZADA, $op->estado);
        $this->assertSame('CBU inválido', $op->motivo_rechazo);
    }

    private function payloadBase(float $importe): array
    {
        return [
            'empresa_id' => $this->empresaId,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'tipo' => 'PROVEEDOR',
            'auxiliar_id' => $this->auxId,
            'moneda_id' => 1,
            'cotizacion' => 1.0,
            'importe' => $importe,
        ];
    }
}
