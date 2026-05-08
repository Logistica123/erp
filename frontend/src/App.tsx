import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AppShell } from './layout/AppShell';
import { ToastProvider } from './hooks/useToast';
import { AsientosPage } from './pages/AsientosPage';
import { AuditoriaPage } from './pages/AuditoriaPage';
import { PlaceholderPage } from './pages/PlaceholderPage';
import { BalanceSSPage } from './pages/BalanceSSPage';
import { BancosPage } from './pages/BancosPage';
import { ConciliacionPage } from './pages/ConciliacionPage';
import { ConciliacionReglasPage } from './pages/ConciliacionReglasPage';
import { DashboardPage } from './pages/DashboardPage';
import { EstadosContablesPage } from './pages/EstadosContablesPage';
import { FacturacionPage } from './pages/FacturacionPage';
import { LibroIvaVentasPage } from './pages/LibroIvaVentasPage';
import { NuevaFacturaPage } from './pages/NuevaFacturaPage';
import { LibroDiarioPage } from './pages/LibroDiarioPage';
import { LibroMayorPage } from './pages/LibroMayorPage';
import { LoginPage } from './pages/LoginPage';
import { NuevoAsientoPage } from './pages/NuevoAsientoPage';
import { OrdenesPagoPage } from './pages/OrdenesPagoPage';
import { PeriodosPage } from './pages/PeriodosPage';
import { PlanCuentasPage } from './pages/PlanCuentasPage';
import { CobrosPage } from './pages/CobrosPage';
import { EcheqPage } from './pages/EcheqPage';
import { TransferenciasPage } from './pages/TransferenciasPage';
import { ArqueosPage } from './pages/ArqueosPage';
import { FacturasCompraPage } from './pages/FacturasCompraPage';
import { CCPage } from './pages/CCPage';
import { FcePage } from './pages/FcePage';
import { PeriodosFiscalesPage } from './pages/PeriodosFiscalesPage';
import { LibroIvaDigitalPage } from './pages/LibroIvaDigitalPage';
import { IvaDdjjPage } from './pages/IvaDdjjPage';
import { SicorePage } from './pages/SicorePage';
import { IibbPage } from './pages/IibbPage';
import { GananciasPage } from './pages/GananciasPage';
import { BpPage } from './pages/BpPage';
import { AgingPage } from './pages/AgingPage';
import { ComparativoPage } from './pages/ComparativoPage';
import { ArcaDashboardPage } from './pages/ArcaDashboardPage';
import { PadronPage } from './pages/PadronPage';
import { ConstatacionPage } from './pages/ConstatacionPage';
import { MisComprobantesPage } from './pages/MisComprobantesPage';
import { CategoriasAfPage } from './pages/CategoriasAfPage';
import { BienesPage } from './pages/BienesPage';
import { AmortizacionesPage } from './pages/AmortizacionesPage';
import { ReportesAfPage } from './pages/ReportesAfPage';
import { PresupuestosPage } from './pages/PresupuestosPage';
import { EjecucionPresupuestoPage } from './pages/EjecucionPresupuestoPage';
import { DistriappPage } from './pages/DistriappPage';
import { LibroIvaImportarPage } from './pages/LibroIvaImportarPage';
import { LibroIvaComprasExportPage } from './pages/LibroIvaComprasExportPage';
import { LibroIvaComprasImportPage } from './pages/LibroIvaComprasImportPage';
import { LibroIvaComprasNoTomadasPage } from './pages/LibroIvaComprasNoTomadasPage';
import { ReportesAnaliticosPage } from './pages/ReportesAnaliticosPage';
import { EmpleadosPage } from './pages/Sueldos/EmpleadosPage';
import { NovedadesPage } from './pages/Sueldos/NovedadesPage';
import { AusenciasPage } from './pages/Sueldos/AusenciasPage';
import { CCPrestamosPage } from './pages/Sueldos/CCPrestamosPage';
import { LiquidacionesPage as LiquidacionesSueldosPage } from './pages/Sueldos/LiquidacionesPage';
import { LiberPage } from './pages/Sueldos/LiberPage';
import { ReportesSueldosPage } from './pages/Sueldos/ReportesSueldosPage';
import { CierresDiariosPage } from './pages/CierresDiariosPage';
import { auth } from './lib/auth';
import type { ReactNode } from 'react';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      staleTime: 30_000,
      retry: 1,
    },
  },
});

