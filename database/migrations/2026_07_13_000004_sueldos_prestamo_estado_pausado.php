<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Workstream Sueldos Bloque 1 — G-08 (decisión P4): estado PAUSADO para
 * préstamos de empleados (congela cuota sin perder el registro).
 * Lección conocida: los ENUM MySQL exigen ALTER antes de usar el valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE erp_emp_prestamos MODIFY estado
            ENUM('VIGENTE','CANCELADO','REFINANCIADO','BAJA','PAUSADO') NOT NULL DEFAULT 'VIGENTE'");
    }

    public function down(): void
    {
        // Reubicar cualquier PAUSADO antes de achicar el enum.
        DB::table('erp_emp_prestamos')->where('estado', 'PAUSADO')->update(['estado' => 'VIGENTE']);
        DB::statement("ALTER TABLE erp_emp_prestamos MODIFY estado
            ENUM('VIGENTE','CANCELADO','REFINANCIADO','BAJA') NOT NULL DEFAULT 'VIGENTE'");
    }
};
