import {
  ArrowLeftRight, Banknote, BookOpen, BookText, Box, Building2,
  Calculator, CalendarCheck, ClipboardList, CloudCog, Coins, Cog,
  FileBarChart, FileText, History, Landmark, LayoutDashboard, ListTree, Lock,
  PieChart, Receipt, Scale, ScrollText, ShieldCheck, ShoppingCart,
  Split, Tag, TrendingUp, Truck, UserCog, Users, Wallet, Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export type NavEntry = {
  to: string;
  label: string;
  icon: LucideIcon;
};

export type NavSection = {
  label: string;
  items: NavEntry[];
};

/**
 * Modelo único del menú lateral. Lo consumen Sidebar (renderiza) y Topbar
 * (deriva el segmento de breadcrumb a partir de la ruta actual).
 */
export const sections: NavSection[] = [
  {
    label: 'General',
    items: [
      { to: '/erp/dashboard', label: 'Dashboard', icon: LayoutDashboard },
      { to: '/erp/distriapp', label: 'DistriApp', icon: Box },
    ],
  },
  {
    label: 'Contabilidad',
    items: [
      // v1.15 Sprint M — Asientos absorbido en Libro Diario (botón "+ Nuevo asiento" en su header).
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
      { to: '/erp/conciliacion-reglas', label: 'Reglas conciliación', icon: Split },
      { to: '/erp/cierres-diarios', label: 'Cierres diarios', icon: Lock },
    ],
  },
  {
    label: 'Ventas',
    items: [
      { to: '/erp/facturacion', label: 'Facturas de venta', icon: FileText },
      { to: '/erp/cc-clientes', label: 'CC Clientes', icon: Users },
      { to: '/erp/cc-clientes/imputar-nc', label: 'Imputar NC a facturas', icon: Users },
      { to: '/erp/libro-iva-ventas', label: 'Libro IVA Ventas (import)', icon: ScrollText },
      { to: '/erp/fce', label: 'FCE MiPyME', icon: ClipboardList },
    ],
  },
  {
    label: 'Compras',
    items: [
      { to: '/erp/facturas-compra', label: 'Facturas de compra', icon: ShoppingCart },
      { to: '/erp/cc-proveedores', label: 'CC Proveedores', icon: Users },
      { to: '/erp/libro-iva-compras/import', label: 'Libro IVA Compras (import enriquecido)', icon: ScrollText },
      { to: '/erp/libro-iva-compras/no-tomadas', label: 'Libro IVA Compras (no tomadas)', icon: ScrollText },
      { to: '/erp/libro-iva-compras/exportar', label: 'Libro IVA Compras (export F.8001)', icon: FileText },
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
      { to: '/erp/reportes/analiticos', label: 'Analíticos (CC + Jurisdicción)', icon: TrendingUp },
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
  {
    label: 'Administración',
    items: [
      { to: '/erp/admin/empresas', label: 'Empresas', icon: Building2 },
      { to: '/erp/admin/usuarios', label: 'Usuarios', icon: UserCog },
      { to: '/erp/admin/roles-permisos', label: 'Roles y permisos', icon: ShieldCheck },
      { to: '/erp/admin/diarios', label: 'Diarios contables', icon: BookText },
      { to: '/erp/admin/centros-costo', label: 'Centros de Costo', icon: Tag },
      { to: '/erp/admin/auxiliares', label: 'Auxiliares', icon: Users },
      { to: '/erp/admin/configuracion', label: 'Configuración', icon: Cog },
      { to: '/erp/admin/auditoria', label: 'Auditoría (log)', icon: History },
    ],
  },
  {
    label: 'Sueldos',
    items: [
      { to: '/erp/sueldos/empleados', label: 'Empleados', icon: Users },
      { to: '/erp/sueldos/novedades', label: 'Novedades del mes', icon: ClipboardList },
      { to: '/erp/sueldos/ausencias', label: 'Ausencias', icon: CalendarCheck },
      { to: '/erp/sueldos/cc', label: 'CC + Préstamos', icon: Wallet },
      { to: '/erp/sueldos/liquidaciones', label: 'Liquidaciones', icon: Calculator },
      { to: '/erp/sueldos/liber', label: 'Export LIBER', icon: FileBarChart },
      { to: '/erp/sueldos/reportes', label: 'Reportes', icon: PieChart },
    ],
  },
];

/**
 * Devuelve la sección + entry que mejor matchea el pathname actual.
 * Estrategia: el item cuyo `to` es prefix más largo del pathname gana.
 */
export function findActive(pathname: string): { section: NavSection; entry: NavEntry } | null {
  let best: { section: NavSection; entry: NavEntry; score: number } | null = null;
  for (const sec of sections) {
    for (const entry of sec.items) {
      if (pathname === entry.to || pathname.startsWith(entry.to + '/')) {
        const score = entry.to.length;
        if (!best || score > best.score) {
          best = { section: sec, entry, score };
        }
      }
    }
  }
  return best ? { section: best.section, entry: best.entry } : null;
}
