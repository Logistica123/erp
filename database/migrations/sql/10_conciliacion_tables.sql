-- ============================================================================
-- SPEC Conciliación Bancaria Multi-Banco — bloque CM-1
-- 2 tablas nuevas + seed inicial de prefijos.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_conciliacion_prefijos: catálogo por banco para extracción de
-- CUIT/identificadores embedidos en el campo `concepto`.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_conciliacion_prefijos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    banco_id {{TIPO_BANCO}} NOT NULL,
    prefijo VARCHAR(60) NOT NULL COMMENT 'Texto prefijo en concepto, sin números.',
    tipo_numero ENUM('CUIT','POLIZA','CUENTA_SERVICIO','TELEFONO','OTRO') NOT NULL,
    longitud_min TINYINT UNSIGNED NULL,
    longitud_max TINYINT UNSIGNED NULL,
    cuenta_contable_default_id BIGINT UNSIGNED NULL,
    observacion TEXT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_banco_prefijo (banco_id, prefijo),
    KEY idx_prefijo_activo (banco_id, activo),
    CONSTRAINT fk_prefijo_banco FOREIGN KEY (banco_id) REFERENCES erp_bancos(id),
    CONSTRAINT fk_prefijo_cta   FOREIGN KEY (cuenta_contable_default_id) REFERENCES erp_cuentas_contables(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Prefijos por banco para extraer CUIT/POLIZA/etc del concepto.';

-- ----------------------------------------------------------------------------
-- erp_alias_contraparte: cache de asignaciones manuales para nombres sin CUIT
-- (típico de MP / Brubank). La primera vez el operador asigna manualmente; las
-- siguientes el alias se reusa con confianza 100.
-- Las FK a personas/clientes son LÓGICAS (en basepersonal, otra DB) — no se
-- declaran como FK físicas pero sí se indexan.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_alias_contraparte (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    banco_id {{TIPO_BANCO}} NULL,
    alias_normalizado VARCHAR(200) NOT NULL COMMENT 'uppercase + trim + collapse whitespace',
    persona_id BIGINT UNSIGNED NULL COMMENT 'FK lógica a basepersonal.personas',
    cliente_id BIGINT UNSIGNED NULL COMMENT 'FK lógica a basepersonal.clientes',
    cuenta_contable_id BIGINT UNSIGNED NULL,
    confianza TINYINT UNSIGNED NOT NULL DEFAULT 100,
    asignado_por BIGINT UNSIGNED NULL,
    asignado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_empresa_banco_alias (empresa_id, banco_id, alias_normalizado),
    KEY idx_persona (persona_id),
    KEY idx_cliente (cliente_id),
    CONSTRAINT fk_alias_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_alias_banco   FOREIGN KEY (banco_id) REFERENCES erp_bancos(id),
    CONSTRAINT fk_alias_cuenta  FOREIGN KEY (cuenta_contable_id) REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_alias_user    FOREIGN KEY (asignado_por) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cache de asignaciones manuales nombre→persona/cliente (RN-CB §4.4).';