function RequireAuth({ children }: { children: ReactNode }) {
  if (!auth.isLoggedIn()) return <Navigate to="/login" replace />;
  return <>{children}</>;
}


export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route
              element={
                <RequireAuth>
                  <AppShell />
                </RequireAuth>
              }
            >
              <Route path="/" element={<Navigate to="/erp/dashboard" replace />} />
              <Route path="/erp" element={<Navigate to="/erp/dashboard" replace />} />
              <Route path="/erp/dashboard" element={<DashboardPage />} handle={{ crumb: 'Dashboard' }} />
              <Route path="/erp/asientos/nuevo" element={<NuevoAsientoPage />} handle={{ crumb: 'Nuevo asiento' }} />
              <Route path="/erp/asientos" element={<AsientosPage />} handle={{ crumb: 'Asientos' }} />
              <Route path="/erp/libro-diario" element={<LibroDiarioPage />} handle={{ crumb: 'Libro Diario' }} />
              <Route path="/erp/libro-mayor" element={<LibroMayorPage />} handle={{ crumb: 'Libro Mayor' }} />
              <Route path="/erp/plan-cuentas" element={<PlanCuentasPage />} handle={{ crumb: 'Plan de Cuentas' }} />
              <Route path="/erp/balance-ss" element={<BalanceSSPage />} handle={{ crumb: 'Sumas y Saldos' }} />
              <Route path="/erp/periodos" element={<PeriodosPage />} handle={{ crumb: 'Períodos' }} />
              <Route path="/erp/estados-contables" element={<EstadosContablesPage />} handle={{ crumb: 'Estados Contables' }} />
              <Route path="/erp/facturacion" element={<FacturacionPage />} handle={{ crumb: 'Facturación' }} />
              <Route path="/erp/facturacion/nueva" element={<NuevaFacturaPage />} handle={{ crumb: 'Nueva factura' }} />
              <Route path="/erp/libro-iva-ventas" element={<LibroIvaVentasPage />} handle={{ crumb: 'Libro IVA Ventas' }} />
              <Route path="/erp/bancos" element={<BancosPage />} handle={{ crumb: 'Bancos' }} />
              <Route path="/erp/conciliacion" element={<ConciliacionPage />} handle={{ crumb: 'Conciliación' }} />
              <Route path="/erp/conciliacion-reglas" element={<ConciliacionReglasPage />} handle={{ crumb: 'Reglas conciliación' }} />
              <Route path="/erp/ordenes-pago" element={<OrdenesPagoPage />} handle={{ crumb: 'Órdenes de pago' }} />

              {/* F2 — Tesorería */}
              <Route path="/erp/cobros" element={<CobrosPage />} handle={{ crumb: 'Cobros' }} />
              <Route path="/erp/echeq" element={<EcheqPage />} handle={{ crumb: 'eCheq' }} />
              <Route path="/erp/transferencias" element={<TransferenciasPage />} handle={{ crumb: 'Transferencias' }} />
              <Route path="/erp/arqueos" element={<ArqueosPage />} handle={{ crumb: 'Arqueos' }} />

              {/* F3 — Compras + CC + FCE */}
              <Route path="/erp/facturas-compra" element={<FacturasCompraPage />} handle={{ crumb: 'Facturas de compra' }} />
              <Route path="/erp/cc-clientes" element={<CCPage kind="clientes" />} handle={{ crumb: 'CC Clientes' }} />
              <Route path="/erp/cc-proveedores" element={<CCPage kind="proveedores" />} handle={{ crumb: 'CC Proveedores' }} />
              <Route path="/erp/fce" element={<FcePage />} handle={{ crumb: 'FCE MiPyME' }} />

              {/* F4 — Impuestos H1-H4 */}
              <Route path="/erp/impuestos/periodos" element={<PeriodosFiscalesPage />} handle={{ crumb: 'Períodos fiscales' }} />
              <Route path="/erp/impuestos/libro-iva-digital" element={<LibroIvaDigitalPage />} handle={{ crumb: 'Libro IVA Digital' }} />
              <Route path="/erp/impuestos/iva" element={<IvaDdjjPage />} handle={{ crumb: 'IVA F.2002' }} />
              <Route path="/erp/impuestos/sicore" element={<SicorePage />} handle={{ crumb: 'SICORE/SIRE' }} />
              <Route path="/erp/impuestos/iibb-cm" element={<IibbPage kind="cm" />} handle={{ crumb: 'IIBB CM' }} />
              <Route path="/erp/impuestos/iibb-caba" element={<IibbPage kind="caba" />} handle={{ crumb: 'IIBB CABA' }} />
              <Route path="/erp/impuestos/iibb-pba" element={<IibbPage kind="pba" />} handle={{ crumb: 'IIBB PBA' }} />

              {/* F5 — Ganancias + BP + Aging + Comparativo */}
              <Route path="/erp/impuestos/ganancias" element={<GananciasPage />} handle={{ crumb: 'Ganancias' }} />
              <Route path="/erp/impuestos/bp" element={<BpPage />} handle={{ crumb: 'BP F.2000' }} />
              <Route path="/erp/reportes/aging" element={<AgingPage />} handle={{ crumb: 'Aging' }} />
              <Route path="/erp/reportes/comparativo" element={<ComparativoPage />} handle={{ crumb: 'Comparativo' }} />
              <Route path="/erp/reportes/analiticos" element={<ReportesAnaliticosPage />} handle={{ crumb: 'Analíticos (CC + Jurisdicción)' }} />

              {/* F6 — ARCA Gateway */}
              <Route path="/erp/arca/dashboard" element={<ArcaDashboardPage />} handle={{ crumb: 'ARCA Gateway' }} />
              <Route path="/erp/arca/padron" element={<PadronPage />} handle={{ crumb: 'Padrón AFIP' }} />
              <Route path="/erp/arca/constatacion" element={<ConstatacionPage />} handle={{ crumb: 'Constatación' }} />
              <Route path="/erp/arca/mis-comprobantes" element={<MisComprobantesPage />} handle={{ crumb: 'Mis Comprobantes' }} />

              {/* F7 — Activos Fijos */}
              <Route path="/erp/af/categorias" element={<CategoriasAfPage />} handle={{ crumb: 'Categorías AF' }} />
              <Route path="/erp/af/bienes" element={<BienesPage />} handle={{ crumb: 'Bienes' }} />
              <Route path="/erp/af/amortizaciones" element={<AmortizacionesPage />} handle={{ crumb: 'Amortizaciones' }} />
              <Route path="/erp/af/reportes" element={<ReportesAfPage />} handle={{ crumb: 'Reportes AF' }} />

              {/* F8 — Presupuestos */}
              <Route path="/erp/presupuestos" element={<PresupuestosPage />} handle={{ crumb: 'Presupuestos' }} />
              <Route path="/erp/presupuestos/ejecucion" element={<EjecucionPresupuestoPage />} handle={{ crumb: 'Ejecución' }} />

              {/* General — DistriApp + Libro IVA importar (cierran §9) */}
              <Route path="/erp/inicio" element={<Navigate to="/erp/dashboard" replace />} />
              <Route path="/erp/distriapp" element={<DistriappPage />} handle={{ crumb: 'DistriApp' }} />
              <Route path="/erp/libro-iva-compras" element={<LibroIvaImportarPage />} handle={{ crumb: 'Libro IVA — importar (legacy)' }} />
              <Route path="/erp/libro-iva-compras/import" element={<LibroIvaComprasImportPage />} handle={{ crumb: 'Libro IVA — import enriquecido' }} />
              <Route path="/erp/libro-iva-compras/no-tomadas" element={<LibroIvaComprasNoTomadasPage />} handle={{ crumb: 'Libro IVA — no tomadas' }} />
              <Route path="/erp/libro-iva-compras/exportar" element={<LibroIvaComprasExportPage />} handle={{ crumb: 'Libro IVA — exportar F.8001' }} />

              {/* Sueldos (SPEC 08) */}
              <Route path="/erp/sueldos/empleados" element={<EmpleadosPage />} handle={{ crumb: 'Empleados' }} />
              <Route path="/erp/sueldos/novedades" element={<NovedadesPage />} handle={{ crumb: 'Novedades' }} />
              <Route path="/erp/sueldos/ausencias" element={<AusenciasPage />} handle={{ crumb: 'Ausencias' }} />
              <Route path="/erp/sueldos/cc" element={<CCPrestamosPage />} handle={{ crumb: 'CC + Préstamos' }} />
              <Route path="/erp/sueldos/liquidaciones" element={<LiquidacionesSueldosPage />} handle={{ crumb: 'Liquidaciones' }} />
              <Route path="/erp/sueldos/liber" element={<LiberPage />} handle={{ crumb: 'Export LIBER' }} />
              <Route path="/erp/sueldos/reportes" element={<ReportesSueldosPage />} handle={{ crumb: 'Reportes Sueldos' }} />

              {/* Cierres Diarios — anexo SPEC Conciliación Multibanco */}
              <Route path="/erp/cierres-diarios" element={<CierresDiariosPage />} handle={{ crumb: 'Cierres diarios' }} />

              {/* Administración — addendum v1.7 §3.2 */}
              <Route path="/erp/admin/auditoria" element={<AuditoriaPage />} handle={{ crumb: 'Auditoría' }} />
              <Route path="/erp/admin/empresas" element={<PlaceholderPage title="Empresas" modulo="Administración" endpoint="GET /api/erp/empresas/actual" />} handle={{ crumb: 'Empresas' }} />
              <Route path="/erp/admin/usuarios" element={<PlaceholderPage title="Usuarios" modulo="Administración" endpoint="GET /api/erp/usuarios" />} handle={{ crumb: 'Usuarios' }} />
              <Route path="/erp/admin/roles-permisos" element={<PlaceholderPage title="Roles y permisos" modulo="Administración" endpoint="GET /api/erp/roles" />} handle={{ crumb: 'Roles' }} />
              <Route path="/erp/admin/diarios" element={<PlaceholderPage title="Diarios contables" modulo="Administración" endpoint="GET /api/erp/diarios" />} handle={{ crumb: 'Diarios' }} />
              <Route path="/erp/admin/centros-costo" element={<PlaceholderPage title="Centros de Costo" modulo="Administración" endpoint="GET /api/erp/centros-costo" />} handle={{ crumb: 'CC' }} />
              <Route path="/erp/admin/auxiliares" element={<PlaceholderPage title="Auxiliares" modulo="Administración" endpoint="GET /api/erp/auxiliares" />} handle={{ crumb: 'Auxiliares' }} />
              <Route path="/erp/admin/configuracion" element={<PlaceholderPage title="Configuración" modulo="Administración" endpoint="GET /api/erp/config" />} handle={{ crumb: 'Configuración' }} />
            </Route>
            <Route path="*" element={<Navigate to="/erp/dashboard" replace />} />
          </Routes>
        </BrowserRouter>
      </ToastProvider>
    </QueryClientProvider>
  );
}
