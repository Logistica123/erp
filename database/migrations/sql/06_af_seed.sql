-- ============================================================================
-- SEED I1 — SPEC 06: Categorías AF base + 14 permisos del módulo
-- Idempotente vía INSERT IGNORE.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 8 categorías típicas para SRL logística (RN-77 umbrales).
-- Resolvemos cuentas por código (los IDs son volátiles entre bases).
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO erp_af_categorias (
    codigo, nombre, descripcion,
    vida_util_contable_meses, vida_util_fiscal_meses, valor_residual_pct,
    metodo_amortizacion,
    cuenta_bien_id, cuenta_amort_acum_id, cuenta_amort_ejercicio_id,
    cuenta_resultado_baja_pos_id, cuenta_resultado_baja_neg_id,
    umbral_baja_cuantia, activa
)
SELECT
    'RODADOS', 'Rodados (vehículos)', 'Camionetas, autos y motos de la flota.',
    60, 60, 0.00, 'LINEAL',
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.1.01' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.2.01' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.2.4.01' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='4.3.02'   AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.6.03'   AND empresa_id=1),
    100000.00, 1
WHERE EXISTS (SELECT 1 FROM erp_cuentas_contables WHERE codigo='1.2.1.01' AND empresa_id=1);

INSERT IGNORE INTO erp_af_categorias (
    codigo, nombre, descripcion,
    vida_util_contable_meses, vida_util_fiscal_meses, valor_residual_pct,
    metodo_amortizacion,
    cuenta_bien_id, cuenta_amort_acum_id, cuenta_amort_ejercicio_id,
    cuenta_resultado_baja_pos_id, cuenta_resultado_baja_neg_id,
    umbral_baja_cuantia, activa
)
SELECT
    'INFORMATICA', 'Equipos Informáticos', 'Notebooks, desktops, monitores, servidores.',
    36, 36, 0.00, 'LINEAL',
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.1.03' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.2.03' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.2.4.03' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='4.3.02'   AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.6.03'   AND empresa_id=1),
    50000.00, 1
WHERE EXISTS (SELECT 1 FROM erp_cuentas_contables WHERE codigo='1.2.1.03' AND empresa_id=1);

INSERT IGNORE INTO erp_af_categorias (
    codigo, nombre, descripcion,
    vida_util_contable_meses, vida_util_fiscal_meses, valor_residual_pct,
    metodo_amortizacion,
    cuenta_bien_id, cuenta_amort_acum_id, cuenta_amort_ejercicio_id,
    cuenta_resultado_baja_pos_id, cuenta_resultado_baja_neg_id,
    umbral_baja_cuantia, activa
)
SELECT
    'TELEFONIA', 'Teléfonos celulares', 'Móviles entregados a distribuidores y administración.',
    36, 36, 0.00, 'LINEAL',
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.1.03' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.2.03' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.2.4.03' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='4.3.02'   AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.6.03'   AND empresa_id=1),
    30000.00, 1
WHERE EXISTS (SELECT 1 FROM erp_cuentas_contables WHERE codigo='1.2.1.03' AND empresa_id=1);

INSERT IGNORE INTO erp_af_categorias (
    codigo, nombre, descripcion,
    vida_util_contable_meses, vida_util_fiscal_meses, valor_residual_pct,
    metodo_amortizacion,
    cuenta_bien_id, cuenta_amort_acum_id, cuenta_amort_ejercicio_id,
    cuenta_resultado_baja_pos_id, cuenta_resultado_baja_neg_id,
    umbral_baja_cuantia, activa
)
SELECT
    'MUEBLES', 'Muebles y útiles', 'Escritorios, sillas, archivos, decoración.',
    120, 120, 0.00, 'LINEAL',
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.1.02' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.2.02' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.2.4.02' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='4.3.02'   AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.6.03'   AND empresa_id=1),
    30000.00, 1
WHERE EXISTS (SELECT 1 FROM erp_cuentas_contables WHERE codigo='1.2.1.02' AND empresa_id=1);

INSERT IGNORE INTO erp_af_categorias (
    codigo, nombre, descripcion,
    vida_util_contable_meses, vida_util_fiscal_meses, valor_residual_pct,
    metodo_amortizacion,
    cuenta_bien_id, cuenta_amort_acum_id, cuenta_amort_ejercicio_id,
    cuenta_resultado_baja_pos_id, cuenta_resultado_baja_neg_id,
    umbral_baja_cuantia, activa
)
SELECT
    'INSTALACIONES', 'Instalaciones oficina', 'Cableado, AA, divisorios.',
    60, 60, 0.00, 'LINEAL',
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.1.04' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.2.04' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.2.4.04' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='4.3.02'   AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.6.03'   AND empresa_id=1),
    50000.00, 1
