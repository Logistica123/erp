<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.51 — Arqueos de caja: acciones directas en el listado.
 *
 * Estado ANULADO en el enum (preservando los 6 valores REALES verificados con
 * SHOW CREATE TABLE — el spec asumía "AUTORIZADO", que no existe: los cierres
 * reales son CIERRA_OK / CERRADO_CON_AJUSTE / CERRADO_CON_DISCREPANCIA) + 4
 * columnas de trazabilidad de la anulación.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE erp_arqueos_caja MODIFY COLUMN estado ENUM(
              'BORRADOR','CIERRA_OK','PENDIENTE_AUTORIZACION','CERRADO_CON_AJUSTE',
              'CERRADO_CON_DISCREPANCIA','RECHAZADO','ANULADO'
            ) NOT NULL DEFAULT 'CIERRA_OK'
        ");

        if (! Schema::hasColumn('erp_arqueos_caja', 'anulado_at')) {
            DB::statement("
                ALTER TABLE erp_arqueos_caja
                  ADD COLUMN anulado_at TIMESTAMP NULL,
                  ADD COLUMN anulado_by BIGINT UNSIGNED NULL,
                  ADD COLUMN motivo_anulacion VARCHAR(255) NULL,
                  ADD COLUMN asiento_reversa_id BIGINT UNSIGNED NULL
                    COMMENT 'FK al asiento de reversa generado al anular',
                  ADD CONSTRAINT fk_arqueos_anulado_by FOREIGN KEY (anulado_by) REFERENCES users(id),
                  ADD CONSTRAINT fk_arqueos_asiento_reversa FOREIGN KEY (asiento_reversa_id) REFERENCES erp_asientos(id)
            ");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE erp_arqueos_caja DROP FOREIGN KEY fk_arqueos_anulado_by');
        DB::statement('ALTER TABLE erp_arqueos_caja DROP FOREIGN KEY fk_arqueos_asiento_reversa');
        DB::statement('ALTER TABLE erp_arqueos_caja DROP COLUMN anulado_at, DROP COLUMN anulado_by, DROP COLUMN motivo_anulacion, DROP COLUMN asiento_reversa_id');
        DB::statement("UPDATE erp_arqueos_caja SET estado='RECHAZADO' WHERE estado='ANULADO'");
        DB::statement("
            ALTER TABLE erp_arqueos_caja MODIFY COLUMN estado ENUM(
              'BORRADOR','CIERRA_OK','PENDIENTE_AUTORIZACION','CERRADO_CON_AJUSTE',
              'CERRADO_CON_DISCREPANCIA','RECHAZADO'
            ) NOT NULL DEFAULT 'CIERRA_OK'
        ");
    }
};
