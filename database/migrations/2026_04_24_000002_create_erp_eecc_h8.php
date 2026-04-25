<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC 05 H8 — tablas para EECC profesionales:
 *   erp_eecc_notas: notas estándar editadas manualmente (RN-62).
 *   erp_eecc_emisiones: histórico de paquetes EECC generados.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('migrations/sql/05_impuestos_h8_eecc.sql')));
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS erp_eecc_emisiones');
        DB::statement('DROP TABLE IF EXISTS erp_eecc_notas');
    }
};
