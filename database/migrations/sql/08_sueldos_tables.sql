-- =============================================================================
-- DDL_08 — SUELDOS Y NÓMINA LIGERA (Fase Sueldos)
-- -----------------------------------------------------------------------------
-- Implementa SPEC 08.
--
-- Alcance:
--   • Padrón de empleados con régimen real (FORMAL / EFECTIVO / MT)
--   • Convenios y categorías (Camioneros, Comercio, FUERA_CONVENIO)
--   • Básicos historizados sin overlap + composición porcentual
--   • Comisiones por esquema (vendedores remotos)
--   • Novedades mensuales (HE, faltas, adelantos de día)
--   • Ausencias (carpeta médica, licencia, vacaciones)
--   • Cuentas corrientes internas del empleado
--       (préstamos, adelantos, combustible, pólizas, sanciones)
--   • Préstamos con plan de cuotas
--   • Ciclo de liquidación idempotente con máquina de estados
--   • Pagos segregados en 3 caminos: transferencia formal / efectivo /
--     transferencia MT contra factura C
--   • Export LIBER (XLSX solo componente FORMAL)
--
-- Pre-requisitos:
--   DDL_01..DDL_07 ejecutados
--   seed_erp_cuentas_contables extendido con cuentas:
--     1.1.5.01 Préstamos al personal
--     1.1.5.02 Adelantos al personal
--     1.1.5.03 CC combustible personal
--     1.1.5.04 CC pólizas personal
--     1.1.5.05 CC sanciones personal
--     2.1.5.01 Sueldos a pagar
--     2.1.5.02 Honorarios personal a pagar
--     2.1.5.03 Cargas sociales a pagar
--     5.2.1.01 Sueldos y jornales
--     5.2.1.02 Gastos personal no registrado
--     5.2.1.03 Honorarios personal
--     5.2.1.04 Cargas sociales
--     5.2.1.05 SAC
--
-- Convenciones:
--   utf8mb4 / utf8mb4_unicode_ci, InnoDB
--   DECIMAL(18,2) importes, DECIMAL(9,4) porcentajes, DECIMAL(9,2) horas
--   FK ON DELETE RESTRICT por defecto
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

-- =============================================================================
-- 1. CONVENIOS Y CATEGORÍAS
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_convenios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    codigo       VARCHAR(30) NOT NULL,
    nombre       VARCHAR(100) NOT NULL,
    descripcion  TEXT NULL,
    activo       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_conv_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Convenios colectivos aplicables (Camioneros, Comercio, FUERA_CONVENIO).';

CREATE TABLE IF NOT EXISTS erp_emp_categorias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    convenio_id      BIGINT UNSIGNED NOT NULL,
    codigo           VARCHAR(30) NOT NULL,
    nombre           VARCHAR(100) NOT NULL,
    nivel_jerarquia  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    descripcion      TEXT NULL,
    activa           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_cat_conv_cod (convenio_id, codigo),
    CONSTRAINT fk_emp_cat_conv FOREIGN KEY (convenio_id) REFERENCES erp_emp_convenios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Categorías dentro de cada convenio con nivel de jerarquía.';

