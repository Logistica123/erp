import { useMemo, useState } from 'react';
import { Layers, Plus, CheckCircle2, Undo2, Trash2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { fmtMoney, fmtDate, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Lote = {
  id: number; codigo: string; auxiliar_nombre: string | null; cuenta_nombre: string | null;
  fecha: string; monto_total: number | string; signo: '+' | '-';
  estado: 'BORRADOR' | 'CONFIRMADO' | 'REVERTIDO';
};
type Auxiliar = { id: number; codigo: string; nombre: string };
type CuentaBanc = { id: number; codigo: string; nombre: string };
type MovCand = { id: number; fecha: string; concepto: string; debito: number | string; credito: number | string; estado: string; cuenta_nombre: string | null };
type FacCand = { id: number; numero: string; fecha: string; total: number; saldo: number };

const EST_VARIANT: Record<string, 'success' | 'warning' | 'danger' | 'neutral'> = {
  BORRADOR: 'warning', CONFIRMADO: 'success', REVERTIDO: 'danger',
};

export function ConciliacionLotesPage() {
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [revertir, setRevertir] = useState<Lote | null>(null);
  const toast = useToast();
  const invalidate = useInvalidate(['conc-lotes']);

  const { data, isLoading, error } = useApi<Paginator<Lote>>(['conc-lotes'], '/api/erp/conciliacion/lotes');
  const rows = data?.data ?? [];

  const confirmar = useApiMutation<unknown, number>(
    (id) => api.post(`/api/erp/conciliacion/lotes/${id}/confirmar`),
    { onSuccess: () => { toast.success('Lote confirmado', 'Asiento consolidado generado.'); invalidate(); }, onError: (e) => toast.error('No se pudo confirmar', errorMessage(e)) },
  );
  const borrar = useApiMutation<unknown, number>(
    (id) => api.delete(`/api/erp/conciliacion/lotes/${id}`),
    { onSuccess: () => { toast.success('Lote borrado'); invalidate(); }, onError: (e) => toast.error('No se pudo borrar', errorMessage(e)) },
  );

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Layers className="w-4 h-4 text-azure" /> Lotes de conciliación (N:M)</div>}
          actions={<Button variant="primary" onClick={() => setNuevoOpen(true)}><Plus className="w-3 h-3" /> Nuevo lote</Button>}
        />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            Concilia N movimientos bancarios contra M facturas del mismo auxiliar (caso URBANO:
            varios eCheq pagando un conjunto de facturas). Genera un asiento consolidado.
          </div>
          {error && <FormError error={errorMessage(error)} />}
          {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
          <div className="border border-line rounded-md overflow-hidden">
            <table className="w-full text-[12.5px]">
              <thead className="bg-surface-row"><tr className="text-left">
                <th className="px-2 py-1.5">Código</th><th className="px-2 py-1.5">Auxiliar</th>
                <th className="px-2 py-1.5">Fecha</th><th className="px-2 py-1.5 text-right">Monto</th>
                <th className="px-2 py-1.5">Estado</th><th className="px-2 py-1.5"></th>
              </tr></thead>
              <tbody>
                {rows.map((l) => (
                  <tr key={l.id} className="border-t border-line hover:bg-surface-row">
                    <td className="px-2 py-1 font-mono">{l.codigo}</td>
                    <td className="px-2 py-1">{l.auxiliar_nombre ?? '—'}</td>
                    <td className="px-2 py-1">{fmtDate(l.fecha)}</td>
                    <td className="px-2 py-1 text-right tabular-nums">{l.signo} {fmtMoney(l.monto_total)}</td>
                    <td className="px-2 py-1"><Badge variant={EST_VARIANT[l.estado]}>{l.estado}</Badge></td>
                    <td className="px-2 py-1 text-right whitespace-nowrap">
                      {l.estado === 'BORRADOR' && <>
                        <Button variant="primary" disabled={confirmar.isPending} onClick={() => confirmar.mutate(l.id)}><CheckCircle2 className="w-3 h-3" /> Confirmar</Button>{' '}
                        <Button variant="danger" onClick={() => { if (confirm(`¿Borrar lote ${l.codigo}?`)) borrar.mutate(l.id); }}><Trash2 className="w-3 h-3" /></Button>
                      </>}
                      {l.estado === 'CONFIRMADO' && <Button variant="danger" onClick={() => setRevertir(l)}><Undo2 className="w-3 h-3" /> Revertir</Button>}
                    </td>
                  </tr>
                ))}
                {!isLoading && rows.length === 0 && <tr><td colSpan={6} className="px-2 py-6 text-center text-ink-3">Sin lotes.</td></tr>}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>

      {nuevoOpen && <NuevoLoteModal onClose={() => { setNuevoOpen(false); invalidate(); }} />}
      {revertir && <RevertirLoteModal lote={revertir} onClose={() => { setRevertir(null); invalidate(); }} />}
    </div>
  );
}

function NuevoLoteModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const [auxId, setAuxId] = useState('');
  const [cuentaId, setCuentaId] = useState('');
  const [tipoFactura, setTipoFactura] = useState<'VENTA' | 'COMPRA'>('VENTA');
  const [selMovs, setSelMovs] = useState<Set<number>>(new Set());
  const [selFacs, setSelFacs] = useState<Set<number>>(new Set());

  const { data: auxiliares } = useApi<Auxiliar[]>(['auxiliares', 'lote'], '/api/erp/auxiliares');
  const { data: cuentas } = useApi<CuentaBanc[]>(['cuentas-bancarias'], '/api/erp/cuentas-bancarias');
  const qs = auxId ? `?auxiliar_id=${auxId}&tipo_factura=${tipoFactura}${cuentaId ? `&cuenta_bancaria_id=${cuentaId}` : ''}` : '';
  const { data: cand } = useApi<{ movimientos: MovCand[]; facturas: FacCand[] }>(
    ['lote-cand', qs], `/api/erp/conciliacion/lotes/candidatos${qs}`, { enabled: !!auxId },
  );

  const movs = cand?.movimientos ?? [];
  const facs = cand?.facturas ?? [];
  const totMovs = useMemo(() => movs.filter((m) => selMovs.has(m.id)).reduce((a, m) => a + Math.max(Number(m.debito), Number(m.credito)), 0), [movs, selMovs]);
  const totFacs = useMemo(() => facs.filter((f) => selFacs.has(f.id)).reduce((a, f) => a + f.saldo, 0), [facs, selFacs]);
  const diff = Math.round((totMovs - totFacs) * 100) / 100;
  const signo: '+' | '-' = tipoFactura === 'VENTA' ? '+' : '-';

  const crear = useApiMutation<{ id: number }, Record<string, unknown>>(
    (v) => api.post('/api/erp/conciliacion/lotes', v),
    { onSuccess: () => { toast.success('Lote creado (BORRADOR)', 'Revisalo y confirmalo para generar el asiento.'); onClose(); }, onError: (e) => toast.error('No se pudo crear', errorMessage(e)) },
  );

  const valid = auxId && cuentaId && selMovs.size > 0 && selFacs.size > 0 && Math.abs(diff) <= 1;

  const toggle = (set: Set<number>, setter: (s: Set<number>) => void, id: number) => {
    const n = new Set(set); n.has(id) ? n.delete(id) : n.add(id); setter(n);
  };

  return (
    <Modal open onClose={onClose} title="Nuevo lote de conciliación" size="lg"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!valid || crear.isPending}
          onClick={() => crear.mutate({
            auxiliar_id: Number(auxId), cuenta_bancaria_id: Number(cuentaId), signo,
            movimientos: [...selMovs],
            facturas: facs.filter((f) => selFacs.has(f.id)).map((f) => ({ id: f.id, tipo: tipoFactura, monto: f.saldo })),
          })}>{crear.isPending ? 'Creando…' : 'Crear lote'}</Button>
      </>}>
      <div className="space-y-3 text-[12px]">
        <div className="grid grid-cols-3 gap-2">
          <SelectField label="Auxiliar" value={auxId} onChange={(e) => { setAuxId(e.target.value); setSelMovs(new Set()); setSelFacs(new Set()); }}
            placeholder="Elegí…" options={(auxiliares ?? []).map((a) => ({ value: a.id, label: `${a.codigo} ${a.nombre}` }))} />
          <SelectField label="Cuenta bancaria" value={cuentaId} onChange={(e) => setCuentaId(e.target.value)}
            placeholder="Elegí…" options={(cuentas ?? []).map((c) => ({ value: c.id, label: c.nombre }))} />
          <SelectField label="Tipo factura" value={tipoFactura} onChange={(e) => { setTipoFactura(e.target.value as 'VENTA' | 'COMPRA'); setSelFacs(new Set()); }}
            options={[{ value: 'VENTA', label: 'Venta (cobro)' }, { value: 'COMPRA', label: 'Compra (pago)' }]} />
        </div>

        {auxId && (
          <div className="grid grid-cols-2 gap-3">
            <div className="border border-line rounded-md overflow-hidden">
              <div className="bg-surface-row px-2 py-1 font-semibold">Movimientos bancarios</div>
              <div className="max-h-[300px] overflow-y-auto">
                {movs.map((m) => {
                  const monto = Math.max(Number(m.debito), Number(m.credito));
                  return (
                    <label key={m.id} className="flex items-center gap-2 px-2 py-1 border-t border-line hover:bg-surface-row cursor-pointer">
                      <input type="checkbox" checked={selMovs.has(m.id)} onChange={() => toggle(selMovs, setSelMovs, m.id)} />
                      <span className="flex-1 truncate" title={m.concepto}>{fmtDate(m.fecha)} · {m.concepto}</span>
                      <span className="tabular-nums">{fmtMoney(monto)}</span>
                    </label>
                  );
                })}
                {movs.length === 0 && <div className="px-2 py-3 text-ink-3 text-center">Sin movimientos elegibles.</div>}
              </div>
              <div className="bg-surface-row px-2 py-1 text-right font-semibold tabular-nums">Total: {fmtMoney(totMovs)}</div>
            </div>

            <div className="border border-line rounded-md overflow-hidden">
              <div className="bg-surface-row px-2 py-1 font-semibold">Facturas pendientes</div>
              <div className="max-h-[300px] overflow-y-auto">
                {facs.map((f) => (
                  <label key={f.id} className="flex items-center gap-2 px-2 py-1 border-t border-line hover:bg-surface-row cursor-pointer">
                    <input type="checkbox" checked={selFacs.has(f.id)} onChange={() => toggle(selFacs, setSelFacs, f.id)} />
                    <span className="flex-1">{f.numero} · {fmtDate(f.fecha)}</span>
                    <span className="tabular-nums">{fmtMoney(f.saldo)}</span>
                  </label>
                ))}
                {facs.length === 0 && <div className="px-2 py-3 text-ink-3 text-center">Sin facturas pendientes.</div>}
              </div>
              <div className="bg-surface-row px-2 py-1 text-right font-semibold tabular-nums">Total: {fmtMoney(totFacs)}</div>
            </div>
          </div>
        )}

        {auxId && (
          <div className={`text-right font-semibold tabular-nums ${Math.abs(diff) <= 1 ? 'text-success' : 'text-danger'}`}>
            Diferencia: {fmtMoney(diff)} {Math.abs(diff) > 1 && '(debe ser ≤ $1 para crear)'}
          </div>
        )}
        <FormError error={crear.error ? errorMessage(crear.error) : null} />
      </div>
    </Modal>
  );
}

function RevertirLoteModal({ lote, onClose }: { lote: Lote; onClose: () => void }) {
  const toast = useToast();
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<unknown, Record<string, unknown>>(
    (v) => api.post(`/api/erp/conciliacion/lotes/${lote.id}/revertir`, v),
    { onSuccess: () => { toast.success('Lote revertido'); onClose(); }, onError: (e) => toast.error('No se pudo revertir', errorMessage(e)) },
  );
  return (
    <Modal open onClose={onClose} title={`Revertir lote ${lote.codigo}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="danger" disabled={motivo.trim().length < 10 || m.isPending} onClick={() => m.mutate({ motivo })}>
          {m.isPending ? 'Revirtiendo…' : 'Revertir'}</Button>
      </>}>
      <div className="space-y-3 text-[12.5px]">
        <div className="bg-warning-bg/30 border border-warning/40 rounded p-2 text-[11.5px]">
          Anula el asiento consolidado, devuelve los movimientos a PENDIENTE y restaura el estado de las facturas.
        </div>
        <TextareaField label="Motivo *" value={motivo} rows={3} onChange={(e) => setMotivo(e.target.value)} hint="Mínimo 10 caracteres." />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
