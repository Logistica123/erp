<?php

namespace Tests\Feature\Tesoreria;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\ConciliacionService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v1.55 Bloque F — redondeo automático contra 5.6.06 en conciliación
 * múltiple: diferencias $0.02–$1.00 sin toggle balancean solas; >$1 sin
 * toggle rechaza; con toggle sigue el circuito manual (cuenta + motivo).
 */
class ConciliacionMultipleRedondeoTest extends TestCase
{
    use DatabaseTransactions;

    private ConciliacionService $service;
    private User $user;
    private int $empresaId = 1;
    private CuentaBancaria $cuentaBancaria;
    private int $proveedorId;
    private int $condicionIvaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ConciliacionService::class);
        $this->user = User::firstOrCreate(
            ['email' => 'test.redondeo@logistica.local'],
            ['name' => 'Test redondeo', 'password' => bcrypt('irrelevante')]
        );
        $this->cuentaBancaria = CuentaBancaria::where('empresa_id', $this->empresaId)->firstOrFail();
        $this->proveedorId = (int) DB::table('erp_auxiliares')->where('empresa_id', $this->empresaId)
            ->where('tipo', 'Proveedor')->value('id');

        // En dev los catálogos pueden estar sin seedar (ids manuales).
        $existente = DB::table('erp_condiciones_iva')->value('id');
        if (! $existente) {
            DB::table('erp_condiciones_iva')->insert([
                'id' => 1, 'codigo_interno' => 'RI', 'nombre' => 'Responsable Inscripto',
                'letra_default' => 'A', 'acepta_fce' => 1, 'activo' => 1,
            ]);
            $existente = 1;
        }
        $this->condicionIvaId = (int) $existente;

        if (! DB::table('erp_tipos_comprobante')->where('id', 1)->exists()) {
            DB::table('erp_tipos_comprobante')->insert([
                'id' => 1, 'codigo_interno' => 'FA', 'nombre' => 'Factura A', 'letra' => 'A',
                'clase' => 'FACTURA', 'signo' => 1, 'es_fce' => 0, 'discrimina_iva' => 1, 'activo' => 1,
            ]);
        }

        // Alinear flags con prod: 5.6.05/5.6.06 no llevan CC ni auxiliar.
        // (En dev 5.6.05 quedó con admite_cc=1 y 5.6.06 no existe. El update
        // se revierte con la transacción del test.)
        DB::table('erp_cuentas_contables')->where('empresa_id', $this->empresaId)
            ->where('codigo', '5.6.05')->update(['admite_cc' => 0, 'admite_auxiliar' => 0]);
        if (! DB::table('erp_cuentas_contables')->where('empresa_id', $this->empresaId)->where('codigo', '5.6.06')->exists()) {
            $ref = (array) DB::table('erp_cuentas_contables')->where('empresa_id', $this->empresaId)
                ->where('codigo', '5.6.05')->first();
            unset($ref['id']);
            $ref['codigo'] = '5.6.06';
            $ref['nombre'] = 'Redondeos';
            $ref['etiqueta_cierre'] = 'REDONDEOS-TEST'; // uk_cuenta_etiqueta
            DB::table('erp_cuentas_contables')->insert($ref);
        }

        if (! DB::table('erp_centros_costo')->where('empresa_id', $this->empresaId)->where('codigo', 'GENERAL')->exists()) {
            DB::table('erp_centros_costo')->insert([
                'empresa_id' => $this->empresaId, 'codigo' => 'GENERAL',
                'nombre' => 'General', 'tipo' => 'OTRO', 'activo' => 1,
            ]);
        }

        if (! $this->proveedorId) {
            $this->proveedorId = (int) DB::table('erp_auxiliares')->insertGetId([
                'empresa_id' => $this->empresaId, 'tipo' => 'Proveedor',
                'codigo' => 'PROV-TEST-REDONDEO', 'nombre' => 'Proveedor Test Redondeo SA',
                'cuit' => '30123456789', 'activo' => 1,
            ]);
        }
    }

    public function test_cp55_f1_diferencia_chica_sin_toggle_genera_linea_auto_5_6_06(): void
    {
        $factura = $this->crearFacturaCompra(1000.00);
        $mov = $this->crearMovBancario(debito: 1000.50);

        $mov = $this->service->conciliarMultiplesFacturas(
            $mov,
            [['id' => $factura->id, 'tipo' => 'COMPRA', 'monto_imputado' => 1000.00]],
            $this->user,
        );

        $this->assertSame(MovimientoBancario::ESTADO_CONCILIADO, $mov->estado);
        $this->assertNotNull($mov->asiento_id);

        $lineaRedondeo = DB::table('erp_movimientos_asiento as ma')
            ->join('erp_cuentas_contables as cc', 'cc.id', '=', 'ma.cuenta_id')
            ->where('ma.asiento_id', $mov->asiento_id)
            ->where('cc.codigo', '5.6.06')
            ->first();

        $this->assertNotNull($lineaRedondeo, 'Debe existir línea automática contra 5.6.06 Redondeos');
        $this->assertEqualsWithDelta(0.50, (float) $lineaRedondeo->debe, 0.001);
        $this->assertStringContainsString('Redondeo automático', $lineaRedondeo->glosa);
    }

    public function test_cp55_f2_diferencia_mayor_a_1_sin_toggle_rechaza(): void
    {
        $factura = $this->crearFacturaCompra(1000.00);
        $mov = $this->crearMovBancario(debito: 1002.00);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/DIFERENCIA_MAYOR_A_TOLERANCIA_AUTOMATICA/');

        $this->service->conciliarMultiplesFacturas(
            $mov,
            [['id' => $factura->id, 'tipo' => 'COMPRA', 'monto_imputado' => 1000.00]],
            $this->user,
        );
    }

    public function test_cp55_f3_diferencia_mayor_a_1_con_toggle_usa_cuenta_manual(): void
    {
        $factura = $this->crearFacturaCompra(1000.00);
        $mov = $this->crearMovBancario(debito: 1002.00);
        $cuentaDif = (int) DB::table('erp_cuentas_contables')->where('empresa_id', $this->empresaId)
            ->where('codigo', '5.6.05')->value('id');

        $mov = $this->service->conciliarMultiplesFacturas(
            $mov,
            [['id' => $factura->id, 'tipo' => 'COMPRA', 'monto_imputado' => 1000.00]],
            $this->user,
            'Diferencia de conciliación de prueba',
            true,
            $cuentaDif,
        );

        $lineas = DB::table('erp_movimientos_asiento as ma')
            ->join('erp_cuentas_contables as cc', 'cc.id', '=', 'ma.cuenta_id')
            ->where('ma.asiento_id', $mov->asiento_id)
            ->pluck('cc.codigo');

        $this->assertContains('5.6.05', $lineas, 'La línea de ajuste debe usar la cuenta elegida manualmente');
        $this->assertNotContains('5.6.06', $lineas, 'No debe haber línea auto de redondeo si el toggle está activo');
    }

    private function crearFacturaCompra(float $total): FacturaCompra
    {
        return FacturaCompra::create([
            'empresa_id' => $this->empresaId,
            'tipo_comprobante_id' => 1,
            'punto_venta' => 1,
            'numero' => random_int(100000, 999999),
            'fecha_emision' => now()->toDateString(),
            'fecha_recepcion' => now()->toDateString(),
            'fecha_imputacion' => now()->toDateString(),
            'auxiliar_id' => $this->proveedorId,
            'cuit_emisor' => '30123456789',
            'razon_social_emisor' => 'Proveedor Test Redondeo SA',
            'condicion_iva_id' => $this->condicionIvaId,
            'moneda_id' => 1,
            'cotizacion' => 1,
            'imp_neto_gravado' => round($total / 1.21, 2),
            'imp_iva' => round($total - $total / 1.21, 2),
            'imp_total' => $total,
            'origen' => 'MANUAL',
            'estado' => 'RECIBIDA',
            'constatacion_estado' => 'PENDIENTE',
            'created_by_user_id' => $this->user->id,
        ]);
    }

    private function crearMovBancario(float $debito = 0, float $credito = 0): MovimientoBancario
    {
        $extracto = ExtractoBancario::create([
            'cuenta_bancaria_id' => $this->cuentaBancaria->id,
            'fecha_desde' => now()->toDateString(),
            'fecha_hasta' => now()->toDateString(),
            'hash_archivo' => hash('sha256', 'redondeo-test-'.uniqid()),
            'nombre_archivo' => 'redondeo-test.csv',
            'cant_movimientos' => 1,
            'importado_por_user_id' => $this->user->id,
            'importado_at' => now(),
        ]);

        return MovimientoBancario::create([
            'extracto_id' => $extracto->id,
            'cuenta_bancaria_id' => $this->cuentaBancaria->id,
            'fecha' => now()->toDateString(),
            'concepto' => 'TEST REDONDEO',
            'debito' => $debito,
            'credito' => $credito,
            'estado' => MovimientoBancario::ESTADO_PENDIENTE,
            'hash_linea' => hash('sha256', 'test-'.uniqid()),
        ]);
    }
}
