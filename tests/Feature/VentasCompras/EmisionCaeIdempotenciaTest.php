<?php

namespace Tests\Feature\VentasCompras;

use App\Erp\Jobs\EmitirFacturaJob;
use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Services\EmisorFacturaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Auditoría 2026-07-12 bug #2 — idempotencia de emisión de CAE.
 *
 * (a) La clave del outbox incluía md5(updated_at): cambiaba entre reintentos
 *     y anulaba la garantía exactly-once del gateway.
 * (b) El camino síncrono emitía ANTES de persistir nada: un timeout después
 *     de que AFIP autorizara dejaba un CAE huérfano sin registro local y el
 *     reintento del operador emitía un SEGUNDO CAE.
 *
 * Todo mockeado con Http::fake — acá no se emite ningún CAE real.
 */
class EmisionCaeIdempotenciaTest extends TestCase
{
    use DatabaseTransactions;

    private int $pvId;
    private int $clienteId;
    private int $alicId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.arca_gateway', [
            'url' => 'http://arca-fake.test',
            'client_id' => 'test',
            'api_key' => 'test',
            'timeout' => 5,
        ]);

        // Fixtures autosuficientes: la base dev local no tiene seedeados
        // PV/alícuotas/tipos (drift conocido — auditoría §3.5).
        if (! DB::table('erp_condiciones_iva')->where('id', 1)->exists()) {
            DB::table('erp_condiciones_iva')->insert([
                'id' => 1, 'codigo_interno' => 'RI', 'nombre' => 'Responsable Inscripto',
                'letra_default' => 'A', 'acepta_fce' => 1, 'activo' => 1,
            ]);
        }
        if (! DB::table('erp_tipos_comprobante')->where('id', 1)->exists()) {
            DB::table('erp_tipos_comprobante')->insert([
                'id' => 1, 'codigo_interno' => 'FA', 'nombre' => 'Factura A', 'letra' => 'A',
                'clase' => 'FACTURA', 'signo' => 1, 'es_fce' => 0, 'discrimina_iva' => 1, 'activo' => 1,
            ]);
        }
        $this->pvId = (int) (DB::table('erp_puntos_venta')->where('empresa_id', 1)->value('id')
            ?? DB::table('erp_puntos_venta')->insertGetId([
                'empresa_id' => 1, 'numero' => 9, 'nombre' => 'PV test', 'tipo_emision' => 'CAE', 'activo' => 1,
            ]));
        $this->clienteId = (int) (DB::table('erp_auxiliares')->where('empresa_id', 1)
            ->where('tipo', 'Cliente')->value('id')
            ?? DB::table('erp_auxiliares')->insertGetId([
                'empresa_id' => 1, 'tipo' => 'Cliente', 'codigo' => 'CLI-TEST-CAE',
                'nombre' => 'Cliente Test CAE', 'cuit' => '20111111112', 'activo' => 1,
            ]));
        $alicExistente = DB::table('erp_alicuotas_iva')->where('tasa', '>', 0)->value('id');
        if (! $alicExistente) {
            // id manual (catálogo sin autoincrement) — insertGetId devolvería 0.
            DB::table('erp_alicuotas_iva')->insert([
                'id' => 5, 'codigo_interno' => 'IVA21', 'nombre' => 'IVA 21%',
                'tasa' => 0.21, 'activo' => 1, 'codigo_afip' => 5,
            ]);
            $alicExistente = 5;
        }
        $this->alicId = (int) $alicExistente;
    }

    private function inputEmision(): array
    {
        return [
            'cliente_id' => $this->clienteId,
            'tipo_comprobante_id' => 1,
            'punto_venta_id' => $this->pvId,
            'concepto_afip' => 1,
            'fecha_emision' => '2026-07-12',
            'items' => [[
                'descripcion' => 'Flete test', 'cantidad' => 1,
                'precio_unit' => 1000, 'alicuota_iva_id' => $this->alicId,
            ]],
        ];
    }

    public function test_clave_del_outbox_no_depende_de_updated_at(): void
    {
        $f = new FacturaVenta([
            'tipo_comprobante_id' => 1, 'concepto_afip' => 1, 'doc_tipo_afip' => 80,
            'doc_nro' => '20111111112', 'condicion_iva_id' => 1, 'numero' => 55,
            'cotizacion' => 1, 'imp_total' => 121, 'imp_no_gravado' => 0,
            'imp_neto_gravado' => 100, 'imp_exento' => 0, 'imp_iva' => 21, 'imp_tributos' => 0,
        ]);
        $f->id = 777;
        $f->fecha_emision = now();
        $f->setRelation('puntoVenta', (object) ['numero' => 9]);
        $f->setRelation('iva', collect());
        $f->setRelation('tributos', collect());

        $m = new ReflectionMethod(EmitirFacturaJob::class, 'buildPayload');
        $m->setAccessible(true);
        $job = new EmitirFacturaJob();

        $f->updated_at = now();
        $key1 = $m->invoke($job, $f)['idempotency_key'];
        $f->updated_at = now()->addMinutes(7); // un reintento posterior
        $key2 = $m->invoke($job, $f)['idempotency_key'];

        $this->assertSame($key1, $key2, 'La clave debe ser estable entre reintentos de la misma factura');
    }

    public function test_timeout_con_cae_emitido_se_adopta_sin_reemitir(): void
    {
        // 1º intento: AFIP autoriza pero la respuesta se pierde (timeout).
        Http::fake([
            'arca-fake.test/wsfe/emitir' => fn () => throw new ConnectionException('timeout simulado'),
            'arca-fake.test/wsfe/ultimo-autorizado/*' => Http::response(['cbte_nro' => 101], 200),
            'arca-fake.test/wsfe/consultar/*' => Http::response([
                'cbte_nro' => 101, 'imp_total' => '1210.00', 'fecha_cbte' => '2026-07-12',
                'cae' => '74111111111111', 'cae_vto' => '2026-07-22', 'doc_nro' => null,
            ], 200),
        ]);

        $svc = app(EmisorFacturaService::class);

        try {
            $svc->emitir($this->inputEmision(), 1, 1);
            $this->fail('El 1º intento debía fallar por timeout');
        } catch (\RuntimeException) {
            // esperado: resultado desconocido
        }

        $this->assertSame(1, DB::table('erp_emisiones_sincronicas')->where('estado', 'VERIFICAR')->count(),
            'El intento con resultado desconocido debe quedar registrado para reconciliar');

        // 2º intento del operador: debe reconciliar, adoptar el CAE 74111... y NO reemitir.
        $out = $svc->emitir($this->inputEmision(), 1, 1);

        $this->assertSame('74111111111111', $out['cae']);
        $this->assertSame(101, (int) $out['numero']);
        $this->assertSame(1, FacturaVenta::where('cae', '74111111111111')->count(), 'Una sola factura');

        // La request que tira ConnectionException no queda en Http::recorded():
        // lo registrado debe ser SOLO la verificación (ultimo-autorizado +
        // consultar), y CERO POSTs exitosos a /wsfe/emitir — sin re-emisión.
        $emitidas = collect(Http::recorded())
            ->filter(fn ($par) => str_contains($par[0]->url(), '/wsfe/emitir'))->count();
        $this->assertSame(0, $emitidas, 'No debe haber un segundo POST /wsfe/emitir (doble CAE)');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/ultimo-autorizado'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/wsfe/consultar'));
    }

    public function test_timeout_sin_cae_emitido_reemite_con_intent_nuevo(): void
    {
        $llamadas = 0;
        Http::fake(function (Request $r) use (&$llamadas) {
            if (str_contains($r->url(), '/wsfe/emitir')) {
                $llamadas++;
                if ($llamadas === 1) {
                    throw new ConnectionException('timeout simulado');
                }

                return Http::response([
                    'resultado' => 'A', 'cae' => '74999999999999', 'cae_vto' => '2026-07-22',
                    'cbte_desde' => 102, 'observaciones' => [],
                ], 200);
            }
            if (str_contains($r->url(), '/ultimo-autorizado')) {
                return Http::response(['cbte_nro' => 88], 200); // último NO coincide
            }
            if (str_contains($r->url(), '/wsfe/consultar')) {
                return Http::response([
                    'cbte_nro' => 88, 'imp_total' => '55555.00', 'fecha_cbte' => '2026-01-01',
                ], 200);
            }

            return Http::response([], 404);
        });

        $svc = app(EmisorFacturaService::class);

        try {
            $svc->emitir($this->inputEmision(), 1, 1);
            $this->fail('El 1º intento debía fallar');
        } catch (\RuntimeException) {
        }

        $out = $svc->emitir($this->inputEmision(), 1, 1);

        $this->assertSame('74999999999999', $out['cae']);
        $this->assertSame(2, $llamadas, 'Reemite porque AFIP confirmó que el 1º intento no se autorizó');
        $this->assertSame(0, DB::table('erp_emisiones_sincronicas')->where('estado', 'VERIFICAR')->count(),
            'El intent dudoso quedó resuelto (DESCARTADA) tras verificar contra AFIP');
    }
}
