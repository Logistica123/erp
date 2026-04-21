import { useState } from 'react';
import { Download, Eye, Loader2, Plus, Trash2, Undo2 } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

type EstadoAsiento = 'BORRADOR' | 'CONTABILIZADO' | 'ANULADO';

type Asiento = {
  id: number;
  numero: number;
  fecha: string;
  glosa: string | null;
  estado: EstadoAsiento;
  total_debe: string;
  total_haber: string;
  hash_integridad: string | null;
  diario: { id: number; codigo: string; nombre: string };
  periodo: { id: number; anio: number; mes: number };
};

type Movimiento = {
  linea: number;
  debe: string;
  haber: string;
  glosa: string | null;
  cuenta: { id: number; codigo: string; nombre: string };
  centro_costo: { id: number; codigo: string; nombre: string } | null;
  auxiliar: { id: number; codigo: string; nombre: string; cuit: string | null } | null;
};

type AsientoDetalle = Asiento & {
  movimientos: Movimiento[];
  asiento_reversa_id: number | null;
  motivo_anulacion: string | null;
};

function firstOfMonth(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}
function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function estadoBadge(estado: EstadoAsiento) {
  if (estado === 'CONTABILIZADO') return <Badge variant="success">{estado}</Badge>;
  if (estado === 'ANULADO') return <Badge variant="danger">{estado}</Badge>;
  return <Badge variant="warning">{estado}</Badge>;
}

