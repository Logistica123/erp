-- ============================================================================
-- DDL_05 — FASE 5: IMPUESTOS COMPLEMENTARIOS, REPORTES Y EECC
-- ERP Logística Argentina SRL
-- Dependencias: DDL_01_Fundaciones.sql, DDL_02_Contabilidad.sql,
--               DDL_03_Tesoreria.sql, DDL_04_VentasCompras.sql
-- Motor: MySQL 8.0+ / MariaDB 10.6+
-- Charset: utf8mb4 / Collation: utf8mb4_unicode_ci
-- Convenciones: prefijo erp_, FK RESTRICT por defecto, IF NOT EXISTS para
--               idempotencia (bases con DDL aplicado parcialmente).
--
-- Bloque H1 entrega:
--   • erp_periodos_fiscales (RN-44)
--   • erp_libro_iva_ventas_periodo, erp_libro_iva_compras_periodo (RN-45..47)
--   • Tablas auxiliares listadas como dependencia en RN-48..72:
--     erp_iva_ddjj, erp_retenciones_practicadas, erp_percepciones_sufridas,
--     erp_iibb_cm_declaracion, erp_iibb_jurisdiccion_mov,
--     erp_ganancias_liquidacion, erp_ganancias_anticipos, erp_bp_participaciones,
--     erp_calendario_vencimientos, erp_iibb_jurisdicciones,
--     erp_regimenes_retencion, erp_iibb_coeficientes,
--     erp_ganancias_ajustes_tipo, erp_ganancias_escala, erp_bp_alicuotas,
--     erp_reportes_cache.
--
-- ALTERs sobre tablas pre-existentes (idempotentes vía IF NOT EXISTS dentro
-- del programs.sql) viven al final del archivo.
-- ============================================================================

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';

