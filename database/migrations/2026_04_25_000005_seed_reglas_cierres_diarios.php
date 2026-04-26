<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anexo Cierres Diarios CB-2-bis — seed de reglas de auto-conciliación.
 *
 * 19 reglas extraídas de los catálogos en los 3 FORMATO_*.md:
 *   - 6 reglas de impuestos (Ley 25413 + SIRCREB) en prioridad 5.
 *   - 2 IVA sobre comisiones (ICBC).
 *   - 2 comisiones bancarias (ICBC).
 *   - 2 rendimientos/intereses (MP + Brubank Rem).
 *   - 7 transferencias internas (cuenta_contable_id=NULL → contabilización
 *     vía detector cross-banco en CB-3; solo etiquetadas para review visual).
 *
 * Idempotente (INSERT IGNORE por código).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('migrations/sql/09_cierres_diarios_reglas_seed.sql')));
    }

    public function down(): void
    {
        $codigos = [
            'ICBC-IMP-DEB','ICBC-IMP-CRED','BR-IMP-LEY-25413',
            'MP-IMP-EXTRAC','MP-IMP-PAGOS','MP-IMP-RETIROS',
            'ICBC-SIRCREB','BR-SIRCREB',
            'ICBC-IVA-COM','ICBC-IVA-COM-105',
            'ICBC-COM-MPAY','ICBC-COM-MANT',
            'MP-RENDIMIENTOS','BR-INTERESES',
            'TRF-INT-ICBC-AR','TRF-INT-BR-A-ICBC','TRF-INT-BR-A-MP',
            'TRF-INT-BR-DE-ICBC','TRF-INT-BR-DE-MP',
            'TRF-INT-BR-RETIRO-REM','TRF-INT-BR-FONDEO-REM',
        ];
        DB::table('erp_conciliacion_reglas')->whereIn('codigo', $codigos)->delete();
    }
};
