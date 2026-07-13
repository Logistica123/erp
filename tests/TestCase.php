<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // ------------------------------------------------------------------
    // Helpers de portabilidad de fixtures (tarea 2.1 del plan de
    // remediación): la suite tiene que correr verde contra el CLON DEL
    // ESQUEMA DE PROD, cuyo catálogo difiere del de la base local (no hay
    // CC 'CENTRAL', no hay medio 'ECHEQ', no hay auxiliar 'PROV-XYZ'…).
    // Todos crean dentro de la transacción del test (DatabaseTransactions
    // los revierte) y son no-op si el dato ya existe.
    // ------------------------------------------------------------------

    /**
     * Varios services (Cobro, Echeq, Ejercicio, TransferenciaInterna,
     * MovimientoBancario) usan el CC de código 'CENTRAL' como fallback
     * cuando una cuenta admite CC y no viene explícito. En prod ese CC no
     * existe (el operativo se llama 'GENERAL') — bug latente anotado en
     * PLAN_REMEDIACION_ESTADO.md. El fixture lo garantiza para que los
     * tests ejerciten el camino feliz del service.
     */
    protected function asegurarCcCentral(int $empresaId = 1): int
    {
        return (int) (DB::table('erp_centros_costo')
            ->where('empresa_id', $empresaId)->where('codigo', 'CENTRAL')->value('id')
            ?? DB::table('erp_centros_costo')->insertGetId([
                'empresa_id' => $empresaId, 'codigo' => 'CENTRAL',
                'nombre' => 'Central (fixture tests)', 'tipo' => 'OTRO', 'activo' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]));
    }

    protected function asegurarAuxiliar(string $codigo, string $tipo, int $empresaId = 1): int
    {
        return (int) (DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)->where('codigo', $codigo)->value('id')
            ?? DB::table('erp_auxiliares')->insertGetId([
                'empresa_id' => $empresaId, 'codigo' => $codigo,
                'nombre' => $codigo.' (fixture tests)', 'tipo' => $tipo,
                'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]));
    }

    protected function asegurarMedioPago(string $codigo, array $flags = []): int
    {
        return (int) (DB::table('erp_medios_pago')->where('codigo', $codigo)->value('id')
            ?? DB::table('erp_medios_pago')->insertGetId(array_merge([
                'codigo' => $codigo, 'nombre' => $codigo.' (fixture tests)',
                'afecta_caja' => 0, 'afecta_banco' => 0, 'genera_echeq' => 0,
                'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
            ], $flags)));
    }
}
