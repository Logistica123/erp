<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Procesamiento de Seguro — suma 'SEGURO' al enum origen de erp_facturas_compra
 * para identificar los comprobantes cargados desde un PDF de póliza.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE erp_facturas_compra MODIFY origen
            ENUM('MANUAL','LIBRO_IVA_IMPORT','MIS_COMPROBANTES','DISTRIAPP','SEGURO')
            NOT NULL DEFAULT 'MANUAL'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE erp_facturas_compra MODIFY origen
            ENUM('MANUAL','LIBRO_IVA_IMPORT','MIS_COMPROBANTES','DISTRIAPP')
            NOT NULL DEFAULT 'MANUAL'");
    }
};
