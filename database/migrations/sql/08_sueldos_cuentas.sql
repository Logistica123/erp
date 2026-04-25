-- ============================================================================
-- SPEC 08 — Extensión del plan de cuentas (mapeo opción C: solo lo que falta)
-- ----------------------------------------------------------------------------
-- Las cuentas que SPEC 08 referenciaba con códigos 1.1.5.01..05 / 2.1.5.01..03
-- / 5.2.1.01..05 chocaban con códigos ya ocupados en el plan vigente. Se
-- decidió reutilizar las cuentas existentes con misma semántica y crear solo
-- las que realmente faltan en códigos libres del rango (.10..). Mapeo:
--
--   SPEC 08             →  Cuenta usada
--   1.1.5.01 Préstamos     →  1.1.5.10 (NUEVA)
--   1.1.5.02 Adelantos     →  1.1.5.02 Anticipos al Personal (existe)
--   1.1.5.03 Combustible   →  1.1.5.11 (NUEVA)
--   1.1.5.04 Pólizas       →  1.1.5.12 (NUEVA)
--   1.1.5.05 Sanciones     →  1.1.5.13 (NUEVA)
--   2.1.5.01 Sueldos pagar →  2.1.5.10 (NUEVA)
--   2.1.5.02 Honorarios pagar →  2.1.5.05 Honorarios a Pagar (existe)
--   2.1.5.03 Cargas Soc pagar →  2.1.5.11 (NUEVA)
--   5.2.1.01 Sueldos formal →  5.2.1.01 Sueldos Administración (existe)
--   5.2.1.02 Sueldos efect. →  5.2.1.10 (NUEVA)
--   5.2.1.03 Honorarios MT  →  5.2.1.07 Honorarios Profesionales (existe)
--   5.2.1.05 SAC            →  5.2.1.03 Aguinaldo (SAC) (existe)
-- ============================================================================

SET @empresa_id := 1;

-- 1.1.5.10 Préstamos al personal
INSERT IGNORE INTO erp_cuentas_contables
 (empresa_id, codigo, codigo_padre_id, nivel, nombre, tipo, rubro_ec, imputable, moneda,
  admite_cc, admite_auxiliar, tipo_auxiliar, etiqueta_cierre, notas, activo)
VALUES
 (@empresa_id, '1.1.5.10',
  (SELECT id FROM erp_cuentas_contables c2 WHERE c2.empresa_id=@empresa_id AND c2.codigo='1.1.5' LIMIT 1),
  4, 'Préstamos al personal', 'A', 'Otros Créditos', 1, 'ARS',
  1, 1, 'Empleado', 'CC-EMP-PRESTAMO', 'Auxiliar por empleado. Generado por erp_emp_prestamos.', 1);

-- 1.1.5.11 CC combustible personal
INSERT IGNORE INTO erp_cuentas_contables
 (empresa_id, codigo, codigo_padre_id, nivel, nombre, tipo, rubro_ec, imputable, moneda,
  admite_cc, admite_auxiliar, tipo_auxiliar, etiqueta_cierre, notas, activo)
VALUES
 (@empresa_id, '1.1.5.11',
  (SELECT id FROM erp_cuentas_contables c2 WHERE c2.empresa_id=@empresa_id AND c2.codigo='1.1.5' LIMIT 1),
  4, 'CC combustible personal', 'A', 'Otros Créditos', 1, 'ARS',
  1, 1, 'Empleado', 'CC-EMP-COMBUSTIBLE', 'Consumo de combustible cargado a cuenta del empleado.', 1);

-- 1.1.5.12 CC pólizas personal
INSERT IGNORE INTO erp_cuentas_contables
 (empresa_id, codigo, codigo_padre_id, nivel, nombre, tipo, rubro_ec, imputable, moneda,
  admite_cc, admite_auxiliar, tipo_auxiliar, etiqueta_cierre, notas, activo)
