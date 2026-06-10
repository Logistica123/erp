import { useMemo, useState } from 'react';
import { Banknote, AlertTriangle, ArrowDownToLine, CheckCircle2, XCircle } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { fmtMoney, fmtDate, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Cheque = {
  id: number;
  numero_cheque: string;
  banco_emisor: string;
  cuit_librador: string | null;
  librador_nombre: string | null;
  fecha_emision: string;
  fecha_pago: string;
  importe: number | string;
  estado: 'EN_CARTERA' | 'DEPOSITADO' | 'COBRADO' | 'RECHAZADO' | 'VENCIDO_NO_COBRADO';
  cuenta_bancaria_deposito_id: number | null;
  cuenta_deposito_nombre: string | null;
  fecha_deposito: string | null;
  fecha_acreditacion: string | null;
  fecha_rechazo: string | null;
  motivo_rechazo: string | null;
  observaciones: string | null;
  recibo_numero: string | null;
  cliente_nombre: string | null;
};

type Alerta = {
  id: number;
  numero_cheque: string;
  banco_emisor: string;
  importe: number | string;
  fecha_pago: string;
  cliente_nombre: string | null;
  recibo_numero: string | null;
  dias_vencido: number;
};

type CuentaBancaria = { id: number; codigo: string; nombre: string };

const ESTADO_VARIANT: Record<Cheque['estado'], 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  EN_CARTERA: 'info',
  DEPOSITADO: 'warning',
  COBRADO: 'success',
  RECHAZADO: 'danger',
  VENCIDO_NO_COBRADO: 'danger',
};

const ESTADO_LABEL: Record<Cheque['estado'], string> = {
  EN_CARTERA: 'En cartera',
  DEPOSITADO: 'Depositado',
  COBRADO: 'Cobrado',
  RECHAZADO: 'Rechazado',
  VENCIDO_NO_COBRADO: 'Vencido sin cobrar',
};

