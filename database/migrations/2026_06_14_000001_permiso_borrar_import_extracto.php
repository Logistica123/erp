<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso dedicado para borrar un import de extracto bancario completo
 * (super_admin). Distinto de tesoreria.extractos.borrar / .cargar.
 */
return new class extends Migration
{
    private const PERMISO = [
        'codigo' => 'tesoreria.extractos.borrar_import',
        'modulo' => 'tesoreria',
        'entidad' => 'extractos_bancarios',
        'accion' => 'borrar_import',
        'descripcion' => 'Borrar un import de extracto bancario completo (irreversible, super_admin).',
        'sensible' => 1,
    ];

    public function up(): void
    {
        DB::table('erp_permisos')->updateOrInsert(['codigo' => self::PERMISO['codigo']], self::PERMISO);
        $pid = DB::table('erp_permisos')->where('codigo', self::PERMISO['codigo'])->value('id');
        $superAdminId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        if ($pid && $superAdminId) {
            DB::table('erp_rol_permiso')->updateOrInsert(['rol_id' => $superAdminId, 'permiso_id' => $pid], []);
        }
    }

    public function down(): void
    {
        $pid = DB::table('erp_permisos')->where('codigo', self::PERMISO['codigo'])->value('id');
        if ($pid) {
            DB::table('erp_rol_permiso')->where('permiso_id', $pid)->delete();
            DB::table('erp_permisos')->where('id', $pid)->delete();
        }
    }
};
