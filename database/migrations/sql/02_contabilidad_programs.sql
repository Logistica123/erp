
DROP TRIGGER IF EXISTS trg_asiento_balance_insert//
CREATE TRIGGER trg_asiento_balance_insert
BEFORE INSERT ON erp_asientos
FOR EACH ROW
BEGIN
    -- En INSERT los totales aún son 0; se recalculan al grabar movimientos.
    IF NEW.estado = 'CONTABILIZADO' AND NEW.total_debe <> NEW.total_haber THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Asiento desbalanceado: debe <> haber';
    END IF;
END//

DROP TRIGGER IF EXISTS trg_asiento_balance_update//
CREATE TRIGGER trg_asiento_balance_update
BEFORE UPDATE ON erp_asientos
FOR EACH ROW
BEGIN
    IF NEW.estado = 'CONTABILIZADO' AND NEW.total_debe <> NEW.total_haber THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Asiento desbalanceado: debe <> haber';
    END IF;
END//

DROP PROCEDURE IF EXISTS sp_recalc_asiento//
CREATE PROCEDURE sp_recalc_asiento(IN p_asiento_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_debe DECIMAL(18,2);
    DECLARE v_haber DECIMAL(18,2);
    SELECT IFNULL(SUM(debe),0), IFNULL(SUM(haber),0)
      INTO v_debe, v_haber
      FROM erp_movimientos_asiento WHERE asiento_id = p_asiento_id;
    UPDATE erp_asientos
       SET total_debe = v_debe, total_haber = v_haber
     WHERE id = p_asiento_id;
END//

DROP TRIGGER IF EXISTS trg_movimiento_ai//
CREATE TRIGGER trg_movimiento_ai
AFTER INSERT ON erp_movimientos_asiento
FOR EACH ROW
BEGIN
    CALL sp_recalc_asiento(NEW.asiento_id);
END//

DROP TRIGGER IF EXISTS trg_movimiento_au//
CREATE TRIGGER trg_movimiento_au
AFTER UPDATE ON erp_movimientos_asiento
FOR EACH ROW
BEGIN
    CALL sp_recalc_asiento(NEW.asiento_id);
END//

DROP TRIGGER IF EXISTS trg_movimiento_ad//
CREATE TRIGGER trg_movimiento_ad
AFTER DELETE ON erp_movimientos_asiento
FOR EACH ROW
BEGIN
    CALL sp_recalc_asiento(OLD.asiento_id);
END//

-- Valida admite_cc / admite_auxiliar antes de insertar movimiento
DROP TRIGGER IF EXISTS trg_movimiento_validar//
CREATE TRIGGER trg_movimiento_validar
BEFORE INSERT ON erp_movimientos_asiento
FOR EACH ROW
BEGIN
    DECLARE v_adm_cc TINYINT; DECLARE v_adm_aux TINYINT; DECLARE v_imp TINYINT;
    SELECT imputable, admite_cc, admite_auxiliar
      INTO v_imp, v_adm_cc, v_adm_aux
      FROM erp_cuentas_contables WHERE id = NEW.cuenta_id;
    IF v_imp = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cuenta no imputable';
    END IF;
    IF v_adm_cc = 1 AND NEW.centro_costo_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cuenta requiere centro de costo';
    END IF;
    IF v_adm_aux = 1 AND NEW.auxiliar_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cuenta requiere auxiliar';
    END IF;
END//

