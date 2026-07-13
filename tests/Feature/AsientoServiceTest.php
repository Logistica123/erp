<?php

namespace Tests\Feature;

use App\Erp\Models\Asiento;
use App\Erp\Services\AsientoService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cobertura de las reglas de negocio del SPEC_01 §6 sobre el service
 * que crea y contabiliza asientos. Usa DatabaseTransactions para hacer
 * rollback al final de cada test (no destruye la DB de dev).
 */
class AsientoServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AsientoService $service;
    private int $empresaId = 1;
    private int $diarioId;
    private int $userId;
    private int $ccCentralId;
    private int $auxProvId;
    private string $cuentaProveedores = '2.1.1.01';
    private string $cuentaIcbc = '1.1.2.01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AsientoService::class);

        // Resuelvo los IDs desde la DB seedeada.
        $this->diarioId = (int) DB::table('erp_diarios')
            ->where('empresa_id', $this->empresaId)->where('codigo', 'BAN')->value('id');
        // Portabilidad (2.1): CENTRAL y PROV-XYZ no existen en el catálogo
        // prod — los helpers los crean dentro de la transacción del test.
        $this->ccCentralId = $this->asegurarCcCentral($this->empresaId);
        $this->auxProvId = $this->asegurarAuxiliar('PROV-XYZ', 'Proveedor', $this->empresaId);

        $user = User::firstOrCreate(
            ['email' => 'test.asientoservice@logistica.local'],
            ['name' => 'Test user', 'password' => bcrypt('irrelevante')]
        );
        $this->userId = $user->id;
    }

    public function test_crear_borrador_con_asiento_balanceado_persiste_y_devuelve_estado_BORRADOR(): void
    {
        $asiento = $this->service->crearBorrador($this->payloadBalanceado(1000));

        $this->assertEquals(Asiento::ESTADO_BORRADOR, $asiento->estado);
        $this->assertEquals(1000.00, (float) $asiento->total_debe);
        $this->assertEquals(1000.00, (float) $asiento->total_haber);
        $this->assertNotEmpty($asiento->numero, 'debe asignar numerador');
    }

    public function test_contabilizar_transiciona_a_CONTABILIZADO_y_calcula_hash_sha256(): void
    {
        $asiento = $this->service->crearBorrador($this->payloadBalanceado(500));
        $contabilizado = $this->service->contabilizar($asiento);

        $this->assertEquals(Asiento::ESTADO_CONTABILIZADO, $contabilizado->estado);
        $this->assertNotNull($contabilizado->hash_integridad);
        $this->assertEquals(64, strlen($contabilizado->hash_integridad), 'SHA-256 = 64 hex chars');
    }

    public function test_rn2_rechaza_linea_con_debe_y_haber_a_la_vez(): void
    {
        $payload = $this->payloadBalanceado(100);
        $payload['movimientos'][0]['haber'] = 50;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/LINEA_INVALIDA/');
        $this->service->crearBorrador($payload);
    }

    public function test_rn3_rechaza_cuenta_no_imputable(): void
    {
        $payload = $this->payloadBalanceado(100);
        // "1" es rubro agrupador ACTIVO, imputable=0
        $payload['movimientos'][0]['cuenta_codigo'] = '1';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/CUENTA_NO_IMPUTABLE/');
        $this->service->crearBorrador($payload);
    }

    public function test_rn10_exige_centro_de_costo_si_la_cuenta_lo_admite(): void
    {
        $payload = $this->payloadBalanceado(100);
        // La cuenta 2.1.1.01 tiene admite_cc=1 — removemos CC y debe fallar
        $payload['movimientos'][0]['centro_costo_id'] = null;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/CC_REQUERIDO/');
        $this->service->crearBorrador($payload);
    }

    public function test_rn1_contabilizar_desbalanceado_lanza_ASIENTO_DESBALANCEADO(): void
    {
        // Creamos un asiento y luego corrompemos un movimiento para simular desbalance.
        // (El service normalmente no deja crear desbalance — lo hacemos via DB directo.)
        $asiento = $this->service->crearBorrador($this->payloadBalanceado(200));

        DB::table('erp_movimientos_asiento')
            ->where('asiento_id', $asiento->id)
            ->where('haber', '>', 0)
            ->limit(1)
            ->update(['haber' => 199.50]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/ASIENTO_DESBALANCEADO/');
        $this->service->contabilizar($asiento->fresh('movimientos'));
    }

    public function test_rn9_numerador_correlativo_se_incrementa_por_diario(): void
    {
        $n1 = $this->service->crearBorrador($this->payloadBalanceado(100));
        $n2 = $this->service->crearBorrador($this->payloadBalanceado(200));

        $this->assertSame($n1->diario_id, $n2->diario_id);
        $this->assertSame($n1->numero + 1, $n2->numero, 'numerador debe incrementar');
    }

    public function test_rn5_anular_genera_asiento_reversa_con_debe_y_haber_invertidos(): void
    {
        $original = $this->service->crearBorrador($this->payloadBalanceado(777));
        $original = $this->service->contabilizar($original);

        $anulado = $this->service->anular($original, $this->userId, 'Error de carga');

        $this->assertEquals(Asiento::ESTADO_ANULADO, $anulado->estado);
        $this->assertNotNull($anulado->asiento_reversa_id);

        $reversa = Asiento::findOrFail($anulado->asiento_reversa_id);
        $this->assertEquals(Asiento::ESTADO_CONTABILIZADO, $reversa->estado);
        $this->assertEquals('AJUSTE', $reversa->origen);

        // Los totales se invirtieron: el debe original ahora es el haber de la reversa.
        $this->assertEquals((float) $original->total_debe, (float) $reversa->total_haber);
        $this->assertEquals((float) $original->total_haber, (float) $reversa->total_debe);
    }

    private function payloadBalanceado(float $monto): array
    {
        return [
            'empresa_id' => $this->empresaId,
            'diario_id' => $this->diarioId,
            'fecha' => '2026-04-18',
            'glosa' => 'Test '.uniqid(),
            'usuario_id' => $this->userId,
            'movimientos' => [
                [
                    'cuenta_codigo' => $this->cuentaProveedores,
                    'debe' => $monto,
                    'haber' => 0,
                    'centro_costo_id' => $this->ccCentralId,
                    'auxiliar_id' => $this->auxProvId,
                ],
                [
                    'cuenta_codigo' => $this->cuentaIcbc,
                    'debe' => 0,
                    'haber' => $monto,
                    'centro_costo_id' => $this->ccCentralId,
                ],
            ],
        ];
    }
}
