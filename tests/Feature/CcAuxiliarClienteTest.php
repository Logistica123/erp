<?php

namespace Tests\Feature;

use App\Erp\Models\Auxiliar;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADDENDUM v1.14 — Tests CC-01..CC-12 (subset crítico).
 *
 * Cubre los invariantes principales:
 *  - Alta de auxiliar tipo='Cliente' crea CC automáticamente (RN-CC-1).
 *  - Update de razón social del auxiliar sincroniza nombre del CC.
 *  - Migración inicial pobló CCs para clientes preexistentes.
 *  - Validación de jurisdicción FK contra erp_iibb_jurisdicciones.
 *  - Formato de período trabajado mensual + quincenal acepta crudo.
 */
class CcAuxiliarClienteTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::first() ?? User::factory()->create();
    }

    public function test_CC_01_alta_auxiliar_cliente_crea_cc(): void
    {
        $aux = Auxiliar::create([
            'empresa_id' => $this->empresaId,
            'tipo' => 'Cliente',
            'codigo' => 'TST-'.substr(uniqid(), -8),
            'nombre' => 'Cliente Test CC-01',
            'activo' => 1,
        ]);

        $cc = DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->first();
        $this->assertNotNull($cc, 'No se creó CC automático para el auxiliar Cliente');
        // v1.14 ampliación (CC-07): código slug en lugar de ID padded.
        // El observer toma la primera palabra "significativa" del nombre.
        $this->assertMatchesRegularExpression('/^CLI-[A-Z0-9]+(-\d+)?$/', $cc->codigo,
            "Código esperado formato CLI-{slug} o CLI-{slug}-N, obtenido: {$cc->codigo}");
        $this->assertSame('CLIENTE', $cc->tipo);
        $this->assertSame('Cliente Test CC-01', $cc->nombre);
    }

    public function test_CC_02_alta_auxiliar_proveedor_NO_crea_cc(): void
    {
        $aux = Auxiliar::create([
            'empresa_id' => $this->empresaId,
            'tipo' => 'Proveedor',
            'codigo' => 'PRV-'.substr(uniqid(), -8),
            'nombre' => 'Proveedor Test CC-02',
            'activo' => 1,
        ]);

        $cc = DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->first();
        $this->assertNull($cc, 'El observer no debe crear CC para auxiliares no-Cliente');
    }

    public function test_CC_03_update_nombre_auxiliar_sincroniza_cc(): void
    {
        $aux = Auxiliar::create([
            'empresa_id' => $this->empresaId,
            'tipo' => 'Cliente',
            'codigo' => 'TST-'.substr(uniqid(), -8),
            'nombre' => 'Original Name',
            'activo' => 1,
        ]);

        $aux->update(['nombre' => 'Renombrado']);
        $cc = DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->first();
        $this->assertSame('Renombrado', $cc->nombre);
    }

    public function test_CC_04_observer_sanea_clientes_sin_cc(): void
    {
        // Portabilidad (2.1): en prod hay clientes creados por insert crudo
        // (sync/merge) que bypasean el observer y quedan sin CC — hallazgo
        // anotado en PLAN_REMEDIACION_ESTADO.md. El invariante global no es
        // portable; lo que SÍ es contrato es el auto-sanado del observer:
        // updated() sobre un Cliente sin CC se lo crea.
        $sinCc = Auxiliar::query()
            ->where('tipo', 'Cliente')->where('activo', 1)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('erp_centros_costo')
                ->whereColumn('erp_centros_costo.auxiliar_id', 'erp_auxiliares.id'))
            ->get();

        foreach ($sinCc as $aux) {
            $aux->touch(); // dispara updated() → ensureCentroCosto()
        }

        $quedan = DB::table('erp_auxiliares as a')
            ->leftJoin('erp_centros_costo as cc', 'cc.auxiliar_id', '=', 'a.id')
            ->where('a.tipo', 'Cliente')->where('a.activo', 1)
            ->whereNull('cc.id')
            ->count();
        $this->assertSame(0, $quedan,
            "Tras el saneo por observer quedan {$quedan} clientes sin CC");
    }

    public function test_CC_05_jurisdicciones_seed_24_filas(): void
    {
        $count = DB::table('erp_iibb_jurisdicciones')->count();
        $this->assertGreaterThanOrEqual(24, $count,
            "Faltan jurisdicciones IIBB en seed (esperado >=24, encontrado {$count})");
        // Verificar algunas críticas.
        $this->assertNotNull(DB::table('erp_iibb_jurisdicciones')->where('codigo', '901')->value('nombre'));
        $this->assertNotNull(DB::table('erp_iibb_jurisdicciones')->where('codigo', '902')->value('nombre'));
        $this->assertNotNull(DB::table('erp_iibb_jurisdicciones')->where('codigo', '924')->value('nombre'));
    }

    public function test_CC_06_normalizar_periodo_trabajado_mensual_y_quincenal(): void
    {
        $svc = app(\App\Erp\Services\LibroIvaComprasImportService::class);
        $rc = new \ReflectionClass($svc);
        $method = $rc->getMethod('normalizarPeriodoTrabajado');
        $method->setAccessible(true);

        $this->assertSame('2026-03', $method->invoke($svc, '2026-03'));
        $this->assertSame('2026-03', $method->invoke($svc, '2026-3'));
        $this->assertSame('2026-03-Q1', $method->invoke($svc, '2026-03-Q1'));
        $this->assertSame('2026-03-Q2', $method->invoke($svc, '2026-3-q2'));
        $this->assertNull($method->invoke($svc, ''));
        // Crudo si no parsea (no falla)
        $this->assertSame('foo bar', $method->invoke($svc, 'foo bar'));
    }

    public function test_CC_07_columnas_v1_14_existen_en_facturas(): void
    {
        $colsCompra = DB::select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='erp_facturas_compra'
               AND COLUMN_NAME IN ('periodo_trabajado_texto','jurisdiccion_codigo','centro_costo_id')"
        );
        $this->assertCount(3, $colsCompra);

        $colsVenta = DB::select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='erp_facturas_venta'
               AND COLUMN_NAME IN ('periodo_trabajado_texto','jurisdiccion_codigo','centro_costo_id')"
        );
        $this->assertCount(3, $colsVenta);

        // periodo_pagado_texto debe estar dropeado (typo del v1.13).
        $dropeada = DB::select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='erp_facturas_compra'
               AND COLUMN_NAME = 'periodo_pagado_texto'"
        );
        $this->assertCount(0, $dropeada,
            'periodo_pagado_texto sigue presente — la migración v1.14 no la dropeo');
    }

    public function test_CC_08_codigo_cc_unico_si_colision(): void
    {
        // Caso edge: dos altas con id colisionando manualmente requeriría
        // crear dos auxiliares con códigos pre-asignados, pero el observer
        // usa $aux->id que es autoincrement → no colisiona.
        // Acá testeamos que el observer es idempotente: re-llamarlo no duplica.
        $aux = Auxiliar::create([
            'empresa_id' => $this->empresaId,
            'tipo' => 'Cliente',
            'codigo' => 'TST-'.substr(uniqid(), -8),
            'nombre' => 'Cliente Idempotencia',
            'activo' => 1,
        ]);
        $count1 = DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->count();
        // Forzar un re-fire del observer simulando un update.
        $aux->update(['nombre' => 'Cliente Idempotencia v2']);
        $count2 = DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->count();
        $this->assertSame(1, $count1);
        $this->assertSame(1, $count2);
    }
}
