<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido 2 (testeo Matías 14/07) — la grilla mensual opera en IMPORTES:
 * el tesorero fija Formal($) y/o MT($) por empleado por liquidación y el
 * efectivo es el residual. NULL = se usa el reparto % (override o maestro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_emp_liquidacion_reparto_override', function (Blueprint $table) {
            $table->decimal('monto_formal', 18, 2)->nullable()->after('porc_mt');
            $table->decimal('monto_mt', 18, 2)->nullable()->after('monto_formal');
        });
    }

    public function down(): void
    {
        Schema::table('erp_emp_liquidacion_reparto_override', function (Blueprint $table) {
            $table->dropColumn(['monto_formal', 'monto_mt']);
        });
    }
};
