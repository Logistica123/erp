-- ============================================================================
-- SPEC 08 — Seed: convenios + categorías + conceptos + permisos + roles.
-- Códigos de cuenta MAPEADOS a la realidad del plan vigente (opción C).
-- Idempotente: INSERT IGNORE.
-- ============================================================================

SET @empresa_id := 1;

-- ============================================================================
-- 1. CONVENIOS
-- ============================================================================
INSERT IGNORE INTO erp_emp_convenios (codigo, nombre, descripcion, activo) VALUES
 ('CAMIONEROS',      'Camioneros',           'Sindicato de Camioneros — CCT 40/89',                1),
 ('COMERCIO',        'Empleados de Comercio','CCT 130/75',                                         1),
 ('FUERA_CONVENIO',  'Fuera de Convenio',    'Personal jerárquico y categorías no convencionadas', 1),
 ('MONOTRIBUTISTA',  'Monotributista',       'Servicio facturado — no vinculado a convenio',       1);

-- ============================================================================
-- 2. CATEGORÍAS (por convenio)
-- ============================================================================
-- Camioneros
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'CAM_CHOFER_PRIM',  'Chofer Primera Categoría',  3, 'Vehículos pesados',           1 FROM erp_emp_convenios WHERE codigo='CAMIONEROS';
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'CAM_CHOFER_SEG',   'Chofer Segunda Categoría',  2, 'Vehículos livianos',          1 FROM erp_emp_convenios WHERE codigo='CAMIONEROS';
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'CAM_AYUD',         'Ayudante',                  1, 'Ayudante de carga',           1 FROM erp_emp_convenios WHERE codigo='CAMIONEROS';

-- Comercio
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'COM_ADM_A',        'Administrativo A',          2, 'Administración operativa',    1 FROM erp_emp_convenios WHERE codigo='COMERCIO';
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'COM_ADM_B',        'Administrativo B',          1, 'Administración auxiliar',     1 FROM erp_emp_convenios WHERE codigo='COMERCIO';
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'COM_VEND',         'Vendedor',                  2, 'Vendedor remoto',             1 FROM erp_emp_convenios WHERE codigo='COMERCIO';
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'COM_MAESTRANZA',   'Maestranza',                1, 'Limpieza y mantenimiento',    1 FROM erp_emp_convenios WHERE codigo='COMERCIO';

-- Fuera de convenio
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'FC_GERENTE',       'Gerente',                   5, 'Gerencia operativa / comercial', 1 FROM erp_emp_convenios WHERE codigo='FUERA_CONVENIO';
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'FC_JEFE',          'Jefatura',                  4, 'Jefe de área',                1 FROM erp_emp_convenios WHERE codigo='FUERA_CONVENIO';
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'FC_DIRECTOR',      'Dirección',                 6, 'Director / Socio',            1 FROM erp_emp_convenios WHERE codigo='FUERA_CONVENIO';

-- Monotributista
INSERT IGNORE INTO erp_emp_categorias (convenio_id, codigo, nombre, nivel_jerarquia, descripcion, activa)
SELECT id, 'MT_SERV',          'Servicios profesionales',   1, 'Factura C por servicios',     1 FROM erp_emp_convenios WHERE codigo='MONOTRIBUTISTA';

-- ============================================================================
-- 3. CATÁLOGO DE CONCEPTOS (HABERES Y DESCUENTOS)
-- Cuentas mapeadas opción C: ver header.
-- ============================================================================

