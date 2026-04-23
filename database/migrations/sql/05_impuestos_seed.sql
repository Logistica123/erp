-- ============================================================================
-- SEED H1 — Impuestos: jurisdicciones IIBB + permisos + rol revisor_fiscal
-- Idempotente vía INSERT IGNORE.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 24 jurisdicciones IIBB (códigos SIFERE) — sólo activamos las operativas
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO erp_iibb_jurisdicciones (codigo, nombre, activa, alicuota_default, portal_url) VALUES
  ('901','Ciudad Autónoma de Buenos Aires', 1, 0.0400, 'https://www.agip.gob.ar'),
  ('902','Buenos Aires',                    1, 0.0350, 'https://www.arba.gov.ar'),
  ('903','Catamarca',                       0, 0.0300, NULL),
  ('904','Córdoba',                         0, 0.0400, 'https://www.rentascordoba.gob.ar'),
  ('905','Corrientes',                      0, 0.0250, NULL),
  ('906','Chaco',                           0, 0.0350, NULL),
  ('907','Chubut',                          0, 0.0300, NULL),
  ('908','Entre Ríos',                      0, 0.0350, NULL),
  ('909','Formosa',                         0, 0.0300, NULL),
  ('910','Jujuy',                           0, 0.0300, NULL),
  ('911','La Pampa',                        0, 0.0300, NULL),
  ('912','La Rioja',                        0, 0.0300, NULL),
  ('913','Mendoza',                         0, 0.0400, NULL),
  ('914','Misiones',                        0, 0.0350, NULL),
  ('915','Neuquén',                         0, 0.0300, NULL),
  ('916','Río Negro',                       0, 0.0300, NULL),
  ('917','Salta',                           0, 0.0360, NULL),
  ('918','San Juan',                        0, 0.0300, NULL),
  ('919','San Luis',                        0, 0.0350, NULL),
  ('920','Santa Cruz',                      0, 0.0300, NULL),
  ('921','Santa Fe',                        0, 0.0450, NULL),
  ('922','Santiago del Estero',             0, 0.0350, NULL),
  ('923','Tierra del Fuego',                0, 0.0300, NULL),
  ('924','Tucumán',                         0, 0.0350, NULL);

-- ----------------------------------------------------------------------------
-- 16 permisos del módulo (impuestos.* + reportes.* + eecc.* + ejercicio.cerrar)
-- Schema real: codigo, modulo, entidad, accion, descripcion, sensible
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO erp_permisos (codigo, modulo, entidad, accion, descripcion, sensible) VALUES
  ('impuestos.periodo.crear',     'impuestos','periodo',   'crear',     'Crear período fiscal',                                  0),
  ('impuestos.periodo.revisar',   'impuestos','periodo',   'revisar',   'Pasar período fiscal a EN_REVISION',                    0),
  ('impuestos.periodo.aprobar',   'impuestos','periodo',   'aprobar',   'Aprobar período fiscal',                                1),
  ('impuestos.periodo.presentar', 'impuestos','periodo',   'presentar', 'Marcar período como PRESENTADO ante el fisco',          1),
  ('impuestos.libro_iva.generar', 'impuestos','libro_iva', 'generar',   'Generar archivo F.8001 Libro IVA Digital',              0),
  ('impuestos.iva.generar',       'impuestos','iva',       'generar',   'Generar DDJJ IVA F.2002',                               0),
  ('impuestos.sicore.generar',    'impuestos','sicore',    'generar',   'Generar archivo SIRE/SICORE de retenciones',            0),
  ('impuestos.iibb.generar',      'impuestos','iibb',      'generar',   'Generar archivos IIBB (CM03/CM05/ARCiBA/ARBA)',         0),
  ('impuestos.ganancias.editar',  'impuestos','ganancias', 'editar',    'Editar ajustes fiscales de Ganancias',                  1),
  ('impuestos.ganancias.generar', 'impuestos','ganancias', 'generar',   'Generar F.713 Ganancias',                               1),
  ('impuestos.bp.generar',        'impuestos','bp',        'generar',   'Generar F.2000 Bienes Personales Participaciones',      1),
  ('reportes.ver',                'reportes', 'reportes',  'ver',       'Ver reportes contables y gerenciales',                  0),
  ('reportes.exportar',           'reportes', 'reportes',  'exportar',  'Exportar reportes a PDF/XLSX/DOCX',                     0),
  ('eecc.generar',                'eecc',     'eecc',      'generar',   'Generar Estados Contables',                             1),
  ('eecc.editar_notas',           'eecc',     'eecc',      'editar',    'Editar notas a los Estados Contables',                  0),
  ('ejercicio.cerrar',            'contabilidad','ejercicio','cerrar',  'Cerrar ejercicio contable',                             1);

-- ----------------------------------------------------------------------------
-- Rol revisor_fiscal (LIBER) — sin acceso operativo, solo revisión
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO erp_roles (id, empresa_id, codigo, nombre, descripcion, nivel_jerarquia, protegido, activo) VALUES
  (8, 1, 'revisor_fiscal', 'Revisor Fiscal', 'Contador externo (LIBER). Revisa períodos fiscales y EECC, no muta datos operativos.', 15, 1, 1);

-- Asignación de permisos al rol revisor_fiscal
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 8, p.id
  FROM erp_permisos p
 WHERE p.codigo IN (
        'impuestos.periodo.revisar',
        'impuestos.periodo.aprobar',
        'impuestos.libro_iva.generar',
        'impuestos.iva.generar',
        'impuestos.sicore.generar',
        'impuestos.iibb.generar',
        'impuestos.ganancias.editar',
        'impuestos.ganancias.generar',
        'impuestos.bp.generar',
        'reportes.ver',
        'reportes.exportar',
        'eecc.generar',
        'eecc.editar_notas',
        'core.auditoria.ver'
       );

-- super_admin recibe todos los nuevos permisos automáticamente
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 1, p.id
  FROM erp_permisos p
 WHERE p.codigo IN (
        'impuestos.periodo.crear','impuestos.periodo.revisar','impuestos.periodo.aprobar',
        'impuestos.periodo.presentar','impuestos.libro_iva.generar','impuestos.iva.generar',
        'impuestos.sicore.generar','impuestos.iibb.generar','impuestos.ganancias.editar',
        'impuestos.ganancias.generar','impuestos.bp.generar','reportes.ver','reportes.exportar',
        'eecc.generar','eecc.editar_notas','ejercicio.cerrar'
       );

-- contador (rol 2): permisos operativos del módulo (todo menos cierre y presentar)
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 2, p.id
  FROM erp_permisos p
 WHERE p.codigo IN (
        'impuestos.periodo.crear','impuestos.periodo.revisar',
        'impuestos.libro_iva.generar','impuestos.iva.generar','impuestos.sicore.generar',
        'impuestos.iibb.generar','impuestos.ganancias.editar','impuestos.ganancias.generar',
        'impuestos.bp.generar','reportes.ver','reportes.exportar','eecc.generar','eecc.editar_notas'
       );
