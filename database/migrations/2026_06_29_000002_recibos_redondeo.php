<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recibos — campo de redondeo.
 *
 * Permite ajustar la diferencia de redondeo de la cobranza (admite valores
 * negativos). Se imputa a la cuenta de resultado 5.6.06 "Redondeos": débito si
 * es positivo (pérdida) o crédito si es negativo (ganancia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_recibos', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_recibos', 'redondeo_monto')) {
                $t->decimal('redondeo_monto', 18, 2)->default(0)->after('otro_observacion');
            }
        });

        // Cuenta de resultado para el redondeo (idempotente).
        $padre = DB::table('erp_cuentas_contables')->where('empresa_id', 1)->where('codigo', '5.6')->value('id');
        $existe = DB::table('erp_cuentas_contables')->where('empresa_id', 1)->where('codigo', '5.6.06')->exists();
        if ($padre && ! $existe) {
            DB::table('erp_cuentas_contables')->insert([
                'empresa_id' => 1,
                'codigo' => '5.6.06',
                'codigo_padre_id' => $padre,
                'nivel' => 4,
                'nombre' => 'Redondeos',
                'tipo' => 'RN',
                'rubro_ec' => 'Otros Egresos',
                'imputable' => 1,
                'moneda' => 'ARS',
                'admite_cc' => 0,
                'admite_auxiliar' => 0,
                'saldo_normal' => 'DEUDOR',
                'regularizadora' => 0,
                'activo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('erp_recibos', function (Blueprint $t) {
            if (Schema::hasColumn('erp_recibos', 'redondeo_monto')) {
                $t->dropColumn('redondeo_monto');
            }
        });
        // La cuenta 5.6.06 se conserva (puede tener movimientos).
    }
};
