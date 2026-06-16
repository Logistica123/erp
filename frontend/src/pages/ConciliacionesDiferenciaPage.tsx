import { useState } from 'react';
import { Download, Loader2, Scale } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { auth } from '@/lib/auth';

type Row = {
  id: number; fecha: string; banco: string | null; concepto: string | null;
  monto_mov: string; monto_conciliado: string; diferencia: string;
  cuenta_ajuste: string | null; motivo: string | null; tipo: string | null;
};
type Meta = { total_diferencia: number; por_motivo: Array<{ motivo: string; cantidad: number; total: number }> };

export default function ConciliacionesDiferenciaPage() {
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');

  const qs = new URLSearchParams();
  if (desde) qs.set('fecha_desde', desde);
  if (hasta) qs.set('fecha_hasta', hasta);

  const { data, isLoading } = useQuery<{ data: Row[]; meta: Meta }>({
    queryKey: ['conciliaciones-diferencia', desde, hasta],
    queryFn: () => api.get(`/api/erp/contabilidad/conciliaciones-con-diferencia${qs.toString() ? `?${qs}` : ''}`),
  });

  const rows = data?.data ?? [];
  const meta = data?.meta;

  const exportar = () => {
    const token = auth.getToken();
    fetch(`/api/erp/contabilidad/conciliaciones-con-diferencia/export${qs.toString() ? `?${qs}` : ''}`,
      { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((b) => {
        const url = URL.createObjectURL(b);
        const a = document.createElement('a'); a.href = url; a.download = 'conciliaciones_con_diferencia.csv'; a.click();
        URL.revokeObjectURL(url);
      });
  };

  return (
    <div>
      <div className="mb-4 flex items-start justify-between">
        <div>
          <h1 className="text-[18px] font-semibold text-navy-800 flex items-center gap-2">
            <Scale className="w-5 h-5" /> Conciliaciones con diferencia
          </h1>
          <p className="text-[12px] text-ink-2">Movimientos conciliados con línea de ajuste. Total diferencia: <strong>{fmtMoney(meta?.total_diferencia ?? 0)}</strong></p>
        </div>
        <Button variant="secondary" size="sm" onClick={exportar}><Download className="w-3 h-3" /> Exportar CSV</Button>
      </div>

      {meta && meta.por_motivo.length > 0 && (
        <div className="mb-4 flex flex-wrap gap-2 text-[11px]">
          {meta.por_motivo.map((d) => (
            <span key={d.motivo} className="px-2 py-1 bg-surface-hover rounded border border-line">
              {d.motivo} · {d.cantidad} · <strong>{fmtMoney(d.total)}</strong>
            </span>
          ))}
        </div>
      )}

      <Card>
        <CardHeader title="Listado" actions={
          <div className="flex gap-2 items-center text-[12px]">
            <input type="date" value={desde} onChange={(e) => setDesde(e.target.value)} className="px-2 py-1 border border-line-strong rounded bg-white" />
            <span className="text-ink-2">a</span>
            <input type="date" value={hasta} onChange={(e) => setHasta(e.target.value)} className="px-2 py-1 border border-line-strong rounded bg-white" />
          </div>
        } />
        <CardBody>
          {isLoading ? (
            <div className="text-[12px] text-ink-2 py-6 text-center"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Cargando…</div>
          ) : rows.length === 0 ? (
            <div className="text-[12px] text-ink-2 py-6 text-center">No hay conciliaciones con diferencia.</div>
          ) : (
            <table className="w-full text-[12px]">
              <thead>
                <tr className="text-left text-[11px] uppercase font-semibold text-ink-2 border-b border-line">
                  <th className="py-2">Fecha</th><th>Banco</th><th>Concepto</th>
                  <th className="text-right">Monto mov</th><th className="text-right">Diferencia</th>
                  <th>Cuenta ajuste</th><th>Motivo</th><th>Tipo</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={r.id} className={i % 2 ? 'bg-surface-row' : ''}>
                    <td className="py-2">{r.fecha.slice(0, 10)}</td>
                    <td>{r.banco ?? '—'}</td>
                    <td className="max-w-[200px] truncate" title={r.concepto ?? ''}>{r.concepto ?? '—'}</td>
                    <td className="text-right tabular">{fmtMoney(Number(r.monto_mov))}</td>
                    <td className="text-right tabular font-semibold text-danger">{fmtMoney(Number(r.diferencia))}</td>
                    <td>{r.cuenta_ajuste ?? '—'}</td>
                    <td>{r.motivo ?? '—'}</td>
                    <td className="text-[11px]">{r.tipo ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
