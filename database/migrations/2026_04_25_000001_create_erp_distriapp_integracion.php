<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC 07 bloque 7A — Tablas puente Integración DistriApp.
 *
 * Crea las dos tablas que viven del lado ERP:
 *   • erp_distriapp_ref: índice invertido DistriApp → ERP, idempotente vía UK.
 *   • erp_integracion_log: auditoría de cada corrida de reconciliación.
 *
 * Las vistas erp_v_* (DDL_07 secciones B) se aplican aparte porque dependen
 * del schema de DistriApp (basepersonal.liq_*) que aún no está completo.
 *
 * Seed: 7 permisos integracion.* + asignaciones a super_admin, contador,
 * tesorero, facturador, auditor, direccion, revisor_fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('migrations/sql/');
        DB::unprepared(file_get_contents($path.'07_integracion_tables.sql'));
        DB::unprepared(file_get_contents($path.'07_integracion_seed.sql'));
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::statement('DROP TABLE IF EXISTS erp_integracion_log');
        DB::statement('DROP TABLE IF EXISTS erp_distriapp_ref');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (SELECT id FROM erp_permisos WHERE modulo='integracion')");
        DB::statement("DELETE FROM erp_permisos WHERE modulo='integracion'");
    }
};
