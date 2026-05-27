<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso para el borrado masivo de facturas de venta (excepto WSFE_ERP).
 *
 * El borrado masivo NUNCA toca facturas emitidas por el propio ERP vía Web
 * Service (origen=WSFE_ERP) — esas son fiscales/inmutables. Tampoco las que
 * estén referenciadas por recibos/cobros/imputaciones NC/Libro IVA (se omiten
 * y reportan). sensible=1, super_admin + contador.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('erp_permisos')->updateOrInsert(
            ['codigo' => 'ventas.facturas.borrar_masivo'],
            [
                'codigo' => 'ventas.facturas.borrar_masivo',
                'modulo' => 'ventas',
                'entidad' => 'facturas',
                'accion' => 'borrar_masivo',
                'descripcion' => 'Borra en masa facturas de venta seleccionadas EXCEPTO las emitidas por Web Service (WSFE_ERP) y las referenciadas por recibos/cobros/imputaciones/Libro IVA.',
                'sensible' => 1,
            ],
        );

        $permId = DB::table('erp_permisos')->where('codigo', 'ventas.facturas.borrar_masivo')->value('id');
        $rolesIds = DB::table('erp_roles')->whereIn('codigo', ['super_admin', 'contador'])->pluck('id');
        foreach ($rolesIds as $rolId) {
            DB::table('erp_rol_permiso')->updateOrInsert(['rol_id' => $rolId, 'permiso_id' => $permId], []);
        }
    }

    public function down(): void
    {
        $permId = DB::table('erp_permisos')->where('codigo', 'ventas.facturas.borrar_masivo')->value('id');
        if ($permId) {
            DB::table('erp_rol_permiso')->where('permiso_id', $permId)->delete();
            DB::table('erp_permisos')->where('id', $permId)->delete();
        }
    }
};
