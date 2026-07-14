<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Workstream Sueldos Bloque 2 — G-02: bajo el Camino A (bolsillo) el SAC
 * se paga con el MISMO reparto FORMAL/EFECTIVO/MT que el sueldo del
 * empleado. El seed original lo marcaba solo-FORMAL, con lo cual un
 * empleado 100% efectivo quedaba sin aguinaldo (drop silencioso del
 * descomponer).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('erp_emp_conceptos')->where('codigo', 'SAC')
            ->update(['afecta_formal' => 1, 'afecta_efectivo' => 1, 'afecta_mt' => 1]);
    }

    public function down(): void
    {
        DB::table('erp_emp_conceptos')->where('codigo', 'SAC')
            ->update(['afecta_formal' => 1, 'afecta_efectivo' => 0, 'afecta_mt' => 0]);
    }
};
