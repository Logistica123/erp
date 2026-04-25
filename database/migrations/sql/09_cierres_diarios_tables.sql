-- ============================================================================
-- ANEXO Cierres Diarios — bloque CB-1
-- Tablas que viven del lado ERP (no dependen de DistriApp).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- erp_dias_contables: un registro por día contable de cada empresa.
-- Estado del día (ABIERTO / EN_PROCESO / CERRADO / REAPERTO) + saldos snapshot
-- por cuenta bancaria + métricas resumen + asiento de cierre opcional.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_dias_contables (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empresa_id          BIGINT UNSIGNED NOT NULL,
    fecha               DATE            NOT NULL,
    estado              ENUM('ABIERTO','EN_PROCESO','CERRADO','REAPERTO') NOT NULL DEFAULT 'ABIERTO',
    saldos_apertura     JSON            NULL COMMENT 'Map cuenta_bancaria_id => saldo_inicial',
    saldos_cierre       JSON            NULL COMMENT 'Map cuenta_bancaria_id => saldo_final',
    total_movimientos   INT UNSIGNED    NOT NULL DEFAULT 0,
    total_conciliados   INT UNSIGNED    NOT NULL DEFAULT 0,
    total_pendientes    INT UNSIGNED    NOT NULL DEFAULT 0,
    total_ignorados     INT UNSIGNED    NOT NULL DEFAULT 0,
    asiento_cierre_id   BIGINT UNSIGNED NULL,
    cerrado_por         BIGINT UNSIGNED NULL,
    cerrado_at          DATETIME        NULL,
    observaciones       TEXT            NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dia_empresa_fecha (empresa_id, fecha),
    KEY idx_dia_estado (estado),
    KEY idx_dia_fecha (fecha),
    CONSTRAINT fk_dia_empresa  FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_dia_cerradou FOREIGN KEY (cerrado_por) REFERENCES users(id),
    CONSTRAINT fk_dia_asiento  FOREIGN KEY (asiento_cierre_id) REFERENCES erp_asientos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Día contable. Encarna concepto de "día abierto/cerrado" del workflow de cierres diarios.';

-- ----------------------------------------------------------------------------
-- erp_ajustes_retroactivos: log de ajustes a días ya cerrados (CD-04).
-- El día original NO se toca; se genera un asiento forward con fecha
-- corriente y glosa "Ajuste retroactivo del DD/MM/YYYY".
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS erp_ajustes_retroactivos (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empresa_id            BIGINT UNSIGNED NOT NULL,
    fecha_dia_afectado    DATE            NOT NULL COMMENT 'Día que tiene el error (ya cerrado)',
    fecha_asiento_ajuste  DATE            NOT NULL COMMENT 'Fecha del asiento forward (típicamente hoy)',
    asiento_ajuste_id     BIGINT UNSIGNED NOT NULL,
    motivo                TEXT            NOT NULL,
    iniciado_por          BIGINT UNSIGNED NOT NULL,
    iniciado_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    movimiento_origen_id  BIGINT UNSIGNED NULL COMMENT 'Si el ajuste viene de un movimiento detectado tarde',
    KEY idx_ajuste_dia_afectado (fecha_dia_afectado),
    KEY idx_ajuste_empresa (empresa_id),
    CONSTRAINT fk_ajuste_empresa  FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
    CONSTRAINT fk_ajuste_asiento  FOREIGN KEY (asiento_ajuste_id) REFERENCES erp_asientos(id),
    CONSTRAINT fk_ajuste_userini  FOREIGN KEY (iniciado_por) REFERENCES users(id),
    CONSTRAINT fk_ajuste_movorig  FOREIGN KEY (movimiento_origen_id) REFERENCES erp_movimientos_bancarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Trazabilidad de ajustes retroactivos sobre días ya cerrados (RN-CD-6).';
