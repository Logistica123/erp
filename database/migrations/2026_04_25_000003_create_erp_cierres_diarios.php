<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anexo Cierres Diarios — bloque CB-1.
 *
 * Crea las tablas que viven del lado ERP y agregan el concepto de "día contable":
 *   • erp_dias_contables: un registro por día con estado + saldos snapshot.
 *   • erp_ajustes_retroactivos: log de ajustes a días ya cerrados.
 *   • Columna erp_movimientos_bancarios.dia_contable_id (FK), para estampar los
 *     movimientos al sellar el día y marcarlos inmutables.
 *
 * Seed: 6 permisos cierres.dia.* + contabilidad.ajuste_retroactivo asignados a
 * super_admin / contador / revisor_fiscal / direccion / auditor según §14 del
 * anexo.
 *
 * Los parsers, workflow y endpoints vienen en bloques posteriores (CB-2..CB-7).
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('migrations/sql/');

        // 1. Tablas nuevas.
        DB::unprepared(file_get_contents($path.'09_cierres_diarios_tables.sql'));

        // 2. Columna nueva en erp_movimientos_bancarios (idempotente — MySQL 8
        //    no soporta ADD COLUMN IF NOT EXISTS, lo emulamos con info_schema).
        $this->addColumnIfMissing(
            'erp_movimientos_bancarios',
            'dia_contable_id',
            "BIGINT UNSIGNED NULL COMMENT 'FK a erp_dias_contables. NULL hasta sellar el día. Inmutabilidad post-sellado.'"
        );

        // FK + índice si no existen.
        if (! $this->indexExists('erp_movimientos_bancarios', 'idx_mov_dia_contable')) {
            DB::statement('ALTER TABLE erp_movimientos_bancarios
                ADD INDEX idx_mov_dia_contable (dia_contable_id)');
        }
        if (! $this->fkExists('erp_movimientos_bancarios', 'fk_mov_dia_contable')) {
            DB::statement('ALTER TABLE erp_movimientos_bancarios
                ADD CONSTRAINT fk_mov_dia_contable
                FOREIGN KEY (dia_contable_id) REFERENCES erp_dias_contables(id)');
        }

        // 3. Permisos.
        DB::unprepared(file_get_contents($path.'09_cierres_diarios_seed.sql'));
    }

    public function down(): void
    {
        // Permisos
        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (
            SELECT id FROM erp_permisos
             WHERE modulo='cierres' OR codigo='contabilidad.ajuste_retroactivo'
        )");
        DB::statement("DELETE FROM erp_permisos
             WHERE modulo='cierres' OR codigo='contabilidad.ajuste_retroactivo'");

        // FK + índice + columna
        try { DB::statement('ALTER TABLE erp_movimientos_bancarios DROP FOREIGN KEY fk_mov_dia_contable'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_movimientos_bancarios DROP INDEX idx_mov_dia_contable'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_movimientos_bancarios DROP COLUMN dia_contable_id'); } catch (\Throwable) {}

        // Tablas
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::statement('DROP TABLE IF EXISTS erp_ajustes_retroactivos');
        DB::statement('DROP TABLE IF EXISTS erp_dias_contables');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS x FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        if (! $exists) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne(
            'SELECT 1 AS x FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        );
        return (bool) $row;
    }

    private function fkExists(string $table, string $fk): bool
    {
        $row = DB::selectOne(
            'SELECT 1 AS x FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$table, $fk, 'FOREIGN KEY']
        );
        return (bool) $row;
    }
};
