-- ============================================================================
-- SEED H5 — Escala Ganancias art 73 LIG + alícuotas BP (orientativas)
-- Idempotente vía INSERT IGNORE.
-- ============================================================================

-- Escala art 73 LIG vigente desde 2024 (Ley 27630 actualizada por IPC).
-- Los valores se ajustan anualmente; ver seed_erp_impuestos.sql para valores
-- históricos cuando se migre a producción y se necesite un rango mayor.
INSERT IGNORE INTO erp_ganancias_escala
  (vigente_desde, vigente_hasta, tramo, limite_inferior, limite_superior, cuota_fija, alicuota_marginal) VALUES
  ('2024-01-01', NULL, 1,          0.00,    14301209.21,         0.00, 0.2500),
  ('2024-01-01', NULL, 2,   14301209.21,   143012092.08,   3575302.30, 0.3000),
  ('2024-01-01', NULL, 3,  143012092.08,           NULL,  42188566.56, 0.3500);

-- Alícuota BP Participaciones vigente (art 25 Ley 23966 — 0.5% orientativo).
INSERT IGNORE INTO erp_bp_alicuotas (vigente_desde, vigente_hasta, tipo, alicuota) VALUES
  ('2024-01-01', NULL, 'PARTICIPACIONES', 0.0050),
  ('2024-01-01', NULL, 'GENERAL',         0.0050);

-- Catálogo de ajustes fiscales típicos (RN-55). El usuario puede agregar más.
INSERT IGNORE INTO erp_ganancias_ajustes_tipo (codigo, tipo, descripcion, activo) VALUES
  ('MULTAS_SANCIONES',           'MAS',   'Multas y sanciones no deducibles',                          1),
  ('INTERES_PUNITORIO_EXCESO',   'MAS',   'Intereses punitorios en exceso del tope legal',             1),
  ('AMORT_CONTABLES_EN_EXCESO',  'MAS',   'Amortizaciones contables que superan las fiscales',         1),
  ('PREVISIONES_NO_DEDUCIBLES',  'MAS',   'Previsiones constituidas no deducibles (art 87 LIG)',       1),
  ('HONORARIOS_DIRECTORES_EXC',  'MAS',   'Honorarios de directores que superan el tope legal',        1),
  ('AMORT_FISCALES_EN_EXCESO',   'MENOS', 'Amortizaciones fiscales que superan las contables',         1),
  ('EXENCIONES',                 'MENOS', 'Ingresos exentos contabilizados',                           1),
  ('AJUSTE_INFLACION_IMPOS',     'MENOS', 'Ajuste por inflación impositivo (si aplica RT 6)',          1);
