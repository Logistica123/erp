import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AppShell } from './layout/AppShell';
import { ToastProvider } from './hooks/useToast';
import { AsientosPage } from './pages/AsientosPage';
import { BalanceSSPage } from './pages/BalanceSSPage';
import { BancosPage } from './pages/BancosPage';
import { ConciliacionPage } from './pages/ConciliacionPage';
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
              <Route path="/erp/libro-iva-compras" element={<LibroIvaImportarPage />} handle={{ crumb: 'Libro IVA — importar' }} />
            </Route>
            <Route path="*" element={<Navigate to="/erp/dashboard" replace />} />
          </Routes>
        </BrowserRouter>
      </ToastProvider>
    </QueryClientProvider>
  );
}
