-- ============================================================================
-- DDL_05 H6 — Tabla erp_empresa_socios (RN-58)
--   Registra socios de la SRL con su CUIT, % de participación y tipo.
--   Necesaria para calcular BP F.2000 (la sociedad paga por sus socios sobre
--   el VPP). Idempotente.
-- ============================================================================

CREATE TABLE IF NOT EXISTS erp_empresa_socios (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id               BIGINT UNSIGNED NOT NULL,
    cuit                     CHAR(11) NOT NULL,
    nombre                   VARCHAR(200) NOT NULL,
    tipo                     ENUM('PERSONA_FISICA','PERSONA_JURIDICA') NOT NULL DEFAULT 'PERSONA_FISICA',
    porcentaje_participacion DECIMAL(7,4) NOT NULL,
    fecha_alta               DATE NOT NULL,
    fecha_baja               DATE NULL,
    activo                   TINYINT(1) NOT NULL DEFAULT 1,
    observaciones            TEXT NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_empresa_socio_cuit (empresa_id, cuit, fecha_alta),
    KEY ix_empresa_socio_activo (empresa_id, activo),
    CONSTRAINT fk_imp_socio_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
