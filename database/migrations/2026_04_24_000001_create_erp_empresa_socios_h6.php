<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC 05 H6 — Tabla erp_empresa_socios (RN-58).
 * Necesaria para calcular BP F.2000 (la sociedad paga por sus socios sobre
 * el valor patrimonial proporcional al 31/12 del ejercicio).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('migrations/sql/05_impuestos_h6_socios.sql')));
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS erp_empresa_socios');
    }
};
