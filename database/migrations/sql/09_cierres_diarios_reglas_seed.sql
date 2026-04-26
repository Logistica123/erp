-- ============================================================================
-- Anexo Cierres Diarios CB-2-bis — Reglas adicionales de auto-conciliación
-- ----------------------------------------------------------------------------
-- Catálogos de los 3 FORMATO_*.md (ICBC §6 / Brubank §3 / MP §4) traducidos
-- a reglas regex contra concepto. Las reglas con cuenta_contable_id=NULL son
-- transferencias internas entre cuentas propias — la contabilización la
-- maneja el detector cross-banco (CB-3), pero queda etiquetadas para que el
-- operador las reconozca al revisar el día.
--
-- Prioridades:
--   < 10 — auto-correcciones de alta confianza (impuestos, comisiones).
--   10..50 — reglas estables de etiquetado con cuenta destino fija.
--   > 50 — reglas con match contraparte que requieren validación manual
--          (no aplica acá; se manejan en el flujo del cierre).
-- Idempotente: INSERT IGNORE por código.
-- ============================================================================

SET @empresa_id := 1;
SET @cta_imp_deb_cred  := (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.4.04' LIMIT 1);
SET @cta_sircreb       := (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='1.1.6.11' LIMIT 1);
SET @cta_iva_cf        := (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='1.1.6.01' LIMIT 1);
SET @cta_com_banc      := (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.4.02' LIMIT 1);
SET @cta_int_remunerad := (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='4.2.02' LIMIT 1);

-- ============================================================================
-- 1) IMPUESTOS — Ley 25413 créditos/débitos (auto-correcciones, prioridad 5)
-- ============================================================================
INSERT IGNORE INTO erp_conciliacion_reglas (empresa_id, codigo, descripcion, tipo, patron_concepto, cuenta_contable_id, orden_prioridad, activa) VALUES
 (@empresa_id, 'ICBC-IMP-DEB',     'ICBC: Impuesto sobre débitos (cod 259)',          'CONCEPTO_REGEX', '^IMP S\\/DEB CT$',                          @cta_imp_deb_cred, 5, 1),
 (@empresa_id, 'ICBC-IMP-CRED',    'ICBC: Impuesto sobre créditos (cod 260)',         'CONCEPTO_REGEX', '^IMP S\\/CRED CT$',                         @cta_imp_deb_cred, 5, 1),
 (@empresa_id, 'BR-IMP-LEY-25413', 'Brubank: Impuesto Ley 25413',                     'CONCEPTO_REGEX', '^Impuesto Ley 25413',                       @cta_imp_deb_cred, 5, 1),
 (@empresa_id, 'MP-IMP-EXTRAC',    'MP: Impuesto por extracción',                     'CONCEPTO_REGEX', '^Impuesto por extracción',                  @cta_imp_deb_cred, 5, 1),
 (@empresa_id, 'MP-IMP-PAGOS',     'MP: Impuesto sobre Créditos y Débitos en pagos',  'CONCEPTO_REGEX', 'Impuesto sobre los Créditos y Débitos en pagos', @cta_imp_deb_cred, 5, 1),
 (@empresa_id, 'MP-IMP-RETIROS',   'MP: Impuesto sobre Créditos y Débitos en retiros','CONCEPTO_REGEX', 'Impuesto sobre los Créditos y Débitos en retiros', @cta_imp_deb_cred, 5, 1);

-- ============================================================================
-- 2) SIRCREB — Retención IIBB (auto-correcciones, prioridad 5)
-- ============================================================================
INSERT IGNORE INTO erp_conciliacion_reglas (empresa_id, codigo, descripcion, tipo, patron_concepto, cuenta_contable_id, orden_prioridad, activa) VALUES
 (@empresa_id, 'ICBC-SIRCREB',  'ICBC: Retención SIRCREB (cod 276)',  'CONCEPTO_REGEX', '^SIRCREB$',           @cta_sircreb, 5, 1),
 (@empresa_id, 'BR-SIRCREB',    'Brubank: Impuesto SIRCREB',          'CONCEPTO_REGEX', '^Impuesto SIRCREB',   @cta_sircreb, 5, 1);

