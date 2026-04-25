-- ============================================================================
-- DDL_05 H8 — Tablas para EECC profesionales
--   erp_eecc_notas: edición manual de las 10 notas estándar (RN-62)
--   erp_eecc_emisiones: histórico de generaciones (PDF/DOCX/XLSX) por ejercicio
-- Idempotente.
-- ============================================================================

CREATE TABLE IF NOT EXISTS erp_eecc_notas (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ejercicio_id      BIGINT UNSIGNED NOT NULL,
    numero            TINYINT UNSIGNED NOT NULL COMMENT 'Nota 1..10 (ver RN-62)',
    titulo            VARCHAR(200) NOT NULL,
    contenido         LONGTEXT NULL,
    editado_user_id   BIGINT UNSIGNED NULL,
    editado_at        DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_eecc_nota (ejercicio_id, numero),
    CONSTRAINT fk_imp_nota_ejercicio FOREIGN KEY (ejercicio_id) REFERENCES erp_ejercicios(id),
    CONSTRAINT fk_imp_nota_user      FOREIGN KEY (editado_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_eecc_emisiones (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ejercicio_id             BIGINT UNSIGNED NOT NULL,
    formato                  ENUM('PDF','DOCX','XLSX') NOT NULL,
    incluir                  JSON NOT NULL COMMENT '["BG","ER","EPN","EFE","NOTAS"]',
    path                     VARCHAR(500) NOT NULL,
    hash                     CHAR(64) NOT NULL,
    profesional_firmante     VARCHAR(200) NULL,
    matricula_firmante       VARCHAR(60) NULL,
    observaciones            TEXT NULL,
    ajuste_por_inflacion     TINYINT(1) NOT NULL DEFAULT 0,
    generado_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generado_user_id         BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY ix_eecc_emi_ejer (ejercicio_id, formato),
    CONSTRAINT fk_imp_emi_ejercicio FOREIGN KEY (ejercicio_id) REFERENCES erp_ejercicios(id),
    CONSTRAINT fk_imp_emi_user      FOREIGN KEY (generado_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
