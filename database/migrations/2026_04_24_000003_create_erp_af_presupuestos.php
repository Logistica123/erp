<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC 06 — Activos Fijos + Presupuestos (Fase 7).
 *
 * I1 entrega:
 *   • erp_af_categorias, erp_af_bienes, erp_af_movimientos.
 *   • Tablas auxiliares definidas vacías para bloques siguientes:
 *     erp_af_amortizaciones (I2), erp_af_reexpresiones (I3),
 *     erp_presupuestos, erp_presupuesto_items, erp_presupuesto_versiones (I4).
 *   • ALTERs en erp_facturas_compra (af_activado, af_bienes_ids) idempotentes
 *     vía PHP — MySQL 8.0 no soporta ADD COLUMN IF NOT EXISTS.
 *
 * Seed: 8 categorías AF base + 14 permisos del módulo + asignaciones a
 * super_admin / contador / direccion / revisor_fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('migrations/sql/');
        DB::unprepared(file_get_contents($path.'06_af_presupuestos.sql'));

        // ALTERs idempotentes vía information_schema (mismo patrón que H1).
        $this->addColumnIfMissing('erp_facturas_compra', 'af_activado',
            "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'TRUE si la factura generó alta de bienes en erp_af_bienes'");
        $this->addColumnIfMissing('erp_facturas_compra', 'af_bienes_ids',
            "JSON NULL COMMENT 'IDs de los bienes generados al activar esta factura'");

        DB::unprepared(file_get_contents($path.'06_af_seed.sql'));
    }

    public function down(): void
    {
        $tables = [
            'erp_presupuesto_versiones',
            'erp_presupuesto_items',
            'erp_presupuestos',
            'erp_af_reexpresiones',
            'erp_af_amortizaciones',
            'erp_af_movimientos',
            'erp_af_bienes',
            'erp_af_categorias',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t}");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        try {
            DB::statement('ALTER TABLE erp_facturas_compra DROP COLUMN IF EXISTS af_activado');
            DB::statement('ALTER TABLE erp_facturas_compra DROP COLUMN IF EXISTS af_bienes_ids');
        } catch (\Throwable) {
            // Best effort.
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $exists = DB::selectOne(
            "SELECT 1 AS x FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        if (! $exists) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
};
