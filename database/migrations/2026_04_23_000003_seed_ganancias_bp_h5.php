<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC 05 H5 — Seed de escala Ganancias art 73, alícuota BP y catálogo de
 * ajustes fiscales típicos. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('migrations/sql/05_impuestos_h5_seed.sql')));
    }

    public function down(): void
    {
        DB::table('erp_ganancias_escala')->where('vigente_desde', '2024-01-01')->delete();
        DB::table('erp_bp_alicuotas')->where('vigente_desde', '2024-01-01')->delete();
        DB::table('erp_ganancias_ajustes_tipo')->whereIn('codigo', [
            'MULTAS_SANCIONES', 'INTERES_PUNITORIO_EXCESO', 'AMORT_CONTABLES_EN_EXCESO',
            'PREVISIONES_NO_DEDUCIBLES', 'HONORARIOS_DIRECTORES_EXC',
            'AMORT_FISCALES_EN_EXCESO', 'EXENCIONES', 'AJUSTE_INFLACION_IMPOS',
        ])->delete();
    }
};
