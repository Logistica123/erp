<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration versionada del paquete de handoff `DDL_01_Fundaciones.sql`.
 *
 * Crea las 14 tablas de la capa de fundaciones del ERP:
 *   erp_empresas, erp_monedas, erp_cotizaciones, erp_ejercicios, erp_periodos,
 *   erp_centros_costo, erp_usuario_perfil, erp_roles, erp_permisos,
 *   erp_rol_permiso, erp_usuario_rol, erp_sesiones, erp_audit_log, erp_config.
 *
 * El SQL vive en database/migrations/sql/01_fundaciones.sql. Es una copia
 * literal del DDL del handoff con un solo parche: la collation `utf8mb4_0900_ai_ci`
 * (MySQL 8 only) se reemplazó por `utf8mb4_unicode_ci` (MariaDB 10.2+ y MySQL 5.7+).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = file_get_contents(database_path('migrations/sql/01_fundaciones.sql'));
        DB::unprepared($sql);
    }

    public function down(): void
    {
        $tables = [
            'erp_config',
            'erp_audit_log',
            'erp_sesiones',
            'erp_usuario_rol',
            'erp_rol_permiso',
            'erp_permisos',
            'erp_roles',
            'erp_usuario_perfil',
            'erp_centros_costo',
            'erp_periodos',
            'erp_ejercicios',
            'erp_cotizaciones',
            'erp_monedas',
            'erp_empresas',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t}");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
};
