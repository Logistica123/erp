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
  ShoppingCart,
  Wallet,
  Users,
  Banknote,
  ScrollText,
  ClipboardList,
  Calculator,
  PieChart,
  Building2,
  Truck,
  Wrench,
  TrendingUp,
  Coins,
  ShieldCheck,
  Tag,
  CloudCog,
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
      { to: '/erp/dashboard', label: 'Dashboard', icon: LayoutDashboard },
      { to: '/erp/distriapp', label: 'DistriApp', icon: Box },
    ],
  },
  {
    label: 'Contabilidad',
    items: [
      { to: '/erp/asientos', label: 'Asientos', icon: BookOpen },
      { to: '/erp/libro-diario', label: 'Libro Diario', icon: BookText },
      { to: '/erp/libro-mayor', label: 'Libro Mayor', icon: BookOpen },
      { to: '/erp/plan-cuentas', label: 'Plan de Cuentas', icon: ListTree },
      { to: '/erp/balance-ss', label: 'Sumas y Saldos', icon: Scale },
      { to: '/erp/periodos', label: 'Períodos', icon: CalendarCheck },
      { to: '/erp/estados-contables', label: 'Estados Contables', icon: FileBarChart },
    ],
  },
  {
    label: 'Tesorería',
    items: [
      { to: '/erp/bancos', label: 'Bancos y Cajas', icon: Landmark },
      { to: '/erp/cobros', label: 'Cobros', icon: Wallet },
      { to: '/erp/ordenes-pago', label: 'Órdenes de pago', icon: ArrowLeftRight },
      { to: '/erp/echeq', label: 'eCheq', icon: Banknote },
      { to: '/erp/transferencias', label: 'Transferencias int.', icon: Split },
      { to: '/erp/arqueos', label: 'Arqueos de caja', icon: Calculator },
      { to: '/erp/conciliacion', label: 'Conciliación', icon: Split },
    ],
  },
  {
    label: 'Ventas',
    items: [
      { to: '/erp/facturacion', label: 'Facturas de venta', icon: FileText },
      { to: '/erp/cc-clientes', label: 'CC Clientes', icon: Users },
      { to: '/erp/libro-iva-ventas', label: 'Libro IVA Ventas (import)', icon: ScrollText },
      { to: '/erp/fce', label: 'FCE MiPyME', icon: ClipboardList },
    ],
  },
  {
    label: 'Compras',
    items: [
      { to: '/erp/facturas-compra', label: 'Facturas de compra', icon: ShoppingCart },
      { to: '/erp/cc-proveedores', label: 'CC Proveedores', icon: Users },
      { to: '/erp/libro-iva-compras', label: 'Libro IVA Compras (import)', icon: ScrollText },
    ],
  },
  {
    label: 'Impuestos',
    items: [
      { to: '/erp/impuestos/periodos', label: 'Períodos fiscales', icon: CalendarCheck },
      { to: '/erp/impuestos/iva', label: 'IVA F.2002', icon: Receipt },
      { to: '/erp/impuestos/libro-iva-digital', label: 'Libro IVA Digital F.8001', icon: ScrollText },
      { to: '/erp/impuestos/sicore', label: 'SICORE / SIRE', icon: Coins },
      { to: '/erp/impuestos/iibb-cm', label: 'IIBB Conv Multilateral', icon: Receipt },
      { to: '/erp/impuestos/iibb-caba', label: 'IIBB CABA (ARCiBA)', icon: Receipt },
      { to: '/erp/impuestos/iibb-pba', label: 'IIBB PBA (ARBA)', icon: Receipt },
      { to: '/erp/impuestos/ganancias', label: 'Ganancias F.713', icon: PieChart },
      { to: '/erp/impuestos/bp', label: 'BP F.2000', icon: PieChart },
    ],
  },
  {
    label: 'Reportes',
    items: [
      { to: '/erp/reportes/aging', label: 'Aging clientes/prov', icon: TrendingUp },
      { to: '/erp/reportes/comparativo', label: 'Comparativo períodos', icon: TrendingUp },
    ],
  },
  {
    label: 'Activos Fijos',
    items: [
      { to: '/erp/af/bienes', label: 'Bienes', icon: Building2 },
      { to: '/erp/af/categorias', label: 'Categorías', icon: Tag },
      { to: '/erp/af/amortizaciones', label: 'Amortizaciones', icon: Wrench },
      { to: '/erp/af/reportes', label: 'Anexo BdU + Reportes', icon: FileBarChart },
    ],
  },
  {
    label: 'Presupuestos',
    items: [
      { to: '/erp/presupuestos', label: 'Listado', icon: ClipboardList },
      { to: '/erp/presupuestos/ejecucion', label: 'Ejecución', icon: TrendingUp },
    ],
  },
  {
    label: 'ARCA Gateway',
    items: [
      { to: '/erp/arca/dashboard', label: 'Estado gateway', icon: CloudCog },
      { to: '/erp/arca/padron', label: 'Padrón AFIP', icon: ShieldCheck },
      { to: '/erp/arca/constatacion', label: 'Constatación CAE', icon: ShieldCheck },
      { to: '/erp/arca/mis-comprobantes', label: 'Mis Comprobantes (scraper)', icon: Truck },
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
    <aside className="w-[230px] bg-navy-800 text-[#DCE5F0] flex flex-col shrink-0 min-h-full">
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
      <nav className="flex-1 overflow-y-auto scrollbar-thin pb-2">
        {sections.map((sec) => (
          <div key={sec.label}>
            <div className="px-[18px] pt-[14px] pb-[5px] text-[10px] font-semibold tracking-wider text-[#7EA3CC] uppercase">
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
                      'flex items-center gap-[11px] px-[18px] py-[7px] text-[12.5px] cursor-pointer border-l-[3px] border-transparent transition-colors select-none',
                      isActive
                        ? 'bg-gradient-to-r from-azure/15 to-transparent text-white border-l-azure font-medium'
                        : 'text-[#CFDAE7] hover:bg-white/5 hover:text-white'
                    )
                  }
                >
                  <Icon className="w-[16px] h-[16px] opacity-85 shrink-0" strokeWidth={1.7} />
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
