-- ============================================================================
-- SEED H3 — Regímenes de retención operativos para Logística Argentina SRL
--   IVA RG 2854: regímenes 001 (RI), 002 (servicios), 003 (obra)
--   GAN RG 830: regímenes 116 (otras locaciones), 118 (transporte)
--   IIBB CABA RG AGIP: 78
--   IIBB PBA DN ARBA: 79
-- Idempotente vía INSERT IGNORE.
-- ============================================================================

INSERT IGNORE INTO erp_regimenes_retencion
  (codigo, tipo, descripcion, minimo_no_ret, alicuota, jurisdiccion, vigente_desde, vigente_hasta, activo) VALUES
  -- IVA RG 2854 (alicuotas espejo de la del comprobante)
  ('001','IVA','RI venta de cosas muebles — alic 21%',         400000.00, 0.2100, NULL, '2024-01-01', NULL, 1),
  ('002','IVA','Locaciones / Prestaciones de servicios — 21%', 400000.00, 0.2100, NULL, '2024-01-01', NULL, 1),
  ('003','IVA','Locaciones de obra — 10.5%',                   400000.00, 0.1050, NULL, '2024-01-01', NULL, 1),
  -- Ganancias RG 830 (alícuotas tomadas de tabla simplificada operativa)
  ('116','GAN','Otras locaciones y prestaciones — RG 830',     650000.00, 0.0200, NULL, '2024-01-01', NULL, 1),
  ('118','GAN','Transporte de carga nacional',                 650000.00, 0.0060, NULL, '2024-01-01', NULL, 1),
  -- IIBB CABA y PBA — alícuotas operativas (logística)
  ('78','IIBB','Retención IIBB CABA — servicios',                  0.00, 0.0200, '901', '2024-01-01', NULL, 1),
  ('79','IIBB','Retención IIBB PBA — servicios',                   0.00, 0.0400, '902', '2024-01-01', NULL, 1);
