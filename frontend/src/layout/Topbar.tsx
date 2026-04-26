import { Bell, ChevronDown } from 'lucide-react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

const CRUMBS: Array<{ match: RegExp; section: string; crumb: string }> = [
  { match: /^\/erp\/dashboard/, section: 'General', crumb: 'Dashboard' },
  { match: /^\/erp\/asientos\/nuevo/, section: 'Contabilidad', crumb: 'Nuevo asiento' },
  { match: /^\/erp\/asientos/, section: 'Contabilidad', crumb: 'Asientos' },
  { match: /^\/erp\/libro-diario/, section: 'Contabilidad', crumb: 'Libro Diario' },
  { match: /^\/erp\/libro-mayor/, section: 'Contabilidad', crumb: 'Libro Mayor' },
  { match: /^\/erp\/plan-cuentas/, section: 'Contabilidad', crumb: 'Plan de Cuentas' },
  { match: /^\/erp\/balance-ss/, section: 'Contabilidad', crumb: 'Balance S y S' },
  { match: /^\/erp\/periodos/, section: 'Contabilidad', crumb: 'Períodos' },
  { match: /^\/erp\/estados-contables/, section: 'Contabilidad', crumb: 'Estados Contables' },
  { match: /^\/erp\/bancos/, section: 'Tesorería', crumb: 'Bancos' },
  { match: /^\/erp\/cobros/, section: 'Tesorería', crumb: 'Cobros' },
  { match: /^\/erp\/ordenes-pago/, section: 'Tesorería', crumb: 'Órdenes de pago' },
  { match: /^\/erp\/echeq/, section: 'Tesorería', crumb: 'eCheq' },
  { match: /^\/erp\/transferencias/, section: 'Tesorería', crumb: 'Transferencias' },
  { match: /^\/erp\/arqueos/, section: 'Tesorería', crumb: 'Arqueos' },
  { match: /^\/erp\/conciliacion-reglas/, section: 'Tesorería', crumb: 'Reglas conciliación' },
  { match: /^\/erp\/conciliacion/, section: 'Tesorería', crumb: 'Conciliación' },
  { match: /^\/erp\/cierres-diarios/, section: 'Tesorería', crumb: 'Cierres diarios' },
  { match: /^\/erp\/facturacion/, section: 'Ventas', crumb: 'Facturación (ARCA)' },
  { match: /^\/erp\/cc-clientes/, section: 'Ventas', crumb: 'CC Clientes' },
  { match: /^\/erp\/libro-iva-ventas/, section: 'Ventas', crumb: 'Libro IVA Ventas' },
  { match: /^\/erp\/fce/, section: 'Ventas', crumb: 'FCE MiPyME' },
  { match: /^\/erp\/facturas-compra/, section: 'Compras', crumb: 'Facturas de compra' },
  { match: /^\/erp\/cc-proveedores/, section: 'Compras', crumb: 'CC Proveedores' },
  { match: /^\/erp\/impuestos/, section: 'Impuestos', crumb: 'Impuestos' },
  { match: /^\/erp\/reportes/, section: 'Reportes', crumb: 'Reportes' },
  { match: /^\/erp\/(af|bienes|categorias-af|amortizaciones)/, section: 'Activos Fijos', crumb: 'Activos Fijos' },
  { match: /^\/erp\/(presupuestos|ejecucion-presupuesto)/, section: 'Presupuestos', crumb: 'Presupuestos' },
  { match: /^\/erp\/arca/, section: 'ARCA Gateway', crumb: 'ARCA' },
  { match: /^\/erp\/sueldos/, section: 'Sueldos', crumb: 'Sueldos' },
  { match: /^\/erp\/distriapp/, section: 'Integración', crumb: 'DistriApp' },
];

const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

type Periodo = { id: number; anio: number; mes: number; estado: string };

export function Topbar() {
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const item = CRUMBS.find((c) => c.match.test(pathname));

  const { data: periodoResp } = useQuery<{ data: Periodo | null }>({
    queryKey: ['periodo', 'abierto'],
    queryFn: () => api.get('/api/erp/periodos/abierto'),
    staleTime: 5 * 60 * 1000,
  });

  const periodo = periodoResp?.data;
  const diasRestantes = periodo ? diasHastaFinDeMes(periodo.anio, periodo.mes) : null;
  const cierreCercano = diasRestantes !== null && diasRestantes <= 5;

  return (
    <div className="h-[52px] bg-white border-b border-line flex items-center px-6 gap-[18px]">
      <div className="flex items-center gap-[6px] text-[13px] text-ink-muted">
        <span>ERP</span>
        <span>›</span>
        <span>{item?.section ?? 'General'}</span>
        <span>›</span>
        <strong className="text-ink font-semibold">{item?.crumb ?? 'ERP'}</strong>
      </div>
      <div className="flex-1" />
      {periodo && (
        <button
          onClick={() => navigate('/erp/periodos')}
          className={`flex items-center gap-2 px-3 py-[5px] rounded-md text-[12px] font-medium transition-colors ${
            cierreCercano
              ? 'bg-amber-50 border border-amber-300 text-amber-800 hover:bg-amber-100'
              : 'bg-[#EEF3F8] border border-[#D1DCE8] text-navy-700 hover:bg-[#E5EDF5]'
          }`}
          title="Ir a períodos"
        >
          <span className={`w-[7px] h-[7px] rounded-full ${cierreCercano ? 'bg-amber-500' : 'bg-success'}`} />
          Período abierto: {MESES[periodo.mes - 1]} {periodo.anio}
          {cierreCercano && (
            <span className="font-semibold">· cerrar en {diasRestantes} día{diasRestantes === 1 ? '' : 's'}</span>
          )}
        </button>
      )}
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

function diasHastaFinDeMes(anio: number, mes: number): number {
  const finDeMes = new Date(anio, mes, 0); // mes - 1 + 1 = "día 0 del mes siguiente" = último día del mes actual
  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);
  return Math.max(0, Math.round((finDeMes.getTime() - hoy.getTime()) / 86_400_000));
}
