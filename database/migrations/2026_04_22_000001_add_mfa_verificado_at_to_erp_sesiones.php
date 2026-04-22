<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC_01 §10: la revalidación MFA cada 15 minutos para permisos sensibles
 * requiere un timestamp de última verificación, no sólo un booleano.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE erp_sesiones
            ADD COLUMN mfa_verificado_at DATETIME NULL AFTER mfa_verificado
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE erp_sesiones DROP COLUMN mfa_verificado_at');
    }
};
