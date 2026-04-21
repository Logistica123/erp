-- ============================================================================
-- ERP Logística Argentina SRL — DDL Fase 2: CONTABILIDAD NÚCLEO
-- Motor: MySQL 8.0+ / MariaDB 10.6+
-- Dependencias: DDL_01_Fundaciones.sql DEBE estar ejecutado antes.
-- Autor: Claude (arquitecto) · Fecha: 2026-04-18
-- ============================================================================
--
-- OBJETO: Partida doble estricta. Un ASIENTO tiene N MOVIMIENTOS; la suma
-- de débitos debe igualar la suma de créditos. Esto se valida por TRIGGER y
-- también en capa aplicación (defensa en profundidad).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 1. PLAN DE CUENTAS
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_cuentas_contables (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id        BIGINT UNSIGNED NOT NULL,
    codigo            VARCHAR(20) NOT NULL COMMENT 'Ej. 1.1.2.01',
    codigo_padre_id   BIGINT UNSIGNED NULL,
    nivel             TINYINT UNSIGNED NOT NULL COMMENT '1=Grupo, 2=Rubro, 3=Subrubro, 4=Cuenta imputable',
    nombre            VARCHAR(200) NOT NULL,
    tipo              ENUM('A','P','PN','RP','RN','CO') NOT NULL COMMENT 'Activo/Pasivo/Patrim.Neto/Result+/Result-/Orden',
    rubro_ec          VARCHAR(120) NULL COMMENT 'Rubro del Estado Contable RT 8/9',
    imputable         TINYINT(1) NOT NULL DEFAULT 0,
    moneda            CHAR(3) NULL COMMENT 'NULL=multi, ARS, USD',
    admite_cc         TINYINT(1) NOT NULL DEFAULT 0,
    admite_auxiliar   TINYINT(1) NOT NULL DEFAULT 0,
    tipo_auxiliar     ENUM('Cliente','Proveedor','Distribuidor','Empleado','Socio','Vehiculo','Sucursal','Colocacion','Bien','Organismo') NULL,
    etiqueta_cierre   VARCHAR(40) NULL COMMENT 'Etiqueta pipeline cierres-contables (UNIQUE cuando no null)',
    saldo_normal      ENUM('DEUDOR','ACREEDOR') NULL COMMENT 'Solo informativo',
    regularizadora    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ej. Prev. Deudores Incobrables',
    notas             TEXT NULL,
    activo            TINYINT(1) NOT NULL DEFAULT 1,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cuenta (empresa_id, codigo),
    UNIQUE KEY uk_cuenta_etiqueta (empresa_id, etiqueta_cierre),
    KEY idx_cuenta_padre (codigo_padre_id),
    KEY idx_cuenta_imputable (empresa_id, imputable, activo),
    KEY idx_cuenta_tipo (empresa_id, tipo),
    CONSTRAINT fk_cuenta_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id),
    CONSTRAINT fk_cuenta_padre FOREIGN KEY (codigo_padre_id) REFERENCES erp_cuentas_contables (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plan de cuentas. Nivel 4 = imputable; niveles 1-3 son agrupadoras.';

-- ============================================================================
-- 2. AUXILIARES DE MAYOR (puente genérico a entidades)
-- ============================================================================
-- Un auxiliar puede ser: un distribuidor (personas), un cliente (clientes),
-- un proveedor (personas con rol), un empleado, etc. Usamos polimorfismo
-- suave: tabla_referencia + id_referencia.
CREATE TABLE IF NOT EXISTS erp_auxiliares (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    tipo            ENUM('Cliente','Proveedor','Distribuidor','Empleado','Socio','Vehiculo','Sucursal','Colocacion','Bien','Organismo') NOT NULL,
    tabla_ref       VARCHAR(50) NULL COMMENT 'Tabla externa: personas, clientes, etc.',
    id_ref          BIGINT UNSIGNED NULL COMMENT 'ID en la tabla externa',
    codigo          VARCHAR(40) NOT NULL COMMENT 'Código interno del auxiliar',
    nombre          VARCHAR(200) NOT NULL,
    cuit            CHAR(11) NULL,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_aux (empresa_id, tipo, codigo),
    KEY idx_aux_ref (tabla_ref, id_ref),
    KEY idx_aux_cuit (cuit),
    CONSTRAINT fk_aux_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auxiliares de mayor. Puente a personas/clientes sin duplicar maestros.';

-- ============================================================================
-- 3. DIARIOS (clasificación de asientos)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_diarios (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    codigo          VARCHAR(20) NOT NULL COMMENT 'GEN, VTA, CPR, TES, IVA, BAN, AJU, CIE, APE',
    nombre          VARCHAR(100) NOT NULL,
    descripcion     VARCHAR(400) NULL,
    tipo            ENUM('MANUAL','SISTEMA','BANCO','VENTAS','COMPRAS','TESORERIA','AJUSTE','APERTURA','CIERRE') NOT NULL DEFAULT 'MANUAL',
    numerador_actual BIGINT UNSIGNED NOT NULL DEFAULT 0,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_diario (empresa_id, codigo),
    CONSTRAINT fk_diario_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Diarios contables. Cada asiento pertenece a uno.';

-- ============================================================================
-- 4. ASIENTOS (cabecera)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_asientos (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id          BIGINT UNSIGNED NOT NULL,
    ejercicio_id        BIGINT UNSIGNED NOT NULL,
    periodo_id          BIGINT UNSIGNED NOT NULL,
    diario_id           BIGINT UNSIGNED NOT NULL,
    numero              BIGINT UNSIGNED NOT NULL COMMENT 'Correlativo por diario y ejercicio',
    fecha               DATE NOT NULL,
    fecha_contabilizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    glosa               VARCHAR(500) NULL COMMENT 'Descripción / concepto del asiento',
    origen              ENUM('MANUAL','FACTURA_VTA','FACTURA_CPR','COBRO','PAGO','BANCO','AJUSTE','CIERRE','APERTURA','REVALUACION','NOMINA','IMPUESTO') NOT NULL DEFAULT 'MANUAL',
    origen_id           BIGINT UNSIGNED NULL COMMENT 'ID del registro que lo generó (factura, cobro, etc.)',
    origen_tabla        VARCHAR(60) NULL COMMENT 'Nombre de la tabla de origen',
    estado              ENUM('BORRADOR','CONTABILIZADO','ANULADO') NOT NULL DEFAULT 'CONTABILIZADO',
    moneda_base         CHAR(3) NOT NULL DEFAULT 'ARS',
    total_debe          DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_haber         DECIMAL(18,2) NOT NULL DEFAULT 0,
    desbalance          DECIMAL(18,2) GENERATED ALWAYS AS (total_debe - total_haber) STORED,
    usuario_id          BIGINT UNSIGNED NOT NULL COMMENT 'users.id que creó',
    usuario_modifico_id BIGINT UNSIGNED NULL,
    usuario_anulo_id    BIGINT UNSIGNED NULL,
    fecha_anulacion     DATETIME NULL,
    motivo_anulacion    VARCHAR(400) NULL,
    asiento_reversa_id  BIGINT UNSIGNED NULL COMMENT 'FK auto-referencia al asiento que lo reversa',
    hash_integridad     CHAR(64) NULL COMMENT 'SHA-256(cabecera + movimientos) para detectar manipulación',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_asiento (empresa_id, ejercicio_id, diario_id, numero),
    KEY idx_asiento_fecha (empresa_id, fecha),
    KEY idx_asiento_periodo (periodo_id, estado),
    KEY idx_asiento_origen (origen, origen_tabla, origen_id),
    KEY idx_asiento_estado (empresa_id, estado, fecha),
    CONSTRAINT fk_asiento_empresa  FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id),
    CONSTRAINT fk_asiento_ejercicio FOREIGN KEY (ejercicio_id) REFERENCES erp_ejercicios (id),
    CONSTRAINT fk_asiento_periodo  FOREIGN KEY (periodo_id) REFERENCES erp_periodos (id),
    CONSTRAINT fk_asiento_diario   FOREIGN KEY (diario_id) REFERENCES erp_diarios (id),
    CONSTRAINT fk_asiento_reversa  FOREIGN KEY (asiento_reversa_id) REFERENCES erp_asientos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cabecera de asientos contables. Partida doble validada por trigger.';

-- ============================================================================
-- 5. MOVIMIENTOS DEL ASIENTO (detalle)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_movimientos_asiento (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asiento_id          BIGINT UNSIGNED NOT NULL,
    linea               SMALLINT UNSIGNED NOT NULL COMMENT 'Orden visual 1,2,3...',
    cuenta_id           BIGINT UNSIGNED NOT NULL,
    centro_costo_id     BIGINT UNSIGNED NULL,
    auxiliar_id         BIGINT UNSIGNED NULL,
    glosa               VARCHAR(400) NULL,
    debe                DECIMAL(18,2) NOT NULL DEFAULT 0,
    haber               DECIMAL(18,2) NOT NULL DEFAULT 0,
    moneda              CHAR(3) NOT NULL DEFAULT 'ARS',
    importe_origen      DECIMAL(18,2) NULL COMMENT 'Si moneda != ARS, importe en moneda original',
    cotizacion          DECIMAL(18,4) NULL COMMENT 'Cotización aplicada',
    referencia_ext      VARCHAR(80) NULL COMMENT 'Ref a movimiento bancario, factura, etc.',
    -- Integración con módulos de origen
    factura_venta_id    BIGINT UNSIGNED NULL,
    factura_compra_id   BIGINT UNSIGNED NULL,
    movimiento_banco_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_mov_asiento (asiento_id, linea),
    KEY idx_mov_cuenta (cuenta_id),
    KEY idx_mov_auxiliar (auxiliar_id),
    KEY idx_mov_cc (centro_costo_id),
    KEY idx_mov_fecha_cuenta (cuenta_id, asiento_id),
    CONSTRAINT fk_mov_asiento FOREIGN KEY (asiento_id) REFERENCES erp_asientos (id) ON DELETE CASCADE,
    CONSTRAINT fk_mov_cuenta  FOREIGN KEY (cuenta_id) REFERENCES erp_cuentas_contables (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_cc      FOREIGN KEY (centro_costo_id) REFERENCES erp_centros_costo (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_aux     FOREIGN KEY (auxiliar_id) REFERENCES erp_auxiliares (id) ON DELETE RESTRICT,
    CONSTRAINT chk_mov_signo  CHECK (
        (debe >= 0 AND haber >= 0)
        AND (debe = 0 OR haber = 0)
        AND (debe + haber > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Líneas de asiento. CHECK asegura una sola columna con valor por fila.';

-- ============================================================================
-- 6. MAPEO ETIQUETA → CUENTA (para auto-imputación de bancos)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_mapeo_etiqueta_cuenta (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id          BIGINT UNSIGNED NOT NULL,
    etiqueta            VARCHAR(40) NOT NULL COMMENT 'EFECTIVO, BCO-ICBC-ARS, etc.',
    descripcion         VARCHAR(200) NULL,
    cuenta_id           BIGINT UNSIGNED NOT NULL,
    contrapartida_hint  VARCHAR(200) NULL COMMENT 'Sugerencia (texto) para la contrapartida',
    activo              TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_mapeo (empresa_id, etiqueta),
    CONSTRAINT fk_mapeo_cuenta FOREIGN KEY (cuenta_id) REFERENCES erp_cuentas_contables (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auto-imputación de movimientos bancarios desde pipeline cierres-contables.';

-- ============================================================================
-- 7. SALDOS ACUMULADOS POR CUENTA/PERÍODO (materializados para performance)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_saldos_cuenta (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    cuenta_id       BIGINT UNSIGNED NOT NULL,
    periodo_id      BIGINT UNSIGNED NOT NULL,
    auxiliar_id     BIGINT UNSIGNED NULL,
    centro_costo_id BIGINT UNSIGNED NULL,
    saldo_inicial   DECIMAL(18,2) NOT NULL DEFAULT 0,
    debitos         DECIMAL(18,2) NOT NULL DEFAULT 0,
    creditos        DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_final     DECIMAL(18,2) GENERATED ALWAYS AS (saldo_inicial + debitos - creditos) STORED,
    actualizado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_saldo (empresa_id, cuenta_id, periodo_id, auxiliar_id, centro_costo_id),
    KEY idx_saldo_periodo (periodo_id),
    KEY idx_saldo_cuenta (cuenta_id, periodo_id),
    CONSTRAINT fk_saldo_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas (id),
    CONSTRAINT fk_saldo_cuenta  FOREIGN KEY (cuenta_id) REFERENCES erp_cuentas_contables (id),
    CONSTRAINT fk_saldo_periodo FOREIGN KEY (periodo_id) REFERENCES erp_periodos (id),
    CONSTRAINT fk_saldo_aux     FOREIGN KEY (auxiliar_id) REFERENCES erp_auxiliares (id),
    CONSTRAINT fk_saldo_cc      FOREIGN KEY (centro_costo_id) REFERENCES erp_centros_costo (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Saldos materializados para consultas rápidas de mayor/balance.';

-- ============================================================================
-- 8. ASIENTOS PLANTILLA (para operaciones repetitivas)
-- ============================================================================
CREATE TABLE IF NOT EXISTS erp_asientos_plantilla (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    codigo          VARCHAR(40) NOT NULL,
    nombre          VARCHAR(150) NOT NULL,
    descripcion     VARCHAR(400) NULL,
    diario_id       BIGINT UNSIGNED NOT NULL,
    json_definicion JSON NOT NULL COMMENT 'Estructura de líneas con placeholders',
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_plantilla (empresa_id, codigo),
    CONSTRAINT fk_plantilla_diario FOREIGN KEY (diario_id) REFERENCES erp_diarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plantillas de asientos recurrentes.';

