import { Bell, ChevronDown } from 'lucide-react';
import { useLocation } from 'react-router-dom';

const CRUMBS: Array<{ match: RegExp; crumb: string }> = [
  { match: /^\/erp\/dashboard/, crumb: 'Dashboard' },
  { match: /^\/erp\/asientos\/nuevo/, crumb: 'Nuevo asiento' },
  { match: /^\/erp\/asientos/, crumb: 'Asientos' },
  { match: /^\/erp\/libro-diario/, crumb: 'Libro Diario' },
  { match: /^\/erp\/libro-mayor/, crumb: 'Libro Mayor' },
  { match: /^\/erp\/plan-cuentas/, crumb: 'Plan de Cuentas' },
  { match: /^\/erp\/balance-ss/, crumb: 'Balance S y S' },
  { match: /^\/erp\/periodos/, crumb: 'Períodos' },
  { match: /^\/erp\/estados-contables/, crumb: 'Estados Contables' },
  { match: /^\/erp\/bancos/, crumb: 'Bancos' },
  { match: /^\/erp\/ordenes-pago/, crumb: 'Órdenes de pago' },
  { match: /^\/erp\/conciliacion/, crumb: 'Conciliación' },
  { match: /^\/erp\/facturacion/, crumb: 'Facturación (ARCA)' },
  { match: /^\/erp\/impuestos/, crumb: 'IVA / IIBB' },
];

export function Topbar() {
  const { pathname } = useLocation();
  const current = CRUMBS.find((c) => c.match.test(pathname))?.crumb ?? 'ERP';

  return (
    <div className="h-[52px] bg-white border-b border-line flex items-center px-6 gap-[18px]">
      <div className="flex items-center gap-[6px] text-[13px] text-ink-muted">
        <span>ERP</span>
        <span>›</span>
        <span>Contabilidad</span>
        <span>›</span>
        <strong className="text-ink font-semibold">{current}</strong>
      </div>
      <div className="flex-1" />
      <div className="flex items-center gap-2 px-3 py-[5px] bg-[#EEF3F8] border border-[#D1DCE8] rounded-md text-[12px] text-navy-700 font-medium">
        <span className="w-[7px] h-[7px] rounded-full bg-success" />
        Período abierto: Abril 2026
      </div>
      <button className="p-[6px] bg-white border border-line-strong rounded-md text-ink-2 hover:bg-surface-hover">
        <Bell className="w-4 h-4" strokeWidth={1.7} />
      </button>
      <button className="px-3 py-[6px] bg-white border border-line-strong rounded-md text-[12px] text-ink-2 hover:bg-surface-hover flex items-center gap-1">
        Logística Argentina SRL
        <ChevronDown className="w-3 h-3" />
      </button>
    </div>
  );
}
