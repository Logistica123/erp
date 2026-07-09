<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Descuento de cheques — concepto Percepción de IVA (→ 1.1.6.04). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_cheques_recibidos', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_cheques_recibidos', 'descuento_percepcion_iva')) {
                $t->decimal('descuento_percepcion_iva', 18, 2)->nullable()->after('descuento_sellado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_cheques_recibidos', function (Blueprint $t) {
            if (Schema::hasColumn('erp_cheques_recibidos', 'descuento_percepcion_iva')) {
                $t->dropColumn('descuento_percepcion_iva');
            }
        });
    }
};
