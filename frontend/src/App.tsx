import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AppShell } from './layout/AppShell';
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
import { auth } from './lib/auth';
import type { ReactNode } from 'react';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      staleTime: 30_000,
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
            <Route path="/erp/balance-ss" element={<BalanceSSPage />} handle={{ crumb: 'Balance S y S' }} />
            <Route path="/erp/periodos" element={<PeriodosPage />} handle={{ crumb: 'Períodos' }} />
            <Route path="/erp/estados-contables" element={<EstadosContablesPage />} handle={{ crumb: 'Estados Contables' }} />
            <Route path="/erp/facturacion" element={<FacturacionPage />} handle={{ crumb: 'Facturación' }} />
            <Route path="/erp/facturacion/nueva" element={<NuevaFacturaPage />} handle={{ crumb: 'Nueva factura' }} />
            <Route path="/erp/libro-iva-ventas" element={<LibroIvaVentasPage />} handle={{ crumb: 'Libro IVA Ventas' }} />
            <Route path="/erp/bancos" element={<BancosPage />} handle={{ crumb: 'Bancos' }} />
            <Route path="/erp/conciliacion" element={<ConciliacionPage />} handle={{ crumb: 'Conciliación' }} />
            <Route path="/erp/ordenes-pago" element={<OrdenesPagoPage />} handle={{ crumb: 'Órdenes de pago' }} />
          </Route>
          <Route path="*" element={<Navigate to="/erp/dashboard" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  );
}
