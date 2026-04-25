-- ============================================================================
-- ANEXO Cierres Diarios — bloque CB-1: permisos
-- 6 permisos del módulo + asignaciones a roles existentes.
-- Idempotente: re-ejecutable sin duplicar.
-- ============================================================================

INSERT IGNORE INTO erp_permisos (codigo, modulo, entidad, accion, descripcion, sensible) VALUES
  ('cierres.dia.ver',                'cierres',       'dia',     'ver',           'Ver dashboards y detalles de cierres diarios',          0),
  ('cierres.dia.iniciar',            'cierres',       'dia',     'iniciar',       'Iniciar proceso de cierre del día',                     0),
  ('cierres.dia.sellar',             'cierres',       'dia',     'sellar',        'Sellar día (irreversible salvo reapertura)',            1),
  ('cierres.dia.exportar',           'cierres',       'dia',     'exportar',      'Generar Excel/PDF para LIBER',                          0),
  ('cierres.dia.reabrir',            'cierres',       'dia',     'reabrir',       'Reapertura cascada de días cerrados (caso edge)',       1),
  ('contabilidad.ajuste_retroactivo','contabilidad',  'asiento', 'ajuste_retro',  'Asiento de ajuste forward sobre día cerrado',           1);

-- ============================================================================
-- Asignaciones por rol (según anexo §14)
-- ============================================================================

-- super_admin (1): TODO
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 1, p.id FROM erp_permisos p
WHERE p.codigo IN (
    'cierres.dia.ver','cierres.dia.iniciar','cierres.dia.sellar',
    'cierres.dia.exportar','cierres.dia.reabrir','contabilidad.ajuste_retroactivo'
);

-- contador (2): ver + iniciar + sellar + exportar
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 2, p.id FROM erp_permisos p
WHERE p.codigo IN (
    'cierres.dia.ver','cierres.dia.iniciar','cierres.dia.sellar','cierres.dia.exportar'
);

-- revisor_fiscal (8): solo ver + exportar
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 8, p.id FROM erp_permisos p
WHERE p.codigo IN (
    'cierres.dia.ver','cierres.dia.exportar'
);

-- direccion (7): ver + exportar (lectura ejecutiva)
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 7, p.id FROM erp_permisos p
WHERE p.codigo IN (
    'cierres.dia.ver','cierres.dia.exportar'
);

-- auditor (6): solo ver
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 6, p.id FROM erp_permisos p
WHERE p.codigo = 'cierres.dia.ver';