-- HABERES REMUNERATIVOS
INSERT IGNORE INTO erp_emp_conceptos (codigo, nombre, tipo, signo, afecta_formal, afecta_efectivo, afecta_mt, formula, cuenta_debe_id, cuenta_haber_id, orden, activo)
VALUES
 ('BASICO',           'Sueldo básico',                       'REMUNERATIVO',     'HABER',     1, 1, 1, 'basico_mensual * (dias_trabajados/30)',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  10, 1),
 ('BASICO_PROP',      'Básico proporcional (ingreso/egreso)','REMUNERATIVO',     'HABER',     1, 1, 1, 'basico_mensual * dias_efectivos / dias_mes',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  11, 1),
 ('COMISION',         'Comisión por ventas',                 'COMISION',         'HABER',     1, 1, 1, 'base * porcentaje / 100',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  20, 1),
 ('HE_50',            'Horas extra al 50%',                  'REMUNERATIVO',     'HABER',     1, 1, 0, 'valor_hora * 1.5 * cantidad',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  30, 1),
 ('HE_100',           'Horas extra al 100%',                 'REMUNERATIVO',     'HABER',     1, 1, 0, 'valor_hora * 2 * cantidad',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  31, 1),
 ('SAC',              'SAC (aguinaldo semestral)',           'SAC',              'HABER',     1, 0, 0, 'mejor_remuneracion_semestre / 2',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.03' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  40, 1),
 ('AUMENTO_GERENCIAL','Aumento por decisión de gerencia',    'REMUNERATIVO',     'HABER',     1, 1, 1, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  50, 1),
 ('VACACIONES',       'Vacaciones gozadas',                  'REMUNERATIVO',     'HABER',     1, 1, 0, 'basico_diario * dias_vacaciones',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  60, 1),
 ('PRESENTISMO',      'Presentismo',                         'REMUNERATIVO',     'HABER',     1, 0, 0, 'basico * 0.085',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  70, 1);

-- HABERES NO REMUNERATIVOS
INSERT IGNORE INTO erp_emp_conceptos (codigo, nombre, tipo, signo, afecta_formal, afecta_efectivo, afecta_mt, formula, cuenta_debe_id, cuenta_haber_id, orden, activo)
VALUES
 ('VIATICO',          'Viáticos no remunerativos',           'NO_REMUNERATIVO',  'HABER',     1, 1, 0, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  80, 1),
 ('BONO_PROD',        'Bono de productividad',               'NO_REMUNERATIVO',  'HABER',     1, 1, 0, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),  90, 1);

-- HONORARIOS MT (MONOTRIBUTISTAS)
INSERT IGNORE INTO erp_emp_conceptos (codigo, nombre, tipo, signo, afecta_formal, afecta_efectivo, afecta_mt, formula, cuenta_debe_id, cuenta_haber_id, orden, activo)
VALUES
 ('HONORARIOS',       'Honorarios (servicio facturado)',     'REMUNERATIVO',     'HABER',     0, 0, 1, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.07' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.05' LIMIT 1), 100, 1);

