<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.50 — Filtros + rediseño modal Vincular + auto-confirmación de cheques.
 *
 * §3.1 trazabilidad de cheques auto-confirmados vía recibo · §3.2 motivo AUTO
 * RECIBO-CON-CHEQUES · §3.3 filtros guardados por usuario · §6.3 estado
 * CONFIRMADO_RECIBO_CON_CHEQUES (enum con los 15 valores reales verificados).
 */
return new class extends Migration
{
    public function up(): void
    {
        // §3.1 — trazabilidad: cheque auto-confirmado al vincular mov ↔ recibo.
        if (! Schema::hasColumn('erp_movimientos_bancarios_cheques', 'confirmado_por_recibo')) {
            DB::statement("
                ALTER TABLE erp_movimientos_bancarios_cheques
                  ADD COLUMN confirmado_por_recibo TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'TRUE = auto-confirmado al vincular el mov al recibo (v1.50); FALSE = vinculación directa vía cheque',
                  ADD COLUMN vinculacion_mov_recibo_id BIGINT UNSIGNED NULL
                    COMMENT 'FK a erp_movimientos_bancarios_recibos si el cheque se auto-confirmó vía recibo',
                  ADD CONSTRAINT fk_mbc_vinc_recibo
                    FOREIGN KEY (vinculacion_mov_recibo_id) REFERENCES erp_movimientos_bancarios_recibos(id)
            ");
        }

        // §6.3 — estado nuevo (set real = 15 verificados + 1).
        DB::statement("
            ALTER TABLE erp_movimientos_bancarios MODIFY COLUMN estado ENUM(
              'PENDIENTE','ETIQUETADO','MATCH_AUTO','CONFIRMADO','REVERTIDO',
              'CONCILIADO','CONCILIADO_MANUAL','IGNORADO','EN_LOTE','CONFIRMADO_EN_LOTE',
              'PENDIENTE_TRANSF_INTERNA','CONFIRMADO_TRANSF_INTERNA',
              'CONFIRMADO_CHEQUES_COBRADOS','CONFIRMADO_DESCUENTO_CHEQUE','CONFIRMADO_RECIBO_DIRECTO',
              'CONFIRMADO_RECIBO_CON_CHEQUES'
            ) NOT NULL DEFAULT 'PENDIENTE'
        ");

        // §3.2 — motivo AUTO.
        DB::statement("
            INSERT IGNORE INTO erp_conciliacion_motivos
              (codigo, nombre, cuenta_ajuste_id, tipo, signo_esperado, requiere_auxiliar_tipo, orden_visual, observaciones)
            VALUES
              ('RECIBO-CON-CHEQUES', 'Cobro con recibo (arrastra cheques a COBRADO)', NULL, 'AUTO', '+', 'Cliente', 230,
               'Aplicado al vincular mov bancario a un recibo cuyos cheques pasaron de EN_CARTERA a COBRADO automáticamente')
        ");

        // §3.3 — filtros guardados por usuario.
        if (! Schema::hasTable('erp_movimientos_bancarios_filtros_guardados')) {
            DB::statement("
                CREATE TABLE erp_movimientos_bancarios_filtros_guardados (
                  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  user_id BIGINT UNSIGNED NOT NULL,
                  nombre VARCHAR(100) NOT NULL,
                  filtros_json JSON NOT NULL,
                  es_default TINYINT(1) NOT NULL DEFAULT 0,
                  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  CONSTRAINT fk_mbfg_user FOREIGN KEY (user_id) REFERENCES users(id),
                  UNIQUE KEY uniq_user_nombre (user_id, nombre)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_movimientos_bancarios_filtros_guardados');
        DB::statement("DELETE FROM erp_conciliacion_motivos WHERE codigo = 'RECIBO-CON-CHEQUES'");
        DB::statement("UPDATE erp_movimientos_bancarios SET estado='CONFIRMADO_RECIBO_DIRECTO' WHERE estado='CONFIRMADO_RECIBO_CON_CHEQUES'");
        DB::statement("
            ALTER TABLE erp_movimientos_bancarios MODIFY COLUMN estado ENUM(
              'PENDIENTE','ETIQUETADO','MATCH_AUTO','CONFIRMADO','REVERTIDO',
              'CONCILIADO','CONCILIADO_MANUAL','IGNORADO','EN_LOTE','CONFIRMADO_EN_LOTE',
              'PENDIENTE_TRANSF_INTERNA','CONFIRMADO_TRANSF_INTERNA',
              'CONFIRMADO_CHEQUES_COBRADOS','CONFIRMADO_DESCUENTO_CHEQUE','CONFIRMADO_RECIBO_DIRECTO'
            ) NOT NULL DEFAULT 'PENDIENTE'
        ");
        DB::statement('ALTER TABLE erp_movimientos_bancarios_cheques DROP FOREIGN KEY fk_mbc_vinc_recibo');
        DB::statement('ALTER TABLE erp_movimientos_bancarios_cheques DROP COLUMN vinculacion_mov_recibo_id, DROP COLUMN confirmado_por_recibo');
    }
};
