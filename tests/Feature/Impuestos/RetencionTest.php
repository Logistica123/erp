<?php

namespace Tests\Feature\Impuestos;

use App\Erp\Models\Impuestos\RetencionPracticada;
use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Services\Impuestos\RetencionCalculator;
use App\Erp\Services\Impuestos\RetencionService;
use App\Erp\Services\Impuestos\SireGeneratorService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests del RetencionCalculator + RetencionService (RN-48 numeración +
 * RN-49 oficio + RN-50 mínimo) y SIRE.
 */
class RetencionTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;
    private int $proveedorId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('erp_retenciones_practicadas') || ! Schema::hasTable('erp_regimenes_retencion')) {
            $this->markTestSkipped('DDL_05 H1 + seed H3 no aplicado');
        }

        $this->user = User::firstOrCreate(
            ['email' => 'test.h3@logistica.local'],
            ['name' => 'Test H3', 'password' => bcrypt('irrelevante')]
        );

        $proveedor = DB::table('erp_auxiliares')->where('empresa_id', 1)
            ->whereIn('tipo', ['Proveedor', 'Distribuidor'])->first();
        if (! $proveedor) {
            $this->markTestSkipped('Sin proveedor seedeado');
        }
        // Asegurar CUIT del proveedor (lo necesita la retención).
        if (! $proveedor->cuit) {
            DB::table('erp_auxiliares')->where('id', $proveedor->id)->update(['cuit' => '30123456786']);
        }
        $this->proveedorId = (int) $proveedor->id;
    }

    // ----- Calculator -----

    public function test_calculator_RI_servicios_aplica_iva_y_ganancias(): void
    {
        $props = app(RetencionCalculator::class)->proponer([
            'monto_pago' => 1_000_000,
            'condicion_iva' => 'RI',
            'naturaleza' => 'SERVICIOS',
        ]);

        $tipos = array_column($props, 'tipo');
        $this->assertContains('IVA', $tipos);
        $this->assertContains('GAN', $tipos);

        $iva = collect($props)->firstWhere('tipo', 'IVA');
        $this->assertEquals('002', $iva['regimen']);
        $this->assertEquals(0.21, $iva['alicuota']);
        $this->assertEquals(210_000.00, $iva['importe']);

        $gan = collect($props)->firstWhere('tipo', 'GAN');
        $this->assertEquals('116', $gan['regimen']);
        $this->assertEquals(20_000.00, $gan['importe']);
    }

    public function test_calculator_MT_no_retiene_iva(): void
    {
        $props = app(RetencionCalculator::class)->proponer([
            'monto_pago' => 1_000_000, 'condicion_iva' => 'MT', 'naturaleza' => 'SERVICIOS',
        ]);
        $tipos = array_column($props, 'tipo');
        $this->assertNotContains('IVA', $tipos);
        $this->assertContains('GAN', $tipos);
    }

    public function test_calculator_transporte_usa_regimen_118(): void
    {
        $props = app(RetencionCalculator::class)->proponer([
            'monto_pago' => 1_000_000, 'condicion_iva' => 'RI', 'naturaleza' => 'TRANSPORTE',
        ]);
        $gan = collect($props)->firstWhere('tipo', 'GAN');
        $this->assertEquals('118', $gan['regimen']);
        $this->assertEquals(6_000.00, $gan['importe']);  // 1M * 0.6%
    }

    public function test_calculator_RN50_minimo_no_retiene(): void
    {
        $props = app(RetencionCalculator::class)->proponer([
            'monto_pago' => 100_000,  // < 400k mínimo IVA
            'condicion_iva' => 'RI', 'naturaleza' => 'SERVICIOS',
        ]);
        $iva = collect($props)->firstWhere('tipo', 'IVA');
        $this->assertEquals(0.0, $iva['importe']);
        $this->assertStringContainsString('mínimo', $iva['motivo_no_aplica']);
    }

    public function test_calculator_iibb_caba(): void
    {
        $props = app(RetencionCalculator::class)->proponer([
            'monto_pago' => 1_000_000, 'condicion_iva' => 'RI', 'jurisdiccion' => 'CABA',
        ]);
        $iibb = collect($props)->firstWhere('tipo', 'IIBB');
        $this->assertEquals('78', $iibb['regimen']);
        $this->assertEquals(20_000.00, $iibb['importe']);  // 2%
    }

    // ----- Service (numeración + persistencia) -----

    public function test_service_persiste_certificados_secuenciales(): void
    {
        $op = $this->crearOpBorrador(1_000_000, '2095-04-15');

        $r = app(RetencionService::class)->aplicar($op, $this->user, [
            'condicion_iva' => 'RI', 'naturaleza' => 'SERVICIOS',
        ]);

        $this->assertCount(2, $r['retenciones']);  // IVA + GAN
        $cert1 = $r['retenciones'][0]->nro_certificado;
        $this->assertMatchesRegularExpression('/^2095-\d{7}$/', $cert1);

        // Segunda OP debe usar número siguiente para cada tipo.
        $op2 = $this->crearOpBorrador(2_000_000, '2095-04-20');
        $r2 = app(RetencionService::class)->aplicar($op2, $this->user, [
            'condicion_iva' => 'RI', 'naturaleza' => 'SERVICIOS',
        ]);

        $cert2_iva = collect($r2['retenciones'])->firstWhere('tipo_retencion', 'IVA')->nro_certificado;
        $cert1_iva = collect($r['retenciones'])->firstWhere('tipo_retencion', 'IVA')->nro_certificado;
        $num1 = (int) substr($cert1_iva, 5);
        $num2 = (int) substr($cert2_iva, 5);
        $this->assertEquals($num1 + 1, $num2);
    }

    public function test_service_anula_pero_no_reusa_numero(): void
    {
        $op = $this->crearOpBorrador(1_000_000, '2094-05-15');
        $r = app(RetencionService::class)->aplicar($op, $this->user, [
            'condicion_iva' => 'RI', 'naturaleza' => 'SERVICIOS',
        ]);
        $cert = collect($r['retenciones'])->firstWhere('tipo_retencion', 'IVA');

        app(RetencionService::class)->anular($cert->fresh(), $this->user, 'OP rechazada');

        // El próximo número de IVA debe ser el siguiente, no el del anulado.
        $op2 = $this->crearOpBorrador(1_500_000, '2094-05-20');
        $r2 = app(RetencionService::class)->aplicar($op2, $this->user, [
            'condicion_iva' => 'RI', 'naturaleza' => 'SERVICIOS',
        ]);

        $cert2 = collect($r2['retenciones'])->firstWhere('tipo_retencion', 'IVA');
        $this->assertNotEquals($cert->nro_certificado, $cert2->nro_certificado);
        // El anulado sigue existiendo con su nro original.
        $this->assertEquals('ANULADO', $cert->fresh()->estado);
    }

    public function test_service_actualiza_total_retenciones_e_importe_neto(): void
    {
        $op = $this->crearOpBorrador(1_000_000, '2093-06-15');
        app(RetencionService::class)->aplicar($op, $this->user, [
            'condicion_iva' => 'RI', 'naturaleza' => 'SERVICIOS',
        ]);

        $op = $op->fresh();
        $this->assertEquals(230_000.00, (float) $op->total_retenciones);  // 210k IVA + 20k GAN
        $this->assertEquals(770_000.00, (float) $op->importe);
        $this->assertEquals(1_000_000.00, (float) $op->importe_bruto);
    }

    public function test_service_rechaza_op_no_borrador(): void
    {
        $op = $this->crearOpBorrador(1_000_000, '2092-07-15');
        $op->update(['estado' => OrdenPago::ESTADO_CARGADA_BANCO]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/RETENCION_OP_INMUTABLE/');
        app(RetencionService::class)->aplicar($op, $this->user, [
            'condicion_iva' => 'RI', 'naturaleza' => 'SERVICIOS',
        ]);
    }

    // ----- SIRE -----

    public function test_sire_genera_archivos_por_tipo(): void
    {
        Storage::fake('local');

        $op = $this->crearOpBorrador(1_000_000, '2091-08-15');
        $r = app(RetencionService::class)->aplicar($op, $this->user, [
            'condicion_iva' => 'RI', 'naturaleza' => 'SERVICIOS',
        ]);
        $periodo = $r['retenciones'][0]->periodo;

        $resultados = app(SireGeneratorService::class)->generar($periodo, $this->user);

        $this->assertArrayHasKey('IVA', $resultados);
        $this->assertArrayHasKey('GAN', $resultados);
        $this->assertEquals(1, $resultados['IVA']['filas']);
        $this->assertTrue(Storage::disk('local')->exists($resultados['IVA']['path']));

        $contenido = Storage::disk('local')->get($resultados['IVA']['path']);
        $this->assertStringContainsString('|002|1000000.00|', $contenido);
    }

    private function crearOpBorrador(float $importe, string $fecha): OrdenPago
    {
        $op = OrdenPago::create([
            'empresa_id' => $this->empresaId,
            'numero' => 'OP-TEST-'.uniqid(),
            'fecha' => $fecha,
            'tipo' => 'PROVEEDOR',
            'auxiliar_id' => $this->proveedorId,
            'moneda_id' => 1,
            'cotizacion' => 1,
            'importe' => $importe,
            'importe_bruto' => $importe,
            'total_retenciones' => 0,
            'estado' => OrdenPago::ESTADO_BORRADOR,
            'creado_por_user_id' => $this->user->id,
        ]);

        return $op;
    }
}
