<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC 06 I4 — fix: el ENUM original de erp_presupuestos.estado no incluía
 * APROBADO. La API de §6.5 expone POST /aprobar para el flujo
 * BORRADOR → APROBADO → VIGENTE (LIBER aprueba antes de marcar vigente).
 * Extendemos el ENUM sin perder data existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE erp_presupuestos
            MODIFY COLUMN estado
            ENUM('BORRADOR','APROBADO','VIGENTE','HISTORICO','DESCARTADO')
            NOT NULL DEFAULT 'BORRADOR'
        ");
    }

    public function down(): void
    {
        // Caer cualquier APROBADO a BORRADOR para no perder data.
        DB::table('erp_presupuestos')->where('estado', 'APROBADO')->update(['estado' => 'BORRADOR']);
        DB::statement("
            ALTER TABLE erp_presupuestos
            MODIFY COLUMN estado
            ENUM('BORRADOR','VIGENTE','HISTORICO','DESCARTADO')
            NOT NULL DEFAULT 'BORRADOR'
        ");
    }
};
