-- ============================================================================
-- SPEC 08 — Triggers del módulo Sueldos.
-- Separador: dos barras al final de cada statement (PDO rechaza DELIMITER).
-- ============================================================================

-- 16.1 Composición % debe sumar exactamente 100 (RN-102)
DROP TRIGGER IF EXISTS tr_emp_composicion_suma_100_ins
//
CREATE TRIGGER tr_emp_composicion_suma_100_ins
BEFORE INSERT ON erp_emp_composicion_sueldo
FOR EACH ROW
BEGIN
    IF ROUND(NEW.porc_formal + NEW.porc_efectivo + NEW.porc_mt, 2) <> 100.00 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-102: porc_formal+porc_efectivo+porc_mt debe sumar 100.';
    END IF;
END
//

DROP TRIGGER IF EXISTS tr_emp_composicion_suma_100_upd
//
CREATE TRIGGER tr_emp_composicion_suma_100_upd
BEFORE UPDATE ON erp_emp_composicion_sueldo
FOR EACH ROW
BEGIN
    IF ROUND(NEW.porc_formal + NEW.porc_efectivo + NEW.porc_mt, 2) <> 100.00 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-102: porc_formal+porc_efectivo+porc_mt debe sumar 100.';
    END IF;
END
//

-- 16.2 Básicos sin overlap para un empleado (RN-103)
DROP TRIGGER IF EXISTS tr_emp_basicos_sin_overlap
//
CREATE TRIGGER tr_emp_basicos_sin_overlap
BEFORE INSERT ON erp_emp_basicos_historial
FOR EACH ROW
BEGIN
    DECLARE v_count INT;
    SELECT COUNT(*) INTO v_count
    FROM erp_emp_basicos_historial
    WHERE empleado_id = NEW.empleado_id
      AND (
          (NEW.vigencia_hasta IS NULL AND vigencia_hasta IS NULL)
          OR (NEW.vigencia_hasta IS NULL AND vigencia_hasta >= NEW.vigencia_desde)
          OR (vigencia_hasta IS NULL AND NEW.vigencia_desde <= IFNULL(NEW.vigencia_hasta, '9999-12-31'))
          OR (NEW.vigencia_desde <= vigencia_hasta AND IFNULL(NEW.vigencia_hasta, '9999-12-31') >= vigencia_desde)
      );
    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-103: rango de básico solapa con otro existente. Cerrar el vigente antes de crear uno nuevo.';
    END IF;
END
//

-- 16.3 Liquidación cerrada (APROBADA/PAGADA) es inmutable (RN-113)
DROP TRIGGER IF EXISTS tr_emp_liquidacion_cerrada_inmutable
//
CREATE TRIGGER tr_emp_liquidacion_cerrada_inmutable
BEFORE UPDATE ON erp_emp_liquidaciones_items
FOR EACH ROW
BEGIN
    DECLARE v_estado VARCHAR(20);
    SELECT estado INTO v_estado
    FROM erp_emp_liquidaciones
    WHERE id = NEW.liquidacion_id;

    IF v_estado IN ('APROBADA','PAGADA') THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-113: liquidación cerrada. Crear RECTIFICATIVA, no modificar.';
    END IF;
END
//
