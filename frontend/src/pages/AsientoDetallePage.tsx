import { useParams, useNavigate, Link } from 'react-router-dom';
import { ArrowLeft, Loader2, FileText, Hash, History, Ban } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { useToast } from '@/hooks/useToast';
import { useState } from 'react';
import { Modal } from '@/components/ui/Modal';

/**
 * ADDENDUM v1.15 Sprint M+ — Pantalla de detalle de asiento (read-only).
 * Muestra cabecera + movimientos + glosa por línea + observaciones + hash + auditoría.
 * Permite "Anular" si está CONTABILIZADO.
 */

type Movimiento = {
  id: number;
  linea: number;
  cuenta: { id: number; codigo: string; nombre: string };
  centro_costo: { id: number; codigo: string; nombre: string } | null;
  auxiliar: { id: number; codigo: string | null; nombre: string } | null;
  glosa: string | null;
  debe: string | number;
  haber: string | number;
};

type Asiento = {
  id: number;
  numero: number;
  fecha: string;
  fecha_contabilizacion: string | null;
  glosa: string | null;
  observaciones: string | null;
  estado: 'BORRADOR' | 'CONTABILIZADO' | 'ANULADO';
  total_debe: string;
  total_haber: string;
  hash_integridad: string | null;
  motivo_anulacion: string | null;
  fecha_anulacion: string | null;
  diario: { id: number; codigo: string; nombre: string };
  periodo: { id: number; anio: number; mes: number; estado: string };
  movimientos: Movimiento[];
};

function estadoBadge(estado: Asiento['estado']) {
  if (estado === 'CONTABILIZADO') return <Badge variant="success">{estado}</Badge>;
  if (estado === 'ANULADO') return <Badge variant="danger">{estado}</Badge>;
  return <Badge variant="warning">{estado}</Badge>;
}

