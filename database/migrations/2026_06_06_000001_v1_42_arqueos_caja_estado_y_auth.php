<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.42 Fase A — Arqueos de caja con estado + autorización (3 caminos).
 *
 * Estado actual: erp_arqueos_caja persiste un arqueo y auto-genera asiento
 * RN-23 si hay diferencia. NO hay flujo de autorización. Sumamos:
 *
 *   - estado ENUM (BORRADOR / CIERRA_OK / PENDIENTE_AUTORIZACION /
 *     CERRADO_CON_AJUSTE / CERRADO_CON_DISCREPANCIA / RECHAZADO).
 *   - Campos de autorización (quien autorizó, cuándo, decisión, motivo).
 *
 * Migración retroactiva: los arqueos históricos (0 en prod hoy) se marcan
 * según diferencia + presencia de asiento_ajuste_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('erp_arqueos_caja', 'estado')) {
            return; // idempotente
        }

        Schema::table('erp_arqueos_caja', function (Blueprint $t) {
            $t->enum('estado', [
                'BORRADOR',
                'CIERRA_OK',
                'PENDIENTE_AUTORIZACION',
                'CERRADO_CON_AJUSTE',
                'CERRADO_CON_DISCREPANCIA',
                'RECHAZADO',
            ])->default('CIERRA_OK')->after('motivo');
            $t->unsignedBigInteger('autorizado_por_user_id')->nullable()->after('estado');
            $t->dateTime('fecha_autorizacion')->nullable()->after('autorizado_por_user_id');
            $t->enum('decision_autorizacion', ['AJUSTAR', 'CERRAR_CON_DISCREPANCIA', 'RECHAZAR'])
                ->nullable()->after('fecha_autorizacion');
            $t->text('motivo_autorizacion')->nullable()->after('decision_autorizacion');
            $t->index('estado', 'idx_arq_estado');
            $t->index(['caja_id', 'estado'], 'idx_arq_caja_estado');
        });

        // Backfill: los arqueos que ya tienen asiento_ajuste_id (autoajustados
        // por el flujo viejo) quedan CERRADO_CON_AJUSTE. Los demás CIERRA_OK.
        DB::statement("
            UPDATE erp_arqueos_caja
               SET estado = CASE
                   WHEN asiento_ajuste_id IS NOT NULL THEN 'CERRADO_CON_AJUSTE'
                   ELSE 'CIERRA_OK'
               END
        ");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('erp_arqueos_caja', 'estado')) return;
        Schema::table('erp_arqueos_caja', function (Blueprint $t) {
            try { $t->dropIndex('idx_arq_estado'); } catch (\Throwable $e) {}
            try { $t->dropIndex('idx_arq_caja_estado'); } catch (\Throwable $e) {}
            $t->dropColumn([
                'estado', 'autorizado_por_user_id', 'fecha_autorizacion',
                'decision_autorizacion', 'motivo_autorizacion',
            ]);
        });
    }
};
