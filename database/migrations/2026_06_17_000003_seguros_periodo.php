<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procesamiento de Seguro — período de imputación (mes/año) elegible al cargar.
 * El comprobante mantiene su fecha de emisión original del PDF; el período
 * determina en qué TXT mensual aparece (igual que las facturas de compra de
 * meses anteriores).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_seguros_comprobantes', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_seguros_comprobantes', 'periodo_anio')) {
                $t->unsignedSmallInteger('periodo_anio')->nullable()->after('fecha_imputacion');
                $t->unsignedTinyInteger('periodo_mes')->nullable()->after('periodo_anio');
                $t->index(['empresa_id', 'periodo_anio', 'periodo_mes'], 'idx_seg_periodo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_seguros_comprobantes', function (Blueprint $t) {
            $t->dropIndex('idx_seg_periodo');
            $t->dropColumn(['periodo_anio', 'periodo_mes']);
        });
    }
};
