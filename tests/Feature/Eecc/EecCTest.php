<?php

namespace Tests\Feature\Eecc;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\EeccEmision;
use App\Erp\Models\Impuestos\EeccNota;
use App\Erp\Services\Eecc\AjusteInflacionService;
use App\Erp\Services\Eecc\BalanceGeneralService;
use App\Erp\Services\Eecc\EecCPdfService;
use App\Erp\Services\Eecc\EecCService;
use App\Erp\Services\Eecc\EpnService;
use App\Erp\Services\Eecc\EstadoResultadosService;
use App\Erp\Services\Eecc\FlujoEfectivoService;
use App\Erp\Services\Eecc\NotasService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests EECC profesionales (RN-59 a RN-63).
 * Aislamos del trigger de balance de asientos: las queries de cálculo
 * se prueban contra estructura/idempotencia, no totales reales (estos
 * dependen de movimientos asentados que no podemos crear desde PHP).
 */
class EecCTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private int $empresaId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('erp_eecc_notas') || ! Schema::hasTable('erp_eecc_emisiones')) {
            $this->markTestSkipped('DDL_05 H8 no aplicado');
        }
        $this->user = User::firstOrCreate(
            ['email' => 'test.h8@logistica.local'],
            ['name' => 'Test H8', 'password' => bcrypt('irrelevante')]
        );
    }

    // ----- BG / ER / EPN / EFE estructura -----

    public function test_balance_general_estructura_y_verificacion(): void
    {
        $ejercicio = $this->crearEjercicio(2063);
        $bg = app(BalanceGeneralService::class)->calcular($ejercicio);

        $this->assertArrayHasKey('activo', $bg);
        $this->assertArrayHasKey('pasivo', $bg);
        $this->assertArrayHasKey('patrimonio', $bg);
        $this->assertArrayHasKey('verificacion', $bg);
        $this->assertArrayHasKey('cierra', $bg['verificacion']);
        $this->assertArrayHasKey('diferencia', $bg['verificacion']);
    }

    public function test_estado_resultados_estructura(): void
    {
        $ejercicio = $this->crearEjercicio(2062);
        $er = app(EstadoResultadosService::class)->calcular($ejercicio);

        foreach (['ingresos', 'egresos', 'resultado_bruto', 'impuesto_ganancias', 'resultado_ejercicio'] as $k) {
            $this->assertArrayHasKey($k, $er);
        }
    }

    public function test_epn_estructura_filas_y_totales(): void
    {
        $ejercicio = $this->crearEjercicio(2061);
        $epn = app(EpnService::class)->calcular($ejercicio);

        $this->assertArrayHasKey('filas', $epn);
        $this->assertArrayHasKey('totales', $epn);
        foreach (['saldo_inicial', 'aumentos', 'disminuciones', 'saldo_final'] as $k) {
            $this->assertArrayHasKey($k, $epn['totales']);
        }
    }

    public function test_efe_metodo_indirecto(): void
    {
        $ejercicio = $this->crearEjercicio(2060);
        $efe = app(FlujoEfectivoService::class)->calcular($ejercicio);

        $this->assertEquals('INDIRECTO', $efe['metodo']);
        $this->assertArrayHasKey('actividades_operativas', $efe);
        $this->assertArrayHasKey('reconciliacion', $efe);
    }

    // ----- Notas -----

    public function test_notas_se_crean_con_plantillas_iniciales(): void
    {
        $ejercicio = $this->crearEjercicio(2059);
        $notas = app(NotasService::class)->paraEjercicio($ejercicio);

        $this->assertCount(10, $notas);
        $this->assertEquals(1, $notas[0]->numero);
        $this->assertEquals('Naturaleza jurídica y operaciones', $notas[0]->titulo);
    }

    public function test_actualizar_nota_persiste_y_marca_editor(): void
    {
        $ejercicio = $this->crearEjercicio(2058);
        $svc = app(NotasService::class);
        $svc->paraEjercicio($ejercicio);

        $nota = $svc->actualizar($ejercicio, 1, 'Nuevo contenido nota 1', $this->user);

        $this->assertEquals('Nuevo contenido nota 1', $nota->contenido);
        $this->assertEquals($this->user->id, $nota->editado_user_id);
        $this->assertNotNull($nota->editado_at);
    }

    public function test_actualizar_nota_numero_invalido_falla(): void
    {
        $ejercicio = $this->crearEjercicio(2057);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/EECC_NOTA_NUMERO_INVALIDO/');
        app(NotasService::class)->actualizar($ejercicio, 99, 'x', $this->user);
    }

    // ----- Ajuste por inflación -----

    public function test_ajuste_no_aplica_devuelve_coef_1(): void
    {
        $ejercicio = $this->crearEjercicio(2056);
        $ejercicio->update(['ajusta_por_inflacion' => 0]);
        $this->assertEquals(1.0, app(AjusteInflacionService::class)->coeficiente($ejercicio));
    }

    public function test_ajuste_aplica_requiere_indice_cierre(): void
    {
        $ejercicio = $this->crearEjercicio(2055);
        $ejercicio->update(['ajusta_por_inflacion' => 1, 'indice_cierre' => null]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/AJUSTE_INFLACION_INDICE_FALTANTE/');
        app(AjusteInflacionService::class)->coeficiente($ejercicio->fresh());
    }

    public function test_ajuste_aplica_devuelve_coeficiente(): void
    {
        $ejercicio = $this->crearEjercicio(2054);
        $ejercicio->update(['ajusta_por_inflacion' => 1, 'indice_cierre' => 1.45]);

        $coef = app(AjusteInflacionService::class)->coeficiente($ejercicio->fresh());
        $this->assertEqualsWithDelta(1.45, $coef, 0.001);
    }

    // ----- Orquestador -----

    public function test_armar_paquete_completo_con_seccion_filtrada(): void
    {
        $ejercicio = $this->crearEjercicio(2053);
        $paquete = app(EecCService::class)->armar($ejercicio, ['BG', 'ER']);

        $this->assertNotNull($paquete['bg']);
        $this->assertNotNull($paquete['er']);
        $this->assertNull($paquete['epn']);
        $this->assertNull($paquete['efe']);
        $this->assertArrayHasKey('estado', $paquete);
        $this->assertArrayHasKey('anexo_inflacion', $paquete);
    }

    public function test_armar_detecta_asientos_borrador_como_no_cierra(): void
    {
        $ejercicio = $this->crearEjercicio(2052);

        // Sembrar 1 asiento BORRADOR sin movimientos (válido a nivel cabecera).
        $diarioId = DB::table('erp_diarios')->where('empresa_id', 1)->value('id');
        if (! $diarioId) {
            $diarioId = DB::table('erp_diarios')->insertGetId([
                'empresa_id' => 1, 'codigo' => 'TEST', 'nombre' => 'Diario Test',
                'tipo' => 'GENERAL', 'activo' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $periodoId = DB::table('erp_periodos')->where('ejercicio_id', $ejercicio->id)->value('id');
        if (! $periodoId) {
            $periodoId = DB::table('erp_periodos')->insertGetId([
                'ejercicio_id' => $ejercicio->id, 'anio' => 2052, 'mes' => 12,
                'fecha_inicio' => $ejercicio->fecha_inicio, 'fecha_fin' => $ejercicio->fecha_cierre,
                'estado' => 'ABIERTO',
            ]);
        }
        DB::table('erp_asientos')->insert([
            'empresa_id' => 1, 'ejercicio_id' => $ejercicio->id, 'periodo_id' => $periodoId,
            'diario_id' => $diarioId, 'numero' => rand(100000, 999999),
            'fecha' => $ejercicio->fecha_cierre, 'glosa' => 'Test borrador',
            'origen' => 'MANUAL', 'estado' => 'BORRADOR', 'moneda_base' => 'ARS',
            'total_debe' => 0, 'total_haber' => 0,
            'usuario_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $paquete = app(EecCService::class)->armar($ejercicio, ['BG']);

        $this->assertFalse($paquete['estado']['cierra']);
        $this->assertEquals(1, $paquete['estado']['asientos_borrador']);
    }

    // ----- PDF -----

    public function test_generar_pdf_persiste_archivo_y_emision(): void
    {
        Storage::fake('local');

        $ejercicio = $this->crearEjercicio(2051);
        $res = app(EecCPdfService::class)->generar($ejercicio, $this->user, [
            'incluir' => ['BG', 'ER'],
            'profesional_firmante' => 'Cdor. Pérez',
            'matricula_firmante'   => 'CPCE 12345',
        ]);

        $this->assertTrue(Storage::disk('local')->exists($res['path']));
        $this->assertEquals(64, strlen($res['hash']));

        $emision = EeccEmision::find($res['emision_id']);
        $this->assertNotNull($emision);
        $this->assertEquals('PDF', $emision->formato);
        $this->assertEquals(['BG', 'ER'], $emision->incluir);
        $this->assertEquals('Cdor. Pérez', $emision->profesional_firmante);
    }

    public function test_pdf_falla_con_seccion_invalida(): void
    {
        $ejercicio = $this->crearEjercicio(2050);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/EECC_SECCION_INVALIDA/');
        app(EecCPdfService::class)->generar($ejercicio, $this->user, [
            'incluir' => ['CUALQUIER_SECCION'],
        ]);
    }

    private function crearEjercicio(int $numero): Ejercicio
    {
        return Ejercicio::create([
            'empresa_id' => $this->empresaId,
            'numero' => $numero, 'nombre' => "Ejercicio {$numero}",
            'fecha_inicio' => sprintf('%04d-01-01', $numero),
            'fecha_cierre' => sprintf('%04d-12-31', $numero),
            'estado' => 'ABIERTO',
        ]);
    }
}
