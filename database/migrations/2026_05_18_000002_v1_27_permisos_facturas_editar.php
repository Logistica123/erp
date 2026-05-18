<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.27 — Permisos compras/ventas.facturas.editar para edición post-carga
 * del campo periodo_trabajado_texto (D-26-8).
 *
 * No-sensible: el periodo_trabajado es metadato analítico, no contable —
 * no requiere MFA fresh ni restricción de período cerrado (D-26-9).
 */
return new class extends Migration
{
    public function up(): void
    {
        $permisos = [
            ['compras.facturas.editar', 'compras', 'factura_compra',
                'Permite editar campos analíticos (período trabajado, jurisdicción) de facturas de compra post-carga.'],
            ['ventas.facturas.editar', 'ventas', 'factura_venta',
                'Permite editar campos analíticos (período trabajado, jurisdicción) de facturas de venta post-carga.'],
        ];

        foreach ($permisos as [$codigo, $modulo, $entidad, $desc]) {
            if (! DB::table('erp_permisos')->where('codigo', $codigo)->exists()) {
                DB::table('erp_permisos')->insert([
                    'codigo' => $codigo,
                    'modulo' => $modulo,
                    'entidad' => $entidad,
                    'accion' => 'editar',
                    'descripcion' => $desc,
                    'sensible' => 0,
                ]);
            }
            $permId = DB::table('erp_permisos')->where('codigo', $codigo)->value('id');
            $roles = DB::table('erp_roles')->whereIn('codigo', ['super_admin', 'contador'])->pluck('id');
            foreach ($roles as $rolId) {
                DB::table('erp_rol_permiso')->updateOrInsert(
                    ['rol_id' => $rolId, 'permiso_id' => $permId],
                    ['rol_id' => $rolId, 'permiso_id' => $permId],
                );
            }
        }
    }

    public function down(): void
    {
        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (
            SELECT id FROM erp_permisos WHERE codigo IN ('compras.facturas.editar', 'ventas.facturas.editar')
        )");
        DB::statement("DELETE FROM erp_permisos WHERE codigo IN ('compras.facturas.editar', 'ventas.facturas.editar')");
    }
};
