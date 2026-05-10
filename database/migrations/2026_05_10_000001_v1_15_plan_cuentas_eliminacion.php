<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.15 Sprint L — soft delete de cuentas del plan + permiso.
 *
 *   1. ALTER erp_cuentas_contables: +eliminada_at, +eliminada_por.
 *   2. INSERT permiso `contabilidad.cuentas.eliminar` (sensible=1) +
 *      asignación a super_admin + contador.
 *
 * El soft delete se implementa con `activo=0`. Las columnas nuevas son
 * solo audit (cuándo + quién). Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addCol('erp_cuentas_contables', 'eliminada_at',
            "DATETIME NULL COMMENT 'v1.15 Sprint L — timestamp del soft delete (activo=0).'");
        $this->addCol('erp_cuentas_contables', 'eliminada_por',
            "BIGINT UNSIGNED NULL COMMENT 'FK a users.id — quién hizo el soft delete.'");

        if (! $this->fkExists('erp_cuentas_contables', 'fk_cc_eliminada_por')) {
            DB::statement('ALTER TABLE erp_cuentas_contables
                ADD CONSTRAINT fk_cc_eliminada_por FOREIGN KEY (eliminada_por)
                REFERENCES users(id)');
        }

        if (! DB::table('erp_permisos')->where('codigo', 'contabilidad.cuentas.eliminar')->exists()) {
            DB::table('erp_permisos')->insert([
                'codigo' => 'contabilidad.cuentas.eliminar',
                'modulo' => 'contabilidad',
                'entidad' => 'cuentas',
                'accion' => 'eliminar',
                'descripcion' => 'Permite desactivar cuentas del plan de cuentas (soft delete).',
                'sensible' => 1,
            ]);
        }

        $permId = DB::table('erp_permisos')->where('codigo', 'contabilidad.cuentas.eliminar')->value('id');
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
            SELECT id FROM erp_permisos WHERE codigo = 'contabilidad.cuentas.eliminar')");
        DB::statement("DELETE FROM erp_permisos WHERE codigo = 'contabilidad.cuentas.eliminar'");
        try { DB::statement('ALTER TABLE erp_cuentas_contables DROP FOREIGN KEY fk_cc_eliminada_por'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_cuentas_contables DROP COLUMN eliminada_por'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_cuentas_contables DROP COLUMN eliminada_at'); } catch (\Throwable) {}
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
