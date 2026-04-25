-- ============================================================================
-- SPEC 07 — Integración ERP ↔ DistriApp (bloque 7A)
-- ============================================================================
-- Tablas puente del lado ERP. Las vistas erp_v_* las define DDL_07 sobre la
-- base DistriApp y se aplican aparte (requieren las tablas liq_* en
-- basepersonal). Este migrate solo crea lo que vive en erp_logistica_prod.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_distriapp_ref: índice invertido DistriApp ↔ ERP.
-- UNIQUE KEY garantiza idempotencia: si el reconciliador corre dos veces,
-- el segundo INSERT falla y se ignora.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_distriapp_ref (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tipo                  ENUM('PAGO_OP','PAGO_DETALLE','FACTURA','COBRO') NOT NULL,
    distriapp_tabla       VARCHAR(60)      NOT NULL,
    distriapp_id          BIGINT UNSIGNED  NOT NULL,
    erp_entidad           ENUM('asiento','asiento_item','factura_venta','cobro') NOT NULL,
    erp_entidad_id        BIGINT UNSIGNED  NOT NULL,
    fecha_conciliacion    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_id            BIGINT UNSIGNED  NULL,
    notas                 TEXT             NULL,
    UNIQUE KEY uq_distriapp_ref (tipo, distriapp_tabla, distriapp_id),
    KEY idx_ref_erp (erp_entidad, erp_entidad_id),
    KEY idx_ref_fecha (fecha_conciliacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Puente registros DistriApp ↔ entidades ERP. UK garantiza idempotencia.';

-- ----------------------------------------------------------------------------
-- erp_integracion_log: auditoría de cada corrida de reconciliación.
-- Feeds dashboard bloque C (errores 24h + última corrida).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_integracion_log (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    timestamp         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    flujo             ENUM('PAGO_MASIVO','FACTURA','COBRO','DASHBOARD') NOT NULL,
    distriapp_tabla   VARCHAR(60)     NULL,
    distriapp_id      BIGINT UNSIGNED NULL,
    estado            ENUM('OK','ERROR','WARNING','SKIPPED') NOT NULL,
    mensaje           TEXT            NULL,
    payload           JSON            NULL,
    KEY idx_log_timestamp (timestamp),
    KEY idx_log_flujo_estado (flujo, estado),
    KEY idx_log_distriapp (distriapp_tabla, distriapp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Auditoría de corridas de integración — feeds dashboard bloque C.';
