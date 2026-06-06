<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.42 Fase A — Consolidación a una sola caja operativa (D-42-1).
 *
 * Estado pre-migración: 2 cajas (CAJA_PRINCIPAL id=1, CAJA_CENTRAL id=2),
 * ambas con saldo 0 y sin arqueos históricos. Se desactiva CAJA_CENTRAL.
 *
 * El spec sugería migrar movimientos de CAJA_CENTRAL → CAJA_PRINCIPAL, pero
 * no hay tabla erp_caja_movimientos en este ERP (el saldo vive en
 * erp_cajas.saldo_actual y se update por ArqueoCajaService/CobroFacturaService).
 * Como CAJA_CENTRAL tiene saldo 0 y 0 arqueos, no hay datos que migrar.
 *
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cajaCentral = DB::table('erp_cajas')->where('codigo', 'CAJA_CENTRAL')->first(['id', 'activo', 'saldo_actual']);
        if (! $cajaCentral || ! $cajaCentral->activo) return;

        // Safety: NO desactivar si tiene saldo (cobertura por si alguien
        // movió dinero antes de la migración).
        if ((float) $cajaCentral->saldo_actual != 0) {
            throw new RuntimeException(
                "CAJA_CENTRAL tiene saldo \${$cajaCentral->saldo_actual} != 0. " .
                "Migrar el saldo a CAJA_PRINCIPAL manualmente antes de re-correr la migración."
            );
        }

        // Migrar arqueos históricos (si los hubiera) a CAJA_PRINCIPAL.
        $cajaPrincipalId = DB::table('erp_cajas')->where('codigo', 'CAJA_PRINCIPAL')->value('id');
        if ($cajaPrincipalId) {
            DB::table('erp_arqueos_caja')
                ->where('caja_id', $cajaCentral->id)
                ->update(['caja_id' => $cajaPrincipalId]);
        }

        // Desactivar.
        DB::table('erp_cajas')->where('id', $cajaCentral->id)->update(['activo' => 0]);
    }

    public function down(): void
    {
        DB::table('erp_cajas')->where('codigo', 'CAJA_CENTRAL')->update(['activo' => 1]);
    }
};
