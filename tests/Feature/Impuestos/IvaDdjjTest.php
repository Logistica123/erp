<?php

namespace Tests\Feature\Impuestos;

use App\Erp\Models\Impuestos\IvaDdjj;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Services\Impuestos\IvaDdjjCalculator;
use App\Erp\Services\Impuestos\IvaDdjjF2002Service;
use App\Erp\Services\Impuestos\PercepcionesSufridasService;
use App\Erp\Services\Impuestos\PeriodoFiscalService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests del calculador F.2002 + percepciones sufridas + arrastre RN-51.
 *
 * Reusa el mismo helper de fixtures de LibroIvaTest pero con setup propio
 * para no depender de orden de ejecución.
 */
class IvaDdjjTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;
    private int $clienteId;
    private int $proveedorId;
    private int $alicuota21Id;
    private int $tipoFAId;
    private int $puntoVentaId;
    private int $condIvaRiId;
    private int $tipoTribPercIvaId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('erp_iva_ddjj')) {
            $this->markTestSkipped('DDL_05 H1+H2 no aplicado');
        }

        $this->user = User::firstOrCreate(
            ['email' => 'test.h2@logistica.local'],
            ['name' => 'Test H2', 'password' => bcrypt('irrelevante')]
        );

        $this->clienteId = (int) DB::table('erp_auxiliares')->where('empresa_id', 1)->where('tipo', 'Cliente')->value('id');
        $this->proveedorId = (int) DB::table('erp_auxiliares')->where('empresa_id', 1)->whereIn('tipo', ['Proveedor', 'Distribuidor'])->value('id');
        if (! $this->clienteId || ! $this->proveedorId) {
            $this->markTestSkipped('Faltan auxiliares cliente/proveedor seedeados');
        }

        DB::table('erp_alicuotas_iva')->updateOrInsert(['id' => 5],
            ['codigo_interno' => 'IVA_21', 'nombre' => 'IVA 21%', 'tasa' => 0.21, 'activo' => 1]);
        $this->alicuota21Id = 5;

        DB::table('erp_tipos_comprobante')->updateOrInsert(['id' => 1],
            ['codigo_interno' => 'FA', 'nombre' => 'Factura A', 'letra' => 'A', 'clase' => 'FACTURA',
             'signo' => 1, 'es_fce' => 0, 'discrimina_iva' => 1, 'activo' => 1]);
        $this->tipoFAId = 1;

        DB::table('erp_puntos_venta')->updateOrInsert(
            ['empresa_id' => 1, 'numero' => 1],
            ['nombre' => 'PV-Test H2', 'tipo_emision' => 'CAE', 'activo' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        $this->puntoVentaId = (int) DB::table('erp_puntos_venta')->where('empresa_id', 1)->where('numero', 1)->value('id');

        $this->condIvaRiId = (int) DB::table('erp_condiciones_iva')->where('codigo_interno', 'RI')->value('id');
        if (! $this->condIvaRiId) {
            DB::table('erp_condiciones_iva')->insert(['id' => 1, 'codigo_interno' => 'RI', 'nombre' => 'Resp Inscripto',
                'letra_default' => 'A', 'acepta_fce' => 1, 'activo' => 1]);
            $this->condIvaRiId = 1;
        }

        $this->tipoTribPercIvaId = (int) DB::table('erp_tipos_tributo')->where('codigo_interno', 'PERC_IVA')->value('id');
        if (! $this->tipoTribPercIvaId) {
            $this->tipoTribPercIvaId = (int) DB::table('erp_tipos_tributo')->insertGetId([
                'codigo_afip' => 1, 'codigo_interno' => 'PERC_IVA', 'nombre' => 'Percepción IVA',
                'es_retencion' => 0, 'activo' => 1,
            ]);
        }
    }

    public function test_calcular_solo_debito_devuelve_a_pagar(): void
    {
        $p = $this->crearPeriodo(2098, 1);
        $this->crearFacturaVenta(98101, '2098-01-15', 1000.00);

        $ddjj = app(IvaDdjjCalculator::class)->calcular($p, $this->user);

        $this->assertEquals(210.00, (float) $ddjj->debito_fiscal);
        $this->assertEquals(0.00,   (float) $ddjj->credito_fiscal);
        $this->assertEquals(210.00, (float) $ddjj->saldo_tecnico);
        $this->assertEquals(210.00, (float) $ddjj->importe_a_pagar);
        $this->assertEquals(0.00,   (float) $ddjj->saldo_libre_disp_final);
    }

    public function test_calcular_credito_mayor_genera_saldo_a_favor(): void
    {
        $p = $this->crearPeriodo(2098, 2);
        $this->crearFacturaVenta(98201, '2098-02-15', 100.00);  // débito 21
        $this->crearFacturaCompra(98202, '2098-02-10', 1000.00); // crédito 210

        $ddjj = app(IvaDdjjCalculator::class)->calcular($p, $this->user);

        $this->assertEquals(21.00,  (float) $ddjj->debito_fiscal);
        $this->assertEquals(210.00, (float) $ddjj->credito_fiscal);
        $this->assertEquals(-189.00, (float) $ddjj->saldo_tecnico);
        $this->assertEquals(0.00,   (float) $ddjj->importe_a_pagar);
        $this->assertEquals(189.00, (float) $ddjj->saldo_libre_disp_final);
    }

    public function test_arrastre_saldo_libre_disp_anterior_RN51(): void
    {
        // Mes 1: genera saldo a favor 100. Lo cerramos PRESENTADO para activar
        // RN-51 (PeriodoFiscalService::saldoLibreDispAnterior lo busca).
        $p1 = $this->crearPeriodo(2097, 1);
        $this->crearFacturaCompra(97101, '2097-01-10', 1000.00); // crédito 210, saldo a favor 210

        $ddjj1 = app(IvaDdjjCalculator::class)->calcular($p1, $this->user);
        $this->assertEquals(210.00, (float) $ddjj1->saldo_libre_disp_final);

        $svc = app(PeriodoFiscalService::class);
        $p1 = $svc->transicionar($p1, 'EN_REVISION', $this->user);
        $p1 = $svc->transicionar($p1, 'APROBADO', $this->user);
        $p1 = $svc->transicionar($p1, 'PRESENTADO', $this->user, ['nro_tramite' => '0000-X']);

        // Mes 2: débito 100. Sin compras. Importe a pagar = max(0, 100 - 210) = 0.
        $p2 = $this->crearPeriodo(2097, 2);
        $this->crearFacturaVenta(97201, '2097-02-15', 1000.00 / 2.1); // ~476.19 → IVA ≈ 100

        $ddjj2 = app(IvaDdjjCalculator::class)->calcular($p2, $this->user);

        $this->assertEquals(210.00, (float) $ddjj2->saldo_libre_disp_anterior);
        $this->assertEquals(0.00,   (float) $ddjj2->importe_a_pagar);
        // Saldo final = 210 - 100 = 110 (queda como crédito)
        $this->assertEqualsWithDelta(110.00, (float) $ddjj2->saldo_libre_disp_final, 0.01);
    }

    public function test_percepciones_iva_se_imputan_como_pago_a_cuenta(): void
    {
        $p = $this->crearPeriodo(2096, 3);
        $this->crearFacturaVenta(96301, '2096-03-15', 1000.00); // débito 210
        $compraId = $this->crearFacturaCompra(96302, '2096-03-10', 100.00); // crédito 21
        // Percepción IVA de 50 sufrida en esa compra.
        DB::table('erp_factura_compra_tributos')->insert([
            'factura_id' => $compraId, 'tributo_id' => $this->tipoTribPercIvaId,
            'origen' => 'EN_FACTURA', 'base_imponible' => 1000, 'alicuota' => 0.05,
            'importe' => 50.00,
        ]);

        $ddjj = app(IvaDdjjCalculator::class)->calcular($p, $this->user);

        $this->assertEquals(50.00, (float) $ddjj->percepciones_sufridas);
        // saldo_tec = 210 - 21 = 189; a pagar = 189 - 50 = 139
        $this->assertEquals(139.00, (float) $ddjj->importe_a_pagar);
    }

    public function test_generar_f2002_persiste_archivo_y_hash(): void
    {
        Storage::fake('local');

        $p = $this->crearPeriodo(2095, 4);
        $this->crearFacturaVenta(95401, '2095-04-10', 1000.00);

        $resultado = app(IvaDdjjF2002Service::class)->generar($p, $this->user);

        $this->assertTrue(Storage::disk('local')->exists($resultado['path']));
        $this->assertEquals(64, strlen($resultado['hash']));
        $this->assertEquals(210.00, $resultado['importe_a_pagar']);

        $contenido = Storage::disk('local')->get($resultado['path']);
        $this->assertStringContainsString('PERIODO=209504', $contenido);
        $this->assertStringContainsString('IMPORTE_A_PAGAR=210.00', $contenido);
    }

    public function test_recalcular_es_idempotente(): void
    {
        $p = $this->crearPeriodo(2094, 5);
        $this->crearFacturaVenta(94501, '2094-05-10', 100.00);

        app(IvaDdjjCalculator::class)->calcular($p, $this->user);
        app(IvaDdjjCalculator::class)->calcular($p, $this->user);
        app(IvaDdjjCalculator::class)->calcular($p, $this->user);

        $this->assertEquals(1, IvaDdjj::where('periodo_id', $p->id)->count());
    }

    public function test_percepciones_service_idempotente(): void
    {
        $p = $this->crearPeriodo(2093, 6);
        $compraId = $this->crearFacturaCompra(93601, '2093-06-10', 100.00);
        DB::table('erp_factura_compra_tributos')->insert([
            'factura_id' => $compraId, 'tributo_id' => $this->tipoTribPercIvaId,
            'origen' => 'EN_FACTURA', 'base_imponible' => 100, 'alicuota' => 0.10,
            'importe' => 10.00,
        ]);

        $svc = app(PercepcionesSufridasService::class);
        $r1 = $svc->recalcular($p);
        $r2 = $svc->recalcular($p);

        $this->assertEquals($r1, $r2);
        $this->assertEquals(1, DB::table('erp_percepciones_sufridas')->where('periodo_id', $p->id)->count());
    }

    // ------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------

    private function crearPeriodo(int $anio, int $mes): PeriodoFiscal
    {
        return app(PeriodoFiscalService::class)->crear([
            'empresa_id' => $this->empresaId, 'impuesto' => 'IVA',
            'anio' => $anio, 'mes' => $mes,
        ], $this->user);
    }

    private function crearFacturaVenta(int $numero, string $fecha, float $neto): int
    {
        $iva = round($neto * 0.21, 2);
        $total = $neto + $iva;

        $facturaId = DB::table('erp_facturas_venta')->insertGetId([
            'empresa_id' => $this->empresaId, 'tipo_comprobante_id' => $this->tipoFAId,
            'punto_venta_id' => $this->puntoVentaId, 'numero' => $numero,
            'cae' => '12345678901234', 'fecha_vto_cae' => $fecha,
            'fecha_emision' => $fecha, 'auxiliar_id' => $this->clienteId,
            'condicion_iva_id' => $this->condIvaRiId, 'doc_tipo_afip' => 80,
            'doc_nro' => '30-12345678-9', 'moneda_id' => 1, 'cotizacion' => 1,
            'concepto_afip' => 2, 'imp_neto_gravado' => $neto, 'imp_iva' => $iva,
            'imp_total' => $total, 'origen' => 'MANUAL', 'estado' => 'EMITIDA',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_factura_venta_iva')->insert([
            'factura_id' => $facturaId, 'alicuota_iva_id' => $this->alicuota21Id,
            'base_imponible' => $neto, 'importe_iva' => $iva,
        ]);
        DB::table('erp_factura_venta_cae')->insert([
            'factura_venta_id' => $facturaId, 'cae' => '12345678901234',
            'fecha_vto_cae' => $fecha, 'resultado' => 'A',
            'idempotency_key' => "h2-fv-{$numero}", 'reintentos' => 0,
            'emitida_at' => now(), 'created_at' => now(),
        ]);

        return $facturaId;
    }

    private function crearFacturaCompra(int $numero, string $fecha, float $neto): int
    {
        $iva = round($neto * 0.21, 2);
        $total = $neto + $iva;

        $facturaId = DB::table('erp_facturas_compra')->insertGetId([
            'empresa_id' => $this->empresaId, 'tipo_comprobante_id' => $this->tipoFAId,
            'punto_venta' => 1, 'numero' => $numero,
            'fecha_emision' => $fecha, 'fecha_recepcion' => $fecha,
            // Explícita: el esquema prod tiene CHECK fecha_imputacion >= fecha_emision
            // y el default (hoy) viola el CHECK con fechas de fixture futuras.
            'fecha_imputacion' => $fecha,
            'auxiliar_id' => $this->proveedorId,
            'cuit_emisor' => '30123456789', 'razon_social_emisor' => 'Proveedor SA',
            'condicion_iva_id' => $this->condIvaRiId, 'moneda_id' => 1, 'cotizacion' => 1,
            'imp_neto_gravado' => $neto, 'imp_iva' => $iva, 'imp_total' => $total,
            'origen' => 'MANUAL', 'estado' => 'CONTROLADA', 'constatacion_estado' => 'PENDIENTE',
            'created_by_user_id' => $this->user->id,
        ]);
        DB::table('erp_factura_compra_iva')->insert([
            'factura_id' => $facturaId, 'alicuota_iva_id' => $this->alicuota21Id,
            'base_imponible' => $neto, 'importe_iva' => $iva,
        ]);

        return $facturaId;
    }
}
