-- ============================================================================
-- DDL_05 — ALTERs idempotentes sobre tablas pre-existentes (SPEC 05 §4.3)
-- Requiere MySQL 8.0.29+ / MariaDB 10.5.6+ por `ADD COLUMN IF NOT EXISTS`.
-- ============================================================================

-- erp_ejercicios — flags ajuste por inflación + índice de cierre RT 6
ALTER TABLE erp_ejercicios
    ADD COLUMN IF NOT EXISTS ajusta_por_inflacion TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Si el ejercicio se valúa y presenta con RT 6',
    ADD COLUMN IF NOT EXISTS indice_cierre DECIMAL(18,6) NULL
        COMMENT 'Índice IPIM/IPC al cierre del ejercicio';

-- erp_factura_venta_items — atribución por jurisdicción IIBB (RN-54)
ALTER TABLE erp_factura_venta_items
    ADD COLUMN IF NOT EXISTS jurisdiccion_iibb CHAR(3) NULL
        COMMENT 'Override jurisdicción IIBB para esta línea (RN-54)';

-- erp_facturas_compra — IDs de retenciones generadas al pagar (auditoría)
ALTER TABLE erp_facturas_compra
    ADD COLUMN IF NOT EXISTS retenciones_practicadas_ids JSON NULL
        COMMENT 'IDs de erp_retenciones_practicadas generadas al pagar';
