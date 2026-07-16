<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calendario de cobros (pedido Francisco 2026-07-15):
 *  - plazo_cobro_dias por cliente (erp_auxiliares): días estimados entre
 *    la FECHA DE FACTURA y el cobro real, editables desde el panel.
 *  - 2 permisos nuevos con asignaciones (patrón item 8).
 */
return new class extends Migration
{
    private const PERMISOS = [
        ['tesoreria.calendario_cobros.ver', 'tesoreria', 'calendario_cobros', 'ver', 0],
        ['tesoreria.plazos_cobro.editar', 'tesoreria', 'plazos_cobro', 'editar', 0],
    ];

    private const ASIGNACIONES = [
        'auditor' => ['tesoreria.calendario_cobros.ver'],
        'contador' => ['tesoreria.calendario_cobros.ver', 'tesoreria.plazos_cobro.editar'],
        'direccion' => ['tesoreria.calendario_cobros.ver'],
        'tesorero' => ['tesoreria.calendario_cobros.ver', 'tesoreria.plazos_cobro.editar'],
        'super_admin' => ['tesoreria.calendario_cobros.ver', 'tesoreria.plazos_cobro.editar'],
    ];

    public function up(): void
    {
        Schema::table('erp_auxiliares', function (Blueprint $table) {
            $table->unsignedSmallInteger('plazo_cobro_dias')->nullable()->after('activo')
                ->comment('Días estimados de cobro desde la fecha de factura (solo tipo Cliente)');
        });

        foreach (self::PERMISOS as [$codigo, $modulo, $entidad, $accion, $sensible]) {
            if (! DB::table('erp_permisos')->where('codigo', $codigo)->exists()) {
                DB::table('erp_permisos')->insert([
                    'codigo' => $codigo, 'modulo' => $modulo, 'entidad' => $entidad,
                    'accion' => $accion, 'sensible' => $sensible,
                    'descripcion' => 'Calendario de cobros (2026-07-15)',
                ]);
            }
        }
        foreach (self::ASIGNACIONES as $rol => $permisos) {
            $rolId = DB::table('erp_roles')->where('codigo', $rol)->value('id');
            if (! $rolId) {
                continue;
            }
            foreach ($permisos as $p) {
                $pid = DB::table('erp_permisos')->where('codigo', $p)->value('id');
                if ($pid && ! DB::table('erp_rol_permiso')->where('rol_id', $rolId)->where('permiso_id', $pid)->exists()) {
                    DB::table('erp_rol_permiso')->insert(['rol_id' => $rolId, 'permiso_id' => $pid]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('erp_auxiliares', function (Blueprint $table) {
            $table->dropColumn('plazo_cobro_dias');
        });
        $ids = DB::table('erp_permisos')->whereIn('codigo', array_map(fn ($p) => $p[0], self::PERMISOS))->pluck('id');
        DB::table('erp_rol_permiso')->whereIn('permiso_id', $ids)->delete();
        DB::table('erp_permisos')->whereIn('id', $ids)->delete();
    }
};
