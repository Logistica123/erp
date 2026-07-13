<?php

namespace Tests\Feature\Af;

use App\Erp\Models\Af\AfBien;
use App\Erp\Models\Af\AfCategoria;
use App\Erp\Models\Af\AfReexpresion;
use App\Erp\Models\Ejercicio;
use App\Erp\Services\Af\AfBienService;
use App\Erp\Services\Af\AfReexpresionService;
use App\Erp\Services\Af\AfReportesService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests de reportes AF + reexpresión RT 6 (RN-82).
 */
class AfReportesTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('erp_af_reexpresiones')) {
            $this->markTestSkipped('DDL_06 I1 no aplicado');
        }
        $this->user = User::firstOrCreate(
            ['email' => 'test.i3@logistica.local'],
            ['name' => 'Test I3', 'password' => bcrypt('irrelevante')]
        );
    }

    // ----- Reportes -----

    public function test_listado_devuelve_estructura_y_totales(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $b1 = $this->crearBien($cat, 1_000_000, '2077-01-15');
        $b2 = $this->crearBien($cat, 500_000, '2077-02-20');

        $res = app(AfReportesService::class)->listado(1, '2077-12-31');

        $this->assertGreaterThanOrEqual(2, $res['cantidad']);
        $this->assertArrayHasKey('totales', $res);
        $this->assertGreaterThanOrEqual(1_500_000.0, $res['totales']['valor_origen']);
    }

    public function test_listado_no_incluye_bienes_dados_de_baja(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_000_000, '2076-01-15');
        $bien->update(['estado' => 'BAJA', 'fecha_baja' => '2076-06-30']);

        $res = app(AfReportesService::class)->listado(1, '2076-12-31');
        $ids = array_column($res['filas'], 'id');
        $this->assertNotContains($bien->id, $ids);
    }

    public function test_anexo_bienes_uso_devuelve_secciones_por_categoria(): void
    {
        $ejercicio = $this->crearEjercicio(2075);
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $this->crearBien($cat, 1_000_000, '2075-03-15');

        $anexo = app(AfReportesService::class)->anexoBienesUso($ejercicio);

        $this->assertArrayHasKey('secciones', $anexo);
        $this->assertArrayHasKey('totales', $anexo);
        $info = collect($anexo['secciones'])->firstWhere('codigo', 'INFORMATICA');
        $this->assertNotNull($info);
        $this->assertGreaterThanOrEqual(1_000_000.0, $info['altas']);
    }

    public function test_altas_bajas_devuelve_movimientos_del_ejercicio(): void
    {
        $ejercicio = $this->crearEjercicio(2074);
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $this->crearBien($cat, 800_000, '2074-04-15');

        $res = app(AfReportesService::class)->altasBajas($ejercicio);
        $tipos = array_column((array) $res['movimientos'], 'tipo');
        $this->assertContains('ALTA', $tipos);
    }

    // ----- Reexpresión RT 6 -----

    public function test_reexp_no_aplica_si_flag_apagado(): void
    {
        $ejercicio = $this->crearEjercicio(2073);
        $ejercicio->update(['ajusta_por_inflacion' => 0]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/AF_REEXP_NO_APLICA/');
        app(AfReexpresionService::class)->generar($ejercicio->fresh(), $this->user);
    }

    public function test_reexp_falla_sin_indice_cierre(): void
    {
        $ejercicio = $this->crearEjercicio(2072);
        $ejercicio->update(['ajusta_por_inflacion' => 1, 'indice_cierre' => null]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/AF_REEXP_INDICE_CIERRE_FALTANTE/');
        app(AfReexpresionService::class)->generar($ejercicio->fresh(), $this->user);
    }

    public function test_reexp_calcula_coeficiente_y_rei(): void
    {
        $ejercicio = $this->crearEjercicio(2071);
        $ejercicio->update(['ajusta_por_inflacion' => 1, 'indice_cierre' => 1.5]);

        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_000_000, '2071-01-15');
        $bien->update(['indice_alta' => 1.0]);

        $res = app(AfReexpresionService::class)->generar($ejercicio->fresh(), $this->user);

        // Portabilidad (2.1): el clon de prod tiene bienes reales que también
        // entran en la reexpresión — se asserta la fila del bien de fixture,
        // no el total global.
        $fila = collect($res['filas'])->firstWhere('bien_id', $bien->id);
        $this->assertNotNull($fila, 'la reexpresión debe incluir el bien de fixture');
        $this->assertEqualsWithDelta(1.5, $fila['coeficiente'], 0.001);
        $this->assertEqualsWithDelta(1_500_000.00, $fila['valor_reexpresado'], 0.01);
        $this->assertEqualsWithDelta(500_000.00, $fila['rei'], 0.01);
        $this->assertGreaterThanOrEqual(500_000.00 - 0.01, $res['totales']['rei']);

        // Bien queda con valor_reexpresado snapshotted.
        $this->assertEqualsWithDelta(1_500_000.00, (float) $bien->fresh()->valor_reexpresado, 0.01);
    }

    public function test_reexp_idempotente(): void
    {
        $ejercicio = $this->crearEjercicio(2070);
        $ejercicio->update(['ajusta_por_inflacion' => 1, 'indice_cierre' => 2.0]);

        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = $this->crearBien($cat, 1_000_000, '2070-01-15');
        $bien->update(['indice_alta' => 1.0]);

        app(AfReexpresionService::class)->generar($ejercicio->fresh(), $this->user);
        app(AfReexpresionService::class)->generar($ejercicio->fresh(), $this->user);

        $count = AfReexpresion::where('bien_id', $bien->id)
            ->where('ejercicio_id', $ejercicio->id)->count();
        $this->assertEquals(1, $count);
    }

    // ------------------------------------------------------------------------

    private function crearEjercicio(int $numero): Ejercicio
    {
        return Ejercicio::create([
            'empresa_id' => 1, 'numero' => $numero,
            'nombre' => "Ej {$numero}",
            'fecha_inicio' => sprintf('%04d-01-01', $numero),
            'fecha_cierre' => sprintf('%04d-12-31', $numero),
            'estado' => 'ABIERTO',
        ]);
    }

    private function crearBien(AfCategoria $cat, float $valor, string $fecha): AfBien
    {
        return app(AfBienService::class)->alta([
            'empresa_id' => 1, 'categoria_id' => $cat->id,
            'nro_inventario' => 'IT-REP-'.uniqid(),
            'descripcion' => 'Test', 'fecha_alta' => $fecha,
            'valor_origen' => $valor,
        ], $this->user);
    }
}
