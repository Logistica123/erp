<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration versionada del paquete `DDL_03_Tesoreria.sql` (SPEC 02).
 *
 * Crea 19 tablas de tesorería + 5 triggers + 4 vistas:
 *   Catálogos:        erp_bancos, erp_medios_pago, erp_motivos_ignorado
 *   Cuentas:          erp_cuentas_bancarias, erp_cajas
 *   Extractos:        erp_extractos_bancarios, erp_movimientos_bancarios
 *   eCheq:            erp_echeq, erp_echeq_movimientos
 *   OP:               erp_ordenes_pago (ya pre-existente), erp_op_items, erp_op_medios
 *   Cobros:           erp_cobros, erp_cobro_items, erp_cobro_medios
 *   Transf internas:  erp_transferencias_internas
 *   Caja:             erp_arqueos_caja
 *   Conciliación:     erp_conciliacion_reglas, erp_conciliaciones
 *   Triggers:         trg_mov_bancario_saldo_au, trg_op_inmutable_bu (RN-17),
 *                     trg_echeq_historial_au, trg_caja_saldo_bu (RN-16),
 *                     trg_ti_validar_bi.
 *   Vistas:           v_tesoreria_saldos, v_echeq_en_cartera,
 *                     v_mov_bancarios_pendientes, v_op_pendientes.
 *
 * Parches vs DDL_03:
 *  · Collation utf8mb4_0900_ai_ci → utf8mb4_unicode_ci (MariaDB compat).
 *  · Triggers en archivo aparte (PDO rechaza DELIMITER).
 *  · CREATE TABLE IF NOT EXISTS y DROP TRIGGER IF EXISTS para idempotencia
 *    (bases donde DDL_03 se aplicó manualmente antes de tener migration).
 *  · Vista v_op_pendientes: a.razon_social → a.nombre (schema real de auxiliares).
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('migrations/sql/');

        // 1. Tablas + FKs + vistas.
        DB::unprepared(file_get_contents($path.'03_tesoreria.sql'));

        // 2. Triggers — uno por statement.
        $programs = file_get_contents($path.'03_tesoreria_programs.sql');
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
    }

    public function down(): void
    {
        // Vistas
        foreach (['v_op_pendientes', 'v_mov_bancarios_pendientes', 'v_echeq_en_cartera', 'v_tesoreria_saldos'] as $v) {
            DB::statement("DROP VIEW IF EXISTS {$v}");
        }

        // Triggers
        foreach (['trg_ti_validar_bi', 'trg_caja_saldo_bu', 'trg_echeq_historial_au', 'trg_op_inmutable_bu', 'trg_mov_bancario_saldo_au'] as $t) {
            DB::statement("DROP TRIGGER IF EXISTS {$t}");
        }

        // Tablas en orden inverso por FK
        $tables = [
            'erp_conciliaciones',
            'erp_conciliacion_reglas',
            'erp_arqueos_caja',
            'erp_transferencias_internas',
            'erp_cobro_medios',
            'erp_cobro_items',
            'erp_cobros',
            'erp_op_medios',
            'erp_op_items',
            'erp_ordenes_pago',
            'erp_echeq_movimientos',
            'erp_echeq',
            'erp_movimientos_bancarios',
            'erp_extractos_bancarios',
            'erp_cajas',
            'erp_cuentas_bancarias',
            'erp_motivos_ignorado',
            'erp_medios_pago',
            'erp_bancos',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t}");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
};
