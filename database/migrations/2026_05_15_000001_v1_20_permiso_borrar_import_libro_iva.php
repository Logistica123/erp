<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.20 — Permiso para borrar uploads del Libro IVA Compras.
 *
 * Solo super_admin. Operación sensible (sensible=1) — borrado físico del
 * upload para liberar el hash SHA256 y permitir re-subir el mismo archivo
 * después de un fix. Snapshot completo queda en erp_audit_log antes del DELETE.
 *
 * El bloqueo natural lo da la FK erp_facturas_compra.import_id (NO ACTION):
 * si el upload generó facturas, el controller responde 409
 * IMPORT_TIENE_ASIENTOS antes de intentar el DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('erp_permisos')->where('codigo', 'compras.libro_iva.borrar_import')->exists()) {
            DB::table('erp_permisos')->insert([
                'codigo' => 'compras.libro_iva.borrar_import',
                'modulo' => 'compras',
                'entidad' => 'libro_iva_compras_import',
                'accion' => 'borrar',
                'descripcion' => 'Permite borrar uploads del import del Libro IVA Compras (solo si no generaron asientos). Operación irreversible con audit log inmutable.',
                'sensible' => 1,
            ]);
        }

        $permId = DB::table('erp_permisos')->where('codigo', 'compras.libro_iva.borrar_import')->value('id');
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
            SELECT id FROM erp_permisos WHERE codigo = 'compras.libro_iva.borrar_import'
        )");
        DB::statement("DELETE FROM erp_permisos WHERE codigo = 'compras.libro_iva.borrar_import'");
    }
};