-- DESCUENTOS LEGALES (solo afectan FORMAL)
INSERT IGNORE INTO erp_emp_conceptos (codigo, nombre, tipo, signo, afecta_formal, afecta_efectivo, afecta_mt, formula, cuenta_debe_id, cuenta_haber_id, orden, activo)
VALUES
 ('JUB_11',           'Jubilación 11%',                      'DESCUENTO_LEGAL',  'DESCUENTO', 1, 0, 0, 'bruto_formal * 0.11',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.11' LIMIT 1), 200, 1),
 ('OS_3',             'Obra Social 3%',                      'DESCUENTO_LEGAL',  'DESCUENTO', 1, 0, 0, 'bruto_formal * 0.03',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.11' LIMIT 1), 210, 1),
 ('LEY_19032',        'Ley 19.032 (INSSJP) 3%',              'DESCUENTO_LEGAL',  'DESCUENTO', 1, 0, 0, 'bruto_formal * 0.03',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.11' LIMIT 1), 220, 1),
 ('SINDICATO',        'Cuota sindical',                      'DESCUENTO_LEGAL',  'DESCUENTO', 1, 0, 0, 'bruto_formal * 0.025',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.11' LIMIT 1), 230, 1),
 ('GANANCIAS_4TA',    'Impuesto Ganancias 4ta Categoría',    'DESCUENTO_LEGAL',  'DESCUENTO', 1, 0, 0, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.3' LIMIT 1), 240, 1);

-- DESCUENTOS INTERNOS (afectan cualquier componente — se aplican sobre el neto total)
INSERT IGNORE INTO erp_emp_conceptos (codigo, nombre, tipo, signo, afecta_formal, afecta_efectivo, afecta_mt, formula, cuenta_debe_id, cuenta_haber_id, orden, activo)
VALUES
 ('PRESTAMO_CUOTA',   'Cuota de préstamo',                   'DESCUENTO_OTRO',   'DESCUENTO', 1, 1, 1, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='1.1.5.10' LIMIT 1), 300, 1),
 ('ADELANTO',         'Adelanto de sueldo',                  'DESCUENTO_OTRO',   'DESCUENTO', 1, 1, 1, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='1.1.5.02' LIMIT 1), 310, 1),
 ('COMBUSTIBLE',      'Combustible (CC personal)',           'DESCUENTO_OTRO',   'DESCUENTO', 1, 1, 1, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='1.1.5.11' LIMIT 1), 320, 1),
 ('POLIZA',           'Póliza de seguro',                    'DESCUENTO_OTRO',   'DESCUENTO', 1, 1, 1, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='1.1.5.12' LIMIT 1), 330, 1),
 ('SANCION',          'Sanción / multa interna',             'DESCUENTO_OTRO',   'DESCUENTO', 1, 1, 0, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='1.1.5.13' LIMIT 1), 340, 1),
 ('HORAS_DESC',       'Horas descontadas',                   'DESCUENTO_OTRO',   'DESCUENTO', 1, 1, 0, 'valor_hora * cantidad',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1), 350, 1),
 ('FALTA_DIA',        'Día no trabajado',                    'DESCUENTO_OTRO',   'DESCUENTO', 1, 1, 0, 'basico_diario * cantidad',
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1), 360, 1),
 ('AJUSTE_MANUAL',    'Ajuste manual',                       'AJUSTE',           'DESCUENTO', 1, 1, 1, NULL,
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='2.1.5.10' LIMIT 1),
  (SELECT id FROM erp_cuentas_contables WHERE empresa_id=@empresa_id AND codigo='5.2.1.01' LIMIT 1), 900, 1);

-- ============================================================================
-- 4. PERMISOS DEL MÓDULO SUELDOS (18)
-- ============================================================================
INSERT IGNORE INTO erp_permisos (codigo, modulo, entidad, accion, descripcion, sensible) VALUES
 ('sueldos.empleados.ver',            'sueldos', 'empleados',    'ver',     'Ver padrón de empleados',                       0),
 ('sueldos.empleados.editar',         'sueldos', 'empleados',    'editar',  'Alta/baja/edición de empleados',                1),
 ('sueldos.basicos.ver',              'sueldos', 'basicos',      'ver',     'Ver básicos e historial',                       0),
 ('sueldos.basicos.aprobar',          'sueldos', 'basicos',      'aprobar', 'Aprobar aumento o cambio de básico',            1),
 ('sueldos.novedades.ver',            'sueldos', 'novedades',    'ver',     'Ver novedades mensuales',                       0),
 ('sueldos.novedades.cargar',         'sueldos', 'novedades',    'cargar',  'Cargar novedades (HE, faltas, descuentos)',     0),
 ('sueldos.liquidaciones.ver',        'sueldos', 'liquidaciones','ver',     'Ver liquidaciones',                             0),
 ('sueldos.liquidaciones.calcular',   'sueldos', 'liquidaciones','calcular','Ejecutar cálculo de liquidación',               0),
 ('sueldos.liquidaciones.aprobar',    'sueldos', 'liquidaciones','aprobar', 'Aprobar liquidación',                           1),
 ('sueldos.liquidaciones.reabrir',    'sueldos', 'liquidaciones','reabrir', 'Reabrir liquidación cerrada (genera rectif.)',  1),
 ('sueldos.pagos.ejecutar.formal',    'sueldos', 'pagos',        'ejecutar_formal',    'Ejecutar pagos transferencia FORMAL',           1),
 ('sueldos.pagos.ejecutar.efectivo',  'sueldos', 'pagos',        'ejecutar_efectivo',  'Ejecutar pagos EFECTIVO',                       1),
 ('sueldos.pagos.ejecutar.mt',        'sueldos', 'pagos',        'ejecutar_mt',        'Ejecutar pagos a monotributistas',              1),
 ('sueldos.efectivos.ver',            'sueldos', 'efectivos',    'ver',     'Ver componente EFECTIVO (dato sensible)',       1),
 ('sueldos.cc.ver',                   'sueldos', 'cc',           'ver',     'Ver cuentas corrientes del empleado',           0),
 ('sueldos.cc.cargar',                'sueldos', 'cc',           'cargar',  'Cargar movimientos a CC del empleado',          0),
 ('sueldos.prestamos.otorgar',        'sueldos', 'prestamos',    'otorgar', 'Otorgar nuevo préstamo al personal',            1),
 ('sueldos.export.liber',             'sueldos', 'export',       'liber',   'Exportar XLSX FORMAL para LIBER',               0);

