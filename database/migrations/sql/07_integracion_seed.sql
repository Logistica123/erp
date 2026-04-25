-- ============================================================================
-- SPEC 07 — Permisos del módulo Integración (bloque 7A)
-- 7 permisos + asignaciones a roles existentes.
-- Idempotente: re-ejecutable sin duplicar.
-- ============================================================================

INSERT IGNORE INTO erp_permisos (codigo, modulo, entidad, accion, descripcion, sensible) VALUES
  ('integracion.dashboard.ver',     'integracion', 'dashboard',    'ver',      'Ver dashboard de integración DistriApp',           0),
  ('integracion.pagos.ver',         'integracion', 'pagos',        'ver',      'Listar pagos masivos a distribuidores',            0),
  ('integracion.facturas.ver',      'integracion', 'facturas',     'ver',      'Listar facturas emitidas a clientes',              0),
  ('integracion.cobranzas.ver',     'integracion', 'cobranzas',    'ver',      'Listar cobranzas recibidas de clientes',           0),
  ('integracion.conciliar.ejecutar','integracion', 'conciliacion', 'ejecutar', 'Forzar reconciliación on-demand',                  1),
  ('integracion.log.ver',           'integracion', 'log',          'ver',      'Ver log de integración (erp_integracion_log)',     0),
  ('integracion.exportar',          'integracion', 'export',       'exportar', 'Exportar vistas a XLSX/CSV',                       0);

-- super_admin (1) y contador (2): TODO
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 1, p.id FROM erp_permisos p WHERE p.modulo = 'integracion';

INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 2, p.id FROM erp_permisos p WHERE p.modulo = 'integracion';

-- tesorero (3): dashboard + pagos + cobranzas + conciliar + exportar
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 3, p.id FROM erp_permisos p
WHERE p.codigo IN (
    'integracion.dashboard.ver',
    'integracion.pagos.ver',
    'integracion.cobranzas.ver',
    'integracion.conciliar.ejecutar',
    'integracion.exportar'
);

-- facturador (4): dashboard + facturas + cobranzas
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 4, p.id FROM erp_permisos p
WHERE p.codigo IN (
    'integracion.dashboard.ver',
    'integracion.facturas.ver',
    'integracion.cobranzas.ver'
);

-- auditor (6): solo .ver
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 6, p.id FROM erp_permisos p
WHERE p.modulo = 'integracion' AND p.accion = 'ver';

-- direccion (7): dashboard + pagos + facturas + cobranzas + exportar
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 7, p.id FROM erp_permisos p
WHERE p.codigo IN (
    'integracion.dashboard.ver',
    'integracion.pagos.ver',
    'integracion.facturas.ver',
    'integracion.cobranzas.ver',
    'integracion.exportar'
);

-- revisor_fiscal (8): facturas + cobranzas + dashboard
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 8, p.id FROM erp_permisos p
WHERE p.codigo IN (
    'integracion.dashboard.ver',
    'integracion.facturas.ver',
    'integracion.cobranzas.ver'
);
