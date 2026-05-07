<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.9 — Fecha de imputación en Facturas de Compra.
 *
 * Agrega 3 columnas nuevas a `erp_facturas_compra`:
 *   - fecha_imputacion    (default: fecha_emision via service; CURRENT_DATE
 *                          como fallback defensivo a nivel DDL)
 *   - periodo_id          (cache del período fiscal correspondiente)
 *   - imputacion_diferida (1 si fecha_imputacion > fecha_emision)
 *
 * + CHECK fecha_imputacion >= fecha_emision
 * + FK periodo_id -> erp_periodos(id)
 * + INSERT permiso `compras.imputar_periodo_cerrado` y asignación a roles
 *   super_admin y contador.
 *
 * No requiere migración de datos: la tabla está vacía en empresa 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasColumn('erp_facturas_compra', 'fecha_imputacion')) {
            DB::statement("ALTER TABLE erp_facturas_compra
                ADD COLUMN fecha_imputacion DATE NOT NULL DEFAULT (CURRENT_DATE)
                  COMMENT 'Fecha contable y fiscal a la que se imputa la factura. Debe ser >= fecha_emision.'
                  AFTER fecha_emision");
        }
        if (! $this->hasColumn('erp_facturas_compra', 'periodo_id')) {
            DB::statement("ALTER TABLE erp_facturas_compra
                ADD COLUMN periodo_id BIGINT UNSIGNED NULL
                  COMMENT 'Período fiscal al que cae la fecha_imputacion. Cacheado.'
                  AFTER fecha_imputacion");
        }
        if (! $this->hasColumn('erp_facturas_compra', 'imputacion_diferida')) {
            DB::statement("ALTER TABLE erp_facturas_compra
                ADD COLUMN imputacion_diferida TINYINT(1) NOT NULL DEFAULT 0
                  COMMENT '1 si fecha_imputacion > fecha_emision. Cacheado para reportes.'
                  AFTER periodo_id");
        }

        if (! $this->indexExists('erp_facturas_compra', 'idx_fecha_imputacion')) {
            DB::statement('ALTER TABLE erp_facturas_compra ADD INDEX idx_fecha_imputacion (fecha_imputacion)');
        }
        if (! $this->indexExists('erp_facturas_compra', 'idx_fc_periodo')) {
            DB::statement('ALTER TABLE erp_facturas_compra ADD INDEX idx_fc_periodo (periodo_id)');
        }

        if (! $this->checkExists('erp_facturas_compra', 'chk_fecha_imputacion')) {
            DB::statement('ALTER TABLE erp_facturas_compra
                ADD CONSTRAINT chk_fecha_imputacion CHECK (fecha_imputacion >= fecha_emision)');
        }
        if (! $this->fkExists('erp_facturas_compra', 'fk_fc_periodo')) {
            DB::statement('ALTER TABLE erp_facturas_compra
                ADD CONSTRAINT fk_fc_periodo FOREIGN KEY (periodo_id) REFERENCES erp_periodos(id)');
        }

        // Permiso + asignación a roles.
        if (! DB::table('erp_permisos')->where('codigo', 'compras.imputar_periodo_cerrado')->exists()) {
            DB::table('erp_permisos')->insert([
                'codigo' => 'compras.imputar_periodo_cerrado',
                'modulo' => 'compras',
                'entidad' => 'factura_compra',
                'accion' => 'imputar_periodo_cerrado',
                'descripcion' => 'Permite imputar facturas de compra a un período ya cerrado.',
                'sensible' => 1,
            ]);
        }
        $permId = DB::table('erp_permisos')->where('codigo', 'compras.imputar_periodo_cerrado')->value('id');
        $roles = DB::table('erp_roles')->whereIn('codigo', ['super_admin', 'contador'])->pluck('id');
        foreach ($roles as $rolId) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $rolId, 'permiso_id' => $permId],
                ['rol_id' => $rolId, 'permiso_id' => $permId]
            );
        }
    }

    public function down(): void
    {
        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (
            SELECT id FROM erp_permisos WHERE codigo = 'compras.imputar_periodo_cerrado'
        )");
        DB::statement("DELETE FROM erp_permisos WHERE codigo = 'compras.imputar_periodo_cerrado'");

        try { DB::statement('ALTER TABLE erp_facturas_compra DROP FOREIGN KEY fk_fc_periodo'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_facturas_compra DROP CHECK chk_fecha_imputacion'); } catch (\Throwable) {}
        foreach (['idx_fc_periodo', 'idx_fecha_imputacion'] as $idx) {
            try { DB::statement("ALTER TABLE erp_facturas_compra DROP INDEX {$idx}"); } catch (\Throwable) {}
        }
        foreach (['imputacion_diferida', 'periodo_id', 'fecha_imputacion'] as $col) {
            try { DB::statement("ALTER TABLE erp_facturas_compra DROP COLUMN {$col}"); } catch (\Throwable) {}
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }
    private function indexExists(string $table, string $index): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        );
    }
    private function fkExists(string $table, string $fk): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = "FOREIGN KEY"',
            [$table, $fk]
        );
    }
    private function checkExists(string $table, string $name): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = "CHECK"',
            [$table, $name]
        );
    }
};
