<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.49 — Conciliación adaptada a Recibos + Cheques v2.
 *
 * §3.1/3.2 tablas de vinculación mov↔cheque y mov↔recibo · §3.3 FK al asiento
 * de descuento · §3.4 tres estados nuevos en el enum (preservando los 12
 * REALES de prod — el spec listaba AUTO_ETIQUETADO pero el valor real es
 * ETIQUETADO, y existe CONCILIADO además de CONCILIADO_MANUAL) · §3.5 tipo
 * AUTO + 3 motivos en el catálogo.
 *
 * Delta propio vs SQL del paquete: columna `cheque_estado_previo` en la tabla
 * de vinculación (el spec §4.3 exige "volver al estado anterior tracked" en la
 * reversa — hay que persistirlo en algún lado).
 */
return new class extends Migration
{
    public function up(): void
    {
        // §3.1 — vinculación mov ↔ cheques (N cheques por mov; un cheque en UN mov).
        if (! Schema::hasTable('erp_movimientos_bancarios_cheques')) {
            DB::statement("
                CREATE TABLE erp_movimientos_bancarios_cheques (
                  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  mov_bancario_id BIGINT UNSIGNED NOT NULL,
                  cheque_recibido_id BIGINT UNSIGNED NOT NULL,
                  monto_imputado DECIMAL(18,2) NOT NULL,
                  cheque_estado_previo VARCHAR(30) NOT NULL DEFAULT 'EN_CARTERA'
                    COMMENT 'Estado del cheque al confirmar; la reversa vuelve a este estado',
                  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  created_by BIGINT UNSIGNED NOT NULL,
                  CONSTRAINT fk_mbc_mov FOREIGN KEY (mov_bancario_id)
                    REFERENCES erp_movimientos_bancarios(id) ON DELETE CASCADE,
                  CONSTRAINT fk_mbc_cheque FOREIGN KEY (cheque_recibido_id)
                    REFERENCES erp_cheques_recibidos(id),
                  UNIQUE KEY uniq_cheque_solo_en_un_mov (cheque_recibido_id),
                  INDEX idx_mov_bancario (mov_bancario_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // §3.2 — vinculación mov ↔ recibo con medio directo.
        if (! Schema::hasTable('erp_movimientos_bancarios_recibos')) {
            DB::statement("
                CREATE TABLE erp_movimientos_bancarios_recibos (
                  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  mov_bancario_id BIGINT UNSIGNED NOT NULL,
                  recibo_id BIGINT UNSIGNED NOT NULL,
                  monto_imputado DECIMAL(18,2) NOT NULL,
                  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  created_by BIGINT UNSIGNED NOT NULL,
                  CONSTRAINT fk_mbr_mov FOREIGN KEY (mov_bancario_id)
                    REFERENCES erp_movimientos_bancarios(id) ON DELETE CASCADE,
                  CONSTRAINT fk_mbr_recibo FOREIGN KEY (recibo_id)
                    REFERENCES erp_recibos(id),
                  UNIQUE KEY uniq_recibo_solo_en_un_mov (recibo_id),
                  INDEX idx_mov_bancario_r (mov_bancario_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // §3.3 — FK al asiento del descuento vinculado (no genera asiento nuevo).
        if (! Schema::hasColumn('erp_movimientos_bancarios', 'asiento_descuento_vinculado_id')) {
            DB::statement("
                ALTER TABLE erp_movimientos_bancarios
                  ADD COLUMN asiento_descuento_vinculado_id BIGINT UNSIGNED NULL
                    COMMENT 'FK al asiento del descuento existente si el mov vinculó a un descuento (no generó asiento nuevo)',
                  ADD CONSTRAINT fk_mov_asiento_desc_vinc
                    FOREIGN KEY (asiento_descuento_vinculado_id) REFERENCES erp_asientos(id),
                  ADD INDEX idx_asiento_desc_vinculado (asiento_descuento_vinculado_id)
            ");
        }

        // §3.4 — 3 estados nuevos, preservando el set REAL verificado en prod
        // (SHOW CREATE TABLE al 2026-07-05).
        DB::statement("
            ALTER TABLE erp_movimientos_bancarios MODIFY COLUMN estado ENUM(
              'PENDIENTE','ETIQUETADO','MATCH_AUTO','CONFIRMADO','REVERTIDO',
              'CONCILIADO','CONCILIADO_MANUAL','IGNORADO','EN_LOTE','CONFIRMADO_EN_LOTE',
              'PENDIENTE_TRANSF_INTERNA','CONFIRMADO_TRANSF_INTERNA',
              'CONFIRMADO_CHEQUES_COBRADOS','CONFIRMADO_DESCUENTO_CHEQUE','CONFIRMADO_RECIBO_DIRECTO'
            ) NOT NULL DEFAULT 'PENDIENTE'
        ");

        // §3.5 — tipo AUTO en el catálogo de motivos + 3 motivos nuevos.
        DB::statement("
            ALTER TABLE erp_conciliacion_motivos
              MODIFY COLUMN tipo ENUM('DEFINITIVO','ANTICIPO_PROVEEDOR','MANUAL','AUTO')
                NOT NULL DEFAULT 'DEFINITIVO'
        ");
        DB::statement("
            INSERT IGNORE INTO erp_conciliacion_motivos
              (codigo, nombre, cuenta_ajuste_id, tipo, signo_esperado, requiere_auxiliar_tipo, orden_visual, observaciones)
            VALUES
              ('CHEQUE-COBRADO', 'Cheque cobrado en cámara', NULL, 'AUTO', '+', 'Cliente', 200,
               'Aplicado automáticamente al vincular cheques desde el modal de Sugerencias'),
              ('CHEQUE-DESCONTADO', 'Descuento de cheque — vincular a asiento existente', NULL, 'AUTO', '+', 'Cliente', 210,
               'Aplicado automáticamente al detectar match perfecto contra asiento de descuento existente'),
              ('RECIBO-DIRECTO', 'Recibo con medio directo (transferencia/MP)', NULL, 'AUTO', '+', 'Cliente', 220,
               'Aplicado automáticamente al vincular un recibo con medio directo al mov bancario')
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM erp_conciliacion_motivos WHERE codigo IN ('CHEQUE-COBRADO','CHEQUE-DESCONTADO','RECIBO-DIRECTO')");
        DB::statement("ALTER TABLE erp_conciliacion_motivos MODIFY COLUMN tipo ENUM('DEFINITIVO','ANTICIPO_PROVEEDOR','MANUAL') NOT NULL DEFAULT 'DEFINITIVO'");
        DB::statement("UPDATE erp_movimientos_bancarios SET estado='CONCILIADO' WHERE estado IN ('CONFIRMADO_CHEQUES_COBRADOS','CONFIRMADO_DESCUENTO_CHEQUE','CONFIRMADO_RECIBO_DIRECTO')");
        DB::statement("
            ALTER TABLE erp_movimientos_bancarios MODIFY COLUMN estado ENUM(
              'PENDIENTE','ETIQUETADO','MATCH_AUTO','CONFIRMADO','REVERTIDO',
              'CONCILIADO','CONCILIADO_MANUAL','IGNORADO','EN_LOTE','CONFIRMADO_EN_LOTE',
              'PENDIENTE_TRANSF_INTERNA','CONFIRMADO_TRANSF_INTERNA'
            ) NOT NULL DEFAULT 'PENDIENTE'
        ");
        DB::statement('ALTER TABLE erp_movimientos_bancarios DROP FOREIGN KEY fk_mov_asiento_desc_vinc');
        DB::statement('ALTER TABLE erp_movimientos_bancarios DROP COLUMN asiento_descuento_vinculado_id');
        Schema::dropIfExists('erp_movimientos_bancarios_recibos');
        Schema::dropIfExists('erp_movimientos_bancarios_cheques');
    }
};
