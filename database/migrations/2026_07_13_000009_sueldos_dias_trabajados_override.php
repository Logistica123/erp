<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workstream Sueldos Bloque 3 — G-09: "Días Trab." editable por empleado
 * por liquidación (columna E del Excel). Vive en la misma tabla de
 * override por (liquidación, empleado); NULL = automático (30 − faltas,
 * con prorrateo por fecha de ingreso).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_emp_liquidacion_reparto_override', function (Blueprint $table) {
            $table->unsignedSmallInteger('dias_trabajados')->nullable()->after('porc_mt');
        });
    }

    public function down(): void
    {
        Schema::table('erp_emp_liquidacion_reparto_override', function (Blueprint $table) {
            $table->dropColumn('dias_trabajados');
        });
    }
};
