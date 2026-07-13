<?php

namespace Tests\Feature\VentasCompras;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Services\CobroService;
use App\Erp\Services\OrdenPagoService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests del gate RN-31: una factura NO CONTROLADA no puede vincularse a
 * un cobro (venta) ni a una orden de pago (compra).
 */
class Rn31GateTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;
    private int $clienteId;
    private int $proveedorId;
    private int $medioTransf;
    private int $cuentaBancariaId;

    protected function setUp(): void
    {
        parent::setUp();

        // Requiere DDL_04 aplicado con todos los catálogos seed (tipos comprobante,
        // puntos venta, condiciones IVA). En dev minimal no corre; en prod sí.
        if (! Schema::hasTable('erp_facturas_venta') || DB::table('erp_tipos_comprobante')->count() === 0) {
            $this->markTestSkipped('DDL_04 + seeds no presentes en este entorno');
        }

        $this->user = User::firstOrCreate(
            ['email' => 'test.rn31@logistica.local'],
            ['name' => 'Test RN-31', 'password' => bcrypt('irrelevante')]
        );
        $this->clienteId = (int) DB::table('erp_auxiliares')->where('empresa_id', 1)->where('tipo', 'Cliente')->value('id');
        $this->proveedorId = (int) DB::table('erp_auxiliares')->where('empresa_id', 1)->whereIn('tipo', ['Proveedor', 'Distribuidor'])->value('id');
        $this->medioTransf = (int) DB::table('erp_medios_pago')->where('codigo', 'TRANSFERENCIA')->value('id');
        $this->cuentaBancariaId = (int) DB::table('erp_cuentas_bancarias')->where('empresa_id', 1)->value('id');
    }

    public function test_cobro_rechaza_factura_venta_no_CONTROLADA(): void
    {
        // Crear factura en estado PREPARADA (no controlada)
        $facturaId = DB::table('erp_facturas_venta')->insertGetId([
            'empresa_id' => 1,
            'tipo_comprobante_id' => 6,
            'punto_venta_id' => DB::table('erp_puntos_venta')->where('empresa_id', 1)->value('id'),
            'numero' => 99999,
            'fecha_emision' => now()->toDateString(),
            'auxiliar_id' => $this->clienteId,
            'condicion_iva_id' => DB::table('erp_condiciones_iva')->value('id'),
            'moneda_id' => 1,
            'cotizacion' => 1,
            'concepto_afip' => 2,
            'imp_neto_gravado' => 100,
            'imp_total' => 121,
            'imp_iva' => 21,
            'origen' => 'MANUAL',
            'estado' => 'PREPARADA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/RN-31/');

        app(CobroService::class)->registrar([
            'empresa_id' => 1,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'auxiliar_id' => $this->clienteId,
            'moneda_id' => 1,
            'items' => [
                ['tipo_item' => 'FACTURA_VENTA', 'factura_id' => $facturaId, 'concepto' => 'FV 99999', 'importe' => 121],
            ],
            'medios' => [
                ['medio_pago_id' => $this->medioTransf, 'cuenta_bancaria_id' => $this->cuentaBancariaId, 'importe' => 121],
            ],
        ]);
    }

    public function test_op_rechaza_factura_compra_no_CONTROLADA(): void
    {
        $factura = FacturaCompra::create([
            'empresa_id' => 1,
            'tipo_comprobante_id' => 1,
            'punto_venta' => 1,
            'numero' => 88888,
            'fecha_emision' => now()->toDateString(),
            // NOT NULL sin default en esquema prod (sql_mode estricto).
            'fecha_recepcion' => now()->toDateString(),
            'auxiliar_id' => $this->proveedorId,
            'cuit_emisor' => '30123456789',
            'razon_social_emisor' => 'Proveedor RN31 SA',
            'condicion_iva_id' => DB::table('erp_condiciones_iva')->value('id'),
            'moneda_id' => 1,
            'cotizacion' => 1,
            'imp_neto_gravado' => 500,
            'imp_iva' => 105,
            'imp_total' => 605,
            'origen' => 'MANUAL',
            'estado' => 'RECIBIDA',
            // 'PENDIENTE' es el único valor del enum válido acá: el sql_mode
            // estricto del esquema prod rechaza valores fuera del enum.
            'constatacion_estado' => 'PENDIENTE',
            'created_by_user_id' => $this->user->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/RN-31/');

        app(OrdenPagoService::class)->crear([
            'empresa_id' => 1,
            'usuario_id' => $this->user->id,
            'fecha' => now()->toDateString(),
            'auxiliar_id' => $this->proveedorId,
            'moneda_id' => 1,
            'cotizacion' => 1,
            'importe' => 605,
            'items' => [
                ['tipo_item' => 'FACTURA_COMPRA', 'comprobante_id' => $factura->id, 'concepto' => 'Fact compra 88888', 'importe' => 605],
            ],
        ]);
    }
}
