import { useMemo, useState } from 'react';
import { Sparkles, CheckCircle2, Undo2, Pencil, History } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { fmtMoney, fmtDate, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Imputacion = {
  id: number;
  fecha: string;
  concepto: string;
  debito: number | string;
  credito: number | string;
  estado: 'MATCH_AUTO' | 'CONFIRMADO' | 'REVERTIDO';
  cuit_extractado: string | null;
  imputacion_confianza: number | string | null;
  factura_imputada_id: number | null;
  factura_imputada_tipo: 'VENTA' | 'COMPRA' | null;
  auxiliar_resuelto_id: number | null;
  auxiliar_nombre: string | null;
  auxiliar_cuit: string | null;
  cuenta_nombre: string | null;
};

type AuditRow = {
  id: number; accion: string; estado_previo: string | null; estado_posterior: string;
  motivo: string | null; created_at: string; user_nombre: string | null;
};

const ESTADO_VARIANT: Record<string, 'success' | 'danger' | 'warning' | 'neutral'> = {
  MATCH_AUTO: 'warning', CONFIRMADO: 'success', REVERTIDO: 'danger',
};
const ESTADO_LABEL: Record<string, string> = {
  MATCH_AUTO: 'Auto-imputado, revisar', CONFIRMADO: 'Conciliado ✓', REVERTIDO: 'Revertido',
};

function confianzaVariant(c: number): 'success' | 'warning' | 'neutral' {
  if (c >= 95) return 'success';
  if (c >= 70) return 'warning';
  return 'neutral';
}

export function ImputacionesAutoPage() {
  const [estado, setEstado] = useState('MATCH_AUTO');
  const [modificar, setModificar] = useState<Imputacion | null>(null);
  const [revertir, setRevertir] = useState<Imputacion | null>(null);
  const [auditDe, setAuditDe] = useState<Imputacion | null>(null);
  const toast = useToast();
  const invalidate = useInvalidate(['imput-auto']);

  const qs = useMemo(() => `?estado=${estado}`, [estado]);
  const { data, isLoading, error } = useApi<Paginator<Imputacion>>(
    ['imput-auto', qs], `/api/erp/conciliacion/imputaciones-pendientes${qs}`,
  );
  const rows = data?.data ?? [];

  const confirmar = useApiMutation<Imputacion, void>(
    () => api.post(`/api/erp/conciliacion/${confirmarId}/confirmar`),
    {
      onSuccess: () => { toast.success('Imputación confirmada', 'Asiento de cobro/pago generado.'); invalidate(); },
      onError: (e) => toast.error('No se pudo confirmar', errorMessage(e)),
    },
  );
  const [confirmarId, setConfirmarId] = useState<number | null>(null);
  const doConfirmar = (id: number) => { setConfirmarId(id); setTimeout(() => confirmar.mutate(), 0); };

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={<div className="flex items-center gap-2"><Sparkles className="w-4 h-4 text-azure" /> Imputaciones automáticas (matching CUIT)</div>} />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            Movimientos de extracto que el sistema imputó automáticamente contra una factura
            por el CUIT del concepto (reglas ICBC-COBRO-TRF / ICBC-PAGO-TRF). Revisá y
            <strong> confirmá</strong> (genera el asiento), <strong>modificá</strong> la factura,
            o <strong>revertí</strong> si fue error.
          </div>
          <div className="flex gap-3">
            <SelectField label="Estado" value={estado} onChange={(e) => setEstado(e.target.value)}
              containerClassName="w-[220px]"
              options={[
                { value: 'MATCH_AUTO', label: 'Auto-imputado (revisar)' },
                { value: 'CONFIRMADO', label: 'Confirmado' },
                { value: 'REVERTIDO', label: 'Revertido' },
                { value: 'TODOS', label: 'Todos' },
              ]} />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
          <div className="border border-line rounded-md overflow-x-auto">
            <table className="w-full text-[12px]">
              <thead className="bg-surface-row">
                <tr className="text-left">
                  <th className="px-2 py-1.5">Fecha</th>
                  <th className="px-2 py-1.5">Concepto</th>
                  <th className="px-2 py-1.5 text-right">Importe</th>
                  <th className="px-2 py-1.5">CUIT / Auxiliar</th>
                  <th className="px-2 py-1.5">Factura</th>
                  <th className="px-2 py-1.5">Conf.</th>
                  <th className="px-2 py-1.5">Estado</th>
                  <th className="px-2 py-1.5"></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((m) => {
                  const monto = Number(m.credito) > 0 ? Number(m.credito) : Number(m.debito);
                  const conf = Number(m.imputacion_confianza ?? 0);
                  return (
                    <tr key={m.id} className="border-t border-line hover:bg-surface-row">
                      <td className="px-2 py-1">{fmtDate(m.fecha)}</td>
                      <td className="px-2 py-1 max-w-[260px] truncate" title={m.concepto}>{m.concepto}</td>
                      <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(monto)}</td>
                      <td className="px-2 py-1">
                        <div className="tabular-nums">{m.cuit_extractado ?? '—'}</div>
                        <div className="text-ink-3 text-[11px]">{m.auxiliar_nombre ?? (m.auxiliar_resuelto_id ? `aux #${m.auxiliar_resuelto_id}` : 'sin auxiliar')}</div>
                      </td>
                      <td className="px-2 py-1">
                        {m.factura_imputada_id
                          ? <span>{m.factura_imputada_tipo} #{m.factura_imputada_id}</span>
                          : <span className="text-ink-3">solo CUIT</span>}
                      </td>
                      <td className="px-2 py-1"><Badge variant={confianzaVariant(conf)}>{conf}</Badge></td>
                      <td className="px-2 py-1"><Badge variant={ESTADO_VARIANT[m.estado] ?? 'neutral'}>{ESTADO_LABEL[m.estado] ?? m.estado}</Badge></td>
                      <td className="px-2 py-1 text-right whitespace-nowrap">
                        {m.estado === 'MATCH_AUTO' && (
                          <>
                            <Button variant="secondary" onClick={() => setModificar(m)}><Pencil className="w-3 h-3" /></Button>{' '}
                            <Button variant="primary" disabled={!m.factura_imputada_id} title={m.factura_imputada_id ? 'Confirmar y generar asiento' : 'Asigná factura primero (Modificar)'} onClick={() => doConfirmar(m.id)}>
                              <CheckCircle2 className="w-3 h-3" /> Confirmar
                            </Button>{' '}
                          </>
                        )}
                        {(m.estado === 'MATCH_AUTO' || m.estado === 'CONFIRMADO') && (
                          <Button variant="danger" onClick={() => setRevertir(m)}><Undo2 className="w-3 h-3" /></Button>
                        )}{' '}
                        <Button variant="ghost" onClick={() => setAuditDe(m)}><History className="w-3 h-3" /></Button>
                      </td>
                    </tr>
                  );
                })}
                {!isLoading && rows.length === 0 && (
                  <tr><td colSpan={8} className="px-2 py-6 text-center text-ink-3">Sin imputaciones en este estado.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>

      {modificar && <ModificarModal mov={modificar} onClose={() => { setModificar(null); invalidate(); }} />}
      {revertir && <RevertirModal mov={revertir} onClose={() => { setRevertir(null); invalidate(); }} />}
      {auditDe && <AuditModal mov={auditDe} onClose={() => setAuditDe(null)} />}
    </div>
  );
}

