<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.22 — Soporte para estados nuevos (OK_CON_WARNINGS, ERROR_TOTAL)
 * y conteo/detalle de warnings en el import del Libro IVA Compras.
 *
 * `COMPLETO` se mantiene como sinónimo de OK para compat con uploads viejos —
 * código nuevo lo trata como OK sin warnings.
 * `PARCIAL` queda deprecado pero presente para los uploads históricos (el
 * código v1.22 nunca asigna ese valor: o es OK / OK_CON_WARNINGS / ERROR_TOTAL).
 *
 * Permiso `compras.facturas.borrar_masivo` (§13) para el bulk delete de
 * facturas de compra con asientos, solo super_admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE erp_libros_iva_compras_import
             MODIFY COLUMN estado
                 ENUM('PROCESANDO','COMPLETO','PARCIAL','ERROR','OK_CON_WARNINGS','ERROR_TOTAL')
                 NOT NULL DEFAULT 'PROCESANDO'"
        );

        if (! $this->columnExists('erp_libros_iva_compras_import', 'warnings_count')) {
            DB::statement(
                'ALTER TABLE erp_libros_iva_compras_import
                 ADD COLUMN warnings_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER filas_error'
            );
        }
        if (! $this->columnExists('erp_libros_iva_compras_import', 'warnings_detalle')) {
            DB::statement(
                'ALTER TABLE erp_libros_iva_compras_import
                 ADD COLUMN warnings_detalle JSON NULL AFTER errores_detalle'
            );
        }

        // §13 — permiso para bulk delete de facturas de compra.
        if (! DB::table('erp_permisos')->where('codigo', 'compras.facturas.borrar_masivo')->exists()) {
            DB::table('erp_permisos')->insert([
                'codigo' => 'compras.facturas.borrar_masivo',
                'modulo' => 'compras',
                'entidad' => 'factura_compra',
                'accion' => 'borrar_masivo',
                'descripcion' => 'Permite borrar facturas de compra masivamente (incluyendo asientos contabilizados) en período ABIERTO. Operación irreversible con audit log.',
                'sensible' => 1,
            ]);
        }
        $permId = DB::table('erp_permisos')->where('codigo', 'compras.facturas.borrar_masivo')->value('id');
        $rolId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        if ($permId && $rolId) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $rolId, 'permiso_id' => $permId],
                ['rol_id' => $rolId, 'permiso_id' => $permId],
            );
        }
    }

    public function down(): void
    {
        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (
            SELECT id FROM erp_permisos WHERE codigo = 'compras.facturas.borrar_masivo'
        )");
        DB::statement("DELETE FROM erp_permisos WHERE codigo = 'compras.facturas.borrar_masivo'");

        if ($this->columnExists('erp_libros_iva_compras_import', 'warnings_detalle')) {
            DB::statement('ALTER TABLE erp_libros_iva_compras_import DROP COLUMN warnings_detalle');
        }
        if ($this->columnExists('erp_libros_iva_compras_import', 'warnings_count')) {
            DB::statement('ALTER TABLE erp_libros_iva_compras_import DROP COLUMN warnings_count');
        }
        DB::statement(
            "ALTER TABLE erp_libros_iva_compras_import
             MODIFY COLUMN estado
                 ENUM('PROCESANDO','COMPLETO','PARCIAL','ERROR')
                 NOT NULL DEFAULT 'PROCESANDO'"
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT 1 AS found FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
        return $row !== null;
    }
};
