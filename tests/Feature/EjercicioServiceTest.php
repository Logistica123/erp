<?php

namespace Tests\Feature;

use App\Erp\Models\Asiento;
use App\Erp\Models\Ejercicio;
use App\Erp\Services\AsientoService;
use App\Erp\Services\EjercicioService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cada test crea un ejercicio NUEVO (año 2030+) con 12 períodos propios.
 * Así los tests no interfieren con los datos de dev (ejercicio 1 / 2026) y
 * entre sí. DatabaseTransactions hace rollback al terminar.
 */
class EjercicioServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AsientoService $asientos;
    private EjercicioService $service;
    private int $empresaId = 1;
    private int $ejercicioId;
    private int $diarioBanId;
    private int $ccCentralId;
    private int $userId;

    // Año base único por test run para evitar colisiones con el ejercicio 1.
    private int $anio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asientos = app(AsientoService::class);
        $this->service = app(EjercicioService::class);

        $this->diarioBanId = (int) DB::table('erp_diarios')
            ->where('empresa_id', $this->empresaId)->where('codigo', 'BAN')->value('id');
        $this->ccCentralId = (int) DB::table('erp_centros_costo')
            ->where('empresa_id', $this->empresaId)->where('codigo', 'CENTRAL')->value('id');

        $user = User::firstOrCreate(
            ['email' => 'test.ejercicioservice@logistica.local'],
            ['name' => 'Test ejercicio', 'password' => bcrypt('x')]
        );
        $this->userId = $user->id;

        // Año arbitrario distante del ejercicio real. Cada test pisa/recrea.
        $this->anio = 2030;

        $this->ejercicioId = (int) DB::table('erp_ejercicios')->insertGetId([
            'empresa_id' => $this->empresaId,
            'numero' => 5, // arbitrario
            'nombre' => 'Test ejercicio '.$this->anio,
            'fecha_inicio' => "{$this->anio}-01-01",
            'fecha_cierre' => "{$this->anio}-12-31",
            'estado' => 'ABIERTO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 12 períodos
        for ($mes = 1; $mes <= 12; $mes++) {
            DB::table('erp_periodos')->insert([
                'ejercicio_id' => $this->ejercicioId,
                'anio' => $this->anio,
                'mes' => $mes,
                'fecha_inicio' => sprintf('%d-%02d-01', $this->anio, $mes),
                'fecha_fin' => date('Y-m-t', strtotime(sprintf('%d-%02d-01', $this->anio, $mes))),
                'estado' => 'ABIERTO',
                'cierre_iva' => false,
                'cierre_iibb' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_cerrar_ejercicio_genera_asiento_refundicion_balanceado_y_marca_ejercicio_CERRADO(): void
    {
        // Ingreso: Intereses ganados $10.000
        $this->asientoContabilizado("{$this->anio}-06-15", [
            ['cuenta_codigo' => '1.1.2.01', 'debe' => 10000, 'haber' => 0, 'centro_costo_id' => $this->ccCentralId],
            ['cuenta_codigo' => '4.2.01', 'debe' => 0, 'haber' => 10000],
        ]);
        // Egreso: Honorarios $3.000
        $this->asientoContabilizado("{$this->anio}-07-20", [
            ['cuenta_codigo' => '5.2.1.08', 'debe' => 3000, 'haber' => 0],
            ['cuenta_codigo' => '1.1.2.01', 'debe' => 0, 'haber' => 3000, 'centro_costo_id' => $this->ccCentralId],
        ]);

        $user = User::find($this->userId);
        $result = $this->service->cerrar(Ejercicio::find($this->ejercicioId), $user);

        $this->assertEquals('CERRADO', $result['ejercicio']->estado);
        $this->assertEquals(7000.0, $result['resultado']);
        $this->assertEquals(Asiento::ESTADO_CONTABILIZADO, $result['asiento_refundicion']->estado);
        $this->assertEquals('CIERRE', $result['asiento_refundicion']->origen);
        $this->assertEquals(
            (float) $result['asiento_refundicion']->total_debe,
            (float) $result['asiento_refundicion']->total_haber,
            'asiento refundición debe estar balanceado'
        );
    }

    public function test_cerrar_con_perdida_debita_cuenta_resultado_PN(): void
    {
        // Solo egreso
        $this->asientoContabilizado("{$this->anio}-06-15", [
            ['cuenta_codigo' => '5.2.1.08', 'debe' => 5000, 'haber' => 0],
            ['cuenta_codigo' => '1.1.2.01', 'debe' => 0, 'haber' => 5000, 'centro_costo_id' => $this->ccCentralId],
        ]);

        $user = User::find($this->userId);
        $result = $this->service->cerrar(Ejercicio::find($this->ejercicioId), $user);

        $this->assertEquals(-5000.0, $result['resultado'], 'solo egresos → pérdida');

        $movPn = $result['asiento_refundicion']
            ->movimientos()
            ->whereHas('cuenta', fn ($q) => $q->where('codigo', '3.3.02'))
            ->first();

        $this->assertNotNull($movPn, 'debe existir movimiento en 3.3.02');
        $this->assertEquals(5000.0, (float) $movPn->debe, 'pérdida → DEBE en 3.3.02');
        $this->assertEquals(0.0, (float) $movPn->haber);
    }

    public function test_cerrar_falla_si_ejercicio_no_esta_ABIERTO(): void
    {
        DB::table('erp_ejercicios')->where('id', $this->ejercicioId)->update(['estado' => 'CERRADO']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/EJERCICIO_NO_ABIERTO/');
        $this->service->cerrar(Ejercicio::find($this->ejercicioId)->fresh(), User::find($this->userId));
    }

    public function test_cerrar_falla_si_ultimo_periodo_esta_CERRADO(): void
    {
        DB::table('erp_periodos')
            ->where('ejercicio_id', $this->ejercicioId)
            ->where('mes', 12)
            ->update(['estado' => 'CERRADO']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/ULTIMO_PERIODO_CERRADO/');
        $this->service->cerrar(Ejercicio::find($this->ejercicioId)->fresh(), User::find($this->userId));
    }

    public function test_cerrar_falla_sin_movimientos_de_resultado(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/SIN_MOVIMIENTOS/');
        $this->service->cerrar(Ejercicio::find($this->ejercicioId), User::find($this->userId));
    }

    private function asientoContabilizado(string $fecha, array $movimientos): Asiento
    {
        $asiento = $this->asientos->crearBorrador([
            'empresa_id' => $this->empresaId,
            'diario_id' => $this->diarioBanId,
            'fecha' => $fecha,
            'glosa' => 'Test '.uniqid(),
            'usuario_id' => $this->userId,
            'movimientos' => $movimientos,
        ]);

        return $this->asientos->contabilizar($asiento);
    }
}