-- ============================================================================
-- 1. CATÁLOGOS / MAESTROS DE IMPUESTOS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_iibb_jurisdicciones — códigos SIFERE de las jurisdicciones IIBB
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_iibb_jurisdicciones (
    codigo            CHAR(3) NOT NULL,
    nombre            VARCHAR(100) NOT NULL,
    activa            TINYINT(1) NOT NULL DEFAULT 1,
    alicuota_default  DECIMAL(6,4) NULL,
    portal_url        VARCHAR(200) NULL,
    PRIMARY KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_calendario_vencimientos — fechas de vencimiento por impuesto/período
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_calendario_vencimientos (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    anio                  SMALLINT UNSIGNED NOT NULL,
    impuesto              VARCHAR(32) NOT NULL,
    periodo_identificador VARCHAR(20) NOT NULL,
    terminacion_cuit      TINYINT UNSIGNED NULL,
    fecha_vencimiento     DATE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_calendario (anio, impuesto, periodo_identificador, terminacion_cuit),
    KEY ix_calendario_fecha (fecha_vencimiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_regimenes_retencion — régimen → mínimo no retenido + alícuota (RN-50)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_regimenes_retencion (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(8) NOT NULL,
    tipo            ENUM('IVA','GAN','SUSS','IIBB') NOT NULL,
    descripcion     VARCHAR(200) NOT NULL,
    minimo_no_ret   DECIMAL(18,2) NOT NULL DEFAULT 0,
    alicuota        DECIMAL(6,4) NOT NULL,
    jurisdiccion    CHAR(3) NULL COMMENT 'Para IIBB: código SIFERE',
    vigente_desde   DATE NOT NULL,
    vigente_hasta   DATE NULL,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_regimen (codigo, tipo, vigente_desde),
    CONSTRAINT fk_imp_reg_jur FOREIGN KEY (jurisdiccion) REFERENCES erp_iibb_jurisdicciones(codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_iibb_coeficientes — coeficientes unificados CM por jurisdicción (RN-52)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_iibb_coeficientes (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    anio_vigencia     SMALLINT UNSIGNED NOT NULL,
    jurisdiccion      CHAR(3) NOT NULL,
    coeficiente       DECIMAL(10,8) NOT NULL,
    origen            ENUM('CM05','MANUAL') NOT NULL DEFAULT 'CM05',
    estado            ENUM('DRAFT','VIGENTE') NOT NULL DEFAULT 'DRAFT',
    aprobado_at       DATETIME NULL,
    aprobado_user_id  BIGINT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_coef (anio_vigencia, jurisdiccion),
    CONSTRAINT fk_imp_coef_jur  FOREIGN KEY (jurisdiccion) REFERENCES erp_iibb_jurisdicciones(codigo),
    CONSTRAINT fk_imp_coef_user FOREIGN KEY (aprobado_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_ganancias_escala — escala art 73 LIG por año (RN-56)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_ganancias_escala (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vigente_desde     DATE NOT NULL,
    vigente_hasta     DATE NULL,
    tramo             TINYINT UNSIGNED NOT NULL,
    limite_inferior   DECIMAL(18,2) NOT NULL,
    limite_superior   DECIMAL(18,2) NULL,
    cuota_fija        DECIMAL(18,2) NOT NULL DEFAULT 0,
    alicuota_marginal DECIMAL(6,4) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_escala (vigente_desde, tramo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_ganancias_ajustes_tipo — catálogo de ajustes fiscales (RN-55)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_ganancias_ajustes_tipo (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo      VARCHAR(40) NOT NULL,
    tipo        ENUM('MAS','MENOS') NOT NULL,
    descripcion VARCHAR(200) NOT NULL,
    activo      TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ajuste_tipo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_bp_alicuotas — alícuotas BP por año (§10.5)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_bp_alicuotas (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vigente_desde DATE NOT NULL,
    vigente_hasta DATE NULL,
    tipo          ENUM('PARTICIPACIONES','GENERAL') NOT NULL,
    alicuota      DECIMAL(6,4) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_bp_alicuota (vigente_desde, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. PERÍODOS FISCALES Y LIBRO IVA DIGITAL (núcleo H1)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_periodos_fiscales — un período por impuesto + año-mes / ejercicio
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_periodos_fiscales (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id          BIGINT UNSIGNED NOT NULL,
    impuesto            ENUM(
        'IVA','SICORE','SIRE','IIBB_CM','IIBB_CABA','IIBB_PBA',
        'GAN_ANUAL','GAN_ANTICIPO','BP_PART'
    ) NOT NULL,
    anio                SMALLINT UNSIGNED NOT NULL,
    mes                 TINYINT UNSIGNED NULL COMMENT 'NULL para anuales',
    ejercicio_id        BIGINT UNSIGNED NULL COMMENT 'set para anuales',
    estado              ENUM('ABIERTO','EN_REVISION','APROBADO','PRESENTADO','CERRADO','RECTIFICATIVA')
                        NOT NULL DEFAULT 'ABIERTO',
    fecha_vencimiento   DATE NOT NULL,
    fecha_presentacion  DATE NULL,
    nro_tramite         VARCHAR(50) NULL,
    acuse_path          VARCHAR(500) NULL,
    observaciones       TEXT NULL,
    rectifica_a_id      BIGINT UNSIGNED NULL COMMENT 'Si es RECTIFICATIVA, apunta al original',
    revisor_user_id     BIGINT UNSIGNED NULL,
    aprobado_user_id    BIGINT UNSIGNED NULL,
    aprobado_at         DATETIME NULL,
    presentado_user_id  BIGINT UNSIGNED NULL,
    presentado_at       DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_periodo (empresa_id, impuesto, anio, mes, ejercicio_id, rectifica_a_id),
    KEY ix_periodo_estado (estado, fecha_vencimiento),
    KEY ix_periodo_anio_mes (impuesto, anio, mes),
    CONSTRAINT fk_imp_periodo_empresa   FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_imp_periodo_ejercicio FOREIGN KEY (ejercicio_id) REFERENCES erp_ejercicios(id),
    CONSTRAINT fk_imp_periodo_rectifica FOREIGN KEY (rectifica_a_id) REFERENCES erp_periodos_fiscales(id),
    CONSTRAINT fk_imp_periodo_revisor   FOREIGN KEY (revisor_user_id) REFERENCES users(id),
    CONSTRAINT fk_imp_periodo_aprobador FOREIGN KEY (aprobado_user_id) REFERENCES users(id),
    CONSTRAINT fk_imp_periodo_presenta  FOREIGN KEY (presentado_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_libro_iva_ventas_periodo — cabecera ventas por período
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_libro_iva_ventas_periodo (
    id                              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    periodo_id                      BIGINT UNSIGNED NOT NULL,
    neto_gravado_21                 DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_gravado_10_5               DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_gravado_27                 DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_gravado_5                  DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_gravado_2_5                DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_no_gravado                 DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_exento                     DECIMAL(18,2) NOT NULL DEFAULT 0,
    iva_21                          DECIMAL(18,2) NOT NULL DEFAULT 0,
    iva_10_5                        DECIMAL(18,2) NOT NULL DEFAULT 0,
    iva_27                          DECIMAL(18,2) NOT NULL DEFAULT 0,
    iva_5                           DECIMAL(18,2) NOT NULL DEFAULT 0,
    iva_2_5                         DECIMAL(18,2) NOT NULL DEFAULT 0,
    percepciones_iibb_practicadas   DECIMAL(18,2) NOT NULL DEFAULT 0,
    otros_tributos                  DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_facturado                 DECIMAL(18,2) NOT NULL DEFAULT 0,
    cantidad_comprobantes           INT UNSIGNED NOT NULL DEFAULT 0,
    archivo_f8001_path              VARCHAR(500) NULL,
    archivo_f8001_hash              CHAR(64) NULL,
    generado_at                     DATETIME NULL,
    generado_user_id                BIGINT UNSIGNED NULL,
    created_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_liv_periodo (periodo_id),
    CONSTRAINT fk_imp_liv_periodo  FOREIGN KEY (periodo_id) REFERENCES erp_periodos_fiscales(id),
    CONSTRAINT fk_imp_liv_user     FOREIGN KEY (generado_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_libro_iva_compras_periodo — cabecera compras por período
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_libro_iva_compras_periodo (
    id                              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    periodo_id                      BIGINT UNSIGNED NOT NULL,
    neto_gravado_21                 DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_gravado_10_5               DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_gravado_27                 DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_gravado_5                  DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_gravado_2_5                DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_no_gravado                 DECIMAL(18,2) NOT NULL DEFAULT 0,
    neto_exento                     DECIMAL(18,2) NOT NULL DEFAULT 0,
    iva_21                          DECIMAL(18,2) NOT NULL DEFAULT 0,
    iva_10_5                        DECIMAL(18,2) NOT NULL DEFAULT 0,
    iva_27                          DECIMAL(18,2) NOT NULL DEFAULT 0,
    iva_5                           DECIMAL(18,2) NOT NULL DEFAULT 0,
    iva_2_5                         DECIMAL(18,2) NOT NULL DEFAULT 0,
    percepciones_iva_sufridas       DECIMAL(18,2) NOT NULL DEFAULT 0,
    percepciones_iibb_sufridas      DECIMAL(18,2) NOT NULL DEFAULT 0,
    retenciones_iva_sufridas        DECIMAL(18,2) NOT NULL DEFAULT 0,
    retenciones_gan_sufridas        DECIMAL(18,2) NOT NULL DEFAULT 0,
    otros_tributos                  DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_facturado                 DECIMAL(18,2) NOT NULL DEFAULT 0,
    cantidad_comprobantes           INT UNSIGNED NOT NULL DEFAULT 0,
    archivo_f8001_path              VARCHAR(500) NULL,
    archivo_f8001_hash              CHAR(64) NULL,
    generado_at                     DATETIME NULL,
    generado_user_id                BIGINT UNSIGNED NULL,
    created_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lic_periodo (periodo_id),
    CONSTRAINT fk_imp_lic_periodo FOREIGN KEY (periodo_id) REFERENCES erp_periodos_fiscales(id),
    CONSTRAINT fk_imp_lic_user    FOREIGN KEY (generado_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. DDJJ Y ARCHIVOS COMPLEMENTARIOS (definidas en H1, pobladas por bloques siguientes)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_iva_ddjj — DDJJ IVA F.2002 (cuerpo se llena en H2)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_iva_ddjj (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    periodo_id                  BIGINT UNSIGNED NOT NULL,
    debito_fiscal               DECIMAL(18,2) NOT NULL DEFAULT 0,
    credito_fiscal              DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_tecnico               DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_libre_disp_anterior   DECIMAL(18,2) NOT NULL DEFAULT 0,
    retenciones_sufridas        DECIMAL(18,2) NOT NULL DEFAULT 0,
    percepciones_sufridas       DECIMAL(18,2) NOT NULL DEFAULT 0,
    pagos_a_cuenta              DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_libre_disp_final      DECIMAL(18,2) NOT NULL DEFAULT 0,
    importe_a_pagar             DECIMAL(18,2) NOT NULL DEFAULT 0,
    archivo_f2002_path          VARCHAR(500) NULL,
    archivo_f2002_hash          CHAR(64) NULL,
    generado_at                 DATETIME NULL,
    volante_pago_id             BIGINT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_iva_periodo (periodo_id),
    CONSTRAINT fk_imp_iva_periodo FOREIGN KEY (periodo_id) REFERENCES erp_periodos_fiscales(id),
    CONSTRAINT fk_imp_iva_op      FOREIGN KEY (volante_pago_id) REFERENCES erp_ordenes_pago(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_retenciones_practicadas — SICORE/SIRE (lógica en H3)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_retenciones_practicadas (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id          BIGINT UNSIGNED NOT NULL,
    factura_compra_id   BIGINT UNSIGNED NULL,
    orden_pago_id       BIGINT UNSIGNED NOT NULL,
    proveedor_id        BIGINT UNSIGNED NOT NULL,
    cuit_retenido       CHAR(11) NOT NULL,
    tipo_retencion      ENUM('IVA','GAN','SUSS','IIBB') NOT NULL,
    regimen             VARCHAR(8) NOT NULL,
    fecha_emision       DATE NOT NULL,
    base_imponible      DECIMAL(18,2) NOT NULL,
    alicuota            DECIMAL(6,4) NOT NULL,
    importe_retenido    DECIMAL(18,2) NOT NULL,
    nro_certificado     VARCHAR(30) NOT NULL,
    estado              ENUM('EMITIDO','ANULADO') NOT NULL DEFAULT 'EMITIDO',
    comprobante_origen  VARCHAR(30) NULL,
    periodo_id          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_retencion_cert (tipo_retencion, nro_certificado),
    KEY ix_ret_periodo (periodo_id, tipo_retencion),
    KEY ix_ret_cuit (cuit_retenido, fecha_emision),
    CONSTRAINT fk_imp_ret_empresa   FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_imp_ret_fc        FOREIGN KEY (factura_compra_id) REFERENCES erp_facturas_compra(id),
    CONSTRAINT fk_imp_ret_op        FOREIGN KEY (orden_pago_id) REFERENCES erp_ordenes_pago(id),
    CONSTRAINT fk_imp_ret_proveedor FOREIGN KEY (proveedor_id) REFERENCES erp_auxiliares(id),
    CONSTRAINT fk_imp_ret_periodo   FOREIGN KEY (periodo_id) REFERENCES erp_periodos_fiscales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_percepciones_sufridas — agregado de percepciones por período (H2/H3)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_percepciones_sufridas (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    factura_compra_id   BIGINT UNSIGNED NOT NULL,
    tipo                ENUM('IVA','IIBB_CABA','IIBB_PBA','IIBB_CM','GAN','SUSS','IMPUESTO_INT') NOT NULL,
    regimen             VARCHAR(8) NULL,
    base                DECIMAL(18,2) NOT NULL,
    alicuota            DECIMAL(6,4) NULL,
    importe             DECIMAL(18,2) NOT NULL,
    periodo_id          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_perc_periodo (periodo_id, tipo),
    CONSTRAINT fk_imp_perc_fc      FOREIGN KEY (factura_compra_id) REFERENCES erp_facturas_compra(id),
    CONSTRAINT fk_imp_perc_periodo FOREIGN KEY (periodo_id) REFERENCES erp_periodos_fiscales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_iibb_cm_declaracion — CM03/CM05 por jurisdicción (H4)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_iibb_cm_declaracion (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    periodo_id              BIGINT UNSIGNED NOT NULL,
    tipo                    ENUM('CM03','CM05') NOT NULL,
    jurisdiccion            CHAR(3) NOT NULL,
    base_imponible          DECIMAL(18,2) NOT NULL DEFAULT 0,
    coeficiente             DECIMAL(10,8) NOT NULL DEFAULT 0,
    base_atribuida          DECIMAL(18,2) NOT NULL DEFAULT 0,
    alicuota                DECIMAL(6,4) NOT NULL DEFAULT 0,
    impuesto_determinado    DECIMAL(18,2) NOT NULL DEFAULT 0,
    percepciones_sufridas   DECIMAL(18,2) NOT NULL DEFAULT 0,
    retenciones_sufridas    DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_anterior          DECIMAL(18,2) NOT NULL DEFAULT 0,
    importe_a_pagar         DECIMAL(18,2) NOT NULL DEFAULT 0,
    archivo_sifere_path     VARCHAR(500) NULL,
    generado_at             DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cm_periodo_jur (periodo_id, jurisdiccion),
    CONSTRAINT fk_imp_cm_periodo FOREIGN KEY (periodo_id) REFERENCES erp_periodos_fiscales(id),
    CONSTRAINT fk_imp_cm_jur     FOREIGN KEY (jurisdiccion) REFERENCES erp_iibb_jurisdicciones(codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_iibb_jurisdiccion_mov — atribuciones (cálculo CM05, H4)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_iibb_jurisdiccion_mov (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id          BIGINT UNSIGNED NOT NULL,
    fecha               DATE NOT NULL,
    jurisdiccion        CHAR(3) NOT NULL,
    tipo                ENUM('INGRESO','GASTO') NOT NULL,
    importe             DECIMAL(18,2) NOT NULL,
    origen              ENUM('FACTURA_VENTA','FACTURA_COMPRA','SUELDO','AJUSTE') NOT NULL,
    factura_venta_id    BIGINT UNSIGNED NULL,
    factura_compra_id   BIGINT UNSIGNED NULL,
    descripcion         VARCHAR(255) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_iibb_mov_fecha_jur (fecha, jurisdiccion, tipo),
    CONSTRAINT fk_imp_iibb_mov_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_imp_iibb_mov_jur     FOREIGN KEY (jurisdiccion) REFERENCES erp_iibb_jurisdicciones(codigo),
    CONSTRAINT fk_imp_iibb_mov_fv      FOREIGN KEY (factura_venta_id) REFERENCES erp_facturas_venta(id),
    CONSTRAINT fk_imp_iibb_mov_fc      FOREIGN KEY (factura_compra_id) REFERENCES erp_facturas_compra(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_ganancias_liquidacion — F.713 (H5)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_ganancias_liquidacion (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    periodo_id                  BIGINT UNSIGNED NOT NULL,
    ejercicio_id                BIGINT UNSIGNED NOT NULL,
    resultado_contable          DECIMAL(18,2) NOT NULL,
    ajustes_fiscales_mas        DECIMAL(18,2) NOT NULL DEFAULT 0,
    ajustes_fiscales_menos      DECIMAL(18,2) NOT NULL DEFAULT 0,
    resultado_impositivo        DECIMAL(18,2) NOT NULL,
    alicuota_escalonada         JSON NULL,
    impuesto_determinado        DECIMAL(18,2) NOT NULL,
    anticipos_computados        DECIMAL(18,2) NOT NULL DEFAULT 0,
    retenciones_sufridas        DECIMAL(18,2) NOT NULL DEFAULT 0,
    percepciones_sufridas       DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_a_pagar               DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_a_favor               DECIMAL(18,2) NOT NULL DEFAULT 0,
    ajusta_por_inflacion        TINYINT(1) NOT NULL DEFAULT 0,
    ajuste_inflacion_importe    DECIMAL(18,2) NOT NULL DEFAULT 0,
    archivo_f713_path           VARCHAR(500) NULL,
    archivo_f713_hash           CHAR(64) NULL,
    generado_at                 DATETIME NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_gan_ejercicio (ejercicio_id),
    CONSTRAINT fk_imp_gan_periodo   FOREIGN KEY (periodo_id) REFERENCES erp_periodos_fiscales(id),
    CONSTRAINT fk_imp_gan_ejercicio FOREIGN KEY (ejercicio_id) REFERENCES erp_ejercicios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_ganancias_anticipos — 10 anticipos del ejercicio siguiente (H5)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_ganancias_anticipos (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ejercicio_id            BIGINT UNSIGNED NOT NULL,
    liquidacion_origen_id   BIGINT UNSIGNED NOT NULL,
    nro_anticipo            TINYINT UNSIGNED NOT NULL,
    fecha_vencimiento       DATE NOT NULL,
    base_calculo            DECIMAL(18,2) NOT NULL,
    porcentaje              DECIMAL(5,2) NOT NULL,
    importe                 DECIMAL(18,2) NOT NULL,
    estado                  ENUM('PENDIENTE','PAGADO','COMPENSADO','EXIMIDO') NOT NULL DEFAULT 'PENDIENTE',
    fecha_pago              DATE NULL,
    orden_pago_id           BIGINT UNSIGNED NULL,
    observaciones           VARCHAR(255) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_anticipo (ejercicio_id, nro_anticipo),
    CONSTRAINT fk_imp_ant_ejercicio   FOREIGN KEY (ejercicio_id) REFERENCES erp_ejercicios(id),
    CONSTRAINT fk_imp_ant_liquidacion FOREIGN KEY (liquidacion_origen_id) REFERENCES erp_ganancias_liquidacion(id),
    CONSTRAINT fk_imp_ant_op          FOREIGN KEY (orden_pago_id) REFERENCES erp_ordenes_pago(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_bp_participaciones — F.2000 (H6)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_bp_participaciones (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    periodo_id                  BIGINT UNSIGNED NOT NULL,
    ejercicio_id                BIGINT UNSIGNED NOT NULL,
    patrimonio_neto_ajustado    DECIMAL(18,2) NOT NULL,
    alicuota                    DECIMAL(6,4) NOT NULL,
    impuesto_total              DECIMAL(18,2) NOT NULL,
    socios_detalle              JSON NOT NULL,
    archivo_f2000_path          VARCHAR(500) NULL,
    archivo_f2000_hash          CHAR(64) NULL,
    generado_at                 DATETIME NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_bp_ejercicio (ejercicio_id),
    CONSTRAINT fk_imp_bp_periodo   FOREIGN KEY (periodo_id) REFERENCES erp_periodos_fiscales(id),
    CONSTRAINT fk_imp_bp_ejercicio FOREIGN KEY (ejercicio_id) REFERENCES erp_ejercicios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- erp_reportes_cache — cache de reportes para ejercicios cerrados (H7, RN-66)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_reportes_cache (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    hash_clave      CHAR(64) NOT NULL,
    reporte         VARCHAR(64) NOT NULL,
    parametros      JSON NULL,
    contenido       LONGTEXT NOT NULL,
    formato         ENUM('JSON','HTML','PDF_BASE64','XLSX_BASE64') NOT NULL DEFAULT 'JSON',
    cierre_hash     CHAR(64) NULL COMMENT 'Hash del último cierre que produjo el cache',
    generado_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_reporte_cache (hash_clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET SQL_MODE=@OLD_SQL_MODE;
