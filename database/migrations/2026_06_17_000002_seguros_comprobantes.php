<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Procesamiento de Seguro — AUTÓNOMO. Almacena los comprobantes de
 * seguro cargados desde PDF, sin impactar ningún otro módulo del ERP. Sirve
 * para detectar duplicados (mismo PDF cargado 2+ veces) y emitir el TXT del
 * Libro IVA Digital de estos comprobantes para importar a AFIP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('erp_seguros_comprobantes')) return;
        Schema::create('erp_seguros_comprobantes', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('empresa_id')->default(1);
            $t->string('aseguradora', 120)->nullable();
            $t->string('cuit_aseguradora', 11);
            $t->date('fecha_emision');
            $t->date('fecha_imputacion')->nullable();
            $t->string('poliza', 40)->nullable();
            $t->string('comprobante_ref', 60)->nullable();
            $t->unsignedSmallInteger('tipo_comprobante'); // 90 baja/NC, 99 alta
            $t->unsignedInteger('punto_venta')->default(0);
            $t->unsignedBigInteger('numero')->default(0);
            $t->decimal('imp_neto_gravado_21', 15, 2)->default(0);
            $t->decimal('imp_iva_21', 15, 2)->default(0);
            $t->decimal('imp_percepciones_iva', 15, 2)->default(0);
            $t->decimal('imp_otros_tributos', 15, 2)->default(0);
            $t->decimal('imp_total', 15, 2)->default(0);
            $t->char('contenido_hash', 64);          // SHA-256 del PDF (dedup)
            $t->string('nombre_archivo', 255)->nullable();
            $t->json('crudos')->nullable();           // valores extraídos crudos
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();

            $t->unique(['empresa_id', 'contenido_hash'], 'uq_seg_hash');
            $t->index(['empresa_id', 'cuit_aseguradora']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_seguros_comprobantes');
    }
};
