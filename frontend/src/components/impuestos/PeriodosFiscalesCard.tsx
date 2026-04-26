import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { useApi } from '@/hooks/useApi';
import { History } from 'lucide-react';

type PeriodoFiscal = {
  id: number;
  impuesto: string;
  anio: number;
  mes: number | null;
  estado: 'ABIERTO' | 'EN_REVISION' | 'APROBADO' | 'PRESENTADO' | 'CERRADO' | 'RECTIFICATIVA';
  fecha_vencimiento: string;
  fecha_presentacion: string | null;
  nro_tramite: string | null;
};

type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number };

const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

const ESTADO_VARIANT: Record<PeriodoFiscal['estado'], 'success' | 'warning' | 'info' | 'neutral' | 'danger'> = {
  ABIERTO: 'neutral',
  EN_REVISION: 'warning',
  APROBADO: 'info',
  PRESENTADO: 'success',
  CERRADO: 'success',
  RECTIFICATIVA: 'danger',
};

/**
 * Listado de períodos fiscales del impuesto indicado. Click en una fila
 * dispara `onSelect(id)` para que el padre cargue la DDJJ correspondiente.
 *
 * Reemplaza al input "ID Período fiscal" manual de las pantallas Impuestos.
 */
export function PeriodosFiscalesCard({
  impuesto,
  selectedId,
  onSelect,
  titulo,
}: {
  impuesto: string;
  selectedId?: string;
  onSelect: (id: number) => void;
  titulo?: string;
}) {
  const { data, isLoading } = useApi<Paginated<PeriodoFiscal>>(
    ['periodos-fiscales', impuesto],
    `/api/erp/impuestos/periodos?impuesto=${impuesto}&per_page=24`,
  );

  return (
    <Card>
      <CardHeader title={
        <div className="flex items-center gap-2"><History className="w-4 h-4 text-azure" /> {titulo ?? 'Períodos fiscales'}</div>
      } />
      <CardBody>
        <table className="w-full text-[12px]">
          <thead>
            <tr className="bg-surface-hover border-b border-line-strong">
              <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[160px]">Período</th>
              <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[120px]">Estado</th>
              <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[120px]">Vencimiento</th>
              <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[120px]">Presentado</th>
              <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase">N° trámite</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={5} className="py-6 text-center text-ink-muted">Cargando…</td></tr>
            )}
            {!isLoading && (data?.data.length ?? 0) === 0 && (
              <tr><td colSpan={5} className="py-6 text-center text-ink-muted">
                Sin períodos fiscales para {impuesto}. Creá uno desde la pantalla Períodos fiscales.
              </td></tr>
            )}
            {data?.data.map((p, i) => {
              const sel = String(p.id) === selectedId;
              return (
                <tr
                  key={p.id}
                  onClick={() => onSelect(p.id)}
                  className={`border-b border-line cursor-pointer transition-colors ${
                    sel ? 'bg-navy-50 ring-1 ring-azure/30' : i % 2 ? 'bg-surface-row hover:bg-surface-hover' : 'hover:bg-surface-hover'
                  }`}
                >
                  <td className="px-[10px] py-[7px] text-ink-2 font-medium">
                    {p.mes ? `${MESES[p.mes - 1]} ${p.anio}` : `Anual ${p.anio}`}
                    <span className="text-ink-muted text-[10px] ml-1">#{p.id}</span>
                  </td>
                  <td className="px-[10px] py-[7px]">
                    <Badge variant={ESTADO_VARIANT[p.estado]}>{p.estado}</Badge>
                  </td>
                  <td className="px-[10px] py-[7px] tabular text-ink-2 text-[11px]">
                    {p.fecha_vencimiento ? new Date(p.fecha_vencimiento).toLocaleDateString('es-AR') : '—'}
                  </td>
                  <td className="px-[10px] py-[7px] tabular text-ink-2 text-[11px]">
                    {p.fecha_presentacion ? new Date(p.fecha_presentacion).toLocaleDateString('es-AR') : '—'}
                  </td>
                  <td className="px-[10px] py-[7px] font-mono text-[10px] text-ink-muted">
                    {p.nro_tramite ?? '—'}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {data && data.total > 0 && (
          <div className="text-[11px] text-ink-muted mt-2 text-right">
            {data.total} período{data.total === 1 ? '' : 's'}
          </div>
        )}
      </CardBody>
    </Card>
  );
}
