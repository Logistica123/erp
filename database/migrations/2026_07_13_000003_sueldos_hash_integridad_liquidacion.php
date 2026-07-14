<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workstream Sueldos Bloque 1 — G-03: hash de integridad del cierre.
 * SHA-256 del snapshot (cabecera + ítems) sellado al APROBAR, patrón
 * RN-6 de asientos. NULL para liquidaciones aún no aprobadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_emp_liquidaciones', function (Blueprint $table) {
            $table->char('hash_integridad', 64)->nullable()->after('asiento_id')
                ->comment('SHA-256 del snapshot al aprobar (G-03, patrón RN-6)');
        });
    }

    public function down(): void
    {
        Schema::table('erp_emp_liquidaciones', function (Blueprint $table) {
            $table->dropColumn('hash_integridad');
        });
    }
};
