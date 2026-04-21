import { NavLink } from 'react-router-dom';
import {
  Home,
  LayoutDashboard,
  BookOpen,
  BookText,
  ListTree,
  Scale,
  Landmark,
  ArrowLeftRight,
  Split,
  FileText,
  Receipt,
  Box,
  CalendarCheck,
  FileBarChart,
} from 'lucide-react';
import { cn } from '@/lib/cn';
import { auth } from '@/lib/auth';
import type { LucideIcon } from 'lucide-react';

type NavEntry = {
  to: string;
  label: string;
  icon: LucideIcon;
};

type NavSection = {
  label: string;
  items: NavEntry[];
};

const sections: NavSection[] = [
  {
    label: 'General',
    items: [
      { to: '/erp/inicio', label: 'Inicio', icon: Home },
      { to: '/erp/distriapp', label: 'DistriApp', icon: Box },
    ],
  },
  {
    label: 'ERP · Contabilidad',
    items: [
      { to: '/erp/dashboard', label: 'Dashboard', icon: LayoutDashboard },
      { to: '/erp/asientos', label: 'Asientos', icon: BookOpen },
      { to: '/erp/libro-diario', label: 'Libro Diario', icon: BookText },
      { to: '/erp/libro-mayor', label: 'Libro Mayor', icon: BookOpen },
      { to: '/erp/plan-cuentas', label: 'Plan de Cuentas', icon: ListTree },
      { to: '/erp/balance-ss', label: 'Balance S y S', icon: Scale },
      { to: '/erp/periodos', label: 'Períodos', icon: CalendarCheck },
      { to: '/erp/estados-contables', label: 'Estados Contables', icon: FileBarChart },
    ],
  },
  {
    label: 'ERP · Tesorería',
    items: [
      { to: '/erp/bancos', label: 'Bancos', icon: Landmark },
      { to: '/erp/ordenes-pago', label: 'Órdenes de pago', icon: ArrowLeftRight },
      { to: '/erp/conciliacion', label: 'Conciliación', icon: Split },
    ],
  },
  {
    label: 'ERP · Fiscal',
    items: [
      { to: '/erp/facturacion', label: 'Facturación (ARCA)', icon: FileText },
      { to: '/erp/impuestos', label: 'IVA / IIBB', icon: Receipt },
    ],
  },
];

export function Sidebar() {
  const user = auth.getUser();
  const initials = user?.name
    ? user.name
        .split(' ')
        .slice(0, 2)
        .map((s) => s[0])
        .join('')
        .toUpperCase()
    : 'US';

  return (
    <aside className="w-[220px] bg-navy-800 text-[#DCE5F0] flex flex-col shrink-0 min-h-full">
      {/* Brand */}
      <div className="px-[18px] py-5 pb-[18px] border-b border-white/10">
        <div className="flex items-center gap-[10px]">
          <div className="w-9 h-9 rounded-lg bg-gradient-to-br from-azure to-navy-600 flex items-center justify-center text-white font-bold text-base shadow-brand">
            LA
          </div>
          <div>
            <div className="text-[13px] font-semibold text-white leading-tight">
              Logística Argentina
            </div>
            <div className="text-[10px] text-[#7EA3CC] uppercase tracking-[0.5px] mt-[2px]">
              ERP · SRL
            </div>
          </div>
        </div>
      </div>

      {/* Nav sections */}
      <nav className="flex-1 overflow-y-auto scrollbar-thin">
        {sections.map((sec) => (
          <div key={sec.label}>
            <div className="px-[18px] pt-[18px] pb-[6px] text-[10px] font-semibold tracking-wider text-[#7EA3CC] uppercase">
              {sec.label}
            </div>
            {sec.items.map((item) => {
              const Icon = item.icon;
              return (
                <NavLink
                  key={item.to}
                  to={item.to}
                  className={({ isActive }) =>
                    cn(
                      'flex items-center gap-[11px] px-[18px] py-[9px] text-[13px] cursor-pointer border-l-[3px] border-transparent transition-colors select-none',
                      isActive
                        ? 'bg-gradient-to-r from-azure/15 to-transparent text-white border-l-azure font-medium'
                        : 'text-[#CFDAE7] hover:bg-white/5 hover:text-white'
                    )
                  }
                >
                  <Icon className="w-[18px] h-[18px] opacity-85 shrink-0" strokeWidth={1.7} />
                  {item.label}
                </NavLink>
              );
            })}
          </div>
        ))}
      </nav>

      {/* User footer */}
      <div className="mt-auto border-t border-white/10 px-[18px] py-[14px] text-[11px] text-[#7EA3CC]">
        <div className="flex items-center gap-[10px] py-[6px]">
          <div className="w-8 h-8 rounded-full bg-azure flex items-center justify-center text-white font-semibold text-[12px]">
            {initials}
          </div>
          <div className="leading-tight">
            <div className="font-medium text-white text-[12px]">{user?.name ?? 'Invitado'}</div>
            <div className="text-[10px] text-[#7EA3CC]">{user ? 'Super Admin' : 'Sin sesión'}</div>
          </div>
        </div>
      </div>
    </aside>
  );
}
