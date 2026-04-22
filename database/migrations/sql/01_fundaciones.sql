-- ============================================================================
-- ERP Logística Argentina SRL — DDL Fase 1: FUNDACIONES
-- Motor: MySQL 8.0+ / MariaDB 10.6+
-- Charset: utf8mb4 / collation utf8mb4_unicode_ci
-- Convención: prefijo erp_* para tablas nuevas, NO tocar tablas existentes de DistriApp.
-- Autor: Claude (arquitecto) para Matías Sánchez · Fecha: 2026-04-18
-- ============================================================================
--
-- ORDEN DE EJECUCIÓN DEL PAQUETE:
--   1. DDL_01_Fundaciones.sql          (este archivo)
--   2. DDL_02_Contabilidad.sql
--   3. seed_erp_empresa.sql            (carga Logística Argentina SRL)
--   4. seed_erp_monedas_y_cotizaciones.sql
--   5. seed_erp_permisos.sql
--   6. seed_erp_cuentas_contables.sql
--   7. seed_erp_mapeo_etiquetas.sql
--
-- NOTAS:
--   · Todas las tablas nuevas llevan columnas de auditoría created_at/updated_at.
--   · Soft-delete con deleted_at donde corresponda.
--   · FK explícitas, ON DELETE RESTRICT por defecto (nunca cascada sobre
--     datos contables).
--   · Índices sobre columnas usadas en filtros frecuentes.
--   · Precisión monetaria: DECIMAL(18,2) para importes ARS, DECIMAL(18,4) para
--     cotizaciones y conversiones (4 decimales para evitar drift).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 1. EMPRESAS (multi-empresa ready, aunque hoy solo Logística Argentina SRL)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_empresas (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    razon_social        VARCHAR(200) NOT NULL,
    nombre_fantasia     VARCHAR(200) NULL,
    cuit                CHAR(11) NOT NULL,
    condicion_iva       ENUM('RI','MONOTRIBUTO','EXENTO','CF') NOT NULL DEFAULT 'RI',
    domicilio_fiscal    VARCHAR(300) NULL,
    iibb_nro            VARCHAR(30) NULL,
    iibb_regimen        ENUM('CM','LOCAL') NOT NULL DEFAULT 'CM',
    iibb_jurisdiccion_sede VARCHAR(10) NULL COMMENT 'Código AFIP juris. sede (ej. 901 CABA, 902 PBA)',
    fecha_inicio_actividades DATE NULL,
    logo_path           VARCHAR(500) NULL,
    moneda_base         CHAR(3) NOT NULL DEFAULT 'ARS',
    aplica_rt6          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ajuste por inflación RT6 FACPCE',
    activo              TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_empresa_cuit (cuit),
    KEY idx_empresa_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Empresas operadas por el ERP. Hoy única: Logística Argentina SRL.';

-- ============================================================================
-- 2. EJERCICIOS CONTABLES (año fiscal)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_ejercicios (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    numero          SMALLINT UNSIGNED NOT NULL COMMENT '1, 2, 3... desde inicio',
    nombre          VARCHAR(50) NOT NULL COMMENT 'Ej. Ejercicio 2026',
    fecha_inicio    DATE NOT NULL,
    fecha_cierre    DATE NOT NULL,
    estado          ENUM('ABIERTO','EN_CIERRE','CERRADO','REABIERTO') NOT NULL DEFAULT 'ABIERTO',
    fecha_cierre_real DATETIME NULL,
    usuario_cierre_id BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ejercicio (empresa_id, numero),
    KEY idx_ejercicio_fechas (empresa_id, fecha_inicio, fecha_cierre),
    CONSTRAINT fk_ejercicio_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ejercicios contables anuales. Regla: no superponer fechas por empresa.';

-- ============================================================================
-- 3. PERÍODOS CONTABLES (meses dentro del ejercicio)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_periodos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ejercicio_id    BIGINT UNSIGNED NOT NULL,
    anio            SMALLINT UNSIGNED NOT NULL,
    mes             TINYINT UNSIGNED NOT NULL,
    fecha_inicio    DATE NOT NULL,
    fecha_fin       DATE NOT NULL,
    estado          ENUM('ABIERTO','BLOQUEADO','CERRADO') NOT NULL DEFAULT 'ABIERTO',
    fecha_cierre    DATETIME NULL,
    usuario_cierre_id BIGINT UNSIGNED NULL,
    cierre_iva      TINYINT(1) NOT NULL DEFAULT 0,
    cierre_iibb     TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_periodo (ejercicio_id, anio, mes),
    KEY idx_periodo_estado (estado),
    CONSTRAINT fk_periodo_ejercicio FOREIGN KEY (ejercicio_id) REFERENCES erp_ejercicios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Períodos mensuales. Bloqueo impide nuevos asientos post-DDJJ.';

-- ============================================================================
-- 4. MONEDAS
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_monedas (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          CHAR(3) NOT NULL COMMENT 'ISO 4217: ARS, USD, EUR, BRL',
    nombre          VARCHAR(50) NOT NULL,
    simbolo         VARCHAR(5) NOT NULL,
    decimales       TINYINT UNSIGNED NOT NULL DEFAULT 2,
    es_base         TINYINT(1) NOT NULL DEFAULT 0,
    activa          TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_moneda_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de monedas.';

-- ============================================================================
-- 5. COTIZACIONES DIARIAS
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_cotizaciones (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    moneda_id       BIGINT UNSIGNED NOT NULL,
    fecha           DATE NOT NULL,
    tipo            ENUM('OFICIAL','MEP','CCL','BLUE','BCRA_COMPRADOR','BCRA_VENDEDOR','CUSTOM') NOT NULL DEFAULT 'OFICIAL',
    valor_compra    DECIMAL(18,4) NULL,
    valor_venta     DECIMAL(18,4) NULL,
    valor_referencia DECIMAL(18,4) NOT NULL COMMENT 'Valor oficial usado para registro contable',
    fuente          VARCHAR(80) NULL COMMENT 'BCRA, AFIP, manual, IOL',
    notas           VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cotizacion (empresa_id, moneda_id, fecha, tipo),
    KEY idx_cotizacion_fecha (fecha),
    CONSTRAINT fk_cotiz_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id),
    CONSTRAINT fk_cotiz_moneda FOREIGN KEY (moneda_id) REFERENCES erp_monedas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cotizaciones diarias para conversión y revaluación.';

-- ============================================================================
-- 6. USUARIOS ERP (extensión de users de Laravel; no duplica)
-- ============================================================================
-- NOTA: La tabla users existente en DistriApp se reutiliza. Esta tabla
-- agrega columnas ERP-specific via relación 1:1 (user_id FK a users.id).
CREATE TABLE IF NOT EXISTS erp_usuario_perfil (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL COMMENT 'FK a users.id (DistriApp)',
    empresa_id          BIGINT UNSIGNED NOT NULL,
    legajo              VARCHAR(30) NULL,
    mfa_habilitado      TINYINT(1) NOT NULL DEFAULT 0,
    mfa_secret          VARCHAR(120) NULL COMMENT 'Encriptado a nivel app',
    ultimo_login        DATETIME NULL,
    ultimo_ip           VARCHAR(45) NULL,
    intentos_fallidos   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_hasta     DATETIME NULL,
    acceso_erp          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag principal de acceso al módulo ERP',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuario_empresa (user_id, empresa_id),
    KEY idx_usuario_empresa (empresa_id, acceso_erp),
    CONSTRAINT fk_usuario_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id)
    -- FK a users.id se agrega en migration separada para no romper si cambia users
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Perfil ERP de usuario (extiende users de DistriApp).';

-- ============================================================================
-- 7. ROLES
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_roles (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NULL COMMENT 'NULL = rol global, no-NULL = rol de empresa',
    codigo          VARCHAR(60) NOT NULL COMMENT 'super_admin, contador, tesorero, facturador...',
    nombre          VARCHAR(100) NOT NULL,
    descripcion     VARCHAR(400) NULL,
    nivel_jerarquia TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Menor valor = más poder',
    protegido       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Sistema, no se puede borrar',
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rol (empresa_id, codigo),
    KEY idx_rol_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Roles del ERP (RBAC).';

-- ============================================================================
-- 8. PERMISOS (catálogo granular modulo.entidad.accion)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_permisos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(120) NOT NULL COMMENT 'contabilidad.asientos.crear, tesoreria.bancos.conciliar...',
    modulo          VARCHAR(60) NOT NULL,
    entidad         VARCHAR(60) NOT NULL,
    accion          VARCHAR(60) NOT NULL COMMENT 'ver, crear, editar, eliminar, aprobar, exportar',
    descripcion     VARCHAR(400) NULL,
    sensible        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Requiere MFA al ejecutar',
    PRIMARY KEY (id),
    UNIQUE KEY uk_permiso (codigo),
    KEY idx_permiso_modulo (modulo, entidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo maestro de permisos granulares.';

-- ============================================================================
-- 9. ROL ↔ PERMISOS (muchos a muchos)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_rol_permiso (
    rol_id          BIGINT UNSIGNED NOT NULL,
    permiso_id      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rol_id, permiso_id),
    KEY idx_rp_permiso (permiso_id),
    CONSTRAINT fk_rp_rol FOREIGN KEY (rol_id) REFERENCES erp_roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES erp_permisos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. USUARIO ↔ ROLES (muchos a muchos)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_usuario_rol (
    usuario_perfil_id BIGINT UNSIGNED NOT NULL,
    rol_id            BIGINT UNSIGNED NOT NULL,
    asignado_por      BIGINT UNSIGNED NULL COMMENT 'users.id que asignó',
    asignado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    vigente_hasta     DATETIME NULL COMMENT 'Rol temporal con expiración',
    PRIMARY KEY (usuario_perfil_id, rol_id),
    KEY idx_ur_rol (rol_id),
    CONSTRAINT fk_ur_usuario FOREIGN KEY (usuario_perfil_id) REFERENCES erp_usuario_perfil (id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_rol FOREIGN KEY (rol_id) REFERENCES erp_roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. AUDIT LOG (hash-chain opcional a nivel aplicación)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_audit_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NULL,
    user_id         BIGINT UNSIGNED NULL COMMENT 'users.id',
    modulo          VARCHAR(60) NOT NULL,
    entidad         VARCHAR(80) NOT NULL COMMENT 'Nombre de tabla afectada',
    entidad_id      BIGINT UNSIGNED NULL,
    accion          VARCHAR(60) NOT NULL COMMENT 'insert, update, delete, login, export, approve',
    descripcion     VARCHAR(500) NULL,
    datos_antes     JSON NULL,
    datos_despues   JSON NULL,
    ip              VARCHAR(45) NULL,
    user_agent      VARCHAR(400) NULL,
    hash_prev       CHAR(64) NULL COMMENT 'SHA-256 del registro anterior',
    hash_actual     CHAR(64) NULL COMMENT 'SHA-256 de este registro',
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_audit_empresa (empresa_id, created_at),
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_entidad (entidad, entidad_id),
    KEY idx_audit_modulo (modulo, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de auditoría global, hash-chained para detección de manipulación.';

-- ============================================================================
-- 12. CENTROS DE COSTO (dimensión analítica transversal)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_centros_costo (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    codigo          VARCHAR(30) NOT NULL,
    nombre          VARCHAR(150) NOT NULL,
    tipo            ENUM('SUCURSAL','VEHICULO','RUTA','PROYECTO','CLIENTE','OTRO') NOT NULL,
    padre_id        BIGINT UNSIGNED NULL,
    ref_externa     VARCHAR(60) NULL COMMENT 'ID de patente, sucursal, etc. en DistriApp',
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cc (empresa_id, codigo),
    KEY idx_cc_tipo (empresa_id, tipo, activo),
    CONSTRAINT fk_cc_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id),
    CONSTRAINT fk_cc_padre FOREIGN KEY (padre_id) REFERENCES erp_centros_costo (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Centros de costo para apertura analítica.';

-- ============================================================================
-- 13. CONFIGURACIÓN POR EMPRESA (key-value tipado)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_config (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    clave           VARCHAR(120) NOT NULL,
    valor           TEXT NULL,
    tipo            ENUM('STRING','INT','DECIMAL','BOOL','JSON','DATE') NOT NULL DEFAULT 'STRING',
    categoria       VARCHAR(60) NOT NULL DEFAULT 'general',
    descripcion     VARCHAR(400) NULL,
    editable        TINYINT(1) NOT NULL DEFAULT 1,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_config (empresa_id, clave),
    KEY idx_config_cat (empresa_id, categoria),
    CONSTRAINT fk_config_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configuración por empresa (claves como arca.cuit_representado, iibb.jurisdicciones, etc.)';

-- ============================================================================
-- 14. SESIONES ERP (extra, para MFA y timeout específicos del ERP)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_sesiones (
    id              CHAR(36) NOT NULL COMMENT 'UUID',
    user_id         BIGINT UNSIGNED NOT NULL,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    mfa_verificado  TINYINT(1) NOT NULL DEFAULT 0,
    mfa_verificado_at DATETIME NULL COMMENT 'Timestamp de la última verificación MFA exitosa (SPEC_01 §10: revalidación cada 15 min en endpoints sensibles).',
    ip              VARCHAR(45) NULL,
    user_agent      VARCHAR(400) NULL,
    inicio          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_uso      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_en       DATETIME NOT NULL,
    cerrada_en      DATETIME NULL,
    motivo_cierre   VARCHAR(60) NULL,
    PRIMARY KEY (id),
    KEY idx_sesion_user (user_id, ultimo_uso),
    CONSTRAINT fk_sesion_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sesiones activas del ERP con control de MFA.';

-- ============================================================================
-- FIN DDL_01_Fundaciones.sql
-- Próximo archivo: DDL_02_Contabilidad.sql
-- ============================================================================
