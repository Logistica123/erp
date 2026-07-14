<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workstream Sueldos Bloque 2 — decisión P9: código visible de préstamo
 * (PR-001…) para conservar los IDs del Excel al importar y para hablar
 * con los empleados ("PR-004" en vez de "préstamo #47").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_emp_prestamos', function (Blueprint $table) {
            $table->string('codigo', 15)->nullable()->unique('uq_emp_prestamo_codigo')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('erp_emp_prestamos', function (Blueprint $table) {
            $table->dropUnique('uq_emp_prestamo_codigo');
            $table->dropColumn('codigo');
        });
    }
};
