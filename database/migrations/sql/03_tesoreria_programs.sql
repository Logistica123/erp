-- ============================================================================
-- Triggers de Tesoreria. Se separan del SQL de tablas porque la directiva
-- DELIMITER del cliente mysql no es un comando SQL nativo. La migration PHP
-- parte este archivo por el separador de statement y ejecuta cada CREATE
-- TRIGGER independiente (mismo patron que 02_contabilidad_programs.sql).
-- Cada trigger se precede por DROP IF EXISTS para que el archivo sea
-- idempotente en bases donde ya fueron creados manualmente.
-- ============================================================================

DROP TRIGGER IF EXISTS trg_mov_bancario_saldo_au//

CREATE TRIGGER trg_mov_bancario_saldo_au
AFTER UPDATE ON erp_movimientos_bancarios
FOR EACH ROW
BEGIN
    IF NEW.estado = 'CONCILIADO' AND (OLD.estado <> 'CONCILIADO' OR OLD.estado IS NULL) THEN
        UPDATE erp_cuentas_bancarias
        SET saldo_actual = saldo_actual + NEW.credito - NEW.debito,
            fecha_ultimo_movimiento = NEW.fecha
        WHERE id = NEW.cuenta_bancaria_id;
    ELSEIF NEW.estado <> 'CONCILIADO' AND OLD.estado = 'CONCILIADO' THEN
        UPDATE erp_cuentas_bancarias
        SET saldo_actual = saldo_actual - OLD.credito + OLD.debito
        WHERE id = OLD.cuenta_bancaria_id;
    END IF;
END//

DROP TRIGGER IF EXISTS trg_op_inmutable_bu//

CREATE TRIGGER trg_op_inmutable_bu
BEFORE UPDATE ON erp_ordenes_pago
FOR EACH ROW
BEGIN
    IF OLD.estado IN ('CARGADA_BANCO','LIBERADA','PAGADA') AND NEW.estado = OLD.estado THEN
        IF OLD.importe <> NEW.importe OR OLD.auxiliar_id <> NEW.auxiliar_id OR OLD.moneda_id <> NEW.moneda_id OR OLD.fecha <> NEW.fecha THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RN-17: OP en estado CARGADA_BANCO o posterior es inmutable — solo puede cambiar estado o motivos';
        END IF;
    END IF;
END//

DROP TRIGGER IF EXISTS trg_echeq_historial_au//

CREATE TRIGGER trg_echeq_historial_au
AFTER UPDATE ON erp_echeq
FOR EACH ROW
BEGIN
    IF OLD.estado <> NEW.estado THEN
        INSERT INTO erp_echeq_movimientos (echeq_id, estado_anterior, estado_nuevo, motivo, user_id, fecha)
        VALUES (NEW.id, OLD.estado, NEW.estado, NEW.motivo_rechazo, COALESCE(@erp_current_user_id, 0), NOW());
    END IF;
END//

DROP TRIGGER IF EXISTS trg_caja_saldo_bu//

CREATE TRIGGER trg_caja_saldo_bu
BEFORE UPDATE ON erp_cajas
FOR EACH ROW
BEGIN
    IF NEW.saldo_actual < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RN-16: La caja no puede tener saldo negativo';
    END IF;
END//

DROP TRIGGER IF EXISTS trg_ti_validar_bi//

CREATE TRIGGER trg_ti_validar_bi
BEFORE INSERT ON erp_transferencias_internas
FOR EACH ROW
BEGIN
    IF NEW.cuenta_origen_id = NEW.cuenta_destino_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transferencia interna: cuenta origen y destino deben ser diferentes';
    END IF;
END//