export function AsientoDetallePage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const toast = useToast();
  const [anularOpen, setAnularOpen] = useState(false);

  const { data, isLoading, error } = useQuery<{ data: Asiento }>({
    queryKey: ['asiento', id],
    queryFn: () => api.get(`/api/erp/asientos/${id}`),
    enabled: !!id,
  });

  const asiento = data?.data;

  if (isLoading) {
    return <div className="p-10 text-center text-ink-muted">
      <Loader2 className="w-5 h-5 animate-spin inline mr-2" /> Cargando asiento…
    </div>;
  }
  if (error || !asiento) {
    return <div className="p-10 text-center text-danger">
      No se pudo cargar el asiento #{id}.
    </div>;
  }

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2 text-[13px] text-ink-muted">
          <Link to="/erp/libro-diario" className="hover:text-ink-2">Libro Diario</Link>
          <span>›</span>
          <span className="text-ink-2 font-medium">Asiento #{asiento.numero}</span>
        </div>
        <div className="flex gap-2">
          <Button variant="ghost" size="sm" onClick={() => navigate('/erp/libro-diario')}>
            <ArrowLeft className="w-3 h-3" /> Volver
          </Button>
          {asiento.estado === 'CONTABILIZADO' && (
            <Button variant="outline" size="sm" onClick={() => setAnularOpen(true)}>
              <Ban className="w-3 h-3" /> Anular
            </Button>
          )}
        </div>
      </div>

      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2">
            <FileText className="w-4 h-4 text-azure" /> Asiento #{asiento.numero} — {asiento.diario.codigo}
            <span className="ml-2">{estadoBadge(asiento.estado)}</span>
          </div>
        } />
        <CardBody className="p-4 space-y-4">
          <div className="grid grid-cols-4 gap-3 text-[12px]">
            <Info label="Fecha" value={asiento.fecha.slice(0, 10)} />
            <Info label="Diario" value={`${asiento.diario.codigo} — ${asiento.diario.nombre}`} />
            <Info label="Período" value={`${String(asiento.periodo.mes).padStart(2, '0')}/${asiento.periodo.anio} (${asiento.periodo.estado})`} />
            <Info label="Contabilizado" value={asiento.fecha_contabilizacion ? asiento.fecha_contabilizacion.slice(0, 16).replace('T', ' ') : '—'} />
          </div>

          {asiento.glosa && (
            <div>
              <div className="text-[10.5px] uppercase tracking-wider text-ink-muted">Glosa (concepto)</div>
              <div className="text-[13px] text-navy-800">{asiento.glosa}</div>
            </div>
          )}
          {asiento.observaciones && (
            <div>
              <div className="text-[10.5px] uppercase tracking-wider text-ink-muted">Observaciones</div>
              <div className="text-[12.5px] whitespace-pre-wrap text-ink-2 bg-surface-row border border-line rounded-md p-2">
                {asiento.observaciones}
              </div>
            </div>
          )}

          {asiento.estado === 'ANULADO' && asiento.motivo_anulacion && (
            <div className="border border-danger/30 bg-danger-bg/30 rounded-md p-3 text-[12px]">
              <strong className="text-danger">Anulado</strong> el {asiento.fecha_anulacion?.slice(0, 16).replace('T', ' ')} ·{' '}
              motivo: <em>{asiento.motivo_anulacion}</em>
            </div>
          )}

          {/* Movimientos */}
          <div className="overflow-x-auto border border-line rounded-md">
            <table className="w-full text-[12px]">
              <thead className="bg-surface-row text-[11px] uppercase tracking-wider text-ink-muted">
                <tr>
                  <th className="px-2 py-2 text-center w-[30px]">#</th>
                  <th className="px-2 py-2 text-left">Cuenta</th>
                  <th className="px-2 py-2 text-left">CC</th>
                  <th className="px-2 py-2 text-left">Auxiliar</th>
                  <th className="px-2 py-2 text-left">Glosa línea</th>
                  <th className="px-2 py-2 text-right w-[130px]">Debe</th>
                  <th className="px-2 py-2 text-right w-[130px]">Haber</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line/60">
                {asiento.movimientos.map((m) => (
                  <tr key={m.id}>
                    <td className="px-2 py-1.5 text-center text-[11px] text-ink-muted">{m.linea}</td>
                    <td className="px-2 py-1.5">
                      <code className="text-[11px] text-azure">{m.cuenta.codigo}</code>
                      <span className="ml-2">{m.cuenta.nombre}</span>
                    </td>
                    <td className="px-2 py-1.5 text-[11.5px]">
                      {m.centro_costo ? <code>{m.centro_costo.codigo}</code> : <span className="text-ink-muted">—</span>}
                    </td>
                    <td className="px-2 py-1.5 text-[11.5px]">
                      {m.auxiliar ? m.auxiliar.nombre : <span className="text-ink-muted">—</span>}
                    </td>
                    <td className="px-2 py-1.5 text-[11.5px] text-ink-2">{m.glosa ?? '—'}</td>
                    <td className="px-2 py-1.5 text-right tabular text-success font-medium">
                      {Number(m.debe) > 0 ? fmtMoney(Number(m.debe)) : ''}
                    </td>
                    <td className="px-2 py-1.5 text-right tabular text-danger font-medium">
                      {Number(m.haber) > 0 ? fmtMoney(Number(m.haber)) : ''}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="bg-surface-row text-[11.5px] font-semibold">
                <tr className="border-t border-line">
                  <td colSpan={5} className="px-2 py-2 text-right">Totales</td>
                  <td className="px-2 py-2 text-right text-success tabular">{fmtMoney(Number(asiento.total_debe))}</td>
                  <td className="px-2 py-2 text-right text-danger tabular">{fmtMoney(Number(asiento.total_haber))}</td>
                </tr>
              </tfoot>
            </table>
          </div>

          {/* Auditoría */}
          {asiento.hash_integridad && (
            <div className="border border-line bg-surface-row rounded-md p-3 text-[11px] space-y-1">
              <div className="flex items-center gap-1 text-[11.5px] font-semibold text-navy-800">
                <Hash className="w-3 h-3" /> Hash de integridad (RN-12)
              </div>
              <code className="break-all font-mono text-ink-muted">{asiento.hash_integridad}</code>
            </div>
          )}
        </CardBody>
      </Card>

      {anularOpen && asiento.estado === 'CONTABILIZADO' && (
        <AnularModal asiento={asiento} onClose={() => setAnularOpen(false)}
          onDone={() => { qc.invalidateQueries({ queryKey: ['asiento', id] }); qc.invalidateQueries({ queryKey: ['asientos'] }); setAnularOpen(false); toast.success('Asiento anulado'); }} />
      )}
    </div>
  );
}

function Info({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="text-[10.5px] uppercase tracking-wider text-ink-muted">{label}</div>
      <div className="text-[12.5px] text-ink-2">{value}</div>
    </div>
  );
}

function AnularModal({ asiento, onClose, onDone }: { asiento: Asiento; onClose: () => void; onDone: () => void }) {
  const [motivo, setMotivo] = useState('');
  const toast = useToast();
  const m = useMutation<unknown, ApiError, void>({
    mutationFn: () => api.post(`/api/erp/asientos/${asiento.id}/anular`, { motivo }),
    onSuccess: onDone,
    onError: (e) => toast.error('No se pudo anular', e.message),
  });

  return (
    <Modal open onClose={onClose} title={`Anular asiento #${asiento.numero}`} size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={motivo.length < 3 || m.isPending}
            onClick={() => m.mutate(undefined as unknown as void)}>
            <History className="w-3 h-3" /> {m.isPending ? 'Anulando…' : 'Anular asiento'}
          </Button>
        </>
      }>
      <div className="space-y-2 text-[12.5px]">
        <p>Se generará un asiento reversa con hash chain. El movimiento original queda visible en el Libro Diario marcado como ANULADO.</p>
        <label className="block text-[11.5px] font-semibold text-ink-2">Motivo (≥3 caracteres)</label>
        <textarea
          rows={3}
          value={motivo}
          onChange={(e) => setMotivo(e.target.value)}
          className="w-full px-2 py-1 text-[12px] border border-line-strong rounded-md bg-white"
          placeholder="Ej: Error en cuenta destino"
        />
      </div>
    </Modal>
  );
}
