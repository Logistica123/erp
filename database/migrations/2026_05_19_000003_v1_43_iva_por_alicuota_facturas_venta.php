<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.43 — Desglose IVA por alícuota en erp_facturas_venta.
 *
 * Mirror del v1.24 (compras): permite cargar el IVA discriminado por
 * alícuota (27/21/10.5/5/2.5) y el neto gravado correspondiente. Necesario
 * para que el F.8001 / Libro IVA Ventas tenga el detalle correcto cuando
 * importamos facturas desde PDF AFIP (el comprobante puede tener varias
 * alícuotas en simultáneo — un solo "IVA agregado" pierde información).
 *
 * Los campos son nullable + default 0 para no romper inserts viejos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_facturas_venta', function (Blueprint $table) {
            $table->decimal('imp_iva_27',  18, 2)->default(0)->after('imp_iva');
            $table->decimal('imp_iva_21',  18, 2)->default(0)->after('imp_iva_27');
            $table->decimal('imp_iva_10_5', 18, 2)->default(0)->after('imp_iva_21');
            $table->decimal('imp_iva_5',   18, 2)->default(0)->after('imp_iva_10_5');
            $table->decimal('imp_iva_2_5', 18, 2)->default(0)->after('imp_iva_5');
            $table->decimal('imp_neto_gravado_27',  18, 2)->default(0)->after('imp_iva_2_5');
            $table->decimal('imp_neto_gravado_21',  18, 2)->default(0)->after('imp_neto_gravado_27');
            $table->decimal('imp_neto_gravado_10_5', 18, 2)->default(0)->after('imp_neto_gravado_21');
            $table->decimal('imp_neto_gravado_5',   18, 2)->default(0)->after('imp_neto_gravado_10_5');
            $table->decimal('imp_neto_gravado_2_5', 18, 2)->default(0)->after('imp_neto_gravado_5');
        });
    }

    public function down(): void
    {
        Schema::table('erp_facturas_venta', function (Blueprint $table) {
            $table->dropColumn([
                'imp_iva_27', 'imp_iva_21', 'imp_iva_10_5', 'imp_iva_5', 'imp_iva_2_5',
                'imp_neto_gravado_27', 'imp_neto_gravado_21', 'imp_neto_gravado_10_5',
                'imp_neto_gravado_5', 'imp_neto_gravado_2_5',
            ]);
        });
    }
};
