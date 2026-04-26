import { Bell, ChevronDown, Menu } from 'lucide-react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { findActive } from './sections';

const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

type Periodo = { id: number; anio: number; mes: number; estado: string };

export function Topbar({ onMenuClick }: { onMenuClick?: () => void } = {}) {
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const active = findActive(pathname);

  const { data: periodoResp } = useQuery<{ data: Periodo | null }>({
    queryKey: ['periodo', 'abierto'],
    queryFn: () => api.get('/api/erp/periodos/abierto'),
    staleTime: 5 * 60 * 1000,
  });

  const periodo = periodoResp?.data;
  const diasRestantes = periodo ? diasHastaFinDeMes(periodo.anio, periodo.mes) : null;
  const cierreCercano = diasRestantes !== null && diasRestantes <= 5;

  return (
    <div className="h-[52px] bg-white border-b border-line flex items-center px-3 sm:px-6 gap-2 sm:gap-[18px]">
      {/* Hamburger — solo mobile */}
      <button
        onClick={onMenuClick}
        className="md:hidden p-[6px] -ml-1 text-ink-2 hover:bg-surface-hover rounded-md"
        aria-label="Abrir menú"
      >
        <Menu className="w-5 h-5" strokeWidth={1.7} />
      </button>
      <div className="flex items-center gap-[6px] text-[13px] text-ink-muted min-w-0">
        <span className="hidden sm:inline">ERP</span>
        <span className="hidden sm:inline">›</span>
        <span className="hidden sm:inline truncate">{active?.section.label ?? 'General'}</span>
        <span className="hidden sm:inline">›</span>
        <strong className="text-ink font-semibold truncate">{active?.entry.label ?? 'ERP'}</strong>
      </div>
      <div className="flex-1" />
      {periodo && (
        <button
          onClick={() => navigate('/erp/periodos')}
          className={`hidden sm:flex items-center gap-2 px-3 py-[5px] rounded-md text-[12px] font-medium transition-colors ${
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
      <button className="hidden sm:flex px-3 py-[6px] bg-white border border-line-strong rounded-md text-[12px] text-ink-2 hover:bg-surface-hover items-center gap-1">
        Logística Argentina SRL
        <ChevronDown className="w-3 h-3" />
      </button>
    </div>
  );
}

function diasHastaFinDeMes(anio: number, mes: number): number {
  const finDeMes = new Date(anio, mes, 0); // día 0 del mes siguiente = último del actual
  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);
  return Math.max(0, Math.round((finDeMes.getTime() - hoy.getTime()) / 86_400_000));
}
