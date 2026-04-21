<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration versionada del paquete de handoff `DDL_02_Contabilidad.sql`.
 *
 * Crea 8 tablas del núcleo contable + 6 triggers + 1 stored procedure + 2 vistas:
 *   Tablas:      erp_diarios, erp_cuentas_contables, erp_mapeo_etiqueta_cuenta,
 *                erp_auxiliares, erp_asientos, erp_movimientos_asiento,
 *                erp_saldos_cuenta, erp_asientos_plantilla.
 *   Programas:   sp_recalc_asiento; trg_asiento_balance_insert/update,
 *                trg_movimiento_ai/au/ad, trg_movimiento_validar.
 *   Vistas:      v_libro_diario, v_libro_mayor.
 *
 * Parches vs DDL_02 del handoff:
 *  · Collation `utf8mb4_0900_ai_ci` → `utf8mb4_unicode_ci` (MariaDB compat).
 *  · v_libro_mayor reescrita con window function (SUM() OVER) porque MariaDB
 *    rechaza user variables (@saldo) dentro de vistas.
 *  · Los triggers se extraen del wrapper `DELIMITER //` y se ejecutan
 *    individualmente (PDO no soporta la directiva DELIMITER del CLI mysql).
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('migrations/sql/');

        // 1. Tablas + índices (una sola llamada; PDO::exec maneja multi-statement).
        DB::unprepared(file_get_contents($path.'02_contabilidad_tables.sql'));

        // 2. Triggers y stored procedure — cada uno como statement independiente.
        $programs = file_get_contents($path.'02_contabilidad_programs.sql');
        foreach (explode('//', $programs) as $stmt) {
            $lines = array_filter(
                explode("\n", $stmt),
                fn ($l) => trim($l) !== '' && ! str_starts_with(trim($l), '--')
            );
            $stmt = trim(implode("\n", $lines));
            if ($stmt === '') {
                continue;
            }
            DB::unprepared($stmt);
        }

        // 3. Vistas (con el fix de v_libro_mayor).
        DB::unprepared(file_get_contents($path.'02_contabilidad_views.sql'));
    }

    public function down(): void
    {
        // Vistas
        DB::statement('DROP VIEW IF EXISTS v_libro_mayor');
        DB::statement('DROP VIEW IF EXISTS v_libro_diario');

        // Triggers
        foreach (['trg_movimiento_validar', 'trg_movimiento_ad', 'trg_movimiento_au', 'trg_movimiento_ai', 'trg_asiento_balance_update', 'trg_asiento_balance_insert'] as $t) {
            DB::statement("DROP TRIGGER IF EXISTS {$t}");
        }
        DB::statement('DROP PROCEDURE IF EXISTS sp_recalc_asiento');

        // Tablas (orden inverso por FK)
        $tables = [
            'erp_asientos_plantilla',
            'erp_saldos_cuenta',
            'erp_movimientos_asiento',
            'erp_asientos',
            'erp_auxiliares',
            'erp_mapeo_etiqueta_cuenta',
            'erp_cuentas_contables',
            'erp_diarios',
        ];
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t}");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
};
