import { useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';

// v1.56 — el mismo build sirve prod y dev (API same-origin); el entorno se
// detecta por hostname para que nadie confunda dónde está parado.
export const ES_DEV = window.location.hostname.includes('erp-dev')
  || window.location.hostname === 'localhost'
  || window.location.hostname.includes('sslip.io');

export function AppShell() {
  const [drawerOpen, setDrawerOpen] = useState(false);
  const location = useLocation();

  // Al cambiar de ruta cerrar el drawer (mobile).
  const closeDrawer = () => setDrawerOpen(false);

  return (
    <div className="flex min-h-screen">
      {/* v1.56 — banner fijo de entorno de pruebas. */}
      {ES_DEV && (
        <div className="fixed top-0 left-0 right-0 z-50 bg-amber-500 text-black text-center text-[11px] font-bold py-0.5 tracking-wide">
          ⚠ ENTORNO DE PRUEBAS (DEV) — los cambios acá NO afectan producción
        </div>
      )}
      {/* Sidebar — fijo en md+ */}
      <div className="hidden md:flex">
        <Sidebar />
      </div>

      {/* Drawer mobile — overlay */}
      {drawerOpen && (
        <div className="md:hidden fixed inset-0 z-40">
          <div className="absolute inset-0 bg-black/50" onClick={closeDrawer} />
          <div className="absolute left-0 top-0 bottom-0 w-[260px] shadow-xl" onClick={closeDrawer}>
            <Sidebar />
          </div>
        </div>
      )}

      <main className="flex-1 flex flex-col min-w-0">
        <Topbar onMenuClick={() => setDrawerOpen((o) => !o)} />
        <div key={location.pathname} className="flex-1 p-3 sm:p-5 sm:px-6 overflow-y-auto">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
