# Auditoría Compras vs Ventas — 2026-05-20

Generado por la IA del programador durante v1.27 Sprint D §14.
Cubre las 9 áreas listadas en el ADDENDUM_v1.27 §14.2.

## Funcionalidades trasladables aplicadas en este Sprint

| # | Funcionalidad | Estado Compras | Estado Ventas (pre-v1.27D) | Acción tomada |
|---|---|---|---|---|
| 1 | Paginación server-side `paginate(50)` en index | ✅ v1.42 | ❌ limit(200) hardcoded | ✅ Migrado a paginate (mismo patrón v1.42). |
| 2 | Filtros `imp_desde` / `imp_hasta` | ✅ v1.49 | ❌ Solo `desde/hasta` por emisión | ✅ Agregados (en ventas la imputación = emisión). |
| 3 | Endpoint `GET /export.xlsx` con filtros | ✅ v1.49 | ❌ No existía | ✅ Agregado (27 columnas + desglose IVA por alícuota). |
| 4 | Botón "Exportar Excel" en listado | ✅ v1.49 | ❌ No existía | ⏳ Pendiente frontend (siguiente sprint). |

## Funcionalidades ya simetrías (sin acción)

| # | Funcionalidad | Estado |
|---|---|---|
| 5 | Filtro período trabajado dropdown + bulk/inline edit | ✅ v1.27 (existente) en ambos |
| 6 | Columna "Origen" con badges | ✅ Existe en ambos |
| 7 | Filtro por origen persistido en URL | ✅ Existe en ambos (URLSearchParams) |
| 8 | Badge ✓ `verificada_arca` | ✅ Existe en Ventas (v1.18 E) |
| 9 | Bulk select + checkbox de selección | ✅ Ambos |
| 10 | Sidebar acordeón / breadcrumb dinámico | ✅ v1.7 — aplica a toda la app |

## Funcionalidades NO trasladables (con justificación)

| # | Funcionalidad de Compras | Por qué NO va a Ventas |
|---|---|---|
| 1 | Import del Libro IVA Compras (v1.11, v1.13, v1.19, v1.21, v1.22, v1.24) | Ventas TIENE su propio importer (v1.45 Libro IVA Ventas) construido en paralelo, no es portable directo. |
| 2 | Toggle "Tomado SÍ/NO" + filtro no_tomada | Concepto exclusivo del CSV AFIP del contador para compras. En Ventas todas las facturas están "tomadas" por definición. |
| 3 | Tabla `erp_configuracion_iva_mapeo` (v1.24) | Para imports masivos. La emisión usa el plan directo desde el módulo de Ventas. |
| 4 | Columnas OP externa + fecha de pago (v1.40) | Diseñado para tracking de pago de proveedores. En Ventas el equivalente es el cobro (módulo Tesorería). |

## Funcionalidades pendientes de simetría (para v1.28+)

| # | Funcionalidad de Compras | Estado en Ventas | Razón de no portar AÚN |
|---|---|---|---|
| 1 | Borrado masivo con `compras.facturas.borrar_masivo` (v1.22 §13) | ❌ No existe | El espejo (`ventas.facturas.borrar_masivo`) tiene complicaciones extra: cobros parciales, facturas con CAE real emitido. Decisión consciente — el caso de uso es raro y el riesgo de borrar lo emitido es mayor que en compras. Si se necesita, va en v1.28 con más validaciones. |
| 2 | Inline edit de jurisdicción IIBB | ❌ No existe en ventas | Bajo uso operativo. Si lo piden, va con el `JurisdiccionCell` reusable. |
| 3 | Botón "Exportar Excel" en FacturacionPage frontend | ❌ No existe (endpoint sí) | Pendiente sprint de frontend siguiente. |

## Plan de aplicación

**Aplicado en este Sprint D**:
- Backend listado venta: paginate + filtros imp_desde/imp_hasta + endpoint export.xlsx

**Pendiente** (decidir por separado):
- Frontend FacturacionPage: paginator + inputs imp_desde/imp_hasta + botón "Exportar Excel" (~1h)
- Borrado masivo de ventas (~2h, requiere análisis de cobros)
- Inline edit jurisdicción ventas (~30 min)

---

*Documento generado durante v1.27 Sprint D §14. La paridad esperada queda en ~85% — el resto se diferencia legítimamente por la naturaleza distinta de cada módulo.*
