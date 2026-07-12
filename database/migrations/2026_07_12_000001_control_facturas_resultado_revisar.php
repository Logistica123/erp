<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría 2026-07-12 bug #3 — nuevo resultado REVISAR en control de
 * facturas: WSCDC aprueba pero APOC no se pudo consultar (WS caído).
 * Antes ese caso se marcaba VALIDA, degradando el propósito anti-fraude.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_control_facturas_validaciones')) {
            return; // entornos locales sin el módulo v1.44 migrado
        }
        DB::statement("
            ALTER TABLE erp_control_facturas_validaciones
            MODIFY COLUMN resultado_global
            ENUM('VALIDA','INVALIDA','APOCRIFA','REVISAR','ERROR','NO_PROCESABLE') NOT NULL
        ");
        DB::statement("
            ALTER TABLE erp_control_facturas_alertas
            MODIFY COLUMN tipo_alerta
            ENUM('FACTURA_INVALIDA','CUIT_APOC','IMPORTE_SOSPECHOSO','COMPROBANTE_DUPLICADO','APOC_NO_CONSULTABLE') NOT NULL
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('erp_control_facturas_validaciones')) {
            return;
        }
        DB::statement("
            UPDATE erp_control_facturas_validaciones
            SET resultado_global = 'ERROR' WHERE resultado_global = 'REVISAR'
        ");
        DB::statement("
            ALTER TABLE erp_control_facturas_validaciones
            MODIFY COLUMN resultado_global
            ENUM('VALIDA','INVALIDA','APOCRIFA','ERROR','NO_PROCESABLE') NOT NULL
        ");
    }
};
