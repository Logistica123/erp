<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.25 — Neto gravado por alícuota IVA en facturas de compra.
 *
 * El v1.24 sumó las 5 columnas de IVA por alícuota (`imp_iva_21/10_5/27/2_5/5`)
 * pero dejó el neto agregado (`imp_neto_gravado`). El form de carga manual
 * necesita capturar el desglose del neto también — Sebastián trabaja con
 * facturas que mezclan IVA 21% y 10,5% en el mismo comprobante (típico
 * supermercados, productos básicos, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_facturas_compra', function (Blueprint $t) {
            $cols = [
                'imp_neto_gravado_21', 'imp_neto_gravado_10_5', 'imp_neto_gravado_27',
                'imp_neto_gravado_2_5', 'imp_neto_gravado_5',
            ];
            foreach ($cols as $c) {
                if (! Schema::hasColumn('erp_facturas_compra', $c)) {
                    $t->decimal($c, 18, 2)->default(0)->after('imp_iva_5');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_facturas_compra', function (Blueprint $t) {
            foreach ([
                'imp_neto_gravado_21', 'imp_neto_gravado_10_5', 'imp_neto_gravado_27',
                'imp_neto_gravado_2_5', 'imp_neto_gravado_5',
            ] as $c) {
                if (Schema::hasColumn('erp_facturas_compra', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
