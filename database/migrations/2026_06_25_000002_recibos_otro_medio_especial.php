<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recibos — campo "Otro" (medio/compensación especial).
 *
 * Permite registrar un importe extra que "suma" a lo cobrado (igual que una
 * retención) cuando el cobro se hizo por un medio especial / compensación
 * especial. Contablemente debita la cuenta puente 1.1.6.99 "Pendientes de
 * Identificar" (a reclasificar luego) y cancela deudor por ese monto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_recibos', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_recibos', 'otro_monto')) {
                $t->decimal('otro_monto', 18, 2)->default(0)->after('retencion_ganancias_total');
                $t->string('otro_observacion', 255)->nullable()->after('otro_monto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_recibos', function (Blueprint $t) {
            foreach (['otro_monto', 'otro_observacion'] as $c) {
                if (Schema::hasColumn('erp_recibos', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