export function AsientosPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [desde, setDesde] = useState(firstOfMonth());
  const [hasta, setHasta] = useState(today());
  const [estado, setEstado] = useState<'' | EstadoAsiento>('');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [anularOpen, setAnularOpen] = useState(false);
  const [motivoAnular, setMotivoAnular] = useState('');
  const [err, setErr] = useState<string | null>(null);

  const { data, isLoading } = useQuery<{ data: Asiento[] }>({
    queryKey: ['asientos', { desde, hasta, estado }],
    queryFn: () => {
      const qs = new URLSearchParams();
      if (desde) qs.set('desde', desde);
      if (hasta) qs.set('hasta', hasta);
      if (estado) qs.set('estado', estado);
      return api.get(`/api/erp/asientos?${qs}`);
    },
  });

  const { data: detalle } = useQuery<{ data: AsientoDetalle }>({
    queryKey: ['asiento', selectedId],
    queryFn: () => api.get(`/api/erp/asientos/${selectedId}`),
    enabled: !!selectedId,
  });

  const eliminar = useMutation({
    mutationFn: (id: number) => api.delete(`/api/erp/asientos/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['asientos'] });
      qc.invalidateQueries({ queryKey: ['health'] });
      setSelectedId(null);
    },
    onError: (e) => setErr(e instanceof ApiError ? e.message : 'Error'),
  });

  const anular = useMutation({
    mutationFn: ({ id, motivo }: { id: number; motivo: string }) =>
      api.post(`/api/erp/asientos/${id}/anular`, { motivo }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['asientos'] });
      qc.invalidateQueries({ queryKey: ['asiento', selectedId] });
      qc.invalidateQueries({ queryKey: ['health'] });
      setAnularOpen(false);
      setMotivoAnular('');
    },
    onError: (e) => setErr(e instanceof ApiError ? e.message : 'Error'),
  });

  const asientos = data?.data ?? [];
  const totalDebe = asientos.reduce((s, a) => s + Number(a.total_debe || 0), 0);
  const totalHaber = asientos.reduce((s, a) => s + Number(a.total_haber || 0), 0);

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Asientos contables</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            {data ? `${asientos.length} asientos del período ${desde} — ${hasta}` : 'Cargando…'}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary">
            <Download className="w-3 h-3" /> Exportar
          </Button>
          <Button variant="primary" onClick={() => navigate('/erp/asientos/nuevo')}>
            <Plus className="w-3 h-3" /> Nuevo asiento
          </Button>
        </div>
      </div>

      {err && (
        <div className="mb-4 p-3 bg-danger-bg text-danger border border-danger/30 rounded-md text-[12px]">
          {err}
        </div>
      )}

      <Card>
        <CardHeader
          title="Listado"
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
                <option value="">Todos</option>
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
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[90px]">Fecha</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[80px]">Diario</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[60px]">N°</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">Glosa</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Estado</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Debe</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Haber</th>
                <th className="w-[60px]" />
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr>
                  <td colSpan={8} className="py-10 text-center text-ink-muted">
                    <Loader2 className="w-4 h-4 animate-spin inline mr-2" /> Cargando…
                  </td>
                </tr>
              )}
              {asientos.map((a, i) => (
                <tr
                  key={a.id}
                  className={`border-b border-line hover:bg-surface-hover cursor-pointer ${i % 2 ? 'bg-surface-row' : ''} ${
                    a.estado === 'ANULADO' ? 'opacity-60' : ''
                  }`}
                  onClick={() => setSelectedId(a.id)}
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
                  <td className="px-[10px] py-[7px] text-right">
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        setSelectedId(a.id);
                      }}
                      className="text-ink-muted hover:text-navy-700 p-1"
                      aria-label="Ver"
                    >
                      <Eye className="w-3.5 h-3.5" />
                    </button>
                  </td>
                </tr>
              ))}
              {data && asientos.length === 0 && (
                <tr>
                  <td colSpan={8} className="py-10 text-center text-ink-muted">
                    Sin asientos en el período.
                  </td>
                </tr>
              )}
            </tbody>
            {data && asientos.length > 0 && (
              <tfoot>
                <tr className="bg-surface-hover font-semibold">
                  <td colSpan={5} className="px-[10px] py-[7px] text-navy-800">
                    Totales
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

      {/* ============ MODAL DETALLE ============ */}
      <Modal
        open={!!selectedId}
        onClose={() => {
          setSelectedId(null);
          setErr(null);
        }}
        title={
          detalle?.data
            ? `Asiento ${detalle.data.diario.codigo} N° ${detalle.data.numero}`
            : 'Asiento'
        }
        size="lg"
        footer={
          detalle?.data && (
            <>
              <Button variant="secondary" onClick={() => setSelectedId(null)}>
                Cerrar
              </Button>
              {detalle.data.estado === 'BORRADOR' && (
                <Button
                  variant="danger"
                  onClick={() => {
                    if (confirm('¿Eliminar este borrador? Esta acción no se puede deshacer.')) {
                      eliminar.mutate(detalle.data.id);
                    }
                  }}
                  disabled={eliminar.isPending}
                >
                  <Trash2 className="w-3 h-3" /> Eliminar borrador
                </Button>
              )}
              {detalle.data.estado === 'CONTABILIZADO' && (
                <Button variant="danger" onClick={() => setAnularOpen(true)}>
                  <Undo2 className="w-3 h-3" /> Anular (genera reversa)
                </Button>
              )}
            </>
          )
        }
      >
        {!detalle && (
          <div className="py-8 text-center text-ink-muted">
            <Loader2 className="w-4 h-4 animate-spin inline mr-2" /> Cargando detalle…
          </div>
        )}
        {detalle?.data && (
          <div className="space-y-4">
            <div className="grid grid-cols-4 gap-3 text-[12px]">
              <div>
                <div className="text-[10px] uppercase tracking-wider text-ink-muted font-semibold">Fecha</div>
                <div className="font-medium text-navy-800">{detalle.data.fecha.slice(0, 10)}</div>
              </div>
              <div>
                <div className="text-[10px] uppercase tracking-wider text-ink-muted font-semibold">Estado</div>
                <div>{estadoBadge(detalle.data.estado)}</div>
              </div>
              <div>
                <div className="text-[10px] uppercase tracking-wider text-ink-muted font-semibold">Período</div>
                <div className="font-medium text-navy-800">
                  {String(detalle.data.periodo.mes).padStart(2, '0')}/{detalle.data.periodo.anio}
                </div>
              </div>
              <div>
                <div className="text-[10px] uppercase tracking-wider text-ink-muted font-semibold">Hash integridad</div>
                <div className="font-mono text-[10px] text-ink-muted truncate" title={detalle.data.hash_integridad ?? ''}>
                  {detalle.data.hash_integridad ? `${detalle.data.hash_integridad.slice(0, 24)}…` : '—'}
                </div>
              </div>
            </div>

            {detalle.data.glosa && (
              <div className="p-3 bg-surface-row rounded-md text-[12px] text-ink-2">
                <div className="text-[10px] uppercase tracking-wider text-ink-muted font-semibold mb-1">Glosa</div>
                {detalle.data.glosa}
              </div>
            )}

            {detalle.data.motivo_anulacion && (
              <div className="p-3 bg-danger-bg rounded-md text-[12px] text-danger">
                <div className="text-[10px] uppercase tracking-wider font-semibold mb-1">Motivo de anulación</div>
                {detalle.data.motivo_anulacion}
                {detalle.data.asiento_reversa_id && (
                  <div className="mt-1 text-[11px] opacity-80">
                    Asiento reversa: #{detalle.data.asiento_reversa_id}
                  </div>
                )}
              </div>
            )}

            <div>
              <div className="text-[10px] uppercase tracking-wider text-ink-muted font-semibold mb-2">Movimientos</div>
              <table className="w-full border-collapse text-[12px]">
                <thead>
                  <tr className="bg-surface-hover border-b border-line-strong text-[10px] uppercase tracking-wider text-navy-800 font-semibold">
                    <th className="px-[10px] py-[6px] text-center w-[30px]">#</th>
                    <th className="px-[10px] py-[6px] text-left">Cuenta</th>
                    <th className="px-[10px] py-[6px] text-left">CC</th>
                    <th className="px-[10px] py-[6px] text-left">Auxiliar</th>
                    <th className="px-[10px] py-[6px] text-right w-[110px]">Debe</th>
                    <th className="px-[10px] py-[6px] text-right w-[110px]">Haber</th>
                  </tr>
                </thead>
                <tbody>
                  {detalle.data.movimientos.map((m) => (
                    <tr key={m.linea} className="border-b border-line">
                      <td className="text-center font-mono text-ink-muted">{m.linea}</td>
                      <td className="px-[10px] py-[6px]">
                        <div className="font-mono text-[11px] text-navy-700">{m.cuenta.codigo}</div>
                        <div className="text-ink-2">{m.cuenta.nombre}</div>
                      </td>
                      <td className="px-[10px] py-[6px] text-ink-muted font-mono text-[11px]">
                        {m.centro_costo?.codigo ?? '—'}
                      </td>
                      <td className="px-[10px] py-[6px] text-ink-muted">{m.auxiliar?.nombre ?? '—'}</td>
                      <td className={`px-[10px] py-[6px] text-right tabular ${Number(m.debe) ? 'text-success font-medium' : 'text-ink-muted'}`}>
                        {Number(m.debe) ? fmtMoney(Number(m.debe)) : '—'}
                      </td>
                      <td className={`px-[10px] py-[6px] text-right tabular ${Number(m.haber) ? 'text-danger font-medium' : 'text-ink-muted'}`}>
                        {Number(m.haber) ? fmtMoney(Number(m.haber)) : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="bg-surface-hover font-semibold">
                    <td colSpan={4} className="px-[10px] py-[6px] text-navy-800">
                      Totales
                    </td>
                    <td className="px-[10px] py-[6px] text-right tabular text-success">{fmtMoney(Number(detalle.data.total_debe))}</td>
                    <td className="px-[10px] py-[6px] text-right tabular text-danger">{fmtMoney(Number(detalle.data.total_haber))}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        )}
      </Modal>

      {/* ============ MODAL ANULAR ============ */}
      <Modal
        open={anularOpen}
        onClose={() => setAnularOpen(false)}
        title="Anular asiento"
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAnularOpen(false)}>
              Cancelar
            </Button>
            <Button
              variant="danger"
              disabled={motivoAnular.trim().length < 3 || anular.isPending}
              onClick={() => selectedId && anular.mutate({ id: selectedId, motivo: motivoAnular.trim() })}
            >
              {anular.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
              Confirmar anulación
            </Button>
          </>
        }
      >
        <p className="text-ink-2 text-[13px] mb-3">
          Se generará un <strong>asiento reversa</strong> automático con los débitos y créditos invertidos. El asiento
          original queda marcado como <span className="text-danger font-semibold">ANULADO</span> pero permanece visible
          en el libro diario (auditoría).
        </p>
        <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
          Motivo de la anulación
        </label>
        <textarea
          rows={3}
          className="w-full px-3 py-2 text-[13px] border border-line-strong rounded-md"
          placeholder="Ej: Error en el CUIT del proveedor; Imputación a cuenta equivocada…"
          value={motivoAnular}
          onChange={(e) => setMotivoAnular(e.target.value)}
        />
      </Modal>
    </>
  );
}
