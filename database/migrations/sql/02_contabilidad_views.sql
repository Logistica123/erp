-- ============================================================================
-- VISTAS de apoyo para el frontend
-- NOTA: v_libro_mayor está reescrita con window function (SUM() OVER) porque
-- MariaDB no acepta variables de usuario (@saldo) dentro de una vista.
-- Equivalente funcional, soportado en MariaDB 10.2+ y MySQL 8+.
-- ============================================================================

CREATE OR REPLACE VIEW v_libro_diario AS
SELECT
    a.empresa_id,
    a.id AS asiento_id,
    a.fecha,
    d.codigo AS diario,
    a.numero,
    a.glosa,
    m.linea,
    c.codigo AS cta_codigo,
    c.nombre AS cta_nombre,
    cc.codigo AS centro_costo,
    ax.nombre AS auxiliar,
    m.debe,
    m.haber,
    a.estado
FROM erp_asientos a
JOIN erp_movimientos_asiento m ON m.asiento_id = a.id
JOIN erp_cuentas_contables c ON c.id = m.cuenta_id
JOIN erp_diarios d ON d.id = a.diario_id
LEFT JOIN erp_centros_costo cc ON cc.id = m.centro_costo_id
LEFT JOIN erp_auxiliares ax ON ax.id = m.auxiliar_id;

CREATE OR REPLACE VIEW v_libro_mayor AS
SELECT
    a.empresa_id,
    c.id AS cuenta_id,
    c.codigo,
    c.nombre,
    a.fecha,
    a.numero,
    d.codigo AS diario,
    a.glosa,
    m.debe,
    m.haber,
    SUM(m.debe - m.haber) OVER (
        PARTITION BY a.empresa_id, c.id
        ORDER BY a.fecha, a.numero, m.linea
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS saldo_corrido,
    ax.nombre AS auxiliar
FROM erp_asientos a
JOIN erp_movimientos_asiento m ON m.asiento_id = a.id
JOIN erp_cuentas_contables c ON c.id = m.cuenta_id
JOIN erp_diarios d ON d.id = a.diario_id
LEFT JOIN erp_auxiliares ax ON ax.id = m.auxiliar_id
WHERE a.estado = 'CONTABILIZADO';
