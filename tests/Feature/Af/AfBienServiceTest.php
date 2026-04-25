<?php

namespace Tests\Feature\Af;

use App\Erp\Models\Af\AfBien;
use App\Erp\Models\Af\AfCategoria;
use App\Erp\Models\Af\AfMovimiento;
use App\Erp\Services\Af\AfBienService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests del AfBienService — alta manual, activar desde factura, edición,
 * RN-77 umbral, RN-84 trazabilidad.
 */
class AfBienServiceTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('erp_af_bienes') || ! Schema::hasTable('erp_af_categorias')) {
            $this->markTestSkipped('DDL_06 I1 no aplicado');
        }
        if (DB::table('erp_af_categorias')->count() === 0) {
            $this->markTestSkipped('Seed AF categorías no aplicado');
        }

        $this->user = User::firstOrCreate(
            ['email' => 'test.i1@logistica.local'],
            ['name' => 'Test I1', 'password' => bcrypt('irrelevante')]
        );
    }

    public function test_alta_manual_genera_bien_y_movimiento(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $this->assertNotNull($cat);

        $bien = app(AfBienService::class)->alta([
            'empresa_id'   => 1,
            'categoria_id' => $cat->id,
            'nro_inventario' => 'IT-TEST-'.uniqid(),
            'descripcion'  => 'Notebook test',
            'fecha_alta'   => '2026-04-15',
            'valor_origen' => 1_500_000,  // > umbral 50.000
            'centro_costo_id' => null,
        ], $this->user);

        $this->assertEquals('ALTA', $bien->estado);
        $this->assertEquals(1_500_000.00, (float) $bien->valor_origen);

        $movs = AfMovimiento::where('bien_id', $bien->id)->get();
        $this->assertCount(1, $movs);
        $this->assertEquals('ALTA', $movs[0]->tipo);
    }

    public function test_alta_bajo_umbral_rechaza_RN77(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/AF_BIEN_BAJO_UMBRAL/');

        app(AfBienService::class)->alta([
            'empresa_id'   => 1,
            'categoria_id' => $cat->id,
            'nro_inventario' => 'IT-CHEAP-'.uniqid(),
            'descripcion'  => 'Mouse barato',
            'fecha_alta'   => '2026-04-15',
            'valor_origen' => 5000,  // < umbral 50.000
        ], $this->user);
    }

    public function test_editar_cc_genera_movimiento_transferencia(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $cc1 = (int) DB::table('erp_centros_costo')->where('empresa_id', 1)->value('id');
        $cc2 = (int) DB::table('erp_centros_costo')->where('empresa_id', 1)->where('id', '>', $cc1)->value('id');
        if (! $cc1 || ! $cc2) {
            $this->markTestSkipped('Sin centros de costo seedeados');
        }

        $bien = app(AfBienService::class)->alta([
            'empresa_id'   => 1, 'categoria_id' => $cat->id,
            'nro_inventario' => 'IT-CC-'.uniqid(),
            'descripcion'  => 'Notebook CC test',
            'fecha_alta'   => '2026-04-15',
            'valor_origen' => 800_000,
            'centro_costo_id' => $cc1,
        ], $this->user);

        app(AfBienService::class)->editar($bien, ['centro_costo_id' => $cc2], $this->user);

        $mov = AfMovimiento::where('bien_id', $bien->id)
            ->where('tipo', 'TRANSFERENCIA_CC')->first();
        $this->assertNotNull($mov);
        $this->assertEquals($cc1, $mov->cc_anterior_id);
        $this->assertEquals($cc2, $mov->cc_nuevo_id);
    }

    public function test_editar_responsable_y_ubicacion_generan_movimientos(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = app(AfBienService::class)->alta([
            'empresa_id'   => 1, 'categoria_id' => $cat->id,
            'nro_inventario' => 'IT-UBIC-'.uniqid(),
            'descripcion'  => 'PC ubic test',
            'fecha_alta'   => '2026-04-15',
            'valor_origen' => 800_000,
            'ubicacion'    => 'Oficina A',
        ], $this->user);

        app(AfBienService::class)->editar($bien, [
            'responsable_user_id' => $this->user->id,
            'ubicacion' => 'Oficina B',
        ], $this->user);

        $movs = AfMovimiento::where('bien_id', $bien->id)
            ->whereIn('tipo', ['CAMBIO_RESPONSABLE', 'CAMBIO_UBICACION'])
            ->get();
        $this->assertCount(2, $movs);
    }

    public function test_activar_desde_factura_marca_factura_y_persiste_ids(): void
    {
        if (DB::table('erp_facturas_compra')->count() === 0) {
            $this->markTestSkipped('Sin facturas de compra existentes');
        }

        // Tomamos cualquier factura disponible y la usamos como fixture.
        $factura = DB::table('erp_facturas_compra')->where('empresa_id', 1)
            ->where('af_activado', 0)
            ->whereNotIn('estado', ['ANULADA_POR_NC', 'RECHAZADA'])
            ->first();
        if (! $factura) {
            $this->markTestSkipped('Sin factura compra disponible');
        }

        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();

        $bienes = app(AfBienService::class)->activarDesdeFactura(
            (int) $factura->id,
            [[
                'categoria_id' => $cat->id,
                'nro_inventario' => 'IT-FAC-'.uniqid(),
                'descripcion' => 'Notebook desde factura',
                'valor_origen' => 800_000,
            ]],
            $this->user
        );

        $this->assertCount(1, $bienes);

        $facturaPost = DB::table('erp_facturas_compra')->where('id', $factura->id)->first();
        $this->assertEquals(1, (int) $facturaPost->af_activado);
        $idsJson = json_decode((string) $facturaPost->af_bienes_ids, true);
        $this->assertContains($bienes[0]->id, $idsJson);

        // Devolver la factura a su estado original para no afectar otros tests.
        DB::table('erp_facturas_compra')->where('id', $factura->id)->update([
            'af_activado' => 0, 'af_bienes_ids' => null,
        ]);
    }

    public function test_activar_factura_inexistente_falla(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/AF_FACTURA_NO_ENCONTRADA/');

        app(AfBienService::class)->activarDesdeFactura(999999999, [
            ['categoria_id' => 1, 'nro_inventario' => 'X', 'descripcion' => 'x', 'valor_origen' => 1000000],
        ], $this->user);
    }

    public function test_bien_relaciones_calculan_vida_util_y_base(): void
    {
        $cat = AfCategoria::where('codigo', 'INFORMATICA')->first();
        $bien = app(AfBienService::class)->alta([
            'empresa_id' => 1, 'categoria_id' => $cat->id,
            'nro_inventario' => 'IT-VU-'.uniqid(),
            'descripcion' => 'PC VU test', 'fecha_alta' => '2026-04-15',
            'valor_origen' => 1_080_000,  // residual 0% → base = 1.080.000
        ], $this->user);

        $this->assertEquals(36, $bien->vuContable());  // INFORMATICA = 36 meses
        $this->assertEquals(36, $bien->vuFiscal());
        $this->assertEqualsWithDelta(1_080_000.00, $bien->baseAmort(), 0.01);
    }
}
