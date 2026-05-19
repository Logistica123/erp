<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * v1.39 — Adjuntar PDF original de AFIP a facturas de venta cargadas a mano.
 *
 * Caso de uso: al importar las ventas históricas (PDFs descargados del portal
 * AFIP), guardamos el PDF como adjunto de cada `erp_facturas_venta` para que
 * después de cargada se pueda reabrir y verificar contra el original.
 *
 * Path apuntado: `private/facturas-venta-pdfs/{yyyy}/{mm}/{id}.pdf`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_facturas_venta', function (Blueprint $table) {
            $table->string('pdf_path', 400)->nullable()->after('observaciones');
        });
    }

    public function down(): void
    {
        Schema::table('erp_facturas_venta', function (Blueprint $table) {
            $table->dropColumn('pdf_path');
        });
    }
};
