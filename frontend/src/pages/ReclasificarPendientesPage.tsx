import { useState } from 'react';
import { Inbox } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { fmtMoney, fmtDate } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Pendiente = {
  linea_id: number; asiento_id: number; numero: number; fecha: string;
  glosa: string | null; origen: string; monto: number | string; linea_glosa: string | null;
};
type Cuenta = { id: number; codigo: string; nombre: string };

export function ReclasificarPendientesPage() {
  const [reclasificar, setReclasificar] = useState<Pendiente | null>(null);
  const { data: saldo } = useApi<{ cuenta: string; saldo: number }>(['pend-saldo'], '/api/erp/contabilidad/pendientes-identificar/saldo');
  const { data, isLoading, error } = useApi<Pendiente[]>(['pend-list'], '/api/erp/contabilidad/pendientes-identificar');
  const rows = data ?? [];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={<div className="flex items-center gap-2"><Inbox className="w-4 h-4 text-azure" /> Reclasificar pendientes (cuenta 1.1.6.99)</div>} />
        <CardBody className="p-4 space-y-3">
          <div className="flex items-center justify-between">
            <div className="text-[12px] text-ink-muted max-w-2xl">
              Movimientos imputados a la cuenta puente <code>1.1.6.99 Pendientes de Identificar</code>.
              Reclasificá cada uno a su cuenta contable real (genera asiento D: cuenta destino / H: 1.1.6.99).
            </div>
            {saldo && (
              <div className="text-right">
                <div className="text-[11px] text-ink-3 uppercase">Saldo pendiente</div>
                <div className="text-[16px] font-semibold tabular-nums">{fmtMoney(saldo.saldo)}</div>
              </div>
            )}
          </div>
          {error && <FormError error={errorMessage(error)} />}
          {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
          <div className="border border-line rounded-md overflow-hidden">
            <table className="w-full text-[12.5px]">
              <thead className="bg-surface-row"><tr className="text-left">
                <th className="px-2 py-1.5">Asiento</th><th className="px-2 py-1.5">Fecha</th>
                <th className="px-2 py-1.5">Concepto</th><th className="px-2 py-1.5">Origen</th>
                <th className="px-2 py-1.5 text-right">Monto</th><th className="px-2 py-1.5"></th>
              </tr></thead>
              <tbody>
                {rows.map((p) => (
                  <tr key={p.linea_id} className="border-t border-line hover:bg-surface-row">
                    <td className="px-2 py-1">#{p.numero}</td>
                    <td className="px-2 py-1">{fmtDate(p.fecha)}</td>
                    <td className="px-2 py-1 max-w-[320px] truncate" title={p.linea_glosa ?? p.glosa ?? ''}>{p.linea_glosa ?? p.glosa ?? '—'}</td>
                    <td className="px-2 py-1 text-ink-3">{p.origen}</td>
                    <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(p.monto)}</td>
                    <td className="px-2 py-1 text-right"><Button variant="primary" onClick={() => setReclasificar(p)}>Reclasificar</Button></td>
                  </tr>
                ))}
                {!isLoading && rows.length === 0 && <tr><td colSpan={6} className="px-2 py-6 text-center text-ink-3">Sin pendientes — 1.1.6.99 está saneada.</td></tr>}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>
      {reclasificar && <ReclasificarModal item={reclasificar} onClose={() => setReclasificar(null)} />}
    </div>
  );
}

function ReclasificarModal({ item, onClose }: { item: Pendiente; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['pend-list'], ['pend-saldo']);
  const [cuentaDestino, setCuentaDestino] = useState('');
  const [motivo, setMotivo] = useState('');
  const { data: cuentas } = useApi<Cuenta[]>(['cuentas-imputables'], '/api/erp/cuentas?imputable=1');

  const m = useApiMutation<{ asiento_id: number }, Record<string, unknown>>(
    (v) => api.post('/api/erp/contabilidad/pendientes-identificar/reclasificar', v),
    { onSuccess: () => { toast.success("Reclasificado", "Asiento generado."); invalidate(); onClose(); }, onError: (e) => toast.error('No se pudo reclasificar', errorMessage(e)) },
  );

  const montoAlto = Number(item.monto) > 50000;
  const valid = cuentaDestino && (!montoAlto || motivo.trim().length >= 3);

  return (
    <Modal open onClose={onClose} title={`Reclasificar $${fmtMoney(item.monto)}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!valid || m.isPending}
          onClick={() => m.mutate({ linea_id: item.linea_id, cuenta_destino_id: Number(cuentaDestino), motivo: motivo || undefined })}>
          {m.isPending ? 'Generando…' : 'Reclasificar'}</Button>
      </>}>
      <div className="space-y-3 text-[12.5px]">
        <div className="bg-surface-row border border-line rounded p-2">
          <div>Asiento origen #{item.numero} · {fmtDate(item.fecha)}</div>
          <div className="text-ink-3">{item.linea_glosa ?? item.glosa}</div>
        </div>
        <SelectField label="Cuenta destino *" value={cuentaDestino} onChange={(e) => setCuentaDestino(e.target.value)}
          placeholder="Elegí cuenta…"
          options={(cuentas ?? []).map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))} />
        <TextareaField label={`Motivo${montoAlto ? ' * (monto > $50.000)' : ''}`} value={motivo} rows={2}
          onChange={(e) => setMotivo(e.target.value)} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
