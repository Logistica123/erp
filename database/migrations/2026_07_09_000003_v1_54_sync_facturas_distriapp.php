<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.54 — Sync facturas de compra DistriApp ↔ ERP.
 *
 * - 5 columnas de sync en erp_facturas_compra + UNIQUE distriapp_factura_id.
 * - Estado nuevo PENDIENTE_AUTORIZACION_ERP (enum real verificado 2026-07-09:
 *   RECIBIDA, CONTROLADA, OBSERVADA, PAGO_PARCIAL, PAGADA, ANULADA_POR_NC,
 *   RECHAZADA, IMPUTADA_EN_LOTE — difiere del spec, que asumía BORRADOR/ANULADA).
 * - Tabla de log erp_facturas_compra_sync_log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_facturas_compra', function (Blueprint $table) {
            $table->string('distriapp_factura_id', 60)->nullable()->comment('ID único en DistriApp. NULL si es carga manual');
            $table->unsignedBigInteger('distriapp_liquidacion_id')->nullable()->comment('Liquidación de origen en DistriApp (informativo)');
            $table->boolean('sincronizada_desde_distriapp')->default(false);
            $table->timestamp('sincronizada_en')->nullable();
            $table->json('sync_payload_json')->nullable()->comment('Payload original del webhook para auditoría/reprocesamiento');

            $table->unique('distriapp_factura_id', 'uniq_fc_distriapp_factura');
            $table->index('sincronizada_desde_distriapp', 'idx_fc_sync_distriapp');
        });

        DB::statement("ALTER TABLE erp_facturas_compra MODIFY COLUMN estado ENUM(
            'RECIBIDA','CONTROLADA','OBSERVADA','PAGO_PARCIAL','PAGADA',
            'ANULADA_POR_NC','RECHAZADA','IMPUTADA_EN_LOTE','PENDIENTE_AUTORIZACION_ERP'
        ) NOT NULL DEFAULT 'RECIBIDA'");

        Schema::create('erp_facturas_compra_sync_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('factura_compra_id')->nullable()->comment('FK si se creó/afectó una factura del ERP');
            $table->string('distriapp_factura_id', 60);
            $table->enum('evento', ['APROBADA', 'BORRADA', 'ACTUALIZADA', 'ERROR', 'AUTORIZADA', 'DESAUTORIZADA']);
            $table->enum('direccion', ['DISTRIAPP_A_ERP', 'ERP_A_DISTRIAPP']);
            $table->json('payload')->nullable();
            $table->integer('respuesta_codigo')->nullable();
            $table->text('respuesta_body')->nullable();
            $table->integer('intento_nro')->default(1);
            $table->timestamp('procesado_at')->useCurrent();
            $table->unsignedBigInteger('procesado_por')->nullable()->comment('Usuario si la acción vino de la UI del ERP');

            $table->foreign('factura_compra_id', 'fk_fcsl_factura')->references('id')->on('erp_facturas_compra')->nullOnDelete();
            $table->foreign('procesado_por', 'fk_fcsl_user')->references('id')->on('users');
            $table->index('distriapp_factura_id', 'idx_fcsl_distriapp');
            $table->index(['evento', 'procesado_at'], 'idx_fcsl_evento_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_facturas_compra_sync_log');
        DB::statement("ALTER TABLE erp_facturas_compra MODIFY COLUMN estado ENUM(
            'RECIBIDA','CONTROLADA','OBSERVADA','PAGO_PARCIAL','PAGADA',
            'ANULADA_POR_NC','RECHAZADA','IMPUTADA_EN_LOTE'
        ) NOT NULL DEFAULT 'RECIBIDA'");
        Schema::table('erp_facturas_compra', function (Blueprint $table) {
            $table->dropUnique('uniq_fc_distriapp_factura');
            $table->dropIndex('idx_fc_sync_distriapp');
            $table->dropColumn(['distriapp_factura_id', 'distriapp_liquidacion_id', 'sincronizada_desde_distriapp', 'sincronizada_en', 'sync_payload_json']);
        });
    }
};
