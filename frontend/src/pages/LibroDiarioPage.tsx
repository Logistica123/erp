import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Download, Loader2, Plus, Trash2, Eye, Pencil, Ban } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { useToast } from '@/hooks/useToast';

type Asiento = {
  id: number;
  numero: number;
  fecha: string;
  glosa: string | null;
  estado: 'BORRADOR' | 'CONTABILIZADO' | 'ANULADO';
  total_debe: string;
  total_haber: string;
  diario: { id: number; codigo: string; nombre: string };
  periodo: { id: number; anio: number; mes: number };
  hash_integridad: string | null;
};

type Resp = { data: Asiento[] };

function firstOfMonth(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}
function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function estadoBadge(estado: Asiento['estado']) {
  if (estado === 'CONTABILIZADO') return <Badge variant="success">{estado}</Badge>;
  if (estado === 'ANULADO') return <Badge variant="danger">{estado}</Badge>;
  return <Badge variant="warning">{estado}</Badge>;
}

export function LibroDiarioPage() {
  const [desde, setDesde] = useState(firstOfMonth());
  const [hasta, setHasta] = useState(today());
  const [estado, setEstado] = useState<'' | Asiento['estado']>('');
  const navigate = useNavigate();
  const qc = useQueryClient();
  const toast = useToast();

  const eliminar = useMutation<unknown, ApiError, number>({
    mutationFn: (id) => api.delete(`/api/erp/asientos/${id}`),
    onSuccess: () => {
      toast.success('Asiento BORRADOR eliminado');
      qc.invalidateQueries({ queryKey: ['asientos'] });
    },
    onError: (e) => toast.error('No se pudo eliminar', e.message),
  });

  const { data, isLoading } = useQuery<Resp>({
    queryKey: ['asientos', { desde, hasta, estado }],
    queryFn: () => {
      const qs = new URLSearchParams();
      if (desde) qs.set('desde', desde);
      if (hasta) qs.set('hasta', hasta);
      if (estado) qs.set('estado', estado);
      return api.get<Resp>(`/api/erp/asientos?${qs}`);
    },
  });

  const asientos = data?.data ?? [];
  const totalDebe = asientos.reduce((s, a) => s + Number(a.total_debe || 0), 0);
  const totalHaber = asientos.reduce((s, a) => s + Number(a.total_haber || 0), 0);

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Libro Diario</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            Período {desde} — {hasta}
            {data && ` · ${asientos.length} asientos`}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary">
            <Download className="w-3 h-3" /> Exportar Excel
          </Button>
          <Button variant="secondary">
            <Download className="w-3 h-3" /> Exportar PDF
          </Button>
          <Button variant="primary" onClick={() => navigate('/erp/asientos/nuevo')}>
            <Plus className="w-3 h-3" /> Nuevo asiento
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader
          title="Asientos del período"
          actions={
            <div className="flex gap-2 items-center">
              <input
                type="date"
                value={desde}
                onChange={(e) => setDesde(e.target.value)}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
              />
              <span className="text-ink-muted text-[11px]">→</span>
              <input
                type="date"
                value={hasta}
                onChange={(e) => setHasta(e.target.value)}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
              />
              <select
                value={estado}
                onChange={(e) => setEstado(e.target.value as typeof estado)}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
              >
                <option value="">Todos los estados</option>
                <option value="BORRADOR">Borrador</option>
                <option value="CONTABILIZADO">Contabilizado</option>
                <option value="ANULADO">Anulado</option>
              </select>
            </div>
          }
        />
        <CardBody>
          <table className="w-full border-collapse text-[12px]">
            <thead>
              <tr className="bg-surface-hover border-b border-line-strong">
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[90px]">
                  Fecha
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[80px]">
                  Diario
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[60px]">
                  N°
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                  Glosa
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">
                  Estado
                </th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[130px]">
                  Debe
                </th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[130px]">
                  Haber
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[80px]">
                  Hash
                </th>
                <th className="px-[6px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr>
                  <td colSpan={9} className="py-10 text-center text-ink-muted">
                    <Loader2 className="w-4 h-4 animate-spin inline mr-2" /> Cargando…
                  </td>
                </tr>
              )}
              {asientos.map((a, i) => (
                <tr
                  key={a.id}
                  className={`border-b border-line hover:bg-surface-hover ${i % 2 ? 'bg-surface-row' : ''} ${
                    a.estado === 'ANULADO' ? 'opacity-60 line-through' : ''
                  }`}
                >
                  <td className="px-[10px] py-[7px] tabular text-ink-2">{a.fecha.slice(0, 10)}</td>
                  <td className="px-[10px] py-[7px]">
                    <Badge variant="info">{a.diario.codigo}</Badge>
                  </td>
                  <td className="px-[10px] py-[7px] tabular text-ink-2">{a.numero}</td>
                  <td className="px-[10px] py-[7px] text-ink-2">{a.glosa ?? '—'}</td>
                  <td className="px-[10px] py-[7px]">{estadoBadge(a.estado)}</td>
                  <td className="px-[10px] py-[7px] text-right tabular text-success font-medium">
                    {fmtMoney(Number(a.total_debe))}
                  </td>
                  <td className="px-[10px] py-[7px] text-right tabular text-danger font-medium">
                    {fmtMoney(Number(a.total_haber))}
                  </td>
                  <td className="px-[10px] py-[7px] font-mono text-[10px] text-ink-muted" title={a.hash_integridad ?? ''}>
                    {a.hash_integridad ? `${a.hash_integridad.slice(0, 8)}…` : '—'}
                  </td>
                  {/* v1.15 Sprint M+: acciones por fila según estado del asiento. */}
                  <td className="px-[6px] py-[6px] text-right">
                    <div className="flex justify-end gap-1">
                      <button
                        onClick={() => navigate(`/erp/asientos/${a.id}`)}
                        className="p-1 opacity-50 hover:opacity-100 hover:text-azure cursor-pointer"
                        title="Ver detalle">
                        <Eye className="w-3 h-3" />
                      </button>
                      {a.estado === 'BORRADOR' && (
                        <>
                          <button
                            onClick={() => navigate(`/erp/asientos/nuevo?edit=${a.id}`)}
                            className="p-1 opacity-50 hover:opacity-100 hover:text-azure cursor-pointer"
                            title="Editar BORRADOR">
                            <Pencil className="w-3 h-3" />
                          </button>
                          <button
                            onClick={() => {
                              if (confirm(`Eliminar asiento BORRADOR #${a.numero}? Esta acción no se puede deshacer.`)) {
                                eliminar.mutate(a.id);
                              }
                            }}
                            className="p-1 opacity-50 hover:opacity-100 hover:text-danger cursor-pointer"
                            title="Eliminar BORRADOR"
                            disabled={eliminar.isPending}>
                            <Trash2 className="w-3 h-3" />
                          </button>
                        </>
                      )}
                      {a.estado === 'CONTABILIZADO' && (
                        <button
                          onClick={() => navigate(`/erp/asientos/${a.id}`)}
                          className="p-1 opacity-50 hover:opacity-100 hover:text-warning cursor-pointer"
                          title="Anular (en detalle)">
                          <Ban className="w-3 h-3" />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
              {data && asientos.length === 0 && (
                <tr>
                  <td colSpan={9} className="py-10 text-center text-ink-muted">
                    Sin asientos en el período.
                  </td>
                </tr>
              )}
            </tbody>
            {data && asientos.length > 0 && (
              <tfoot>
                <tr className="bg-surface-hover font-semibold">
                  <td colSpan={5} className="px-[10px] py-[7px] text-navy-800">
                    Totales del período
                  </td>
                  <td className="px-[10px] py-[7px] text-right tabular text-success">{fmtMoney(totalDebe)}</td>
                  <td className="px-[10px] py-[7px] text-right tabular text-danger">{fmtMoney(totalHaber)}</td>
                  <td />
                </tr>
              </tfoot>
            )}
          </table>
        </CardBody>
      </Card>
    </>
  );
}
