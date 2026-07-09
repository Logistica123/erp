<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recibos — separar fecha de emisión y fecha de cobro.
 *
 * fecha_emision = fecha en que se emite el recibo (hoy, no editable).
 * fecha_cobro   = fecha real del cobro (editable, ≤ emisión). Es la fecha
 *                 económica que usa el asiento contable.
 *
 * Backfill: los recibos existentes toman fecha_cobro = fecha_emision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_recibos', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_recibos', 'fecha_cobro')) {
                $t->date('fecha_cobro')->nullable()->after('fecha_emision');
            }
        });
        DB::statement('UPDATE erp_recibos SET fecha_cobro = fecha_emision WHERE fecha_cobro IS NULL');
    }

    public function down(): void
    {
        Schema::table('erp_recibos', function (Blueprint $t) {
            if (Schema::hasColumn('erp_recibos', 'fecha_cobro')) {
                $t->dropColumn('fecha_cobro');
            }
        });
    }
};
