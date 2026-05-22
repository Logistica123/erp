<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.30 D-30-2..D-30-5 — Permiso para el modo "Control" del import de Ventas
 * (compara el archivo de AFIP "Mis Comprobantes" contra lo cargado en el ERP).
 *
 * Diferente del permiso `ventas.libro_iva.importar` (que es para el modo
 * "Introducir datos"): el modo Control NO inserta facturas — solo reporta
 * diferencias. La inserción de los "solo en AFIP" tiene un endpoint aparte que
 * sigue requiriendo `ventas.libro_iva.importar`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('erp_permisos')->updateOrInsert(
            ['codigo' => 'ventas.import.control'],
            [
                'codigo' => 'ventas.import.control',
                'modulo' => 'ventas',
                'entidad' => 'import',
                'accion' => 'control',
                'descripcion' => 'Permite ejecutar el modo "Control" del import de Ventas (compara contra AFIP).',
                'sensible' => 0,
            ],
        );
        $permisoId = DB::table('erp_permisos')->where('codigo', 'ventas.import.control')->value('id');

        $rolesIds = DB::table('erp_roles')
            ->whereIn('codigo', ['super_admin', 'contador', 'facturador'])
            ->pluck('id');
        foreach ($rolesIds as $rolId) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $rolId, 'permiso_id' => $permisoId], [],
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('erp_permisos')->where('codigo', 'ventas.import.control')->value('id');
        if ($id) {
            DB::table('erp_rol_permiso')->where('permiso_id', $id)->delete();
            DB::table('erp_permisos')->where('id', $id)->delete();
        }
    }
};
