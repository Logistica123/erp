<?php

namespace Tests\Feature\Tesoreria;

use App\Erp\Models\CentroCosto;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Services\CobroService;
use App\Erp\Services\TransferenciaInternaService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mini-tanda 2026-07-13, bug 1 (PLAN_REMEDIACION_ESTADO.md): 5 services
 * usaban el CC de código 'CENTRAL' como fallback cuando una cuenta admite
 * CC y no viene explícito — pero en prod ese CC no existe (el operativo
 * es 'GENERAL'; v1.51 arregló SOLO ArqueoCajaService). Resultado: una
 * operación sin CC explícito sobre cuenta admite_cc=1 moría con
 * CC_REQUERIDO en producción.
 *
 * Contrato nuevo: CentroCosto::operativoId() resuelve CENTRAL → GENERAL
 * → null, y los 6 services (Cobro, Echeq, Ejercicio, MovimientoBancario,
 * TransferenciaInterna, ArqueoCaja) lo comparten.
 *
 * OJO: estos tests NO crean el CC 'CENTRAL' (a diferencia de las fixtures
 * de portabilidad de 2.1) — ejercitan exactamente el escenario prod.
 */
class CcOperativoFallbackTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::firstOrCreate(
            ['email' => 'test.ccfallback@logistica.local'],
            ['name' => 'Test CC fallback', 'password' => bcrypt('irrelevante')]
        );

        // Precondición del escenario prod: no existe CC 'CENTRAL'.
        $this->assertNull(
            DB::table('erp_centros_costo')
                ->where('empresa_id', $this->empresaId)->where('codigo', 'CENTRAL')->value('id'),
            'Este test requiere una base sin CC CENTRAL (esquema prod)'
        );
    }

    public function test_resolver_devuelve_general_cuando_no_hay_central(): void
    {
        $generalId = (int) DB::table('erp_centros_costo')
            ->where('empresa_id', $this->empresaId)->where('codigo', 'GENERAL')->value('id');
        $this->assertGreaterThan(0, $generalId, 'el clon prod tiene CC GENERAL');

        $this->assertSame($generalId, CentroCosto::operativoId($this->empresaId));
    }

    public function test_resolver_prefiere_central_si_existe(): void
    {
        $centralId = (int) DB::table('erp_centros_costo')->insertGetId([
            'empresa_id' => $this->empresaId, 'codigo' => 'CENTRAL',
            'nombre' => 'Central (fixture)', 'tipo' => 'OTRO', 'activo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($centralId, CentroCosto::operativoId($this->empresaId));
    }

    public function test_cobro_efectivo_sin_central_usa_general_y_no_muere_con_cc_requerido(): void
    {
        $clienteId = (int) DB::table('erp_auxiliares')
            ->where('empresa_id', $this->empresaId)->where('tipo', 'Cliente')->value('id');
        $cajaId = (int) DB::table('erp_cajas')
            ->where('empresa_id', $this->empresaId)->where('activo', 1)->value('id');
        $medioEfectivoId = (int) DB::table('erp_medios_pago')->where('codigo', 'EFECTIVO')->value('id');

        $cobro = app(CobroService::class)->registrar([
            'empresa_id' => $this->empresaId,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'auxiliar_id' => $clienteId,
            'moneda_id' => 1,
            'items' => [['tipo_item' => 'FACTURA_VENTA', 'concepto' => 'FV cc-fallback', 'importe' => 100]],
            'medios' => [['medio_pago_id' => $medioEfectivoId, 'caja_id' => $cajaId, 'importe' => 100]],
        ]);

        $this->assertNotNull($cobro->asiento_id, 'el cobro debe contabilizar sin CC explícito');

        // Toda línea sobre cuenta admite_cc quedó con el CC operativo (GENERAL).
        $generalId = (int) DB::table('erp_centros_costo')
            ->where('empresa_id', $this->empresaId)->where('codigo', 'GENERAL')->value('id');
        $sinCc = DB::table('erp_movimientos_asiento as m')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_id')
            ->where('m.asiento_id', $cobro->asiento_id)
            ->where('c.admite_cc', 1)
            ->whereNull('m.centro_costo_id')
            ->count();
        $this->assertSame(0, $sinCc, 'ninguna línea admite_cc puede quedar sin CC');
        $this->assertTrue(
            DB::table('erp_movimientos_asiento')->where('asiento_id', $cobro->asiento_id)
                ->where('centro_costo_id', $generalId)->exists(),
            'el fallback debe haber usado GENERAL'
        );
    }

    public function test_transferencia_interna_sin_central_contabiliza(): void
    {
        $cuentas = CuentaBancaria::where('empresa_id', $this->empresaId)->take(2)->pluck('id')->all();
        $this->assertCount(2, $cuentas);

        $svc = app(TransferenciaInternaService::class);
        $ti = $svc->registrar([
            'empresa_id' => $this->empresaId,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'cuenta_origen_id' => $cuentas[0],
            'cuenta_destino_id' => $cuentas[1],
            'importe_origen' => 100,
        ]);
        $ti = $svc->contabilizar($ti, $this->user);

        $this->assertNotNull($ti->asiento_id, 'la TI debe contabilizar sin CC explícito');
    }
}
