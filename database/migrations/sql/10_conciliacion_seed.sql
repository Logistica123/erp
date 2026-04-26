-- ============================================================================
-- SPEC Conciliación Bancaria — seed CM-1
-- Prefijos iniciales de ICBC + permisos del módulo.
-- Idempotente.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Prefijos ICBC (observados en marzo 2026 reales).
-- ----------------------------------------------------------------------------
SET @banco_icbc := (SELECT id FROM erp_bancos WHERE codigo='ICBC' LIMIT 1);

INSERT IGNORE INTO erp_conciliacion_prefijos (banco_id, prefijo, tipo_numero, longitud_min, longitud_max, observacion) VALUES
  (@banco_icbc, 'DEBITO INMEDIATO', 'CUIT',           11, 11, 'DEBIN — pago a distribuidor (CUIT pegado).'),
  (@banco_icbc, 'DEB PREA DEBIN',   'CUIT',           11, 11, 'Débito preautorizado.'),
  (@banco_icbc, 'CREDITO INMEDIATO','CUIT',           11, 11, 'Transferencia recibida — CUIT del emisor.'),
  (@banco_icbc, 'PAGO AFIP',        'CUIT',           11, 11, 'Pago a AFIP (CUIT 30717060985).'),
  (@banco_icbc, 'PAGO LA SEGUNDA',  'POLIZA',         12, 14, 'Cuota La Segunda Seguros.'),
  (@banco_icbc, 'PAGO SAN CRISTO',  'POLIZA',         10, 16, 'San Cristóbal seguros.'),
  (@banco_icbc, 'PAGO NOSIS',       'CUENTA_SERVICIO',10, 20, 'Nosis.'),
  (@banco_icbc, 'PAGO PERSONAL',    'TELEFONO',       10, 16, 'Personal (telefonía).'),
  (@banco_icbc, 'PAGO MOVISTAR',    'TELEFONO',       10, 16, 'Movistar.'),
  (@banco_icbc, 'PAGO CLARO',       'TELEFONO',       10, 16, 'Claro.'),
  (@banco_icbc, 'PAGO EDENOR',      'CUENTA_SERVICIO',10, 20, 'EDENOR (electricidad).'),
  (@banco_icbc, 'PAGO EDESUR',      'CUENTA_SERVICIO',10, 20, 'EDESUR.'),
  (@banco_icbc, 'PAGO METROGAS',    'CUENTA_SERVICIO',10, 20, 'Metrogas.'),
  (@banco_icbc, 'PAGO ABSA',        'CUENTA_SERVICIO',10, 20, 'ABSA (agua).'),
  (@banco_icbc, 'TRANS PAG PROV',   'CUIT',           11, 11, 'Pago a proveedor masivo.');

-- ----------------------------------------------------------------------------
-- Permisos del módulo Conciliación.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO erp_permisos (codigo, modulo, entidad, accion, descripcion, sensible) VALUES
  ('tesoreria.extractos.importar',  'tesoreria', 'extracto',   'importar',  'Subir extractos bancarios',                       0),
  ('tesoreria.movimientos.conciliar','tesoreria','movimiento', 'conciliar', 'Confirmar conciliación de movimiento',            0),
  ('tesoreria.movimientos.ignorar', 'tesoreria', 'movimiento', 'ignorar',   'Marcar movimiento como ignorado',                 0),
  ('tesoreria.reglas.ver',          'tesoreria', 'regla',      'ver',      'Ver reglas de auto-conciliación',                  0),
  ('tesoreria.reglas.gestionar',    'tesoreria', 'regla',      'gestionar','Crear/editar/borrar reglas de conciliación',       1);

-- Asignaciones por rol.
-- super_admin (1) y contador (2): TODO.
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 1, p.id FROM erp_permisos p WHERE p.codigo IN (
  'tesoreria.extractos.importar','tesoreria.movimientos.conciliar','tesoreria.movimientos.ignorar',
  'tesoreria.reglas.ver','tesoreria.reglas.gestionar'
);
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 2, p.id FROM erp_permisos p WHERE p.codigo IN (
  'tesoreria.extractos.importar','tesoreria.movimientos.conciliar','tesoreria.movimientos.ignorar',
  'tesoreria.reglas.ver','tesoreria.reglas.gestionar'
);

-- tesorero (3): importar + conciliar + ignorar + ver reglas (no gestiona).
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 3, p.id FROM erp_permisos p WHERE p.codigo IN (
  'tesoreria.extractos.importar','tesoreria.movimientos.conciliar','tesoreria.movimientos.ignorar','tesoreria.reglas.ver'
);

-- auditor (6) y direccion (7): solo ver reglas.
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 6, p.id FROM erp_permisos p WHERE p.codigo = 'tesoreria.reglas.ver';
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 7, p.id FROM erp_permisos p WHERE p.codigo = 'tesoreria.reglas.ver';