export function ChequesRecibidosPage() {
  const [estado, setEstado] = useState<string>('');
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');
  const [numero, setNumero] = useState('');
  const [accion, setAccion] = useState<{ tipo: 'depositar' | 'cobrar' | 'rechazar'; cheque: Cheque } | null>(null);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (estado) p.set('estado', estado);
    if (desde) p.set('desde', desde);
    if (hasta) p.set('hasta', hasta);
    if (numero) p.set('numero', numero);
    return p.toString();
  }, [estado, desde, hasta, numero]);

  const { data, isLoading, error } = useApi<Paginator<Cheque>>(
    ['cheques-recibidos', qs],
    `/api/erp/tesoreria/cheques-recibidos?${qs}`,
  );

  const { data: alertas } = useApi<Alerta[]>(['cheques-alertas'], '/api/erp/tesoreria/cheques-recibidos/alertas');

  const rows = data?.data ?? [];

  return (
    <div className="p-6 space-y-4">
      {(alertas ?? []).length > 0 && (
        <Card>
          <CardBody className="p-3">
            <div className="flex items-center gap-2 text-danger">
              <AlertTriangle className="w-4 h-4" />
              <strong>{(alertas ?? []).length} cheque(s) vencido(s) sin cobrar</strong>
            </div>
            <div className="mt-2 space-y-1 text-[12px]">
              {(alertas ?? []).slice(0, 8).map((a) => (
                <div key={a.id} className="flex items-center justify-between border-t border-line pt-1">
                  <div>
                    <strong>#{a.numero_cheque}</strong> · {a.banco_emisor}
                    {a.cliente_nombre && <> · <span className="text-ink-3">{a.cliente_nombre}</span></>}
                    {' '}vto {fmtDate(a.fecha_pago)} (<span className="text-danger">{a.dias_vencido}d vencido</span>)
                  </div>
                  <div className="tabular-nums font-semibold">{fmtMoney(a.importe)}</div>
                </div>
              ))}
            </div>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader title={<div className="flex items-center gap-2"><Banknote className="w-4 h-4 text-azure" /> Cheques recibidos</div>} />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <SelectField label="Estado" value={estado} onChange={(e) => setEstado(e.target.value)}
              containerClassName="w-[200px]" placeholder="Todos"
              options={[
                { value: 'EN_CARTERA', label: 'En cartera' },
                { value: 'DEPOSITADO', label: 'Depositado' },
                { value: 'COBRADO', label: 'Cobrado' },
                { value: 'VENCIDO_NO_COBRADO', label: 'Vencido sin cobrar' },
                { value: 'RECHAZADO', label: 'Rechazado' },
              ]} />
            <Field label="Desde (vto)" type="date" value={desde}
              onChange={(e) => setDesde(e.target.value)} containerClassName="w-[150px]" />
            <Field label="Hasta (vto)" type="date" value={hasta}
              onChange={(e) => setHasta(e.target.value)} containerClassName="w-[150px]" />
            <Field label="N° cheque" value={numero}
              onChange={(e) => setNumero(e.target.value)} containerClassName="w-[150px]" />
          </div>

          {error && <FormError error={errorMessage(error)} />}
          {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}

          <div className="border border-line rounded-md overflow-hidden">
            <table className="w-full text-[12.5px]">
              <thead className="bg-surface-row">
                <tr className="text-left">
                  <th className="px-2 py-1.5">N° / Banco</th>
                  <th className="px-2 py-1.5">Librador</th>
                  <th className="px-2 py-1.5">Recibo / Cliente</th>
                  <th className="px-2 py-1.5">Emisión</th>
                  <th className="px-2 py-1.5">Pago</th>
                  <th className="px-2 py-1.5 text-right">Importe</th>
                  <th className="px-2 py-1.5">Estado</th>
                  <th className="px-2 py-1.5"></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((c) => (
                  <tr key={c.id} className={`border-t border-line hover:bg-surface-row ${c.estado === 'VENCIDO_NO_COBRADO' ? 'bg-danger-bg/10' : ''}`}>
                    <td className="px-2 py-1"><strong>{c.numero_cheque}</strong><br/><span className="text-ink-3 text-[11px]">{c.banco_emisor}</span></td>
                    <td className="px-2 py-1">{c.librador_nombre ?? '—'}<br/><span className="text-ink-3 text-[11px]">{c.cuit_librador ?? ''}</span></td>
                    <td className="px-2 py-1">{c.recibo_numero ?? '—'}<br/><span className="text-ink-3 text-[11px]">{c.cliente_nombre ?? ''}</span></td>
                    <td className="px-2 py-1">{fmtDate(c.fecha_emision)}</td>
                    <td className="px-2 py-1">{fmtDate(c.fecha_pago)}</td>
                    <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(c.importe)}</td>
                    <td className="px-2 py-1"><Badge variant={ESTADO_VARIANT[c.estado]}>{ESTADO_LABEL[c.estado]}</Badge></td>
                    <td className="px-2 py-1 text-right whitespace-nowrap">
                      {(c.estado === 'EN_CARTERA' || c.estado === 'VENCIDO_NO_COBRADO') && (
                        <>
                          <Button variant="secondary" onClick={() => setAccion({ tipo: 'depositar', cheque: c })}>
                            <ArrowDownToLine className="w-3 h-3" /> Depositar
                          </Button>{' '}
                        </>
                      )}
                      {c.estado !== 'COBRADO' && c.estado !== 'RECHAZADO' && (
                        <>
                          <Button variant="primary" onClick={() => setAccion({ tipo: 'cobrar', cheque: c })}>
                            <CheckCircle2 className="w-3 h-3" /> Cobrar
                          </Button>{' '}
                          <Button variant="danger" onClick={() => setAccion({ tipo: 'rechazar', cheque: c })}>
                            <XCircle className="w-3 h-3" /> Rechazar
                          </Button>
                        </>
                      )}
                    </td>
                  </tr>
                ))}
                {!isLoading && rows.length === 0 && (
                  <tr><td colSpan={8} className="px-2 py-6 text-center text-ink-3">Sin cheques.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>

      {accion && <AccionModal accion={accion} onClose={() => setAccion(null)} />}
    </div>
  );
}

function AccionModal({ accion, onClose }: { accion: { tipo: 'depositar' | 'cobrar' | 'rechazar'; cheque: Cheque }; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['cheques-recibidos'], ['cheques-alertas']);
  const { data: bancos } = useApi<CuentaBancaria[]>(['cuentas-bancarias'], '/api/erp/cuentas-bancarias');
  const [form, setForm] = useState({
    cuenta_bancaria_id: '',
    fecha_deposito: new Date().toISOString().slice(0, 10),
    fecha_acreditacion: new Date().toISOString().slice(0, 10),
    motivo: '',
    observaciones: '',
  });

  const path = accion.tipo === 'depositar' ? 'depositar' : accion.tipo === 'cobrar' ? 'cobrar' : 'rechazar';
  const m = useApiMutation<Cheque, Record<string, unknown>>(
    (v) => api.post(`/api/erp/tesoreria/cheques-recibidos/${accion.cheque.id}/${path}`, v),
    {
      onSuccess: () => {
        toast.success(`Cheque ${accion.tipo === 'depositar' ? 'depositado' : accion.tipo === 'cobrar' ? 'cobrado' : 'rechazado'}`);
        invalidate(); onClose();
      },
      onError: (e) => toast.error('No se pudo aplicar la acción', errorMessage(e)),
    },
  );

  const valid =
    accion.tipo === 'depositar' ? !!(form.cuenta_bancaria_id && form.fecha_deposito)
    : accion.tipo === 'cobrar' ? !!form.fecha_acreditacion
    : form.motivo.trim().length >= 5;

  const title =
    accion.tipo === 'depositar' ? `Depositar cheque #${accion.cheque.numero_cheque}`
    : accion.tipo === 'cobrar' ? `Marcar cobrado #${accion.cheque.numero_cheque}`
    : `Rechazar cheque #${accion.cheque.numero_cheque}`;

  return (
    <Modal open onClose={onClose} title={title} size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant={accion.tipo === 'rechazar' ? 'danger' : 'primary'} disabled={!valid || m.isPending}
            onClick={() => m.mutate(
              accion.tipo === 'depositar' ? {
                cuenta_bancaria_id: Number(form.cuenta_bancaria_id),
                fecha_deposito: form.fecha_deposito,
                observaciones: form.observaciones || undefined,
              }
              : accion.tipo === 'cobrar' ? {
                fecha_acreditacion: form.fecha_acreditacion,
              }
              : { motivo: form.motivo },
            )}>
            {m.isPending ? 'Guardando…' : 'Confirmar'}
          </Button>
        </>
      }>
      <div className="space-y-3 text-[12.5px]">
        <div className="bg-surface-row border border-line rounded-md p-3 grid grid-cols-3 gap-2">
          <div><div className="text-ink-3 text-[11px]">Banco</div><div>{accion.cheque.banco_emisor}</div></div>
          <div><div className="text-ink-3 text-[11px]">Vencimiento</div><div>{fmtDate(accion.cheque.fecha_pago)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Importe</div><div className="tabular-nums font-semibold">{fmtMoney(accion.cheque.importe)}</div></div>
        </div>
        {accion.tipo === 'depositar' && (
          <>
            <SelectField label="Cuenta donde se deposita *" value={form.cuenta_bancaria_id}
              onChange={(e) => setForm({ ...form, cuenta_bancaria_id: e.target.value })}
              placeholder="Elegí cuenta…"
              options={(bancos ?? []).filter((b) => b.codigo !== 'CHEQUES_CARTERA').map((b) => ({ value: b.id, label: b.nombre }))} />
            <Field label="Fecha de depósito *" type="date" value={form.fecha_deposito}
              onChange={(e) => setForm({ ...form, fecha_deposito: e.target.value })} />
            <Field label="Observaciones" value={form.observaciones}
              onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
          </>
        )}
        {accion.tipo === 'cobrar' && (
          <Field label="Fecha de acreditación *" type="date" value={form.fecha_acreditacion}
            onChange={(e) => setForm({ ...form, fecha_acreditacion: e.target.value })}
            hint="Día en que efectivamente el cheque acreditó en el banco." />
        )}
        {accion.tipo === 'rechazar' && (
          <TextareaField label="Motivo del rechazo *" value={form.motivo} rows={3}
            onChange={(e) => setForm({ ...form, motivo: e.target.value })}
            hint="Mínimo 5 caracteres. Sin fondos, librador cerrado, etc." />
        )}
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
