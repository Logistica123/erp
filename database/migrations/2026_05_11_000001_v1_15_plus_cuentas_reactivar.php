<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.15 Sprint L+ — Reactivar cuentas inactivas.
 *
 * ALTER erp_cuentas_contables: agregar `reactivada_at`, `reactivada_por`.
 * (eliminada_at + eliminada_por ya están desde el Sprint L base.)
 *
 * El permiso `contabilidad.cuentas.eliminar` se reutiliza para reactivar
 * (D-PC-7: "quien puede eliminar puede reactivar").
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addCol('erp_cuentas_contables', 'reactivada_at',
            "DATETIME NULL COMMENT 'v1.15 Sprint L+ — timestamp de la reactivación post soft-delete.'");
        $this->addCol('erp_cuentas_contables', 'reactivada_por',
            "BIGINT UNSIGNED NULL COMMENT 'FK a users.id — quién reactivó la cuenta.'");

        if (! $this->fkExists('erp_cuentas_contables', 'fk_cc_reactivada_por')) {
            DB::statement('ALTER TABLE erp_cuentas_contables
                ADD CONSTRAINT fk_cc_reactivada_por FOREIGN KEY (reactivada_por)
                REFERENCES users(id)');
        }
    }

    public function down(): void
    {
        try { DB::statement('ALTER TABLE erp_cuentas_contables DROP FOREIGN KEY fk_cc_reactivada_por'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_cuentas_contables DROP COLUMN reactivada_por'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_cuentas_contables DROP COLUMN reactivada_at'); } catch (\Throwable) {}
    }

    private function addCol(string $table, string $column, string $definition): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$table, $column]
        );
        if (! $exists) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function fkExists(string $table, string $fk): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE="FOREIGN KEY"',
            [$table, $fk]
        );
    }
};
