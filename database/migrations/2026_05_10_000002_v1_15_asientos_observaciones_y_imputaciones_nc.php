<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.15 Sprint M + O — DDL combinado:
 *
 *   1. ALTER erp_asientos +observaciones (TEXT NULL).
 *   2. INSERT permiso `contabilidad.asientos.eliminar_borrador` (sensible=0)
 *      asignado a super_admin + contador + asistente_contable.
 *   3. CREATE erp_imputaciones_nc (Sprint O · imputación de Notas de Crédito).
 *   4. INSERT permiso `tesoreria.nc.imputar` asignado a super_admin + contador.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----- 1. ALTER erp_asientos +observaciones -------------------------
        $this->addCol('erp_asientos', 'observaciones',
            "TEXT NULL COMMENT 'v1.15 Sprint M — texto libre detallado, separado de glosa.'");

        // ----- 2. Permiso eliminar_borrador ---------------------------------
        if (! DB::table('erp_permisos')->where('codigo', 'contabilidad.asientos.eliminar_borrador')->exists()) {
            DB::table('erp_permisos')->insert([
                'codigo' => 'contabilidad.asientos.eliminar_borrador',
                'modulo' => 'contabilidad',
                'entidad' => 'asientos',
                'accion' => 'eliminar_borrador',
                'descripcion' => 'Permite eliminar asientos en estado BORRADOR.',
                'sensible' => 0,
            ]);
        }
        $permEliminarId = DB::table('erp_permisos')->where('codigo', 'contabilidad.asientos.eliminar_borrador')->value('id');
        $rolesEliminar = DB::table('erp_roles')
            ->whereIn('codigo', ['super_admin', 'contador', 'asistente_contable'])
            ->pluck('id');
        foreach ($rolesEliminar as $rolId) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $rolId, 'permiso_id' => $permEliminarId],
                ['rol_id' => $rolId, 'permiso_id' => $permEliminarId]
            );
        }

        // ----- 3. CREATE erp_imputaciones_nc --------------------------------
        if (! $this->tableExists('erp_imputaciones_nc')) {
            DB::statement("CREATE TABLE erp_imputaciones_nc (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                empresa_id BIGINT UNSIGNED NOT NULL,
                nc_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a erp_facturas_venta de tipo NC',
                factura_id BIGINT UNSIGNED NOT NULL COMMENT 'FK a erp_facturas_venta tipo factura/ND',
                importe DECIMAL(18,2) NOT NULL,
                fecha_imputacion DATE NOT NULL DEFAULT (CURRENT_DATE),
                imputado_por BIGINT UNSIGNED NOT NULL,
                imputado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                observaciones TEXT NULL,
                INDEX idx_imp_nc (nc_id),
                INDEX idx_imp_factura (factura_id),
                INDEX idx_imp_empresa_fecha (empresa_id, fecha_imputacion),
                CONSTRAINT fk_imputnc_nc FOREIGN KEY (nc_id) REFERENCES erp_facturas_venta(id),
                CONSTRAINT fk_imputnc_factura FOREIGN KEY (factura_id) REFERENCES erp_facturas_venta(id),
                CONSTRAINT fk_imputnc_user FOREIGN KEY (imputado_por) REFERENCES users(id),
                CONSTRAINT fk_imputnc_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // ----- 4. Permiso tesoreria.nc.imputar ------------------------------
        if (! DB::table('erp_permisos')->where('codigo', 'tesoreria.nc.imputar')->exists()) {
            DB::table('erp_permisos')->insert([
                'codigo' => 'tesoreria.nc.imputar',
                'modulo' => 'tesoreria',
                'entidad' => 'nc',
                'accion' => 'imputar',
                'descripcion' => 'Permite imputar Notas de Crédito a facturas/notas de débito.',
                'sensible' => 0,
            ]);
        }
        $permImputarId = DB::table('erp_permisos')->where('codigo', 'tesoreria.nc.imputar')->value('id');
        $rolesImputar = DB::table('erp_roles')
            ->whereIn('codigo', ['super_admin', 'contador', 'tesorero'])
            ->pluck('id');
        foreach ($rolesImputar as $rolId) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $rolId, 'permiso_id' => $permImputarId],
                ['rol_id' => $rolId, 'permiso_id' => $permImputarId]
            );
        }
    }

    public function down(): void
    {
        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (
            SELECT id FROM erp_permisos WHERE codigo IN ('contabilidad.asientos.eliminar_borrador', 'tesoreria.nc.imputar'))");
        DB::statement("DELETE FROM erp_permisos WHERE codigo IN ('contabilidad.asientos.eliminar_borrador', 'tesoreria.nc.imputar')");
        DB::statement('DROP TABLE IF EXISTS erp_imputaciones_nc');
        try { DB::statement('ALTER TABLE erp_asientos DROP COLUMN observaciones'); } catch (\Throwable) {}
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

    private function tableExists(string $table): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$table]
        );
    }
};