VALUES
 (@empresa_id, '1.1.5.12',
  (SELECT id FROM erp_cuentas_contables c2 WHERE c2.empresa_id=@empresa_id AND c2.codigo='1.1.5' LIMIT 1),
  4, 'CC pólizas personal', 'A', 'Otros Créditos', 1, 'ARS',
  1, 1, 'Empleado', 'CC-EMP-POLIZA', 'Pólizas de seguro asumidas por la empresa y descontadas del empleado.', 1);

-- 1.1.5.13 CC sanciones personal
INSERT IGNORE INTO erp_cuentas_contables
 (empresa_id, codigo, codigo_padre_id, nivel, nombre, tipo, rubro_ec, imputable, moneda,
  admite_cc, admite_auxiliar, tipo_auxiliar, etiqueta_cierre, notas, activo)
VALUES
 (@empresa_id, '1.1.5.13',
  (SELECT id FROM erp_cuentas_contables c2 WHERE c2.empresa_id=@empresa_id AND c2.codigo='1.1.5' LIMIT 1),
  4, 'CC sanciones personal', 'A', 'Otros Créditos', 1, 'ARS',
  1, 1, 'Empleado', 'CC-EMP-SANCION', 'Multas internas y sanciones pendientes de aplicar en nómina.', 1);

-- 2.1.5.10 Sueldos a pagar
INSERT IGNORE INTO erp_cuentas_contables
 (empresa_id, codigo, codigo_padre_id, nivel, nombre, tipo, rubro_ec, imputable, moneda,
  admite_cc, admite_auxiliar, tipo_auxiliar, etiqueta_cierre, notas, activo)
VALUES
 (@empresa_id, '2.1.5.10',
  (SELECT id FROM erp_cuentas_contables c2 WHERE c2.empresa_id=@empresa_id AND c2.codigo='2.1.5' LIMIT 1),
  4, 'Sueldos a pagar', 'P', 'Remuneraciones y Cs. Soc.', 1, 'ARS',
  0, 1, 'Empleado', 'SUELDOS-A-PAGAR', 'Neto a pagar (formal + efectivo) pendiente de pago.', 1);

-- 2.1.5.11 Cargas sociales a pagar
INSERT IGNORE INTO erp_cuentas_contables
 (empresa_id, codigo, codigo_padre_id, nivel, nombre, tipo, rubro_ec, imputable, moneda,
  admite_cc, admite_auxiliar, tipo_auxiliar, etiqueta_cierre, notas, activo)
VALUES
 (@empresa_id, '2.1.5.11',
  (SELECT id FROM erp_cuentas_contables c2 WHERE c2.empresa_id=@empresa_id AND c2.codigo='2.1.5' LIMIT 1),
  4, 'Cargas sociales a pagar', 'P', 'Cargas Fiscales', 1, 'ARS',
  0, 0, NULL, 'CS-A-PAGAR', 'Jubilación 11%, OS 3%, Ley 19032, sindicato, etc. (Solo componente FORMAL).', 1);

-- 5.2.1.10 Sueldos en efectivo
INSERT IGNORE INTO erp_cuentas_contables
 (empresa_id, codigo, codigo_padre_id, nivel, nombre, tipo, rubro_ec, imputable, moneda,
  admite_cc, admite_auxiliar, tipo_auxiliar, etiqueta_cierre, notas, activo)
VALUES
 (@empresa_id, '5.2.1.10',
  (SELECT id FROM erp_cuentas_contables c2 WHERE c2.empresa_id=@empresa_id AND c2.codigo='5.2.1' LIMIT 1),
  4, 'Sueldos en efectivo', 'RN', 'Gastos de Administración', 1, 'ARS',
  1, 1, 'Empleado', 'SUELDOS-EFECTIVO', 'Componente EFECTIVO. NO se exporta a LIBER. Solo visible con sueldos.efectivos.ver.', 1);
