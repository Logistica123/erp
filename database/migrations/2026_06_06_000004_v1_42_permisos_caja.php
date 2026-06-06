<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.42 Fase A — Permisos para caja efectivo + arqueos + operadores.
 */
return new class extends Migration
{
    private const PERMISOS = [
        [
            'codigo' => 'tesoreria.caja.arqueo.registrar',
            'modulo' => 'tesoreria', 'entidad' => 'arqueo_caja', 'accion' => 'registrar',
            'descripcion' => 'Registrar arqueo de caja (operador autorizado en la lista de operadores).',
            'sensible' => 0,
        ],
        [
            'codigo' => 'tesoreria.caja.arqueo.autorizar',
            'modulo' => 'tesoreria', 'entidad' => 'arqueo_caja', 'accion' => 'autorizar',
            'descripcion' => 'Autorizar/rechazar arqueo en PENDIENTE_AUTORIZACION (diferencia > tolerancia).',
            'sensible' => 1,
        ],
        [
            'codigo' => 'tesoreria.caja.operadores.abm',
            'modulo' => 'tesoreria', 'entidad' => 'cajas_operadores', 'accion' => 'abm',
            'descripcion' => 'Alta/baja de operadores autorizados en cada caja.',
            'sensible' => 1,
        ],
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
