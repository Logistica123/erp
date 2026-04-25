-- ============================================================================
-- DDL_06 — FASE 7: ACTIVOS FIJOS Y PRESUPUESTOS
-- ERP Logística Argentina SRL
-- Dependencias: DDL_01..05
-- Motor: MySQL 8.0+ / MariaDB 10.6+
-- Convenciones: prefijo erp_, FK RESTRICT por defecto, IF NOT EXISTS para
--               idempotencia.
--
-- I1 entrega:
--   • erp_af_categorias (RN-77 umbrales, RN-73 VU dual)
--   • erp_af_bienes
--   • erp_af_movimientos (RN-84 trazabilidad)
--   • erp_af_amortizaciones (definida ahora, llenada en I2)
--   • erp_af_reexpresiones (definida ahora, llenada en I3 con RT 6)
--   • erp_presupuestos, erp_presupuesto_items, erp_presupuesto_versiones
--     (definidas ahora, llenadas en I4)
--
-- ALTERs sobre erp_facturas_compra: af_activado + af_bienes_ids (idempotente
-- vía PHP en la migration, MySQL 8 no soporta ADD COLUMN IF NOT EXISTS).
-- ============================================================================

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';

-- ----------------------------------------------------------------------------
-- erp_af_categorias — catálogo (RN-77 umbral, RN-73 VU dual)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_af_categorias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo                       VARCHAR(20) NOT NULL,
    nombre                       VARCHAR(100) NOT NULL,
    descripcion                  TEXT NULL,
    vida_util_contable_meses     SMALLINT UNSIGNED NOT NULL,
    vida_util_fiscal_meses       SMALLINT UNSIGNED NOT NULL,
    valor_residual_pct           DECIMAL(5,2) NOT NULL DEFAULT 0,
    metodo_amortizacion          ENUM('LINEAL','UNIDADES') NOT NULL DEFAULT 'LINEAL',
    cuenta_bien_id               BIGINT UNSIGNED NOT NULL,
    cuenta_amort_acum_id         BIGINT UNSIGNED NOT NULL,
    cuenta_amort_ejercicio_id    BIGINT UNSIGNED NOT NULL,
    cuenta_resultado_baja_pos_id BIGINT UNSIGNED NOT NULL,
    cuenta_resultado_baja_neg_id BIGINT UNSIGNED NOT NULL,
    umbral_baja_cuantia          DECIMAL(18,2) NOT NULL DEFAULT 0,
    activa                       TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_af_cat_codigo (codigo),
    CONSTRAINT fk_af_cat_cta_bien   FOREIGN KEY (cuenta_bien_id)        REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_af_cat_cta_amort  FOREIGN KEY (cuenta_amort_acum_id)  REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_af_cat_cta_ejerc  FOREIGN KEY (cuenta_amort_ejercicio_id) REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_af_cat_cta_bpos   FOREIGN KEY (cuenta_resultado_baja_pos_id) REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_af_cat_cta_bneg   FOREIGN KEY (cuenta_resultado_baja_neg_id) REFERENCES erp_cuentas_contables(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Categorías de bienes de uso con VU contable+fiscal y cuentas asociadas (SPEC 06).';

-- ----------------------------------------------------------------------------
-- erp_af_bienes — bien individual (1 por objeto)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_af_bienes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id               BIGINT UNSIGNED NOT NULL,
    nro_inventario           VARCHAR(30) NOT NULL,
    categoria_id             BIGINT UNSIGNED NOT NULL,
    descripcion              VARCHAR(255) NOT NULL,
    marca                    VARCHAR(60) NULL,
    modelo                   VARCHAR(60) NULL,
    nro_serie                VARCHAR(100) NULL,
    patente                  VARCHAR(20) NULL,
    fecha_alta               DATE NOT NULL,
    factura_compra_id        BIGINT UNSIGNED NULL,
    proveedor_auxiliar_id    BIGINT UNSIGNED NULL,
    valor_origen             DECIMAL(18,2) NOT NULL,
    moneda_origen            CHAR(3) NOT NULL DEFAULT 'ARS',
    valor_origen_me          DECIMAL(18,2) NULL,
    cotizacion_alta          DECIMAL(18,4) NULL,
    valor_residual_cfg       DECIMAL(18,2) NULL,
    vida_util_contable_meses SMALLINT UNSIGNED NULL,
    vida_util_fiscal_meses   SMALLINT UNSIGNED NULL,
    centro_costo_id          BIGINT UNSIGNED NULL,
    responsable_user_id      BIGINT UNSIGNED NULL,
    ubicacion                VARCHAR(100) NULL,
    estado                   ENUM('ALTA','EN_REPARACION','PRESTADO','BAJA') NOT NULL DEFAULT 'ALTA',
    fecha_baja               DATE NULL,
    motivo_baja              VARCHAR(255) NULL,
    valor_recupero           DECIMAL(18,2) NULL,
    factura_venta_baja_id    BIGINT UNSIGNED NULL,
    indice_alta              DECIMAL(18,6) NULL,
    valor_reexpresado        DECIMAL(18,2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_af_bien_nro (empresa_id, nro_inventario),
    KEY ix_af_bien_estado (empresa_id, estado),
    KEY ix_af_bien_cc (centro_costo_id),
    KEY ix_af_bien_categoria (categoria_id),
    CONSTRAINT fk_af_bien_empresa     FOREIGN KEY (empresa_id)        REFERENCES erp_empresas(id),
    CONSTRAINT fk_af_bien_categoria   FOREIGN KEY (categoria_id)      REFERENCES erp_af_categorias(id),
    CONSTRAINT fk_af_bien_fc          FOREIGN KEY (factura_compra_id) REFERENCES erp_facturas_compra(id),
    CONSTRAINT fk_af_bien_proveedor   FOREIGN KEY (proveedor_auxiliar_id) REFERENCES erp_auxiliares(id),
    CONSTRAINT fk_af_bien_cc          FOREIGN KEY (centro_costo_id)   REFERENCES erp_centros_costo(id),
    CONSTRAINT fk_af_bien_resp        FOREIGN KEY (responsable_user_id) REFERENCES users(id),
    CONSTRAINT fk_af_bien_fv_baja     FOREIGN KEY (factura_venta_baja_id) REFERENCES erp_facturas_venta(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bienes de uso individuales (SPEC 06).';

-- ----------------------------------------------------------------------------
-- erp_af_movimientos — auditoría completa (RN-84)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_af_movimientos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bien_id                  BIGINT UNSIGNED NOT NULL,
    tipo                     ENUM('ALTA','MEJORA','TRANSFERENCIA_CC','REVALUO','BAJA','CAMBIO_RESPONSABLE','CAMBIO_UBICACION') NOT NULL,
    fecha                    DATE NOT NULL,
    importe                  DECIMAL(18,2) NULL,
    cc_anterior_id           BIGINT UNSIGNED NULL,
    cc_nuevo_id              BIGINT UNSIGNED NULL,
    responsable_anterior_id  BIGINT UNSIGNED NULL,
    responsable_nuevo_id     BIGINT UNSIGNED NULL,
    ubicacion_anterior       VARCHAR(100) NULL,
    ubicacion_nueva          VARCHAR(100) NULL,
    descripcion              VARCHAR(255) NULL,
    asiento_id               BIGINT UNSIGNED NULL,
    factura_compra_id        BIGINT UNSIGNED NULL,
    usuario_id               BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_af_mov_bien (bien_id, fecha),
    CONSTRAINT fk_af_mov_bien      FOREIGN KEY (bien_id)               REFERENCES erp_af_bienes(id),
    CONSTRAINT fk_af_mov_cc_old    FOREIGN KEY (cc_anterior_id)        REFERENCES erp_centros_costo(id),
    CONSTRAINT fk_af_mov_cc_new    FOREIGN KEY (cc_nuevo_id)           REFERENCES erp_centros_costo(id),
    CONSTRAINT fk_af_mov_resp_old  FOREIGN KEY (responsable_anterior_id) REFERENCES users(id),
    CONSTRAINT fk_af_mov_resp_new  FOREIGN KEY (responsable_nuevo_id)    REFERENCES users(id),
    CONSTRAINT fk_af_mov_asiento   FOREIGN KEY (asiento_id)            REFERENCES erp_asientos(id),
    CONSTRAINT fk_af_mov_fc        FOREIGN KEY (factura_compra_id)     REFERENCES erp_facturas_compra(id),
    CONSTRAINT fk_af_mov_user      FOREIGN KEY (usuario_id)            REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bitácora de eventos sobre bienes de uso (SPEC 06 RN-84).';

-- ----------------------------------------------------------------------------
-- erp_af_amortizaciones — contable + fiscal (RN-73, RN-74)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_af_amortizaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bien_id              BIGINT UNSIGNED NOT NULL,
    periodo_anio         SMALLINT UNSIGNED NOT NULL,
    periodo_mes          TINYINT UNSIGNED NOT NULL,
    base_amort_contable  DECIMAL(18,2) NOT NULL,
    amort_contable_mes   DECIMAL(18,2) NOT NULL,
    amort_contable_acum  DECIMAL(18,2) NOT NULL,
    base_amort_fiscal    DECIMAL(18,2) NOT NULL,
    amort_fiscal_mes     DECIMAL(18,2) NOT NULL,
    amort_fiscal_acum    DECIMAL(18,2) NOT NULL,
    diferencia_mes       DECIMAL(18,2) GENERATED ALWAYS AS (amort_contable_mes - amort_fiscal_mes) STORED,
    asiento_id           BIGINT UNSIGNED NULL,
    generado_at          DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_af_amort (bien_id, periodo_anio, periodo_mes),
    KEY ix_af_amort_periodo (periodo_anio, periodo_mes),
    CONSTRAINT fk_af_amort_bien    FOREIGN KEY (bien_id)    REFERENCES erp_af_bienes(id),
    CONSTRAINT fk_af_amort_asiento FOREIGN KEY (asiento_id) REFERENCES erp_asientos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Amortización mensual contable + fiscal por bien (SPEC 06 RN-73).';

-- ----------------------------------------------------------------------------
-- erp_af_reexpresiones — RT 6 al cierre (RN-82)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_af_reexpresiones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bien_id              BIGINT UNSIGNED NOT NULL,
    ejercicio_id         BIGINT UNSIGNED NOT NULL,
    indice_origen        DECIMAL(18,6) NOT NULL,
    indice_cierre        DECIMAL(18,6) NOT NULL,
    coeficiente          DECIMAL(18,6) NOT NULL,
    valor_original       DECIMAL(18,2) NOT NULL,
    valor_reexpresado    DECIMAL(18,2) NOT NULL,
    resultado_exposicion DECIMAL(18,2) NOT NULL,
    asiento_id           BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_af_reexp (bien_id, ejercicio_id),
    CONSTRAINT fk_af_reexp_bien     FOREIGN KEY (bien_id)      REFERENCES erp_af_bienes(id),
    CONSTRAINT fk_af_reexp_ejerc    FOREIGN KEY (ejercicio_id) REFERENCES erp_ejercicios(id),
    CONSTRAINT fk_af_reexp_asiento  FOREIGN KEY (asiento_id)   REFERENCES erp_asientos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reexpresión RT 6 por bien al cierre de ejercicio (SPEC 06 RN-82).';

-- ----------------------------------------------------------------------------
-- erp_presupuestos — cabecera (RN-85, RN-86, RN-88)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_presupuestos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id        BIGINT UNSIGNED NOT NULL,
    ejercicio_id      BIGINT UNSIGNED NOT NULL,
    nombre            VARCHAR(100) NOT NULL,
    estado            ENUM('BORRADOR','VIGENTE','HISTORICO','DESCARTADO') NOT NULL DEFAULT 'BORRADOR',
    es_reforecast     TINYINT(1) NOT NULL DEFAULT 0,
    forecast_base_id  BIGINT UNSIGNED NULL,
    moneda            CHAR(3) NOT NULL DEFAULT 'ARS',
    descripcion       TEXT NULL,
    creado_por        BIGINT UNSIGNED NOT NULL,
    aprobado_por      BIGINT UNSIGNED NULL,
    aprobado_at       DATETIME NULL,
    vigente_desde     DATE NULL,
    vigente_hasta     DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_presup_estado (empresa_id, ejercicio_id, estado),
    CONSTRAINT fk_presup_empresa   FOREIGN KEY (empresa_id)       REFERENCES erp_empresas(id),
    CONSTRAINT fk_presup_ejercicio FOREIGN KEY (ejercicio_id)     REFERENCES erp_ejercicios(id),
    CONSTRAINT fk_presup_base      FOREIGN KEY (forecast_base_id) REFERENCES erp_presupuestos(id),
    CONSTRAINT fk_presup_creador   FOREIGN KEY (creado_por)       REFERENCES users(id),
    CONSTRAINT fk_presup_aprob     FOREIGN KEY (aprobado_por)     REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cabecera de presupuesto (SPEC 06 RN-85).';

-- ----------------------------------------------------------------------------
-- erp_presupuesto_items — cuenta × CC × mes (RN-88)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_presupuesto_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    presupuesto_id    BIGINT UNSIGNED NOT NULL,
    cuenta_id         BIGINT UNSIGNED NOT NULL,
    centro_costo_id   BIGINT UNSIGNED NULL,
    mes               TINYINT UNSIGNED NOT NULL,
    importe           DECIMAL(18,2) NOT NULL,
    notas             VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_presup_item (presupuesto_id, cuenta_id, centro_costo_id, mes),
    KEY ix_presup_cuenta (cuenta_id, mes),
    CONSTRAINT fk_pi_presupuesto FOREIGN KEY (presupuesto_id) REFERENCES erp_presupuestos(id) ON DELETE CASCADE,
    CONSTRAINT fk_pi_cuenta      FOREIGN KEY (cuenta_id)      REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_pi_cc          FOREIGN KEY (centro_costo_id) REFERENCES erp_centros_costo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Líneas de presupuesto cuenta×CC×mes (SPEC 06 RN-88).';

-- ----------------------------------------------------------------------------
-- erp_presupuesto_versiones — bitácora
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_presupuesto_versiones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    presupuesto_id   BIGINT UNSIGNED NOT NULL,
    evento           ENUM('CREADO','APROBADO','VIGENTE','REFORECAST','HISTORICO','DESCARTADO') NOT NULL,
    usuario_id       BIGINT UNSIGNED NOT NULL,
    detalle          VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_pv_presup (presupuesto_id, created_at),
    CONSTRAINT fk_pv_presup FOREIGN KEY (presupuesto_id) REFERENCES erp_presupuestos(id) ON DELETE CASCADE,
    CONSTRAINT fk_pv_user   FOREIGN KEY (usuario_id)     REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bitácora de cambios de estado del presupuesto (SPEC 06).';

SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET SQL_MODE=@OLD_SQL_MODE;
