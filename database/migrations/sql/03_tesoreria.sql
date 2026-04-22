-- ============================================================================
-- DDL_03 — FASE 3: TESORERÍA Y BANCOS
-- ERP Logística Argentina SRL
-- Dependencias: DDL_01_Fundaciones.sql, DDL_02_Contabilidad.sql
-- Motor: MySQL 8.0+ / MariaDB 10.6+
-- Charset: utf8mb4 / Collation: utf8mb4_unicode_ci
-- Convenciones: todas las tablas con prefijo erp_, FK RESTRICT por defecto
-- ============================================================================

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';

-- ============================================================================
-- 1. CATÁLOGOS BASE
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_bancos — catálogo de bancos soportados
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_bancos (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(10) NOT NULL,
    nombre          VARCHAR(100) NOT NULL,
    codigo_parser   VARCHAR(50) NOT NULL COMMENT 'Identificador del parser PHP: ICBC, GALICIA, BRUBANK_CC, BRUBANK_REM, MERCADO_PAGO, EFECTIVO',
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_bancos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_medios_pago — catálogo de medios de pago
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_medios_pago (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(20) NOT NULL COMMENT 'EFECTIVO, TRANSFERENCIA, ECHEQ, MP, DEBITO_AUTOMATICO, RETENCION',
    nombre          VARCHAR(80) NOT NULL,
    afecta_caja     TINYINT(1) NOT NULL DEFAULT 0,
    afecta_banco    TINYINT(1) NOT NULL DEFAULT 0,
    genera_echeq    TINYINT(1) NOT NULL DEFAULT 0,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_medios_pago_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_motivos_ignorado — catálogo de motivos para marcar movimiento IGNORADO
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_motivos_ignorado (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(30) NOT NULL,
    descripcion     VARCHAR(200) NOT NULL,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_motivos_ignorado_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. CUENTAS OPERATIVAS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_cuentas_bancarias — cuentas propias en cada banco
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_cuentas_bancarias (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id              INT UNSIGNED NOT NULL,
    banco_id                INT UNSIGNED NOT NULL,
    cuenta_contable_id      BIGINT UNSIGNED NOT NULL COMMENT 'FK a erp_cuentas_contables — cada banco tiene su cuenta contable exclusiva',
    moneda_id               INT UNSIGNED NOT NULL,
    codigo                  VARCHAR(20) NOT NULL COMMENT 'Código interno, ej: ICBC_CC_01',
    nombre                  VARCHAR(120) NOT NULL COMMENT 'Nombre descriptivo, ej: ICBC Cta Cte 001-12345/6',
    tipo                    ENUM('CC','CA','CSU','CI','CREM','BILLETERA') NOT NULL COMMENT 'Cta Cte, Cta Ahorro, Cta Sueldo, Cta Inversión, Cta Remunerada, Billetera digital',
    numero_cuenta           VARCHAR(50) NULL,
    cbu                     VARCHAR(22) NULL,
    cvu                     VARCHAR(22) NULL,
    alias_cbu               VARCHAR(50) NULL,
    titular_nombre          VARCHAR(120) NULL COMMENT 'Razón social titular de la cuenta. Puede diferir de la empresa (cuentas del grupo, joint).',
    titular_cuit            VARCHAR(13) NULL COMMENT 'CUIT del titular (formato XX-XXXXXXXX-X).',
    saldo_actual            DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Denormalizado, actualizado por trigger',
    saldo_moneda_origen     DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    fecha_ultimo_movimiento DATE NULL,
    activo                  TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at              DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cuentas_bancarias_codigo (empresa_id, codigo),
    KEY ix_cuentas_bancarias_banco (banco_id),
    KEY ix_cuentas_bancarias_empresa (empresa_id),
    CONSTRAINT fk_cuentas_bancarias_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_cuentas_bancarias_banco FOREIGN KEY (banco_id) REFERENCES erp_bancos(id),
    CONSTRAINT fk_cuentas_bancarias_cta_cont FOREIGN KEY (cuenta_contable_id) REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_cuentas_bancarias_moneda FOREIGN KEY (moneda_id) REFERENCES erp_monedas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_cajas — catálogo de cajas físicas (Fase 3: 1 sola)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_cajas (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id              INT UNSIGNED NOT NULL,
    codigo                  VARCHAR(20) NOT NULL,
    nombre                  VARCHAR(120) NOT NULL,
    cuenta_contable_id      BIGINT UNSIGNED NOT NULL,
    moneda_id               INT UNSIGNED NOT NULL,
    responsable_user_id     BIGINT UNSIGNED NULL,
    saldo_actual            DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    activo                  TINYINT(1) NOT NULL DEFAULT 1,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cajas_codigo (empresa_id, codigo),
    CONSTRAINT fk_cajas_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_cajas_cta_cont FOREIGN KEY (cuenta_contable_id) REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_cajas_moneda FOREIGN KEY (moneda_id) REFERENCES erp_monedas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. EXTRACTOS Y MOVIMIENTOS BANCARIOS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_extractos_bancarios — cabecera de cada importación
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_extractos_bancarios (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_bancaria_id      INT UNSIGNED NOT NULL,
    fecha_desde             DATE NOT NULL,
    fecha_hasta             DATE NOT NULL,
    hash_archivo            CHAR(64) NOT NULL COMMENT 'SHA-256 del archivo original — RN-12 idempotencia',
    nombre_archivo          VARCHAR(255) NOT NULL,
    ruta_archivo            VARCHAR(500) NULL COMMENT 'Ruta en MinIO/S3',
    saldo_inicial           DECIMAL(18,2) NULL,
    saldo_final             DECIMAL(18,2) NULL,
    cant_movimientos        INT UNSIGNED NOT NULL DEFAULT 0,
    importado_por_user_id   BIGINT UNSIGNED NOT NULL,
    importado_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observaciones           TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_extractos_hash (hash_archivo),
    KEY ix_extractos_cuenta_fecha (cuenta_bancaria_id, fecha_desde, fecha_hasta),
    CONSTRAINT fk_extractos_cuenta FOREIGN KEY (cuenta_bancaria_id) REFERENCES erp_cuentas_bancarias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_movimientos_bancarios — cada línea del extracto
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_movimientos_bancarios (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    extracto_id             BIGINT UNSIGNED NOT NULL,
    cuenta_bancaria_id      INT UNSIGNED NOT NULL,
    fecha                   DATE NOT NULL,
    fecha_valor             DATE NULL,
    concepto                VARCHAR(500) NOT NULL,
    comprobante_banco       VARCHAR(100) NULL COMMENT 'Nro de comprobante/operación del banco',
    debito                  DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    credito                 DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    saldo                   DECIMAL(18,2) NULL,
    estado                  ENUM('PENDIENTE','ETIQUETADO','CONCILIADO','IGNORADO') NOT NULL DEFAULT 'PENDIENTE',
    etiqueta_sugerida       VARCHAR(100) NULL,
    cuenta_contable_propuesta_id BIGINT UNSIGNED NULL,
    asiento_id              BIGINT UNSIGNED NULL COMMENT 'Asiento generado cuando CONCILIADO',
    motivo_ignorado_id      INT UNSIGNED NULL,
    observacion             TEXT NULL,
    hash_linea              CHAR(64) NOT NULL COMMENT 'SHA-256 para detectar duplicados dentro del mismo extracto',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_mov_bancarios_cuenta_fecha (cuenta_bancaria_id, fecha, estado),
    KEY ix_mov_bancarios_estado (estado),
    KEY ix_mov_bancarios_extracto (extracto_id),
    KEY ix_mov_bancarios_asiento (asiento_id),
    UNIQUE KEY uk_mov_bancarios_hash_linea (cuenta_bancaria_id, hash_linea),
    CONSTRAINT fk_mov_bancarios_extracto FOREIGN KEY (extracto_id) REFERENCES erp_extractos_bancarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_mov_bancarios_cuenta FOREIGN KEY (cuenta_bancaria_id) REFERENCES erp_cuentas_bancarias(id),
    CONSTRAINT fk_mov_bancarios_cta_prop FOREIGN KEY (cuenta_contable_propuesta_id) REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_mov_bancarios_asiento FOREIGN KEY (asiento_id) REFERENCES erp_asientos(id),
    CONSTRAINT fk_mov_bancarios_motivo_ign FOREIGN KEY (motivo_ignorado_id) REFERENCES erp_motivos_ignorado(id),
    CONSTRAINT ck_mov_bancarios_importe CHECK (debito >= 0 AND credito >= 0 AND (debito = 0 OR credito = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. eCHEQ
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_echeq — cheques electrónicos recibidos
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_echeq (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id                  INT UNSIGNED NOT NULL,
    numero                      VARCHAR(50) NOT NULL,
    cuit_librador               VARCHAR(13) NOT NULL COMMENT 'CUIT del emisor (cliente)',
    razon_social_librador       VARCHAR(200) NULL,
    banco_origen                VARCHAR(100) NULL,
    cbu_origen                  VARCHAR(22) NULL,
    importe                     DECIMAL(18,2) NOT NULL,
    moneda_id                   INT UNSIGNED NOT NULL,
    fecha_emision               DATE NOT NULL,
    fecha_pago                  DATE NOT NULL COMMENT 'Fecha diferida de cobro',
    estado                      ENUM('EN_CARTERA','DEPOSITADO','ACREDITADO','RECHAZADO','ENDOSADO','ANULADO') NOT NULL DEFAULT 'EN_CARTERA',
    cobro_id                    BIGINT UNSIGNED NULL COMMENT 'Cobro origen',
    deposito_cuenta_id          INT UNSIGNED NULL COMMENT 'Cuenta bancaria donde se depositó',
    fecha_deposito              DATE NULL,
    movimiento_bancario_id      BIGINT UNSIGNED NULL COMMENT 'Movimiento que confirma acreditación',
    fecha_acreditacion          DATE NULL,
    motivo_rechazo              VARCHAR(200) NULL,
    observaciones               TEXT NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_echeq_numero_cuit (empresa_id, numero, cuit_librador),
    KEY ix_echeq_estado (estado),
    KEY ix_echeq_fecha_pago (fecha_pago),
    KEY ix_echeq_cobro (cobro_id),
    KEY ix_echeq_mov_bancario (movimiento_bancario_id),
    CONSTRAINT fk_echeq_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_echeq_moneda FOREIGN KEY (moneda_id) REFERENCES erp_monedas(id),
    CONSTRAINT fk_echeq_dep_cuenta FOREIGN KEY (deposito_cuenta_id) REFERENCES erp_cuentas_bancarias(id),
    CONSTRAINT fk_echeq_mov_bancario FOREIGN KEY (movimiento_bancario_id) REFERENCES erp_movimientos_bancarios(id),
    CONSTRAINT ck_echeq_importe CHECK (importe > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_echeq_movimientos — historial de cambios de estado
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_echeq_movimientos (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    echeq_id                BIGINT UNSIGNED NOT NULL,
    estado_anterior         VARCHAR(20) NULL,
    estado_nuevo            VARCHAR(20) NOT NULL,
    motivo                  VARCHAR(200) NULL,
    user_id                 BIGINT UNSIGNED NOT NULL,
    fecha                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_echeq_mov_echeq (echeq_id, fecha),
    CONSTRAINT fk_echeq_mov_echeq FOREIGN KEY (echeq_id) REFERENCES erp_echeq(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. ÓRDENES DE PAGO
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_ordenes_pago — cabecera de OP
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_ordenes_pago (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id              INT UNSIGNED NOT NULL,
    numero                  VARCHAR(30) NOT NULL COMMENT 'Numeración interna OP-2026-00001',
    fecha                   DATE NOT NULL,
    tipo                    ENUM('PROVEEDOR','DISTRIBUIDOR','LIQUIDACION_DISTRIBUIDOR','OTROS') NOT NULL DEFAULT 'PROVEEDOR',
    auxiliar_id             BIGINT UNSIGNED NOT NULL COMMENT 'FK erp_auxiliares: destinatario',
    liq_encabezado_id       BIGINT UNSIGNED NULL COMMENT 'FK opcional a liq_encabezado (DistriApp) si tipo = LIQUIDACION_DISTRIBUIDOR',
    moneda_id               INT UNSIGNED NOT NULL,
    cotizacion              DECIMAL(18,4) NOT NULL DEFAULT 1.0000,
    importe                 DECIMAL(18,2) NOT NULL COMMENT 'Neto a pagar tras retenciones',
    importe_bruto           DECIMAL(18,2) NOT NULL COMMENT 'Antes de retenciones',
    total_retenciones       DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    estado                  ENUM('BORRADOR','CARGADA_BANCO','LIBERADA','PAGADA','RECHAZADA','ANULADA') NOT NULL DEFAULT 'BORRADOR',
    fecha_carga_banco       DATETIME NULL,
    fecha_liberacion        DATETIME NULL,
    fecha_pago              DATETIME NULL,
    concepto                VARCHAR(500) NULL,
    observaciones           TEXT NULL,
    creado_por_user_id      BIGINT UNSIGNED NOT NULL,
    cargado_por_user_id     BIGINT UNSIGNED NULL,
    liberado_por_user_id    BIGINT UNSIGNED NULL,
    asiento_id              BIGINT UNSIGNED NULL COMMENT 'Asiento cuando PAGADA',
    motivo_rechazo          VARCHAR(200) NULL,
    motivo_anulacion        VARCHAR(200) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_op_numero (empresa_id, numero),
    KEY ix_op_estado_fecha (estado, fecha),
    KEY ix_op_auxiliar (auxiliar_id),
    KEY ix_op_tipo (tipo),
    CONSTRAINT fk_op_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_op_auxiliar FOREIGN KEY (auxiliar_id) REFERENCES erp_auxiliares(id),
    CONSTRAINT fk_op_moneda FOREIGN KEY (moneda_id) REFERENCES erp_monedas(id),
    CONSTRAINT fk_op_asiento FOREIGN KEY (asiento_id) REFERENCES erp_asientos(id),
    CONSTRAINT ck_op_importe CHECK (importe >= 0 AND importe_bruto >= 0 AND total_retenciones >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_op_items — detalle de qué se paga
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_op_items (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    op_id                   BIGINT UNSIGNED NOT NULL,
    orden                   INT UNSIGNED NOT NULL DEFAULT 1,
    tipo_item               ENUM('FACTURA_COMPRA','ADELANTO','REINTEGRO','RETENCION','OTRO') NOT NULL,
    comprobante_id          BIGINT UNSIGNED NULL COMMENT 'FK a factura de compra — SPEC 03',
    cuenta_contable_id      BIGINT UNSIGNED NULL COMMENT 'Solo si tipo_item = OTRO / ADELANTO',
    concepto                VARCHAR(300) NOT NULL,
    importe                 DECIMAL(18,2) NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_op_items_op (op_id),
    CONSTRAINT fk_op_items_op FOREIGN KEY (op_id) REFERENCES erp_ordenes_pago(id) ON DELETE CASCADE,
    CONSTRAINT fk_op_items_cta_cont FOREIGN KEY (cuenta_contable_id) REFERENCES erp_cuentas_contables(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_op_medios — cómo se paga (transferencia, MP, etc.)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_op_medios (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    op_id                   BIGINT UNSIGNED NOT NULL,
    medio_pago_id           INT UNSIGNED NOT NULL,
    cuenta_bancaria_id      INT UNSIGNED NULL COMMENT 'Desde qué cuenta sale (si aplica)',
    importe                 DECIMAL(18,2) NOT NULL,
    referencia              VARCHAR(100) NULL COMMENT 'Nro de operación / transferencia',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_op_medios_op (op_id),
    CONSTRAINT fk_op_medios_op FOREIGN KEY (op_id) REFERENCES erp_ordenes_pago(id) ON DELETE CASCADE,
    CONSTRAINT fk_op_medios_medio FOREIGN KEY (medio_pago_id) REFERENCES erp_medios_pago(id),
    CONSTRAINT fk_op_medios_cuenta FOREIGN KEY (cuenta_bancaria_id) REFERENCES erp_cuentas_bancarias(id),
    CONSTRAINT ck_op_medios_importe CHECK (importe > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. COBROS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_cobros — cabecera de cobro
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_cobros (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id              INT UNSIGNED NOT NULL,
    numero                  VARCHAR(30) NOT NULL COMMENT 'REC-2026-00001',
    fecha                   DATE NOT NULL,
    auxiliar_id             BIGINT UNSIGNED NOT NULL COMMENT 'Cliente',
    moneda_id               INT UNSIGNED NOT NULL,
    cotizacion              DECIMAL(18,4) NOT NULL DEFAULT 1.0000,
    importe_total           DECIMAL(18,2) NOT NULL,
    total_retenciones       DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    estado                  ENUM('REGISTRADO','PARCIAL_ACREDITADO','ACREDITADO','RECHAZADO_PARCIAL','RECHAZADO','ANULADO') NOT NULL DEFAULT 'REGISTRADO',
    concepto                VARCHAR(500) NULL,
    observaciones           TEXT NULL,
    creado_por_user_id      BIGINT UNSIGNED NOT NULL,
    asiento_id              BIGINT UNSIGNED NULL,
    motivo_anulacion        VARCHAR(200) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cobros_numero (empresa_id, numero),
    KEY ix_cobros_fecha_estado (fecha, estado),
    KEY ix_cobros_auxiliar (auxiliar_id),
    CONSTRAINT fk_cobros_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_cobros_auxiliar FOREIGN KEY (auxiliar_id) REFERENCES erp_auxiliares(id),
    CONSTRAINT fk_cobros_moneda FOREIGN KEY (moneda_id) REFERENCES erp_monedas(id),
    CONSTRAINT fk_cobros_asiento FOREIGN KEY (asiento_id) REFERENCES erp_asientos(id),
    CONSTRAINT ck_cobros_importe CHECK (importe_total > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_cobro_items — facturas que cancela
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_cobro_items (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cobro_id                BIGINT UNSIGNED NOT NULL,
    tipo_item               ENUM('FACTURA_VENTA','NOTA_DEBITO','SEÑA','OTRO') NOT NULL DEFAULT 'FACTURA_VENTA',
    factura_id              BIGINT UNSIGNED NULL COMMENT 'FK facturas de venta — SPEC 03',
    cuenta_contable_id      BIGINT UNSIGNED NULL,
    concepto                VARCHAR(300) NOT NULL,
    importe                 DECIMAL(18,2) NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_cobro_items_cobro (cobro_id),
    CONSTRAINT fk_cobro_items_cobro FOREIGN KEY (cobro_id) REFERENCES erp_cobros(id) ON DELETE CASCADE,
    CONSTRAINT fk_cobro_items_cta_cont FOREIGN KEY (cuenta_contable_id) REFERENCES erp_cuentas_contables(id),
    CONSTRAINT ck_cobro_items_importe CHECK (importe > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_cobro_medios — cómo se cobró
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_cobro_medios (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cobro_id                BIGINT UNSIGNED NOT NULL,
    medio_pago_id           INT UNSIGNED NOT NULL,
    cuenta_bancaria_id      INT UNSIGNED NULL,
    caja_id                 INT UNSIGNED NULL,
    echeq_id                BIGINT UNSIGNED NULL COMMENT 'Si medio es ECHEQ, apunta al eCheq creado',
    importe                 DECIMAL(18,2) NOT NULL,
    referencia              VARCHAR(100) NULL,
    movimiento_bancario_id  BIGINT UNSIGNED NULL COMMENT 'Cuando concilia',
    estado_acreditacion     ENUM('PENDIENTE','ACREDITADO','RECHAZADO') NOT NULL DEFAULT 'PENDIENTE',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_cobro_medios_cobro (cobro_id),
    CONSTRAINT fk_cobro_medios_cobro FOREIGN KEY (cobro_id) REFERENCES erp_cobros(id) ON DELETE CASCADE,
    CONSTRAINT fk_cobro_medios_medio FOREIGN KEY (medio_pago_id) REFERENCES erp_medios_pago(id),
    CONSTRAINT fk_cobro_medios_cuenta FOREIGN KEY (cuenta_bancaria_id) REFERENCES erp_cuentas_bancarias(id),
    CONSTRAINT fk_cobro_medios_caja FOREIGN KEY (caja_id) REFERENCES erp_cajas(id),
    CONSTRAINT fk_cobro_medios_echeq FOREIGN KEY (echeq_id) REFERENCES erp_echeq(id),
    CONSTRAINT fk_cobro_medios_mov FOREIGN KEY (movimiento_bancario_id) REFERENCES erp_movimientos_bancarios(id),
    CONSTRAINT ck_cobro_medios_importe CHECK (importe > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK deferred: erp_echeq.cobro_id → erp_cobros.id (se agrega después de crear ambas).
-- Idempotente: si ya existe la FK no se vuelve a crear.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'fk_echeq_cobro'
      AND TABLE_NAME = 'erp_echeq'
);
SET @stmt := IF(@fk_exists = 0,
    'ALTER TABLE erp_echeq ADD CONSTRAINT fk_echeq_cobro FOREIGN KEY (cobro_id) REFERENCES erp_cobros(id)',
    'DO 0');
PREPARE _ddl FROM @stmt;
EXECUTE _ddl;
DEALLOCATE PREPARE _ddl;

-- ============================================================================
-- 7. TRANSFERENCIAS INTERNAS
-- ============================================================================

CREATE TABLE IF NOT EXISTS erp_transferencias_internas (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id              INT UNSIGNED NOT NULL,
    numero                  VARCHAR(30) NOT NULL,
    fecha                   DATE NOT NULL,
    cuenta_origen_id        INT UNSIGNED NOT NULL,
    cuenta_destino_id       INT UNSIGNED NOT NULL,
    moneda_origen_id        INT UNSIGNED NOT NULL,
    moneda_destino_id       INT UNSIGNED NOT NULL,
    importe_origen          DECIMAL(18,2) NOT NULL,
    importe_destino         DECIMAL(18,2) NOT NULL,
    tipo_cambio             DECIMAL(18,4) NOT NULL DEFAULT 1.0000,
    estado                  ENUM('PENDIENTE','PARCIAL','CONCILIADA','ANULADA') NOT NULL DEFAULT 'PENDIENTE',
    movimiento_origen_id    BIGINT UNSIGNED NULL,
    movimiento_destino_id   BIGINT UNSIGNED NULL,
    asiento_id              BIGINT UNSIGNED NULL,
    concepto                VARCHAR(300) NULL,
    creado_por_user_id      BIGINT UNSIGNED NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ti_numero (empresa_id, numero),
    KEY ix_ti_estado (estado),
    CONSTRAINT fk_ti_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_ti_origen FOREIGN KEY (cuenta_origen_id) REFERENCES erp_cuentas_bancarias(id),
    CONSTRAINT fk_ti_destino FOREIGN KEY (cuenta_destino_id) REFERENCES erp_cuentas_bancarias(id),
    CONSTRAINT fk_ti_moneda_ori FOREIGN KEY (moneda_origen_id) REFERENCES erp_monedas(id),
    CONSTRAINT fk_ti_moneda_dst FOREIGN KEY (moneda_destino_id) REFERENCES erp_monedas(id),
    CONSTRAINT fk_ti_mov_origen FOREIGN KEY (movimiento_origen_id) REFERENCES erp_movimientos_bancarios(id),
    CONSTRAINT fk_ti_mov_destino FOREIGN KEY (movimiento_destino_id) REFERENCES erp_movimientos_bancarios(id),
    CONSTRAINT fk_ti_asiento FOREIGN KEY (asiento_id) REFERENCES erp_asientos(id),
    CONSTRAINT ck_ti_diferentes CHECK (cuenta_origen_id <> cuenta_destino_id),
    CONSTRAINT ck_ti_importes CHECK (importe_origen > 0 AND importe_destino > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. CAJA — ARQUEOS
-- ============================================================================

CREATE TABLE IF NOT EXISTS erp_arqueos_caja (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    caja_id                 INT UNSIGNED NOT NULL,
    fecha                   DATE NOT NULL,
    saldo_teorico           DECIMAL(18,2) NOT NULL,
    saldo_fisico            DECIMAL(18,2) NOT NULL,
    diferencia              DECIMAL(18,2) GENERATED ALWAYS AS (saldo_fisico - saldo_teorico) STORED,
    motivo                  VARCHAR(300) NULL,
    asiento_ajuste_id       BIGINT UNSIGNED NULL,
    realizado_por_user_id   BIGINT UNSIGNED NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_arqueos_caja_fecha (caja_id, fecha),
    KEY ix_arqueos_fecha (fecha),
    CONSTRAINT fk_arqueos_caja FOREIGN KEY (caja_id) REFERENCES erp_cajas(id),
    CONSTRAINT fk_arqueos_asiento FOREIGN KEY (asiento_ajuste_id) REFERENCES erp_asientos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. CONCILIACIÓN
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_conciliacion_reglas — reglas auto (complemento de erp_mapeo_etiqueta_cuenta)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_conciliacion_reglas (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id              INT UNSIGNED NOT NULL,
    codigo                  VARCHAR(50) NOT NULL,
    descripcion             VARCHAR(200) NOT NULL,
    tipo                    ENUM('CONCEPTO_REGEX','IMPORTE_EXACTO','COMBINADA') NOT NULL,
    patron_concepto         VARCHAR(300) NULL COMMENT 'Regex sobre concepto del movimiento',
    patron_importe_desde    DECIMAL(18,2) NULL,
    patron_importe_hasta    DECIMAL(18,2) NULL,
    cuenta_contable_id      BIGINT UNSIGNED NULL,
    auxiliar_id             BIGINT UNSIGNED NULL,
    centro_costo_id         INT UNSIGNED NULL,
    diario_id               INT UNSIGNED NULL,
    orden_prioridad         INT UNSIGNED NOT NULL DEFAULT 100,
    activa                  TINYINT(1) NOT NULL DEFAULT 1,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_concil_reglas_cod (empresa_id, codigo),
    KEY ix_concil_reglas_orden (orden_prioridad, activa),
    CONSTRAINT fk_concil_reglas_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_concil_reglas_cta FOREIGN KEY (cuenta_contable_id) REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_concil_reglas_aux FOREIGN KEY (auxiliar_id) REFERENCES erp_auxiliares(id),
    CONSTRAINT fk_concil_reglas_cc FOREIGN KEY (centro_costo_id) REFERENCES erp_centros_costo(id),
    CONSTRAINT fk_concil_reglas_diario FOREIGN KEY (diario_id) REFERENCES erp_diarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_conciliaciones — registro polimórfico de cada matching
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_conciliaciones (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    movimiento_bancario_id  BIGINT UNSIGNED NOT NULL,
    referencia_tipo         ENUM('ORDEN_PAGO','COBRO','TRANSFERENCIA_INTERNA','ASIENTO_MANUAL','ECHEQ','REGLA_AUTO') NOT NULL,
    referencia_id           BIGINT UNSIGNED NOT NULL,
    importe_conciliado      DECIMAL(18,2) NOT NULL,
    user_id                 BIGINT UNSIGNED NOT NULL,
    modo                    ENUM('MANUAL','AUTO') NOT NULL DEFAULT 'MANUAL',
    observacion             VARCHAR(300) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_concil_mov (movimiento_bancario_id),
    KEY ix_concil_ref (referencia_tipo, referencia_id),
    CONSTRAINT fk_concil_mov FOREIGN KEY (movimiento_bancario_id) REFERENCES erp_movimientos_bancarios(id),
    CONSTRAINT ck_concil_importe CHECK (importe_conciliado > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Triggers extraídos a 03_tesoreria_programs.sql (la directiva DELIMITER no
-- es un comando SQL nativo; la migration PHP los aplica aparte).

-- ============================================================================
-- 11. VISTAS
-- ============================================================================

-- Vista: saldos consolidados de todas las cuentas
CREATE OR REPLACE VIEW v_tesoreria_saldos AS
SELECT
    cb.id AS cuenta_id,
    cb.codigo,
    cb.nombre,
    b.nombre AS banco,
    cb.tipo,
    m.codigo AS moneda,
    cb.saldo_actual,
    cb.saldo_moneda_origen,
    cb.fecha_ultimo_movimiento
FROM erp_cuentas_bancarias cb
JOIN erp_bancos b ON b.id = cb.banco_id
JOIN erp_monedas m ON m.id = cb.moneda_id
WHERE cb.activo = 1 AND cb.deleted_at IS NULL
UNION ALL
SELECT
    c.id + 10000 AS cuenta_id,
    c.codigo,
    c.nombre,
    'CAJA' AS banco,
    'CAJA' AS tipo,
    m.codigo AS moneda,
    c.saldo_actual,
    c.saldo_actual AS saldo_moneda_origen,
    NULL AS fecha_ultimo_movimiento
FROM erp_cajas c
JOIN erp_monedas m ON m.id = c.moneda_id
WHERE c.activo = 1;

-- Vista: eCheq en cartera (pendientes de depositar)
CREATE OR REPLACE VIEW v_echeq_en_cartera AS
SELECT
    e.id,
    e.numero,
    e.cuit_librador,
    e.razon_social_librador,
    e.banco_origen,
    e.importe,
    m.codigo AS moneda,
    e.fecha_emision,
    e.fecha_pago,
    DATEDIFF(e.fecha_pago, CURDATE()) AS dias_a_vencimiento,
    c.numero AS cobro_numero
FROM erp_echeq e
JOIN erp_monedas m ON m.id = e.moneda_id
LEFT JOIN erp_cobros c ON c.id = e.cobro_id
WHERE e.estado = 'EN_CARTERA'
ORDER BY e.fecha_pago ASC;

-- Vista: movimientos bancarios pendientes de conciliar
CREATE OR REPLACE VIEW v_mov_bancarios_pendientes AS
SELECT
    mb.id,
    mb.fecha,
    mb.concepto,
    mb.debito,
    mb.credito,
    mb.estado,
    mb.etiqueta_sugerida,
    cc.codigo AS cuenta_propuesta,
    cb.codigo AS cuenta_bancaria,
    b.nombre AS banco
FROM erp_movimientos_bancarios mb
JOIN erp_cuentas_bancarias cb ON cb.id = mb.cuenta_bancaria_id
JOIN erp_bancos b ON b.id = cb.banco_id
LEFT JOIN erp_cuentas_contables cc ON cc.id = mb.cuenta_contable_propuesta_id
WHERE mb.estado IN ('PENDIENTE','ETIQUETADO')
ORDER BY mb.fecha DESC, mb.id DESC;

-- Vista: OP pendientes de liberar o acreditar
CREATE OR REPLACE VIEW v_op_pendientes AS
SELECT
    op.id,
    op.numero,
    op.fecha,
    op.tipo,
    a.nombre AS auxiliar,
    m.codigo AS moneda,
    op.importe,
    op.estado,
    op.fecha_carga_banco,
    op.fecha_liberacion
FROM erp_ordenes_pago op
JOIN erp_auxiliares a ON a.id = op.auxiliar_id
JOIN erp_monedas m ON m.id = op.moneda_id
WHERE op.estado IN ('BORRADOR','CARGADA_BANCO','LIBERADA')
ORDER BY op.fecha DESC, op.numero DESC;

SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET SQL_MODE=@OLD_SQL_MODE;

-- FIN DDL_03
