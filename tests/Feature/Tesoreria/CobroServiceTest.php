<?php

namespace Tests\Feature\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\Tesoreria\Cobro;
use App\Erp\Models\Tesoreria\CobroMedio;
use App\Erp\Models\Tesoreria\Echeq;
use App\Erp\Services\CobroService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests del flujo de cobros (SPEC 02 §7.5, RN-27).
 */
class CobroServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CobroService $service;
    private int $empresaId = 1;
    private int $clienteId;
    private int $cajaId;
    private int $bancoId;
    private int $medioEfectivoId;
    private int $medioTransfId;
    private int $medioEcheqId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CobroService::class);
        $this->user = User::firstOrCreate(
            ['email' => 'test.cobro@logistica.local'],
            ['name' => 'Test cobro', 'password' => bcrypt('irrelevante')]
        );

        $this->clienteId = (int) DB::table('erp_auxiliares')
            ->where('empresa_id', $this->empresaId)->where('tipo', 'Cliente')->value('id');
        $this->cajaId = (int) DB::table('erp_cajas')->where('empresa_id', $this->empresaId)->value('id');
        $this->bancoId = (int) DB::table('erp_cuentas_bancarias')->where('empresa_id', $this->empresaId)->value('id');
        $this->medioEfectivoId = (int) DB::table('erp_medios_pago')->where('codigo', 'EFECTIVO')->value('id');
        $this->medioTransfId = (int) DB::table('erp_medios_pago')->where('codigo', 'TRANSFERENCIA')->value('id');
        $this->medioEcheqId = (int) DB::table('erp_medios_pago')->where('codigo', 'ECHEQ')->value('id');
    }

    public function test_cobro_desbalanceado_rechaza_RN27(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/COBRO_DESBALANCEADO/');

        $this->service->registrar($this->payloadBase(
            items: [['tipo_item' => 'FACTURA_VENTA', 'concepto' => 'FV 1', 'importe' => 1000]],
            medios: [['medio_pago_id' => $this->medioEfectivoId, 'caja_id' => $this->cajaId, 'importe' => 800]],
        ));
    }

    public function test_cobro_efectivo_queda_ACREDITADO_y_genera_asiento(): void
    {
        $cobro = $this->service->registrar($this->payloadBase(
            items: [['tipo_item' => 'FACTURA_VENTA', 'concepto' => 'FV 1', 'importe' => 500]],
            medios: [['medio_pago_id' => $this->medioEfectivoId, 'caja_id' => $this->cajaId, 'importe' => 500]],
        ));

        $this->assertSame(Cobro::ESTADO_ACREDITADO, $cobro->estado);
        $this->assertNotNull($cobro->asiento_id);
        $this->assertCount(1, $cobro->medios);
        $this->assertSame(CobroMedio::ESTADO_ACREDITADO, $cobro->medios->first()->estado_acreditacion);

        $asiento = Asiento::find($cobro->asiento_id);
        $this->assertSame(Asiento::ESTADO_CONTABILIZADO, $asiento->estado);
        $this->assertEqualsWithDelta(500.00, (float) $asiento->total_debe, 0.01);
    }

    public function test_cobro_transferencia_queda_REGISTRADO_pendiente_acreditar(): void
    {
        $cobro = $this->service->registrar($this->payloadBase(
            items: [['tipo_item' => 'FACTURA_VENTA', 'concepto' => 'FV 2', 'importe' => 1200]],
            medios: [['medio_pago_id' => $this->medioTransfId, 'cuenta_bancaria_id' => $this->bancoId, 'importe' => 1200]],
        ));

        $this->assertSame(Cobro::ESTADO_REGISTRADO, $cobro->estado);
        $this->assertSame(CobroMedio::ESTADO_PENDIENTE, $cobro->medios->first()->estado_acreditacion);
    }

    public function test_cobro_mixto_efectivo_y_transferencia_queda_PARCIAL_ACREDITADO(): void
    {
        $cobro = $this->service->registrar($this->payloadBase(
            items: [['tipo_item' => 'FACTURA_VENTA', 'concepto' => 'FV 3', 'importe' => 1000]],
            medios: [
                ['medio_pago_id' => $this->medioEfectivoId, 'caja_id' => $this->cajaId, 'importe' => 400],
                ['medio_pago_id' => $this->medioTransfId, 'cuenta_bancaria_id' => $this->bancoId, 'importe' => 600],
            ],
        ));

        $this->assertSame(Cobro::ESTADO_PARCIAL_ACREDITADO, $cobro->estado);
    }

    public function test_cobro_con_echeq_crea_registro_EN_CARTERA(): void
    {
        $cobro = $this->service->registrar($this->payloadBase(
            items: [['tipo_item' => 'FACTURA_VENTA', 'concepto' => 'FV 4', 'importe' => 2500]],
            medios: [[
                'medio_pago_id' => $this->medioEcheqId,
                'importe' => 2500,
                'echeq' => [
                    'numero' => 'E-'.uniqid(),
                    'cuit_librador' => '20304050607',
                    'fecha_pago' => now()->addDays(30)->toDateString(),
                ],
            ]],
        ));

        $echeq = Echeq::where('cobro_id', $cobro->id)->firstOrFail();
        $this->assertSame(Echeq::ESTADO_EN_CARTERA, $echeq->estado);
        $this->assertEqualsWithDelta(2500.00, (float) $echeq->importe, 0.01);
        $this->assertSame(Cobro::ESTADO_REGISTRADO, $cobro->estado);
    }

    public function test_anular_cobro_REGISTRADO(): void
    {
        $cobro = $this->service->registrar($this->payloadBase(
            items: [['tipo_item' => 'FACTURA_VENTA', 'concepto' => 'FV 5', 'importe' => 100]],
            medios: [['medio_pago_id' => $this->medioTransfId, 'cuenta_bancaria_id' => $this->bancoId, 'importe' => 100]],
        ));

        $cobro = $this->service->anular($cobro, 'Carga errada', $this->user);
        $this->assertSame(Cobro::ESTADO_ANULADO, $cobro->estado);
    }

    private function payloadBase(array $items, array $medios): array
    {
        return [
            'empresa_id' => $this->empresaId,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'auxiliar_id' => $this->clienteId,
            'moneda_id' => 1,
            'cotizacion' => 1.0,
            'items' => $items,
            'medios' => $medios,
        ];
    }
}