-- ============================================================================
-- 5. ROLES NUEVOS (rrhh + contador_interno; revisor_fiscal ya existe).
-- ============================================================================
INSERT IGNORE INTO erp_roles (empresa_id, codigo, nombre, descripcion, nivel_jerarquia, protegido, activo) VALUES
 (1, 'rrhh',             'Recursos Humanos',     'Carga empleados, novedades, préstamos. No ejecuta pagos.',         25, 1, 1),
 (1, 'contador_interno', 'Contador Interno',     'Variante de contador con acceso al componente EFECTIVO.',          10, 1, 1);

-- ============================================================================
-- 6. ASIGNACIÓN DE PERMISOS POR ROL
-- ============================================================================

-- super_admin: TODO
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM erp_permisos p, erp_roles r
WHERE p.modulo='sueldos' AND r.codigo='super_admin';

-- direccion: ver + aprobar + ver EFECTIVO
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM erp_permisos p, erp_roles r
WHERE p.modulo='sueldos'
  AND r.codigo='direccion'
  AND (p.accion IN ('ver','aprobar') OR p.codigo='sueldos.efectivos.ver');

-- contador_interno: ver + calcular/aprobar liquidaciones + EFECTIVO + export LIBER + reabrir + ejecutar EFECTIVO
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM erp_permisos p, erp_roles r
WHERE r.codigo='contador_interno'
  AND p.codigo IN (
    'sueldos.empleados.ver','sueldos.basicos.ver','sueldos.basicos.aprobar',
    'sueldos.novedades.ver','sueldos.liquidaciones.ver','sueldos.liquidaciones.calcular',
    'sueldos.liquidaciones.aprobar','sueldos.efectivos.ver','sueldos.cc.ver',
    'sueldos.export.liber','sueldos.liquidaciones.reabrir',
    'sueldos.pagos.ejecutar.efectivo'
);

-- rrhh: empleados + novedades + préstamos + ver básicos + ver liquidaciones (sin aprobar)
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM erp_permisos p, erp_roles r
WHERE r.codigo='rrhh'
  AND p.codigo IN (
    'sueldos.empleados.ver','sueldos.empleados.editar',
    'sueldos.basicos.ver',
    'sueldos.novedades.ver','sueldos.novedades.cargar',
    'sueldos.liquidaciones.ver',
    'sueldos.cc.ver','sueldos.cc.cargar',
    'sueldos.prestamos.otorgar'
);

-- tesorero: solo ejecutar pagos formales y MT (NUNCA EFECTIVO)
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM erp_permisos p, erp_roles r
WHERE r.codigo='tesorero'
  AND p.codigo IN (
    'sueldos.liquidaciones.ver',
    'sueldos.pagos.ejecutar.formal',
    'sueldos.pagos.ejecutar.mt'
);

-- auditor: solo lecturas, NO componente EFECTIVO
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM erp_permisos p, erp_roles r
WHERE p.modulo='sueldos' AND p.accion='ver'
  AND r.codigo='auditor'
  AND p.codigo <> 'sueldos.efectivos.ver';

-- revisor_fiscal: ver FORMAL + export LIBER (NO efectivos.ver)
INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM erp_permisos p, erp_roles r
WHERE r.codigo='revisor_fiscal'
  AND p.codigo IN (
    'sueldos.empleados.ver',
    'sueldos.basicos.ver',
    'sueldos.liquidaciones.ver',
    'sueldos.export.liber'
);
