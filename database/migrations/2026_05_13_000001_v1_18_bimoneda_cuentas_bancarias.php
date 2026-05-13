<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.18 Sprint T — Bimoneda en cuentas bancarias.
 *
 * ALTER erp_cuentas_bancarias ADD COLUMN monedas_aceptadas JSON NULL
 *
 * NULL  → cuenta monomoneda (usa moneda_id principal).
 * Array → cuenta multimoneda. Ej: JSON_ARRAY('ARS','USD').
 *
 * El form de Nuevo Cobro consulta GET /cuentas-bancarias/{id}/monedas-aceptadas
 * para decidir si el dropdown de moneda queda habilitado o no.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            ['erp_cuentas_bancarias', 'monedas_aceptadas']
        );
        if (! $exists) {
            DB::statement("ALTER TABLE erp_cuentas_bancarias
                ADD COLUMN monedas_aceptadas JSON NULL
                COMMENT 'v1.18 — Array de códigos de moneda aceptados si la cuenta es multimoneda. NULL = monomoneda (usa moneda_id principal).'
                AFTER moneda_id");
        }
    }

    public function down(): void
    {
        try { DB::statement('ALTER TABLE erp_cuentas_bancarias DROP COLUMN monedas_aceptadas'); } catch (\Throwable) {}
    }
};
