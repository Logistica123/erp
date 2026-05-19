<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.45 — Permiso para borrar uploads del Libro IVA Ventas.
 *
 * Mirror del v1.20 (compras.libro_iva.borrar_import). Solo super_admin
 * debería tenerlo: el borrado es irreversible (con audit log inmutable).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('erp_permisos')->updateOrInsert(
            ['codigo' => 'ventas.libro_iva.borrar_import'],
            [
                'modulo' => 'ventas',
                'entidad' => 'libro_iva',
                'accion' => 'borrar_import',
                'descripcion' => 'Permite borrar uploads del import del Libro IVA Ventas (solo si no generaron asientos). Operación irreversible con audit log inmutable.',
                'sensible' => 1,
            ],
        );

        // Asignar al rol super_admin si existe.
        $rolId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        $permId = DB::table('erp_permisos')->where('codigo', 'ventas.libro_iva.borrar_import')->value('id');
        if ($rolId && $permId) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $rolId, 'permiso_id' => $permId],
                [],
            );
        }
    }

    public function down(): void
    {
        $permId = DB::table('erp_permisos')->where('codigo', 'ventas.libro_iva.borrar_import')->value('id');
        if ($permId) {
            DB::table('erp_rol_permiso')->where('permiso_id', $permId)->delete();
            DB::table('erp_permisos')->where('id', $permId)->delete();
        }
    }
};