WHERE EXISTS (SELECT 1 FROM erp_cuentas_contables WHERE codigo='1.2.1.04' AND empresa_id=1);

INSERT IGNORE INTO erp_af_categorias (
    codigo, nombre, descripcion,
    vida_util_contable_meses, vida_util_fiscal_meses, valor_residual_pct,
    metodo_amortizacion,
    cuenta_bien_id, cuenta_amort_acum_id, cuenta_amort_ejercicio_id,
    cuenta_resultado_baja_pos_id, cuenta_resultado_baja_neg_id,
    umbral_baja_cuantia, activa
)
SELECT
    'INMUEBLES', 'Inmuebles propios', 'Edificios, depósitos, terrenos.',
    600, 600, 0.00, 'LINEAL',
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.1.05' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='1.2.2.05' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.2.4.05' AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='4.3.02'   AND empresa_id=1),
    (SELECT id FROM erp_cuentas_contables WHERE codigo='5.6.03'   AND empresa_id=1),
    0.00, 1
WHERE EXISTS (SELECT 1 FROM erp_cuentas_contables WHERE codigo='1.2.1.05' AND empresa_id=1);

-- ----------------------------------------------------------------------------
-- 14 permisos del módulo (af.* + presupuesto.*).
-- Schema real: codigo, modulo, entidad, accion, descripcion, sensible.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO erp_permisos (codigo, modulo, entidad, accion, descripcion, sensible) VALUES
  ('af.categorias.gestionar',    'af', 'categorias',     'gestionar', 'Crear/editar categorías AF',                1),
  ('af.bienes.crear',            'af', 'bienes',         'crear',     'Alta de bien de uso',                       0),
  ('af.bienes.editar',           'af', 'bienes',         'editar',    'Editar datos no contables del bien',        0),
  ('af.bienes.baja',             'af', 'bienes',         'baja',      'Dar de baja un bien',                       1),
  ('af.bienes.mejora',           'af', 'bienes',         'mejora',    'Registrar mejora / revalúo',                1),
  ('af.amortizaciones.generar',  'af', 'amortizaciones', 'generar',   'Ejecutar amortización mensual',             1),
  ('af.reexpresion.generar',     'af', 'reexpresion',    'generar',   'Generar reexpresión RT 6',                  1),
  ('af.reportes.ver',            'af', 'reportes',       'ver',       'Ver reportes AF',                           0),
  ('af.reportes.exportar',       'af', 'reportes',       'exportar',  'Exportar reportes AF',                      0),
  ('presupuesto.crear',          'presupuesto', 'presupuestos', 'crear',     'Crear presupuesto / reforecast',     0),
  ('presupuesto.editar',         'presupuesto', 'presupuestos', 'editar',    'Editar líneas de presupuesto',       0),
  ('presupuesto.aprobar',        'presupuesto', 'presupuestos', 'aprobar',   'Aprobar y marcar VIGENTE',           1),
  ('presupuesto.descartar',      'presupuesto', 'presupuestos', 'descartar', 'Descartar presupuesto',              1),
  ('presupuesto.variaciones.ver','presupuesto', 'variaciones',  'ver',       'Ver dashboard de variaciones',       0);

-- super_admin recibe todos los permisos nuevos automáticamente
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 1, p.id FROM erp_permisos p
 WHERE p.codigo LIKE 'af.%' OR p.codigo LIKE 'presupuesto.%';

-- contador (rol 2) recibe AF completo + presupuesto.variaciones.ver
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 2, p.id FROM erp_permisos p
 WHERE p.codigo LIKE 'af.%' OR p.codigo = 'presupuesto.variaciones.ver';

-- direccion (rol 7) y revisor_fiscal (rol 8) ven variaciones presupuestarias
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 7, p.id FROM erp_permisos p WHERE p.codigo IN ('presupuesto.variaciones.ver','af.reportes.ver','af.reportes.exportar');
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT 8, p.id FROM erp_permisos p WHERE p.codigo IN ('presupuesto.variaciones.ver','af.reportes.ver');