-- ============================================================================
-- 3) IVA SOBRE COMISIONES — ICBC (cod 206/208)
-- ============================================================================
INSERT IGNORE INTO erp_conciliacion_reglas (empresa_id, codigo, descripcion, tipo, patron_concepto, cuenta_contable_id, orden_prioridad, activa) VALUES
 (@empresa_id, 'ICBC-IVA-COM',     'ICBC: IVA sobre comisiones 21%',   'CONCEPTO_REGEX', '^IVA COMISIONES$',     @cta_iva_cf, 10, 1),
 (@empresa_id, 'ICBC-IVA-COM-105', 'ICBC: IVA sobre comisiones 10.5%', 'CONCEPTO_REGEX', '^IVA COMISIONES 10\\.5', @cta_iva_cf, 10, 1);

-- ============================================================================
-- 4) COMISIONES BANCARIAS — ICBC (cod 020 COM MPAY, 515 COM MANT CT)
-- ============================================================================
INSERT IGNORE INTO erp_conciliacion_reglas (empresa_id, codigo, descripcion, tipo, patron_concepto, cuenta_contable_id, orden_prioridad, activa) VALUES
 (@empresa_id, 'ICBC-COM-MPAY',   'ICBC: Comisión MultiPay (cod 020)',     'CONCEPTO_REGEX', '^COM MPAY|^Comisión MultiPay', @cta_com_banc, 10, 1),
 (@empresa_id, 'ICBC-COM-MANT',   'ICBC: Comisión mantenimiento cuenta',   'CONCEPTO_REGEX', '^COM MANT CT|MANTENIMIENTO',   @cta_com_banc, 10, 1);

-- ============================================================================
-- 5) RENDIMIENTOS / INTERESES — MP + Brubank cuenta remunerada
-- ============================================================================
INSERT IGNORE INTO erp_conciliacion_reglas (empresa_id, codigo, descripcion, tipo, patron_concepto, cuenta_contable_id, orden_prioridad, activa) VALUES
 (@empresa_id, 'MP-RENDIMIENTOS',  'MP: Rendimientos (intereses cuenta remunerada)',   'CONCEPTO_REGEX', '^Rendimientos\\s*$',           @cta_int_remunerad, 5, 1),
 (@empresa_id, 'BR-INTERESES',     'Brubank: Intereses pagados (cuenta remunerada)',   'CONCEPTO_REGEX', '^Intereses pagados',            @cta_int_remunerad, 5, 1);

-- ============================================================================
-- 6) TRANSFERENCIAS INTERNAS ENTRE CUENTAS PROPIAS
-- cuenta_contable_id=NULL: el detector cross-banco (CB-3) genera el asiento
-- de transferencia interna; estas reglas solo etiquetan para revisión visual.
-- ============================================================================
INSERT IGNORE INTO erp_conciliacion_reglas (empresa_id, codigo, descripcion, tipo, patron_concepto, cuenta_contable_id, orden_prioridad, activa) VALUES
 (@empresa_id, 'TRF-INT-ICBC-AR',   'Transferencia entre bancos propios (ICBC cod 795)', 'CONCEPTO_REGEX', '^TRANSF E\\/BCOS',                       NULL, 20, 1),
 (@empresa_id, 'TRF-INT-BR-A-ICBC', 'Brubank → ICBC',                                     'CONCEPTO_REGEX', '^A una cuenta tuya - ICBC',              NULL, 20, 1),
 (@empresa_id, 'TRF-INT-BR-A-MP',   'Brubank → MercadoPago',                              'CONCEPTO_REGEX', '^A una cuenta tuya - MercadoPago',       NULL, 20, 1),
 (@empresa_id, 'TRF-INT-BR-DE-ICBC','Brubank ← ICBC',                                     'CONCEPTO_REGEX', '^De una cuenta tuya - ICBC',             NULL, 20, 1),
 (@empresa_id, 'TRF-INT-BR-DE-MP',  'Brubank ← MercadoPago',                              'CONCEPTO_REGEX', '^De una cuenta tuya - MercadoPago',      NULL, 20, 1),
 (@empresa_id, 'TRF-INT-BR-RETIRO-REM', 'Brubank: Retiro de cuenta remunerada (CC ↔ Rem)', 'CONCEPTO_REGEX', '^Retiro de tu cuenta remunerada|^Retiro de cuenta remunerada', NULL, 20, 1),
 (@empresa_id, 'TRF-INT-BR-FONDEO-REM', 'Brubank: Fondeo a cuenta remunerada (CC ↔ Rem)',  'CONCEPTO_REGEX', '^Fondeo de tu cuenta remunerada|^Depósito en cuenta remunerada', NULL, 20, 1);
