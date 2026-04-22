<?php

namespace Tests\Feature\Tesoreria;

use App\Erp\Models\Tesoreria\Cobro;
use App\Erp\Models\Tesoreria\CobroMedio;
use App\Erp\Models\Tesoreria\Conciliacion;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MotivoIgnorado;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Services\ConciliacionService;
use App\Erp\Services\CobroService;
use App\Erp\Services\OrdenPagoService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de conciliación polimórfica (SPEC 02 RN-14, RN-21, RN-26).
 */
class ConciliacionServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ConciliacionService $service;
    private OrdenPagoService $opService;
    private CobroService $cobroService;
    private User $user;
    private int $empresaId = 1;
    private CuentaBancaria $cuentaBancaria;
    private int $auxProveedorId;
    private int $auxClienteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ConciliacionService::class);
        $this->opService = app(OrdenPagoService::class);
        $this->cobroService = app(CobroService::class);
        $this->user = User::firstOrCreate(
            ['email' => 'test.conciliacion@logistica.local'],
            ['name' => 'Test conciliación', 'password' => bcrypt('irrelevante')]
        );

        $this->cuentaBancaria = CuentaBancaria::where('empresa_id', $this->empresaId)->firstOrFail();
        $this->auxProveedorId = (int) DB::table('erp_auxiliares')->where('empresa_id', $this->empresaId)
            ->whereIn('tipo', ['Proveedor', 'Distribuidor'])->value('id');
        $this->auxClienteId = (int) DB::table('erp_auxiliares')->where('empresa_id', $this->empresaId)
            ->where('tipo', 'Cliente')->value('id');
    }

    public function test_conciliar_OP_marca_pagada_y_genera_asiento(): void
    {
        $op = $this->crearOpLiberada(1000);
        $mov = $this->crearMovBancario(debito: 1000);

        $mov = $this->service->conciliar($mov, [
            'referencia_tipo' => Conciliacion::REF_ORDEN_PAGO,
            'referencia_id' => $op->id,
        ], $this->user);

        $this->assertSame(MovimientoBancario::ESTADO_CONCILIADO, $mov->estado);
        $this->assertNotNull($mov->asiento_id);

        $op->refresh();
        $this->assertSame(OrdenPago::ESTADO_PAGADA, $op->estado);
        $this->assertSame($mov->asiento_id, (int) $op->asiento_id);

        $this->assertSame(1, Conciliacion::where('movimiento_bancario_id', $mov->id)
            ->where('referencia_tipo', Conciliacion::REF_ORDEN_PAGO)
            ->count());
    }

    public function test_rn14_rechaza_segunda_conciliacion_mismo_origen(): void
    {
        $op = $this->crearOpLiberada(500);
        $mov1 = $this->crearMovBancario(debito: 500);
        $mov2 = $this->crearMovBancario(debito: 500);

        $this->service->conciliar($mov1, [
            'referencia_tipo' => Conciliacion::REF_ORDEN_PAGO,
            'referencia_id' => $op->id,
        ], $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/RN-14/');
        $this->service->conciliar($mov2, [
            'referencia_tipo' => Conciliacion::REF_ORDEN_PAGO,
            'referencia_id' => $op->id,
        ], $this->user);
    }

    public function test_conciliar_cobro_transferencia_marca_medio_acreditado(): void
    {
        $medioTransf = (int) DB::table('erp_medios_pago')->where('codigo', 'TRANSFERENCIA')->value('id');
        $cobro = $this->cobroService->registrar([
            'empresa_id' => $this->empresaId,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'auxiliar_id' => $this->auxClienteId,
            'moneda_id' => 1,
            'items' => [['tipo_item' => 'FACTURA_VENTA', 'concepto' => 'FV x', 'importe' => 700]],
            'medios' => [['medio_pago_id' => $medioTransf, 'cuenta_bancaria_id' => $this->cuentaBancaria->id, 'importe' => 700]],
        ]);

        $mov = $this->crearMovBancario(credito: 700);

        $mov = $this->service->conciliar($mov, [
            'referencia_tipo' => Conciliacion::REF_COBRO,
            'referencia_id' => $cobro->id,
        ], $this->user);

        $medio = CobroMedio::where('cobro_id', $cobro->id)->first();
        $this->assertSame(CobroMedio::ESTADO_ACREDITADO, $medio->estado_acreditacion);
        $this->assertSame($mov->id, (int) $medio->movimiento_bancario_id);
        $this->assertSame(Cobro::ESTADO_ACREDITADO, $cobro->fresh()->estado);
    }

    public function test_desconciliar_revierte_estado_OP_y_borra_conciliacion(): void
    {
        $op = $this->crearOpLiberada(250);
        $mov = $this->crearMovBancario(debito: 250);
        $mov = $this->service->conciliar($mov, [
            'referencia_tipo' => Conciliacion::REF_ORDEN_PAGO,
            'referencia_id' => $op->id,
        ], $this->user);

        $mov = $this->service->desconciliar($mov, 'Prueba de rollback', $this->user);

        $this->assertNotSame(MovimientoBancario::ESTADO_CONCILIADO, $mov->estado);
        $this->assertNull($mov->asiento_id);
        $this->assertSame(OrdenPago::ESTADO_LIBERADA, $op->fresh()->estado);
        $this->assertSame(0, Conciliacion::where('movimiento_bancario_id', $mov->id)->count());
    }

    public function test_rn26_ignorar_requiere_motivo_activo(): void
    {
        $mov = $this->crearMovBancario(debito: 20);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/MOTIVO_IGNORADO_INVALIDO/');
        $this->service->ignorar($mov, 99999, null, $this->user);
    }

    public function test_rn26_ignorar_con_motivo_valido_ok(): void
    {
        $motivo = MotivoIgnorado::where('activo', true)->firstOrFail();
        $mov = $this->crearMovBancario(debito: 10);

        $mov = $this->service->ignorar($mov, $motivo->id, 'Comisión agrupada', $this->user);

        $this->assertSame(MovimientoBancario::ESTADO_IGNORADO, $mov->estado);
        $this->assertSame($motivo->id, (int) $mov->motivo_ignorado_id);
    }

    public function test_rn21_conciliado_no_se_puede_ignorar(): void
    {
        $op = $this->crearOpLiberada(100);
        $mov = $this->crearMovBancario(debito: 100);
        $this->service->conciliar($mov, [
            'referencia_tipo' => Conciliacion::REF_ORDEN_PAGO,
            'referencia_id' => $op->id,
        ], $this->user);

        $mov->refresh();
        $motivo = MotivoIgnorado::where('activo', true)->firstOrFail();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/MOVIMIENTO_CONCILIADO/');
        $this->service->ignorar($mov, $motivo->id, null, $this->user);
    }

    private function crearOpLiberada(float $importe): OrdenPago
    {
        $op = $this->opService->crear([
            'empresa_id' => $this->empresaId,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'auxiliar_id' => $this->auxProveedorId,
            'moneda_id' => 1,
            'cotizacion' => 1.0,
            'importe' => $importe,
        ]);
        $op = $this->opService->cargarBanco($op, $this->user);

        return $this->opService->liberar($op, $this->user);
    }

    private function crearMovBancario(float $debito = 0, float $credito = 0): MovimientoBancario
    {
        $extracto = ExtractoBancario::create([
            'cuenta_bancaria_id' => $this->cuentaBancaria->id,
            'fecha_desde' => now()->toDateString(),
            'fecha_hasta' => now()->toDateString(),
            'hash_archivo' => hash('sha256', 'concil-test-'.uniqid()),
            'nombre_archivo' => 'concil-test.csv',
            'cant_movimientos' => 1,
            'importado_por_user_id' => $this->user->id,
            'importado_at' => now(),
        ]);

        return MovimientoBancario::create([
            'extracto_id' => $extracto->id,
            'cuenta_bancaria_id' => $this->cuentaBancaria->id,
            'fecha' => now()->toDateString(),
            'concepto' => 'TEST CONCIL',
            'debito' => $debito,
            'credito' => $credito,
            'estado' => MovimientoBancario::ESTADO_PENDIENTE,
            'hash_linea' => hash('sha256', 'test-'.uniqid()),
        ]);
    }
}
