<?php

namespace Tests\Feature\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Services\Impuestos\LibroIvaF8001Service;
use App\Erp\Services\Impuestos\LibroIvaService;
use App\Erp\Services\Impuestos\LibroIvaValidador;
use App\Erp\Services\Impuestos\PeriodoFiscalService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests del LibroIvaService + LibroIvaValidador (RN-46) + F8001Service.
 *
 * Crea datos in-test (fixture mínimo: alícuotas, tipo comprobante, pto venta,
 * factura). Si el catálogo base no está seedeado en este entorno, se omiten.
 */
class LibroIvaTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;
    private int $clienteId;
    private int $alicuota21Id;
    private int $tipoFAId;
    private int $puntoVentaId;
    private int $condIvaRiId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('erp_facturas_venta') || ! Schema::hasTable('erp_libro_iva_ventas_periodo')) {
            $this->markTestSkipped('DDL_04 + DDL_05 H1 no aplicado');
        }

        $this->user = User::firstOrCreate(
            ['email' => 'test.libro-iva@logistica.local'],
            ['name' => 'Test Libro IVA', 'password' => bcrypt('irrelevante')]
        );

        $this->clienteId = (int) DB::table('erp_auxiliares')
            ->where('empresa_id', 1)->where('tipo', 'Cliente')->value('id');

        if (! $this->clienteId) {
            $this->markTestSkipped('Sin auxiliares cliente seedeados');
        }

        // Sembrar alícuota 21% si no existe.
        DB::table('erp_alicuotas_iva')->updateOrInsert(
            ['id' => 5],
            ['codigo_interno' => 'IVA_21', 'nombre' => 'IVA 21%', 'tasa' => 0.21, 'activo' => 1]
        );
        $this->alicuota21Id = 5;

        // Sembrar tipo comprobante FA si no existe.
        DB::table('erp_tipos_comprobante')->updateOrInsert(
            ['id' => 1],
            ['codigo_interno' => 'FA', 'nombre' => 'Factura A', 'letra' => 'A', 'clase' => 'FACTURA', 'signo' => 1, 'es_fce' => 0, 'discrimina_iva' => 1, 'activo' => 1]
        );
        $this->tipoFAId = 1;

        // Sembrar punto de venta 1 si no existe.
        DB::table('erp_puntos_venta')->updateOrInsert(
            ['empresa_id' => 1, 'numero' => 1],
            ['nombre' => 'PV-Test', 'tipo_emision' => 'CAE', 'activo' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        $this->puntoVentaId = (int) DB::table('erp_puntos_venta')
            ->where('empresa_id', 1)->where('numero', 1)->value('id');

        $this->condIvaRiId = (int) DB::table('erp_condiciones_iva')->where('codigo_interno', 'RI')->value('id');
        if (! $this->condIvaRiId) {
            // erp_condiciones_iva tiene id manual (no autoincrement). Insertamos con id=1.
            DB::table('erp_condiciones_iva')->insert([
                'id' => 1, 'codigo_interno' => 'RI', 'nombre' => 'Resp Inscripto',
                'letra_default' => 'A', 'acepta_fce' => 1, 'activo' => 1,
            ]);
            $this->condIvaRiId = 1;
        }
    }

    public function test_validador_detecta_factura_sin_cae(): void
    {
        $periodo = $this->crearPeriodo(2099, 7);
        $this->crearFactura(99001, '2099-07-15', 100, 21, conCae: false);

        $reporte = app(LibroIvaValidador::class)->validarCierrePeriodo($periodo);

        $this->assertFalse($reporte['ok']);
        $this->assertSame(1, $reporte['bloqueantes']);
        $this->assertEquals('RN46_FACTURA_SIN_CAE', $reporte['anomalias'][0]['codigo']);
    }

    public function test_validador_pasa_con_factura_con_cae(): void
    {
        $periodo = $this->crearPeriodo(2099, 8);
        $this->crearFactura(99002, '2099-08-15', 100, 21, conCae: true);

        $reporte = app(LibroIvaValidador::class)->validarCierrePeriodo($periodo);

        $this->assertTrue($reporte['ok']);
        $this->assertSame(0, $reporte['bloqueantes']);
    }

    public function test_armar_libro_iva_ventas_agrega_totales(): void
    {
        $periodo = $this->crearPeriodo(2099, 9);
        $this->crearFactura(99003, '2099-09-10', 100, 21, conCae: true);
        $this->crearFactura(99004, '2099-09-20', 200, 21, conCae: true);

        $resultado = app(LibroIvaService::class)->armar($periodo, $this->user);

        $this->assertSame(2, $resultado['ventas']->cantidad_comprobantes);
        $this->assertEquals(300.00, (float) $resultado['ventas']->neto_gravado_21);
        $this->assertEquals(63.00,  (float) $resultado['ventas']->iva_21);
        $this->assertEquals(363.00, (float) $resultado['ventas']->total_facturado);
    }

    public function test_generar_f8001_persiste_archivo_y_hash(): void
    {
        Storage::fake('local');

        $periodo = $this->crearPeriodo(2099, 10);
        $this->crearFactura(99005, '2099-10-05', 100, 21, conCae: true);

        $paths = app(LibroIvaF8001Service::class)->generar($periodo, $this->user);

        $this->assertTrue(Storage::disk('local')->exists($paths['ventas_path']));
        $this->assertEquals(64, strlen($paths['ventas_hash'])); // SHA-256 hex

        $cabecera = $periodo->fresh()->libroIvaVentas;
        $this->assertEquals($paths['ventas_hash'], $cabecera->archivo_f8001_hash);
        $this->assertNotNull($cabecera->generado_at);
    }

    public function test_generar_f8001_falla_si_periodo_aprobado(): void
    {
        $periodo = $this->crearPeriodo(2099, 11);
        $this->crearFactura(99006, '2099-11-05', 100, 21, conCae: true);
        $svc = app(PeriodoFiscalService::class);
        $periodo = $svc->transicionar($periodo, 'EN_REVISION', $this->user);
        $periodo = $svc->transicionar($periodo, 'APROBADO', $this->user);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/F8001_PERIODO_NO_EDITABLE/');
        app(LibroIvaF8001Service::class)->generar($periodo, $this->user);
    }

    // ------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------

    private function crearPeriodo(int $anio, int $mes): PeriodoFiscal
    {
        return app(PeriodoFiscalService::class)->crear([
            'empresa_id' => $this->empresaId,
            'impuesto'   => 'IVA',
            'anio'       => $anio,
            'mes'        => $mes,
        ], $this->user);
    }

    private function crearFactura(int $numero, string $fecha, float $neto, float $tasaPct, bool $conCae): int
    {
        $tasa = $tasaPct / 100;
        $iva = round($neto * $tasa, 2);
        $total = $neto + $iva;

        $facturaId = DB::table('erp_facturas_venta')->insertGetId([
            'empresa_id'         => $this->empresaId,
            'tipo_comprobante_id'=> $this->tipoFAId,
            'punto_venta_id'     => $this->puntoVentaId,
            'numero'             => $numero,
            'cae'                => $conCae ? '12345678901234' : null,
            'fecha_vto_cae'      => $conCae ? $fecha : null,
            'fecha_emision'      => $fecha,
            'auxiliar_id'        => $this->clienteId,
            'condicion_iva_id'   => $this->condIvaRiId,
            'doc_tipo_afip'      => 80,
            'doc_nro'            => '30-12345678-9',
            'moneda_id'          => 1,
            'cotizacion'         => 1,
            'concepto_afip'      => 2,
            'imp_neto_gravado'   => $neto,
            'imp_iva'            => $iva,
            'imp_total'          => $total,
            'origen'             => 'MANUAL',
            'estado'             => 'EMITIDA',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        DB::table('erp_factura_venta_iva')->insert([
            'factura_id'      => $facturaId,
            'alicuota_iva_id' => $this->alicuota21Id,
            'base_imponible'  => $neto,
            'importe_iva'     => $iva,
        ]);

        if ($conCae) {
            DB::table('erp_factura_venta_cae')->insert([
                'factura_venta_id' => $facturaId,
                'cae'              => '12345678901234',
                'fecha_vto_cae'    => $fecha,
                'resultado'        => 'A',
                'idempotency_key'  => "test-{$numero}",
                'reintentos'       => 0,
                'emitida_at'       => now(),
                'created_at'       => now(),
            ]);
        }

        return $facturaId;
    }
}