-- =============================================================================
-- 2. EMPLEADOS (PADRÓN)
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_empleados (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    legajo              VARCHAR(20) NOT NULL,
    cuil                VARCHAR(13) NULL,
    cuit                VARCHAR(13) NULL,
    apellido            VARCHAR(80) NOT NULL,
    nombre              VARCHAR(80) NOT NULL,
    dni                 VARCHAR(15) NULL,
    fecha_nacimiento    DATE NULL,
    fecha_ingreso       DATE NOT NULL,
    fecha_egreso        DATE NULL,
    categoria_id        BIGINT UNSIGNED NULL,
    convenio_id         BIGINT UNSIGNED NULL,
    regimen             ENUM('FORMAL_PURO','MIXTO','EFECTIVO_PURO','MONOTRIBUTISTA') NOT NULL,
    jornada_formal_pct  DECIMAL(5,2) NOT NULL DEFAULT 0
        COMMENT 'Porcentaje de jornada blanqueada (0 a 100).',
    es_vendedor         BOOLEAN NOT NULL DEFAULT FALSE,
    paga_sac            BOOLEAN NOT NULL DEFAULT TRUE,
    cbu                 VARCHAR(22) NULL,
    banco               VARCHAR(60) NULL,
    alias_cbu           VARCHAR(40) NULL,
    email               VARCHAR(120) NULL,
    telefono            VARCHAR(30) NULL,
    domicilio           VARCHAR(200) NULL,
    activo              BOOLEAN NOT NULL DEFAULT TRUE,
    observaciones       TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_legajo (legajo),
    KEY ix_emp_cuil (cuil),
    KEY ix_emp_activo (activo),
    KEY ix_emp_categoria (categoria_id),
    CONSTRAINT fk_emp_categoria FOREIGN KEY (categoria_id) REFERENCES erp_emp_categorias(id),
    CONSTRAINT fk_emp_convenio  FOREIGN KEY (convenio_id)  REFERENCES erp_emp_convenios(id),
    CONSTRAINT chk_emp_jornada  CHECK (jornada_formal_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Empleados de LAR SRL (formales, efectivo, mixtos, monotributistas).';

-- =============================================================================
-- 3. BÁSICOS HISTORIZADOS
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_basicos_historial (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empleado_id         BIGINT UNSIGNED NOT NULL,
    basico_total        DECIMAL(18,2) NOT NULL,
    vigencia_desde      DATE NOT NULL,
    vigencia_hasta      DATE NULL
        COMMENT 'NULL = vigente. Se cierra al aprobar uno nuevo.',
    motivo              ENUM('INGRESO','AUMENTO_PARITARIA','AUMENTO_GERENCIAL','CORRECCION','RECATEGORIZACION') NOT NULL,
    aprobado_por_id     BIGINT UNSIGNED NULL,
    fecha_aprobacion    DATETIME NULL,
    observaciones       TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_emp_bas_empleado_vig (empleado_id, vigencia_desde),
    CONSTRAINT fk_emp_bas_empleado FOREIGN KEY (empleado_id)     REFERENCES erp_emp_empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_bas_aprueba  FOREIGN KEY (aprobado_por_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historial de básicos. RN-101/103: sin overlap, cambios conservan historia.';

-- =============================================================================
-- 4. COMPOSICIÓN PORCENTUAL FORMAL / EFECTIVO / MT
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_composicion_sueldo (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empleado_id        BIGINT UNSIGNED NOT NULL,
    porc_formal        DECIMAL(5,2) NOT NULL DEFAULT 0,
    porc_efectivo      DECIMAL(5,2) NOT NULL DEFAULT 0,
    porc_mt            DECIMAL(5,2) NOT NULL DEFAULT 0,
    vigencia_desde     DATE NOT NULL,
    vigencia_hasta     DATE NULL,
    observaciones      TEXT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_emp_comp_emp_vig (empleado_id, vigencia_desde),
    CONSTRAINT fk_emp_comp_empleado FOREIGN KEY (empleado_id) REFERENCES erp_emp_empleados(id) ON DELETE CASCADE,
    CONSTRAINT chk_emp_comp_rango   CHECK (
        porc_formal BETWEEN 0 AND 100 AND
        porc_efectivo BETWEEN 0 AND 100 AND
        porc_mt BETWEEN 0 AND 100
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Composición % del sueldo bruto. RN-102: porc_formal+efectivo+mt=100 (trigger).';

-- =============================================================================
-- 5. ESQUEMAS DE COMISIÓN
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_comisiones_esquema (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empleado_id        BIGINT UNSIGNED NOT NULL,
    base               ENUM('VENTAS_NETAS','COBRANZAS','MARGEN','UNIDADES','FIJO_MENSUAL') NOT NULL,
    porcentaje         DECIMAL(7,4) NULL
        COMMENT 'Aplica si base es % sobre métrica.',
    importe_unitario   DECIMAL(18,2) NULL
        COMMENT 'Aplica si base es UNIDADES.',
    importe_fijo       DECIMAL(18,2) NULL
        COMMENT 'Aplica si base es FIJO_MENSUAL.',
    tope_mensual       DECIMAL(18,2) NULL,
    vigencia_desde     DATE NOT NULL,
    vigencia_hasta     DATE NULL,
    observaciones      TEXT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_emp_com_emp_vig (empleado_id, vigencia_desde),
    CONSTRAINT fk_emp_com_empleado FOREIGN KEY (empleado_id) REFERENCES erp_emp_empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Esquemas de comisión por empleado (vendedores remotos mayormente).';

-- =============================================================================
-- 6. CATÁLOGO DE CONCEPTOS
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_conceptos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    codigo           VARCHAR(30) NOT NULL,
    nombre           VARCHAR(100) NOT NULL,
    tipo             ENUM('REMUNERATIVO','NO_REMUNERATIVO','DESCUENTO_LEGAL','DESCUENTO_OTRO','SAC','COMISION','AJUSTE') NOT NULL,
    signo            ENUM('HABER','DESCUENTO') NOT NULL,
    afecta_formal    BOOLEAN NOT NULL DEFAULT TRUE,
    afecta_efectivo  BOOLEAN NOT NULL DEFAULT FALSE,
    afecta_mt        BOOLEAN NOT NULL DEFAULT FALSE,
    formula          VARCHAR(200) NULL
        COMMENT 'Expresión simbólica opcional (ej. basico*1.5*horas).',
    cuenta_debe_id   BIGINT UNSIGNED NULL,
    cuenta_haber_id  BIGINT UNSIGNED NULL,
    orden            SMALLINT NOT NULL DEFAULT 0,
    activo           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_con_codigo (codigo),
    CONSTRAINT fk_emp_con_cta_debe  FOREIGN KEY (cuenta_debe_id)  REFERENCES erp_cuentas_contables(id),
    CONSTRAINT fk_emp_con_cta_haber FOREIGN KEY (cuenta_haber_id) REFERENCES erp_cuentas_contables(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de conceptos (BASICO, COMISION, HE_50, SAC, JUB_11, PRESTAMO_CUOTA, ...).';

-- =============================================================================
-- 7. NOVEDADES MENSUALES
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_novedades (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empleado_id    BIGINT UNSIGNED NOT NULL,
    periodo        CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    concepto_id    BIGINT UNSIGNED NOT NULL,
    cantidad       DECIMAL(9,2) NOT NULL DEFAULT 0
        COMMENT 'Horas, días, unidades según el concepto.',
    importe        DECIMAL(18,2) NULL
        COMMENT 'Importe fijo si no se calcula sobre básico.',
    observaciones  TEXT NULL,
    creado_por_id  BIGINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_emp_nov_emp_per (empleado_id, periodo),
    CONSTRAINT fk_emp_nov_empleado FOREIGN KEY (empleado_id)   REFERENCES erp_emp_empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_nov_concepto FOREIGN KEY (concepto_id)   REFERENCES erp_emp_conceptos(id),
    CONSTRAINT fk_emp_nov_usr      FOREIGN KEY (creado_por_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Novedades del mes: HE, faltas, descuentos especiales, aumentos gerenciales.';

-- =============================================================================
-- 8. AUSENCIAS
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_ausencias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empleado_id    BIGINT UNSIGNED NOT NULL,
    tipo           ENUM('CARPETA_MEDICA','LICENCIA_ESPECIAL','VACACIONES','FALTA_INJUSTIFICADA','SUSPENSION','OTROS') NOT NULL,
    fecha_desde    DATE NOT NULL,
    fecha_hasta    DATE NOT NULL,
    dias_habiles   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    paga           BOOLEAN NOT NULL DEFAULT TRUE,
    observaciones  TEXT NULL,
    adjunto_path   VARCHAR(400) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_emp_aus_emp_fec (empleado_id, fecha_desde),
    CONSTRAINT fk_emp_aus_empleado FOREIGN KEY (empleado_id) REFERENCES erp_emp_empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ausencias (carpeta médica, licencia, vacaciones, faltas).';

-- =============================================================================
-- 9. CUENTAS CORRIENTES INTERNAS DEL EMPLEADO
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_cc (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empleado_id       BIGINT UNSIGNED NOT NULL,
    tipo              ENUM('PRESTAMO','ADELANTO','COMBUSTIBLE','POLIZA','SANCION','OTRO') NOT NULL,
    cuenta_contable_id BIGINT UNSIGNED NOT NULL
        COMMENT 'Auxiliar de 1.1.5.0x / 5.2.1.xx según tipo.',
    saldo_actual      DECIMAL(18,2) NOT NULL DEFAULT 0,
    limite_credito    DECIMAL(18,2) NULL,
    activa            BOOLEAN NOT NULL DEFAULT TRUE,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_cc_tipo (empleado_id, tipo),
    CONSTRAINT fk_emp_cc_empleado FOREIGN KEY (empleado_id)        REFERENCES erp_emp_empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_cc_cuenta   FOREIGN KEY (cuenta_contable_id) REFERENCES erp_cuentas_contables(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Una fila por tipo de CC del empleado. RN-110: siempre refleja cuenta contable.';

CREATE TABLE IF NOT EXISTS erp_emp_cc_movimientos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cc_id              BIGINT UNSIGNED NOT NULL,
    fecha              DATE NOT NULL,
    tipo_mov           ENUM('CARGO','PAGO','DESCUENTO_LIQUIDACION','AJUSTE') NOT NULL,
    importe            DECIMAL(18,2) NOT NULL,
    saldo_posterior    DECIMAL(18,2) NOT NULL,
    asiento_id         BIGINT UNSIGNED NULL,
    liquidacion_id     BIGINT UNSIGNED NULL,
    referencia         VARCHAR(100) NULL
        COMMENT 'Nro factura combustible, nro póliza, nro sanción, etc.',
    observaciones      TEXT NULL,
    creado_por_id      BIGINT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_emp_ccmov_cc_fec (cc_id, fecha),
    CONSTRAINT fk_emp_ccmov_cc       FOREIGN KEY (cc_id)         REFERENCES erp_emp_cc(id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_ccmov_asiento  FOREIGN KEY (asiento_id)    REFERENCES erp_asientos(id),
    CONSTRAINT fk_emp_ccmov_usr      FOREIGN KEY (creado_por_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Movimientos de CC del empleado. Cada fila genera asiento real.';

-- =============================================================================
-- 10. PRÉSTAMOS CON PLAN DE CUOTAS
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_prestamos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empleado_id          BIGINT UNSIGNED NOT NULL,
    fecha_otorgamiento   DATE NOT NULL,
    capital              DECIMAL(18,2) NOT NULL,
    cuotas_total         SMALLINT UNSIGNED NOT NULL,
    cuotas_pagadas       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    cuota_mensual        DECIMAL(18,2) NOT NULL,
    saldo_capital        DECIMAL(18,2) NOT NULL,
    primera_cuota_periodo CHAR(7) NOT NULL,
    estado               ENUM('VIGENTE','CANCELADO','REFINANCIADO','BAJA') NOT NULL DEFAULT 'VIGENTE',
    asiento_alta_id      BIGINT UNSIGNED NULL,
    aprobado_por_id      BIGINT UNSIGNED NULL,
    observaciones        TEXT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_emp_prest_emp_est (empleado_id, estado),
    CONSTRAINT fk_emp_prest_empleado FOREIGN KEY (empleado_id)     REFERENCES erp_emp_empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_prest_asiento  FOREIGN KEY (asiento_alta_id) REFERENCES erp_asientos(id),
    CONSTRAINT fk_emp_prest_aprueba  FOREIGN KEY (aprobado_por_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Préstamos al personal con plan de cuotas. Descuento automático en nómina.';

-- =============================================================================
-- 11. LIQUIDACIONES (CABECERA)
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_liquidaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    periodo              CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    tipo                 ENUM('MENSUAL','SAC','AJUSTE','FINAL') NOT NULL DEFAULT 'MENSUAL',
    estado               ENUM('BORRADOR','CALCULADA','APROBADA','PAGADA','RECTIFICADA','ANULADA') NOT NULL DEFAULT 'BORRADOR',
    fecha_calculo        DATETIME NULL,
    fecha_aprobacion     DATETIME NULL,
    fecha_pago           DATETIME NULL,
    total_bruto          DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_descuentos     DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_neto           DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_formal         DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_efectivo       DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_mt             DECIMAL(18,2) NOT NULL DEFAULT 0,
    empleados_count      INT UNSIGNED NOT NULL DEFAULT 0,
    asiento_id           BIGINT UNSIGNED NULL
        COMMENT 'Único asiento total de la liquidación.',
    calculado_por_id     BIGINT UNSIGNED NULL,
    aprobado_por_id      BIGINT UNSIGNED NULL,
    liquidacion_origen_id BIGINT UNSIGNED NULL
        COMMENT 'Si es RECTIFICADA, apunta al original.',
    observaciones        TEXT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_liq_periodo_tipo (periodo, tipo),
    KEY ix_emp_liq_estado (estado),
    CONSTRAINT fk_emp_liq_asiento FOREIGN KEY (asiento_id)           REFERENCES erp_asientos(id),
    CONSTRAINT fk_emp_liq_calc    FOREIGN KEY (calculado_por_id)     REFERENCES users(id),
    CONSTRAINT fk_emp_liq_aprob   FOREIGN KEY (aprobado_por_id)      REFERENCES users(id),
    CONSTRAINT fk_emp_liq_origen  FOREIGN KEY (liquidacion_origen_id) REFERENCES erp_emp_liquidaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cabecera de liquidación. Máquina de estados RN-114. Idempotente RN-113.';

-- =============================================================================
-- 12. LIQUIDACIÓN — ITEMS POR EMPLEADO × CONCEPTO
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_liquidaciones_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    liquidacion_id     BIGINT UNSIGNED NOT NULL,
    empleado_id        BIGINT UNSIGNED NOT NULL,
    concepto_id        BIGINT UNSIGNED NOT NULL,
    componente         ENUM('FORMAL','EFECTIVO','MT') NOT NULL,
    cantidad           DECIMAL(9,2) NOT NULL DEFAULT 0,
    importe_unitario   DECIMAL(18,2) NULL,
    importe            DECIMAL(18,2) NOT NULL,
    base_calculo       DECIMAL(18,2) NULL,
    observaciones      VARCHAR(255) NULL,
    KEY ix_emp_liqit_liq (liquidacion_id),
    KEY ix_emp_liqit_emp (empleado_id),
    KEY ix_emp_liqit_comp (componente),
    CONSTRAINT fk_emp_liqit_liq      FOREIGN KEY (liquidacion_id) REFERENCES erp_emp_liquidaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_liqit_empleado FOREIGN KEY (empleado_id)    REFERENCES erp_emp_empleados(id),
    CONSTRAINT fk_emp_liqit_concepto FOREIGN KEY (concepto_id)    REFERENCES erp_emp_conceptos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un ítem por (liquidación, empleado, concepto, componente). Detalle del recibo.';

-- =============================================================================
-- 13. PAGOS (3 caminos: TRANSFERENCIA / EFECTIVO / TRANSFERENCIA MT)
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_pagos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    liquidacion_id     BIGINT UNSIGNED NOT NULL,
    empleado_id        BIGINT UNSIGNED NOT NULL,
    componente         ENUM('FORMAL','EFECTIVO','MT') NOT NULL,
    medio              ENUM('TRANSFERENCIA','EFECTIVO','CHEQUE','OTRO') NOT NULL,
    importe            DECIMAL(18,2) NOT NULL,
    fecha              DATE NOT NULL,
    orden_pago_id      BIGINT UNSIGNED NULL
        COMMENT 'FK a erp_ordenes_pago si es transferencia.',
    movimiento_caja_id BIGINT UNSIGNED NULL
        COMMENT 'FK lógica a futura erp_cajas_movimientos (no existe aún en V1).',
    factura_compra_id  BIGINT UNSIGNED NULL
        COMMENT 'FK a factura C del monotributista si componente=MT.',
    cbu_destino        VARCHAR(22) NULL,
    banco_destino      VARCHAR(60) NULL,
    recibido_por       VARCHAR(120) NULL
        COMMENT 'Nombre y apellido del que recibe efectivo (RN-112).',
    dni_recibio        VARCHAR(15) NULL,
    firma_path         VARCHAR(400) NULL,
    asiento_id         BIGINT UNSIGNED NULL,
    observaciones      TEXT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_pago_liq_emp_comp (liquidacion_id, empleado_id, componente)
        COMMENT 'RN-115: un solo pago por liquidación × empleado × componente.',
    KEY ix_emp_pago_fecha (fecha),
    KEY ix_emp_pago_medio (medio),
    CONSTRAINT fk_emp_pago_liq      FOREIGN KEY (liquidacion_id)     REFERENCES erp_emp_liquidaciones(id),
    CONSTRAINT fk_emp_pago_empleado FOREIGN KEY (empleado_id)        REFERENCES erp_emp_empleados(id),
    CONSTRAINT fk_emp_pago_op       FOREIGN KEY (orden_pago_id)      REFERENCES erp_ordenes_pago(id),
    -- FK fk_emp_pago_cajamov a erp_cajas_movimientos diferida hasta V2 (la tabla no existe en V1).
    CONSTRAINT fk_emp_pago_factc    FOREIGN KEY (factura_compra_id)  REFERENCES erp_facturas_compra(id),
    CONSTRAINT fk_emp_pago_asiento  FOREIGN KEY (asiento_id)         REFERENCES erp_asientos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pagos 3 caminos. Efectivo trazado por receptor (RN-112).';

-- =============================================================================
-- 14. EXPORT LIBER (XLSX solo componente FORMAL)
-- =============================================================================
CREATE TABLE IF NOT EXISTS erp_emp_export_liber (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    liquidacion_id     BIGINT UNSIGNED NOT NULL,
    periodo            CHAR(7) NOT NULL,
    fecha_export       DATETIME NOT NULL,
    generado_por_id    BIGINT UNSIGNED NULL,
    total_exportado    DECIMAL(18,2) NOT NULL,
    empleados_count    INT UNSIGNED NOT NULL,
    archivo_path       VARCHAR(400) NOT NULL,
    hash_sha256        CHAR(64) NOT NULL
        COMMENT 'Hash del archivo para trazabilidad.',
    enviado_a_liber    BOOLEAN NOT NULL DEFAULT FALSE,
    fecha_envio        DATETIME NULL,
    observaciones      TEXT NULL,
    UNIQUE KEY uq_emp_exp_liq (liquidacion_id),
    CONSTRAINT fk_emp_exp_liq FOREIGN KEY (liquidacion_id) REFERENCES erp_emp_liquidaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_exp_usr FOREIGN KEY (generado_por_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Trazabilidad de XLSX exportado a LIBER (solo FORMAL).';

-- =============================================================================
-- 15. VISTAS
-- =============================================================================

-- 15.1 Saldos de CC por empleado
CREATE OR REPLACE VIEW v_erp_emp_saldos_cc AS
SELECT
    e.id                 AS empleado_id,
    e.legajo,
    CONCAT(e.apellido, ', ', e.nombre) AS nombre_completo,
    cc.tipo,
    cc.saldo_actual,
    cc.limite_credito,
    cc.activa,
    MAX(m.fecha)         AS ultimo_mov_fecha
FROM erp_emp_empleados e
LEFT JOIN erp_emp_cc cc         ON cc.empleado_id = e.id
LEFT JOIN erp_emp_cc_movimientos m ON m.cc_id = cc.id
GROUP BY e.id, e.legajo, nombre_completo, cc.tipo, cc.saldo_actual, cc.limite_credito, cc.activa;

-- 15.2 Recibos FORMALES (vista pública para revisor_fiscal / LIBER)
CREATE OR REPLACE VIEW v_erp_emp_recibos_formales AS
SELECT
    li.liquidacion_id,
    l.periodo,
    l.estado,
    li.empleado_id,
    e.legajo,
    e.cuil,
    CONCAT(e.apellido, ', ', e.nombre) AS nombre_completo,
    c.codigo       AS concepto_codigo,
    c.nombre       AS concepto_nombre,
    c.tipo         AS concepto_tipo,
    c.signo,
    li.cantidad,
    li.importe_unitario,
    li.importe,
    li.base_calculo
FROM erp_emp_liquidaciones_items li
JOIN erp_emp_liquidaciones  l ON l.id = li.liquidacion_id
JOIN erp_emp_empleados      e ON e.id = li.empleado_id
JOIN erp_emp_conceptos      c ON c.id = li.concepto_id
WHERE li.componente = 'FORMAL'
  AND l.estado IN ('APROBADA','PAGADA');

-- 15.3 Costo laboral total por periodo (todos los componentes)
CREATE OR REPLACE VIEW v_erp_emp_costo_laboral AS
SELECT
    l.periodo,
    l.tipo,
    l.estado,
    SUM(CASE WHEN li.componente = 'FORMAL'   THEN li.importe ELSE 0 END) AS total_formal,
    SUM(CASE WHEN li.componente = 'EFECTIVO' THEN li.importe ELSE 0 END) AS total_efectivo,
    SUM(CASE WHEN li.componente = 'MT'       THEN li.importe ELSE 0 END) AS total_mt,
    SUM(li.importe)                                                       AS total_bruto,
    COUNT(DISTINCT li.empleado_id)                                        AS empleados_count
FROM erp_emp_liquidaciones l
JOIN erp_emp_liquidaciones_items li ON li.liquidacion_id = l.id
WHERE l.estado IN ('APROBADA','PAGADA')
  AND li.concepto_id IN (SELECT id FROM erp_emp_conceptos WHERE signo = 'HABER')
GROUP BY l.periodo, l.tipo, l.estado;

-- =============================================================================
-- TRIGGERS: ver 08_sueldos_triggers.sql (PDO no soporta DELIMITER).
-- =============================================================================
SET SQL_MODE = @OLD_SQL_MODE;

