<?php

namespace Tests\Feature\Conciliacion;

use App\Erp\Models\Tesoreria\AliasContraparte;
use App\Erp\Models\Tesoreria\ConciliacionPrefijo;
use App\Erp\Models\Tesoreria\ConciliacionRegla;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Tesoreria\MatchingContraparteService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests del MatchingContraparteService (SPEC Conciliacion CM-2):
 *   - reglas explícitas
 *   - extracción de CUIT por prefijo
 *   - alias de cache
 *   - fuzzy match
 */
class MatchingContraparteServiceTest extends TestCase
{
    use DatabaseTransactions;

    private MatchingContraparteService $svc;
    private CuentaBancaria $cuenta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(MatchingContraparteService::class);
        $this->cuenta = CuentaBancaria::where('empresa_id', 1)->where('activo', 1)->first()
            ?? $this->fail('Necesito al menos 1 cuenta bancaria activa para empresa_id=1');
    }

    private function mov(string $concepto, float $debito = 0, float $credito = 0): MovimientoBancario
    {
        $ext = ExtractoBancario::create([
            'cuenta_bancaria_id' => $this->cuenta->id,
            'fecha_desde' => '2026-04-25', 'fecha_hasta' => '2026-04-25',
            'hash_archivo' => substr(md5(uniqid()), 0, 64),
            'nombre_archivo' => 't.csv', 'ruta_archivo' => '/tmp/t.csv',
            'saldo_inicial' => 0, 'saldo_final' => 0, 'cant_movimientos' => 0,
            'importado_por_user_id' => 1, 'importado_at' => now(),
        ]);
        return MovimientoBancario::create([
            'extracto_id' => $ext->id, 'cuenta_bancaria_id' => $this->cuenta->id,
            'fecha' => '2026-04-25', 'concepto' => $concepto,
            'debito' => $debito, 'credito' => $credito, 'saldo' => 0,
            'estado' => 'PENDIENTE',
            'hash_linea' => str_pad(substr(md5($concepto.uniqid()), 0, 60), 64, 'X'),
        ]);
    }

    public function test_cuit_validator_acepta_cuits_reales(): void
    {
        $this->assertTrue($this->svc->esCuitValido('30708123451')); // OCA dev seed
        $this->assertFalse($this->svc->esCuitValido('30123456789'));
        $this->assertFalse($this->svc->esCuitValido('123'));
        $this->assertFalse($this->svc->esCuitValido('abcdefghijk'));
    }

    public function test_normalizar_alias_quita_cortesias_y_collapsa(): void
    {
        $this->assertSame('JUAN PEREZ', AliasContraparte::normalizar('  Sr. Juan   Perez  '));
        $this->assertSame('LOGISTICA ARGENTINA SRL', AliasContraparte::normalizar('Logistica Argentina S.R.L.'));
        $this->assertSame('PAGO LA REINA CORRIENTES', AliasContraparte::normalizar('Pago La Reina Corrientes'));
    }

    public function test_regla_concepto_regex_matchea_y_devuelve_cuenta(): void
    {
        $cuentaContableId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)->where('imputable', 1)->value('id');

        $regla = ConciliacionRegla::create([
            'empresa_id' => 1, 'codigo' => 'TEST-RDIM',
            'descripcion' => 'Rendimientos MP',
            'tipo' => 'CONCEPTO_REGEX', 'patron_concepto' => '^Rendimientos',
            'cuenta_contable_id' => $cuentaContableId,
            'orden_prioridad' => 5, 'activa' => 1,
            'banco_id' => $this->cuenta->banco_id,
            'signo' => 'CREDITO', 'confianza' => 95,
        ]);

        $mov = $this->mov('Rendimientos del fondo MP', credito: 100.50);
        $r = $this->svc->matchear($mov);

        $this->assertSame('REGLA', $r['estrategia']);
        $this->assertSame($regla->id, $r['regla_aplicada_id']);
        $this->assertSame($cuentaContableId, $r['cuenta_contable_propuesta_id']);
        $this->assertSame(95, $r['confianza_match']);
    }

    public function test_alias_cache_devuelve_persona_id(): void
    {
        AliasContraparte::create([
            'empresa_id' => 1, 'banco_id' => $this->cuenta->banco_id,
            'alias_normalizado' => 'ZZTEST PERSONA SINTETICA UNO',
            'persona_id' => 42, 'confianza' => 100,
            'asignado_por' => 1, 'asignado_at' => now(),
        ]);

        // Portabilidad (2.1): concepto sintético — el real ('Transferencia
        // enviada…') lo intercepta una regla seedeada del clon de prod.
        $mov = $this->mov('Zztest Persona Sintetica Uno', debito: 5000);
        $r = $this->svc->matchear($mov);

        $this->assertSame('ALIAS', $r['estrategia']);
        $this->assertSame(42, $r['persona_id']);
        $this->assertSame(100, $r['confianza_match']);
    }

    public function test_fuzzy_devuelve_pendiente_cuando_no_hay_candidatos(): void
    {
        $mov = $this->mov('Pago al desconocido inexistente XYZ123', debito: 100);
        $r = $this->svc->matchear($mov);

        $this->assertSame('NINGUNA', $r['estrategia']);
        $this->assertLessThan(50, $r['confianza_match']);
        $this->assertNull($r['persona_id']);
        $this->assertNull($r['cliente_id']);
    }

    public function test_regla_filtra_por_signo(): void
    {
        $cuentaContableId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)->where('imputable', 1)->value('id');

        ConciliacionRegla::create([
            'empresa_id' => 1, 'codigo' => 'TEST-SIGN',
            'descripcion' => 'Solo créditos',
            'tipo' => 'CONCEPTO_REGEX', 'patron_concepto' => 'COBRANZA',
            'cuenta_contable_id' => $cuentaContableId,
            'orden_prioridad' => 5, 'activa' => 1,
            'banco_id' => $this->cuenta->banco_id,
            'signo' => 'CREDITO', 'confianza' => 90,
        ]);

        // Mismo concepto pero como débito → no debe matchear esa regla.
        $mov = $this->mov('COBRANZA cliente xyz', debito: 100);
        $r = $this->svc->matchear($mov);
        $this->assertNotSame('REGLA', $r['estrategia']);
    }

    public function test_regla_cod_concepto_filtra(): void
    {
        $cuentaContableId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)->where('imputable', 1)->value('id');

        // Regla que solo aplica a cod_concepto=DBT.
        ConciliacionRegla::create([
            'empresa_id' => 1, 'codigo' => 'TEST-COD',
            'descripcion' => 'Solo DBT', 'tipo' => 'CONCEPTO_REGEX',
            'patron_concepto' => 'ZZTESTDEB', 'cuenta_contable_id' => $cuentaContableId,
            'orden_prioridad' => 5, 'activa' => 1,
            'banco_id' => $this->cuenta->banco_id,
            'signo' => 'AMBOS', 'confianza' => 90, 'cod_concepto' => 'DBT',
        ]);

        // Sin (DBT) en el concepto → no matchea.
        // Portabilidad (2.1): concepto sintético — 'DEBITO INMEDIATO' lo
        // matchean las reglas reales seedeadas en el clon de prod.
        $movSinCod = $this->mov('ZZTESTDEB INMEDIATO', debito: 100);
        $r1 = $this->svc->matchear($movSinCod);
        $this->assertNotSame('REGLA', $r1['estrategia']);

        // Con (DBT) en el concepto → matchea.
        $movConCod = $this->mov('ZZTESTDEB INMEDIATO (DBT)', debito: 100);
        $r2 = $this->svc->matchear($movConCod);
        $this->assertSame('REGLA', $r2['estrategia']);
    }

    public function test_extrae_cuit_via_prefijo_y_devuelve_referencia(): void
    {
        // Asume que prefijo "DEBITO INMEDIATO CUIT" está seedado para el
        // banco_id del seed CM-1 ICBC. Si la cuenta no es ICBC el test crea
        // un prefijo ad-hoc para el banco que tenga.
        ConciliacionPrefijo::firstOrCreate(
            ['banco_id' => $this->cuenta->banco_id, 'prefijo' => 'XCUIT'],
            [
                'tipo_numero' => 'CUIT', 'longitud_min' => 11, 'longitud_max' => 11,
                'activo' => 1,
            ],
        );

        // CUIT 30708123451 (OCA dev seed) — válido, en erp_auxiliares.
        $mov = $this->mov('Pago a XCUIT 30708123451 OCA SA', debito: 1000);
        $r = $this->svc->matchear($mov);

        // Debe extraer la referencia y al menos exponer el CUIT.
        $this->assertSame('30708123451', $r['referencia_externa']);
        $this->assertSame('30708123451', $r['cuit_contraparte']);
        // Confianza 90 si encuentra el auxiliar, 50 si no.
        $this->assertGreaterThanOrEqual(50, $r['confianza_match']);
    }
}