function ModificarModal({ mov, onClose }: { mov: Imputacion; onClose: () => void }) {
  const toast = useToast();
  const [form, setForm] = useState({
    factura_id: mov.factura_imputada_id ? String(mov.factura_imputada_id) : '',
    factura_tipo: mov.factura_imputada_tipo ?? (Number(mov.credito) > 0 ? 'VENTA' : 'COMPRA'),
    motivo: '',
  });
  const m = useApiMutation<Imputacion, Record<string, unknown>>(
    (v) => api.patch(`/api/erp/conciliacion/${mov.id}/modificar`, v),
    { onSuccess: () => { toast.success('Imputación modificada'); onClose(); }, onError: (e) => toast.error('No se pudo modificar', errorMessage(e)) },
  );
  const valid = form.motivo.trim().length >= 5;
  return (
    <Modal open onClose={onClose} title={`Modificar imputación #${mov.id}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!valid || m.isPending}
          onClick={() => m.mutate({
            factura_id: form.factura_id ? Number(form.factura_id) : null,
            factura_tipo: form.factura_id ? form.factura_tipo : null,
            motivo: form.motivo,
          })}>{m.isPending ? 'Guardando…' : 'Guardar'}</Button>
      </>}>
      <div className="space-y-3 text-[12.5px]">
        <div className="text-ink-3">Auxiliar: {mov.auxiliar_nombre ?? '—'} ({mov.cuit_extractado})</div>
        <div className="grid grid-cols-2 gap-2">
          <SelectField label="Tipo factura" value={form.factura_tipo}
            onChange={(e) => setForm({ ...form, factura_tipo: e.target.value as 'VENTA' | 'COMPRA' })}
            options={[{ value: 'VENTA', label: 'Venta' }, { value: 'COMPRA', label: 'Compra' }]} />
          <Field label="ID factura (vacío = solo CUIT)" type="number" value={form.factura_id}
            onChange={(e) => setForm({ ...form, factura_id: e.target.value })} />
        </div>
        <TextareaField label="Motivo *" value={form.motivo} rows={2}
          onChange={(e) => setForm({ ...form, motivo: e.target.value })} hint="Mínimo 5 caracteres." />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function RevertirModal({ mov, onClose }: { mov: Imputacion; onClose: () => void }) {
  const toast = useToast();
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<Imputacion, Record<string, unknown>>(
    (v) => api.post(`/api/erp/conciliacion/${mov.id}/revertir`, v),
    { onSuccess: () => { toast.success('Imputación revertida'); onClose(); }, onError: (e) => toast.error('No se pudo revertir', errorMessage(e)) },
  );
  return (
    <Modal open onClose={onClose} title={`Revertir imputación #${mov.id}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="danger" disabled={motivo.trim().length < 10 || m.isPending}
          onClick={() => m.mutate({ motivo })}>{m.isPending ? 'Revirtiendo…' : 'Revertir'}</Button>
      </>}>
      <div className="space-y-3 text-[12.5px]">
        {mov.estado === 'CONFIRMADO' && (
          <div className="bg-warning-bg/30 border border-warning/40 rounded p-2 text-[11.5px]">
            Esta imputación ya está confirmada. Revertir anula el asiento contable generado.
            Bloqueado si la factura está en un período cerrado / F.8001 exportado.
          </div>
        )}
        <TextareaField label="Motivo de reversión *" value={motivo} rows={3}
          onChange={(e) => setMotivo(e.target.value)} hint="Mínimo 10 caracteres." />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function AuditModal({ mov, onClose }: { mov: Imputacion; onClose: () => void }) {
  const { data, isLoading } = useApi<AuditRow[]>(['imput-audit', mov.id], `/api/erp/conciliacion/${mov.id}/audit`);
  return (
    <Modal open onClose={onClose} title={`Historial imputación #${mov.id}`} size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}>
      {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
      <div className="border border-line rounded-md overflow-hidden">
        <table className="w-full text-[12px]">
          <thead className="bg-surface-row"><tr className="text-left">
            <th className="px-2 py-1.5">Fecha</th><th className="px-2 py-1.5">Acción</th>
            <th className="px-2 py-1.5">Estado</th><th className="px-2 py-1.5">Usuario</th><th className="px-2 py-1.5">Motivo</th>
          </tr></thead>
          <tbody>
            {(data ?? []).map((a) => (
              <tr key={a.id} className="border-t border-line">
                <td className="px-2 py-1">{fmtDate(a.created_at)}</td>
                <td className="px-2 py-1"><Badge variant="neutral">{a.accion}</Badge></td>
                <td className="px-2 py-1 text-ink-3">{a.estado_previo ?? '—'} → {a.estado_posterior}</td>
                <td className="px-2 py-1">{a.user_nombre ?? 'sistema'}</td>
                <td className="px-2 py-1 text-ink-3">{a.motivo ?? '—'}</td>
              </tr>
            ))}
            {(data ?? []).length === 0 && !isLoading && (
              <tr><td colSpan={5} className="px-2 py-4 text-center text-ink-3">Sin historial.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </Modal>
  );
}
