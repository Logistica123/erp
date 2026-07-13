<?php

namespace Tests\Feature\Tesoreria;

use App\Erp\Models\Asiento;
use App\Erp\Models\Tesoreria\Cobro;
use App\Erp\Models\Tesoreria\CobroMedio;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\Echeq;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\EcheqService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests del flujo eCheq (SPEC 02 RN-18, RN-19).
 */
class EcheqServiceTest extends TestCase
{
    use DatabaseTransactions;

    private EcheqService $service;
    private int $empresaId = 1;
    private User $user;
    private CuentaBancaria $cuentaBancaria;
    private int $auxClienteId;
    private int $medioEcheqId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EcheqService::class);
        $this->user = User::firstOrCreate(
            ['email' => 'test.echeq@logistica.local'],
            ['name' => 'Test eCheq user', 'password' => bcrypt('irrelevante')]
        );

        $this->cuentaBancaria = CuentaBancaria::where('empresa_id', $this->empresaId)->firstOrFail();

        $this->auxClienteId = (int) DB::table('erp_auxiliares')
            ->where('empresa_id', $this->empresaId)
            ->where('tipo', 'Cliente')
            ->value('id');

        // Portabilidad (2.1): el catálogo prod no tiene medio ECHEQ ni CC
        // CENTRAL (fallback del service) — los helpers los crean (rollback).
        $this->asegurarCcCentral($this->empresaId);
        $this->medioEcheqId = $this->asegurarMedioPago('ECHEQ', ['afecta_banco' => 1, 'genera_echeq' => 1]);
    }

    public function test_depositar_requiere_EN_CARTERA(): void
    {
        $echeq = $this->crearEcheqEnCartera();
        $echeq->update(['estado' => Echeq::ESTADO_DEPOSITADO]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/ECHEQ_ESTADO_INVALIDO/');

        $this->service->depositar($echeq, $this->cuentaBancaria->id, $this->user);
    }

    public function test_depositar_setea_estado_y_cuenta(): void
    {
        $echeq = $this->crearEcheqEnCartera();

        $echeq = $this->service->depositar($echeq, $this->cuentaBancaria->id, $this->user);

        $this->assertSame(Echeq::ESTADO_DEPOSITADO, $echeq->estado);
        $this->assertSame($this->cuentaBancaria->id, (int) $echeq->deposito_cuenta_id);
        $this->assertNotNull($echeq->fecha_deposito);
    }

    public function test_acreditar_genera_asiento_RN18(): void
    {
        $echeq = $this->crearEcheqEnCartera(15000);
        $echeq = $this->service->depositar($echeq, $this->cuentaBancaria->id, $this->user);
        $mov = $this->crearMovimientoBancario($this->cuentaBancaria->id, 15000);

        $echeq = $this->service->acreditar($echeq, $mov->id, $this->user);

        $this->assertSame(Echeq::ESTADO_ACREDITADO, $echeq->estado);
        $this->assertSame($mov->id, (int) $echeq->movimiento_bancario_id);

        // RN-18: asiento generado debita cuenta bancaria y acredita Valores a Depositar.
        // Origen=BANCO (el ENUM erp_asientos.origen no incluye ECHEQ; se trazabiliza
        // vía origen_tabla=erp_echeq + origen_id=echeq.id).
        $asiento = Asiento::where('origen_tabla', 'erp_echeq')
            ->where('origen_id', $echeq->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Asiento::ESTADO_CONTABILIZADO, $asiento->estado);
        $this->assertEqualsWithDelta(15000.00, (float) $asiento->total_debe, 0.01);
        $this->assertEqualsWithDelta(15000.00, (float) $asiento->total_haber, 0.01);

        // Verifica que tiene una línea contra la cuenta "Valores a Depositar" (1.1.1.04)
        $cuentaValoresId = (int) DB::table('erp_cuentas_contables')
            ->where('codigo', '1.1.1.04')->where('empresa_id', 1)->value('id');
        $tieneLineaValores = $asiento->movimientos()->where('cuenta_id', $cuentaValoresId)->exists();
        $this->assertTrue($tieneLineaValores, 'asiento debe tener línea contra 1.1.1.04');
    }

    public function test_rechazar_desde_DEPOSITADO_genera_reversa_RN19(): void
    {
        $echeq = $this->crearEcheqEnCartera(8000, $this->crearCobro(8000));
        $echeq = $this->service->depositar($echeq, $this->cuentaBancaria->id, $this->user);

        $echeq = $this->service->rechazar($echeq, 'Fondos insuficientes', $this->user);

        $this->assertSame(Echeq::ESTADO_RECHAZADO, $echeq->estado);
        $this->assertSame('Fondos insuficientes', $echeq->motivo_rechazo);

        // RN-19: cobro pasa a RECHAZADO (único medio, sin otros vigentes).
        $cobro = Cobro::find($echeq->cobro_id);
        $this->assertSame(Cobro::ESTADO_RECHAZADO, $cobro->estado);

        // Asiento reversa en diario AJU
        $asiento = Asiento::where('origen', 'AJUSTE')
            ->where('origen_tabla', 'erp_echeq')
            ->where('origen_id', $echeq->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Asiento::ESTADO_CONTABILIZADO, $asiento->estado);
        $this->assertEqualsWithDelta(8000.00, (float) $asiento->total_debe, 0.01);
    }

    public function test_anular_desde_EN_CARTERA(): void
    {
        $echeq = $this->crearEcheqEnCartera(2000);

        $echeq = $this->service->anular($echeq, 'Error de carga', $this->user);

        $this->assertSame(Echeq::ESTADO_ANULADO, $echeq->estado);
    }

    private function crearCobro(float $importe): Cobro
    {
        return Cobro::create([
            'empresa_id' => $this->empresaId,
            'numero' => 'REC-TEST-'.uniqid(),
            'fecha' => now()->toDateString(),
            'auxiliar_id' => $this->auxClienteId,
            'moneda_id' => 1,
            'cotizacion' => 1.0,
            'importe_total' => $importe,
            'estado' => Cobro::ESTADO_REGISTRADO,
            'creado_por_user_id' => $this->user->id,
        ]);
    }

    private function crearEcheqEnCartera(float $importe = 10000, ?Cobro $cobro = null): Echeq
    {
        $cobro ??= $this->crearCobro($importe);

        $echeq = Echeq::create([
            'empresa_id' => $this->empresaId,
            'numero' => 'ECH-'.uniqid(),
            'cuit_librador' => '20123456789',
            'razon_social_librador' => 'Cliente Test SA',
            'banco_origen' => 'Galicia',
            'importe' => $importe,
            'moneda_id' => 1,
            'fecha_emision' => now()->toDateString(),
            'fecha_pago' => now()->addDays(30)->toDateString(),
            'estado' => Echeq::ESTADO_EN_CARTERA,
            'cobro_id' => $cobro->id,
        ]);

        // Crear el cobro_medio asociado (necesario para que rechazar() propague correctamente)
        CobroMedio::create([
            'cobro_id' => $cobro->id,
            'medio_pago_id' => $this->medioEcheqId,
            'echeq_id' => $echeq->id,
            'importe' => $importe,
            'estado_acreditacion' => CobroMedio::ESTADO_PENDIENTE,
        ]);

        return $echeq;
    }

    private function crearMovimientoBancario(int $cuentaBancariaId, float $importe): MovimientoBancario
    {
        $extracto = ExtractoBancario::create([
            'cuenta_bancaria_id' => $cuentaBancariaId,
            'fecha_desde' => now()->toDateString(),
            'fecha_hasta' => now()->toDateString(),
            'hash_archivo' => hash('sha256', 'test-extracto-'.uniqid()),
            'nombre_archivo' => 'test.csv',
            'cant_movimientos' => 1,
            'importado_por_user_id' => $this->user->id,
            'importado_at' => now(),
        ]);

        return MovimientoBancario::create([
            'extracto_id' => $extracto->id,
            'cuenta_bancaria_id' => $cuentaBancariaId,
            'fecha' => now()->toDateString(),
            'concepto' => 'DEP ECHEQ TEST',
            'debito' => 0,
            'credito' => $importe,
            'estado' => MovimientoBancario::ESTADO_PENDIENTE,
            'hash_linea' => hash('sha256', 'test-mov-'.uniqid()),
        ]);
    }
}
