<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISOS = [
        ['codigo' => 'flujo.ver', 'modulo' => 'tesoreria', 'entidad' => 'flujo_fondos', 'accion' => 'ver',
         'descripcion' => 'Ver flujo de fondos (matriz, escenarios, variance).', 'sensible' => 0],
        ['codigo' => 'flujo.editar_proyectado', 'modulo' => 'tesoreria', 'entidad' => 'flujo_fondos', 'accion' => 'editar',
         'descripcion' => 'Editar valores proyectados (no override).', 'sensible' => 0],
        ['codigo' => 'flujo.override_manual', 'modulo' => 'tesoreria', 'entidad' => 'flujo_fondos', 'accion' => 'override',
         'descripcion' => 'Override manual de celdas con motivo.', 'sensible' => 1],
        ['codigo' => 'flujo.escenarios.administrar', 'modulo' => 'tesoreria', 'entidad' => 'flujo_escenarios', 'accion' => 'abm',
         'descripcion' => 'ABM de escenarios (Realista/Optimista/Pesimista).', 'sensible' => 1],
        ['codigo' => 'flujo.categorias.administrar', 'modulo' => 'tesoreria', 'entidad' => 'flujo_categorias', 'accion' => 'abm',
         'descripcion' => 'ABM de categorías del catálogo F.F.S.', 'sensible' => 1],
        ['codigo' => 'flujo.calendario_cobros.administrar', 'modulo' => 'tesoreria', 'entidad' => 'flujo_calendario', 'accion' => 'abm',
         'descripcion' => 'Configurar calendario de cobros por cliente.', 'sensible' => 0],
    ];

    public function up(): void
    {
        $superAdminId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        foreach (self::PERMISOS as $p) {
            DB::table('erp_permisos')->updateOrInsert(['codigo' => $p['codigo']], $p);
            $permId = DB::table('erp_permisos')->where('codigo', $p['codigo'])->value('id');
            if ($superAdminId && $permId) {
                DB::table('erp_rol_permiso')->updateOrInsert(
                    ['rol_id' => $superAdminId, 'permiso_id' => $permId], [],
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::PERMISOS as $p) {
            $permId = DB::table('erp_permisos')->where('codigo', $p['codigo'])->value('id');
            if ($permId) {
                DB::table('erp_rol_permiso')->where('permiso_id', $permId)->delete();
                DB::table('erp_permisos')->where('id', $permId)->delete();
            }
        }
    }
};
