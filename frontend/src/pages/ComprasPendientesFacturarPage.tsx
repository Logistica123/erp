import { useState } from 'react';
import { Download, FileWarning, Link2, Loader2, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { auth } from '@/lib/auth';

type Row = {
  id: number; fecha: string; distribuidor: string | null; cuit: string | null;
  distribuidor_pendiente_id: number | null; monto_pagado: string;
  diferencia_a_facturar: string; observaciones_pendiente: string | null;
  factura_origen: string | null; dias_pendiente: number;
};
type Meta = { total_pendiente: number; por_distribuidor: Array<{ distribuidor: string; cantidad: number; total: number }> };

export default function ComprasPendientesFacturarPage() {
  const qc = useQueryClient();
  const [err, setErr] = useState('');
  const [ncMov, setNcMov] = useState<Row | null>(null);

  const { data, isLoading } = useQuery<{ data: Row[]; meta: Meta }>({
    queryKey: ['pendientes-facturar'],
    queryFn: () => api.get('/api/erp/compras/pendientes-facturar'),
  });

  const anular = useMutation({
    mutationFn: (v: { movId: number; motivo: string }) =>
      api.patch(`/api/erp/compras/pendientes-facturar/${v.movId}/anular`, { motivo: v.motivo }),
    onSuccess: () => { setErr(''); qc.invalidateQueries({ queryKey: ['pendientes-facturar'] }); },
    onError: (e: ApiError) => setErr(e.message),
  });

  const rows = data?.data ?? [];
  const meta = data?.meta;

  const exportar = () => {
    const token = auth.getToken();
    fetch('/api/erp/compras/pendientes-facturar/export', { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((b) => {
        const url = URL.createObjectURL(b);
        const a = document.createElement('a'); a.href = url; a.download = 'pendientes_facturar.csv'; a.click();
        URL.revokeObjectURL(url);
      });
  };

  return (
    <div>
      <div className="mb-4 flex items-start justify-between">
        <div>
          <h1 className="text-[18px] font-semibold text-navy-800 flex items-center gap-2">
            <FileWarning className="w-5 h-5" /> Pendientes de facturar
          </h1>
          <p className="text-[12px] text-ink-2">Pagos a distribuidores con diferencia que espera NC complementaria. Total pendiente: <strong>{fmtMoney(meta?.total_pendiente ?? 0)}</strong></p>
        </div>
        <Button variant="secondary" size="sm" onClick={exportar}><Download className="w-3 h-3" /> Exportar CSV</Button>
      </div>

      {err && <div className="mb-4 p-3 bg-danger-bg text-danger rounded-md text-[12px]">{err}</div>}

      {meta && meta.por_distribuidor.length > 0 && (
        <div className="mb-4 flex flex-wrap gap-2 text-[11px]">
          {meta.por_distribuidor.map((d) => (
            <span key={d.distribuidor} className="px-2 py-1 bg-surface-hover rounded border border-line">
              {d.distribuidor} · {d.cantidad} · <strong>{fmtMoney(d.total)}</strong>
            </span>
          ))}
        </div>
      )}

      <Card>
        <CardHeader title={`Pendientes (${rows.length})`} />
        <CardBody>
          {isLoading ? (
            <div className="text-[12px] text-ink-2 py-6 text-center"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Cargando…</div>
          ) : rows.length === 0 ? (
            <div className="text-[12px] text-ink-2 py-6 text-center">No hay pendientes de facturar. 🎉</div>
          ) : (
            <table className="w-full text-[12px]">
              <thead>
                <tr className="text-left text-[11px] uppercase font-semibold text-ink-2 border-b border-line">
                  <th className="py-2">Fecha pago</th><th>Distribuidor</th><th>CUIT</th>
                  <th className="text-right">Monto pagado</th><th className="text-right">Dif. a facturar</th>
                  <th>Origen</th><th className="text-right">Días</th><th></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={r.id} className={i % 2 ? 'bg-surface-row' : ''}>
                    <td className="py-2">{r.fecha.slice(0, 10)}</td>
                    <td>{r.distribuidor ?? '—'}</td>
                    <td className="tabular">{r.cuit ?? '—'}</td>
                    <td className="text-right tabular">{fmtMoney(Number(r.monto_pagado))}</td>
                    <td className="text-right tabular font-semibold text-danger">{fmtMoney(Number(r.diferencia_a_facturar))}</td>
                    <td className="max-w-[180px] truncate" title={r.factura_origen ?? ''}>{r.factura_origen ?? '—'}</td>
                    <td className="text-right">{r.dias_pendiente}</td>
                    <td className="text-right whitespace-nowrap">
                      <Button variant="primary" size="sm" className="mr-1" onClick={() => setNcMov(r)}>
                        <Link2 className="w-3 h-3" /> Asociar NC
                      </Button>
                      <Button variant="outline" size="sm"
                        onClick={() => { const m = prompt('Motivo de anulación (mín 5 chars):'); if (m && m.length >= 5) anular.mutate({ movId: r.id, motivo: m }); }}>
                        <Trash2 className="w-3 h-3" /> Anular
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardBody>
      </Card>

      {ncMov && <AsociarNcModal mov={ncMov} onClose={() => setNcMov(null)}
        onDone={() => { setNcMov(null); qc.invalidateQueries({ queryKey: ['pendientes-facturar'] }); }}
        onError={setErr} />}
    </div>
  );
}

function AsociarNcModal({ mov, onClose, onDone, onError }: {
  mov: Row; onClose: () => void; onDone: () => void; onError: (m: string) => void;
}) {
  const [ncId, setNcId] = useState('');
  const asociar = useMutation({
    mutationFn: () => api.patch(`/api/erp/compras/pendientes-facturar/${mov.id}/asociar-nc`, { nc_factura_compra_id: Number(ncId) }),
    onSuccess: onDone,
    onError: (e: ApiError) => onError(e.message),
  });
  return (
    <Modal open onClose={onClose} title={`Asociar NC complementaria · mov #${mov.id}`} size="md">
      <div className="space-y-3 text-[12px]">
        <div className="bg-surface-hover rounded p-2">
          <div>Distribuidor: <strong>{mov.distribuidor ?? '—'}</strong></div>
          <div>Diferencia a facturar: <strong className="text-danger">{fmtMoney(Number(mov.diferencia_a_facturar))}</strong></div>
        </div>
        <p className="text-ink-2">Ingresá el ID de la NC de compra (ya cargada vía Libro IVA Compras o carga manual). Se valida que el monto coincida (tol $1) y el auxiliar sea el mismo.</p>
        <input type="number" placeholder="ID de NC de compra" value={ncId} onChange={(e) => setNcId(e.target.value)}
          className="w-full px-2 py-1 border border-line rounded" />
        <div className="flex justify-end gap-2 pt-2 border-t border-line">
          <Button variant="secondary" onClick={onClose} disabled={asociar.isPending}>Cancelar</Button>
          <Button variant="primary" disabled={!ncId || asociar.isPending} onClick={() => asociar.mutate()}>
            {asociar.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Link2 className="w-3 h-3" />} Asociar y cancelar anticipo
          </Button>
        </div>
      </div>
    </Modal>
  );
}
