<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mini-tanda 2026-07-13, bug 3: en prod hay 9 clientes activos sin CC
 * porque los caminos de integración crean auxiliares con insert crudo
 * (bypass de AuxiliarClienteObserver, que solo dispara por Eloquent).
 *
 * Cobertura:
 *  - CcCliente::asegurar() (helper extraído del observer) crea el CC
 *    para un cliente creado por insert crudo.
 *  - comando erp:sanear-cc-clientes repara TODOS los gaps existentes
 *    (idempotente, reporta cantidad).
 */
class CcClienteSaneoTest extends TestCase
{
    use DatabaseTransactions;

    private function crearClienteCrudo(string $codigo): int
    {
        // Reproduce el bypass real: insert directo, sin Eloquent.
        return (int) DB::table('erp_auxiliares')->insertGetId([
            'empresa_id' => 1, 'tipo' => 'Cliente', 'codigo' => $codigo,
            'nombre' => 'Cliente Crudo '.$codigo, 'activo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_helper_asegura_cc_para_cliente_creado_por_insert_crudo(): void
    {
        $auxId = $this->crearClienteCrudo('ZZT-'.substr(uniqid(), -6));
        $this->assertFalse(
            DB::table('erp_centros_costo')->where('auxiliar_id', $auxId)->exists(),
            'precondición: el insert crudo NO crea CC'
        );

        \App\Erp\Support\CcCliente::asegurar($auxId);

        $cc = DB::table('erp_centros_costo')->where('auxiliar_id', $auxId)->first();
        $this->assertNotNull($cc, 'el helper debe crear el CC');
        $this->assertSame('CLIENTE', $cc->tipo);
        $this->assertStringStartsWith('CLI-', $cc->codigo);

        // Idempotente: segunda llamada no duplica.
        \App\Erp\Support\CcCliente::asegurar($auxId);
        $this->assertSame(1,
            DB::table('erp_centros_costo')->where('auxiliar_id', $auxId)->count());
    }

    public function test_comando_sanea_todos_los_clientes_sin_cc(): void
    {
        $a = $this->crearClienteCrudo('ZZT-'.substr(uniqid(), -6));
        $b = $this->crearClienteCrudo('ZZT-'.substr(uniqid(), -6));

        $exit = Artisan::call('erp:sanear-cc-clientes');
        $this->assertSame(0, $exit);

        foreach ([$a, $b] as $auxId) {
            $this->assertTrue(
                DB::table('erp_centros_costo')->where('auxiliar_id', $auxId)->exists(),
                "el comando debe crear el CC del auxiliar {$auxId}"
            );
        }

        $quedan = DB::table('erp_auxiliares as a')
            ->leftJoin('erp_centros_costo as cc', 'cc.auxiliar_id', '=', 'a.id')
            ->where('a.tipo', 'Cliente')->where('a.activo', 1)
            ->whereNull('cc.id')->count();
        $this->assertSame(0, $quedan, 'no puede quedar ningún cliente activo sin CC');
    }
}
