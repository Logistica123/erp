<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso para el borrado DEFINITIVO de asientos contables (hard-delete).
 *
 * Opción C acordada: el borrado existe pero NUNCA sin traza. Requiere
 * super_admin + MFA fresh + motivo, y deja audit log inmutable con snapshot
 * completo del asiento + sus movimientos + las FKs liberadas. sensible=2.
 *
 * Para asientos contabilizados, el camino normal sigue siendo ANULAR (reversa).
 * Este permiso es para limpiar datos de prueba / errores graves de setup.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('erp_permisos')->updateOrInsert(
            ['codigo' => 'contabilidad.asientos.eliminar_definitivo'],
            [
                'codigo' => 'contabilidad.asientos.eliminar_definitivo',
                'modulo' => 'contabilidad',
                'entidad' => 'asientos',
                'accion' => 'eliminar_definitivo',
                'descripcion' => 'Borra físicamente un asiento (incluso contabilizado) con audit log inmutable. MUY SENSIBLE — solo super_admin. Lo normal es ANULAR.',
                'sensible' => 2,
            ],
        );

        $superAdminId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        $permId = DB::table('erp_permisos')->where('codigo', 'contabilidad.asientos.eliminar_definitivo')->value('id');
        if ($superAdminId && $permId) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $superAdminId, 'permiso_id' => $permId], [],
            );
        }
    }

    public function down(): void
    {
        $permId = DB::table('erp_permisos')->where('codigo', 'contabilidad.asientos.eliminar_definitivo')->value('id');
        if ($permId) {
            DB::table('erp_rol_permiso')->where('permiso_id', $permId)->delete();
            DB::table('erp_permisos')->where('id', $permId)->delete();
        }
    }
};
