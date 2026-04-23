<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC 05 H3 — Seed de regímenes de retención operativos para Logística
 * Argentina SRL (RG 2854 IVA, RG 830 GAN, AGIP CABA, ARBA PBA).
 * Idempotente: usa INSERT IGNORE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('migrations/sql/05_impuestos_h3_seed.sql')));
    }

    public function down(): void
    {
        DB::table('erp_regimenes_retencion')
            ->whereIn('codigo', ['001', '002', '003', '116', '118', '78', '79'])
            ->delete();
    }
};
