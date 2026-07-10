<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.56 — agrega REIMPUTADA al ENUM evento del sync log de facturas
 * DistriApp: se registra cuando al autorizar hubo que correr la
 * fecha_imputacion porque el período original estaba cerrado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE erp_facturas_compra_sync_log
            MODIFY COLUMN evento ENUM('APROBADA','BORRADA','ACTUALIZADA','ERROR','AUTORIZADA','DESAUTORIZADA','REIMPUTADA') NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE erp_facturas_compra_sync_log
            MODIFY COLUMN evento ENUM('APROBADA','BORRADA','ACTUALIZADA','ERROR','AUTORIZADA','DESAUTORIZADA') NOT NULL
        ");
    }
};
