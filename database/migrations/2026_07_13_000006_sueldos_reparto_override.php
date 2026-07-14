<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workstream Sueldos Bloque 2 — G-07 (decisión P1): la composición % del
 * maestro es el DEFAULT; en cada liquidación el tesorero puede pisar el
 * reparto FORMAL/EFECTIVO/MT empleado por empleado sin tocar el maestro
 * (el "algunos meses ajusto el reparto" del Excel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_emp_liquidacion_reparto_override', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_id')->constrained('erp_emp_liquidaciones')->cascadeOnDelete();
            $table->foreignId('empleado_id')->constrained('erp_emp_empleados');
            $table->decimal('porc_formal', 5, 2)->default(0);
            $table->decimal('porc_efectivo', 5, 2)->default(0);
            $table->decimal('porc_mt', 5, 2)->default(0);
            $table->string('observaciones', 300)->nullable();
            $table->foreignId('creado_por_id')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['liquidacion_id', 'empleado_id'], 'uq_emp_reparto_liq_emp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_emp_liquidacion_reparto_override');
    }
};
