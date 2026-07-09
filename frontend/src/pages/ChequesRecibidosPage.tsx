import { Fragment, useMemo, useState } from 'react';
import { Banknote, AlertTriangle, ArrowDownToLine, XCircle, Pencil, Undo2, Percent, Send, CalendarClock } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { fmtMoney, fmtDate, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { parseMontoEs } from '@/lib/montos';
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
  estado: 'EN_CARTERA' | 'DEPOSITADO' | 'COBRADO' | 'RECHAZADO' | 'VENCIDO_NO_COBRADO' | 'DESCONTADO' | 'ENDOSADO';
  descuento_entidad: string | null;
  descuento_intereses: number | string | null;
  descuento_iva: number | string | null;
  descuento_comision: number | string | null;
  descuento_sellado: number | string | null;
  descuento_percepcion_iva: number | string | null;
  descuento_percepcion_iibb: number | string | null;
  descuento_otros: number | string | null;
  descuento_neto: number | string | null;
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
  DESCONTADO: 'success',
  ENDOSADO: 'success',
};

const ESTADO_LABEL: Record<Cheque['estado'], string> = {
  EN_CARTERA: 'En cartera',
  DEPOSITADO: 'Depositado',
  COBRADO: 'Cobrado',
  RECHAZADO: 'Rechazado',
  VENCIDO_NO_COBRADO: 'Vencido sin cobrar',
  DESCONTADO: 'Descontado',
  ENDOSADO: 'Endosado',
};

/** Día siguiente a una fecha YYYY-MM-DD (los cheques cobran al día siguiente del vto). */
function diaSiguiente(fecha: string): string {
  const d = new Date(fecha + 'T00:00:00');
  d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
}

export function ChequesRecibidosPage() {
  const [estado, setEstado] = useState<string>('');
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');
  const [numero, setNumero] = useState('');
  const [accion, setAccion] = useState<{ tipo: 'depositar' | 'editar' | 'rechazar' | 'descontar'; cheque: Cheque } | null>(null);
  const [endosar, setEndosar] = useState<Cheque | null>(null);
  const toast = useToast();
  const invalidate = useInvalidate(['cheques-recibidos'], ['cheques-alertas']);

  const anular = useApiMutation<Cheque, number>(
    (id) => api.post(`/api/erp/tesoreria/cheques-recibidos/${id}/anular`, {}),
    {
      onSuccess: () => { toast.success('Cobro anulado', 'El cheque volvió a cartera.'); invalidate(); },
      onError: (e) => toast.error('No se pudo anular', errorMessage(e)),
    },
  );

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (estado) p.set('estado', estado);
    if (desde) p.set('desde', desde);
    if (hasta) p.set('hasta', hasta);
    if (numero) p.set('numero', numero);
    // Sin paginado en la UI: traemos todo (el default de 50 dejaba cheques
    // afuera cuando el total lo superaba).
    p.set('per_page', '1000');
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

      <PendientesAFechaCard />

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
                { value: 'DESCONTADO', label: 'Descontado' },
                { value: 'ENDOSADO', label: 'Endosado' },
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

          <div className="border border-line rounded-md overflow-x-auto">
            <table className="w-full text-[12.5px]">
              <thead className="bg-surface-row">
                <tr className="text-left">
                  <th className="px-2 py-1.5">N° / Banco</th>
                  <th className="px-2 py-1.5">Librador</th>
                  <th className="px-2 py-1.5">Recibo / Cliente</th>
                  <th className="px-2 py-1.5">Emisión</th>
                  <th className="px-2 py-1.5">Vto</th>
                  <th className="px-2 py-1.5">Cobro</th>
                  <th className="px-2 py-1.5 text-right">Importe</th>
                  <th className="px-2 py-1.5">Estado</th>
                  <th className="px-2 py-1.5">Observaciones</th>
                  <th className="px-2 py-1.5"></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((c) => {
                  const resuelto = c.estado === 'COBRADO' || c.estado === 'DEPOSITADO' || c.estado === 'DESCONTADO' || c.estado === 'ENDOSADO';
                  const sinEdicion = c.estado === 'DESCONTADO' || c.estado === 'ENDOSADO';
                  const pendiente = c.estado === 'EN_CARTERA' || c.estado === 'VENCIDO_NO_COBRADO';
                  return (
                  <tr key={c.id} className={`border-t border-line hover:bg-surface-row ${c.estado === 'VENCIDO_NO_COBRADO' ? 'bg-danger-bg/10' : ''}`}>
                    <td className="px-2 py-1"><strong>{c.numero_cheque}</strong><br/><span className="text-ink-3 text-[11px]">{c.banco_emisor}</span></td>
                    <td className="px-2 py-1">{c.librador_nombre ?? '—'}<br/><span className="text-ink-3 text-[11px]">{c.cuit_librador ?? ''}</span></td>
                    <td className="px-2 py-1">{c.recibo_numero ?? '—'}<br/><span className="text-ink-3 text-[11px]">{c.cliente_nombre ?? ''}</span></td>
                    <td className="px-2 py-1 whitespace-nowrap">{fmtDate(c.fecha_emision)}</td>
                    <td className="px-2 py-1 whitespace-nowrap">{fmtDate(c.fecha_pago)}</td>
                    <td className="px-2 py-1 whitespace-nowrap">
                      {c.fecha_acreditacion
                        ? <span title="Fecha real de cobro">{fmtDate(c.fecha_acreditacion)}</span>
                        : <span className="text-ink-3 italic" title="Estimado: día siguiente al vencimiento">~ {fmtDate(diaSiguiente(c.fecha_pago))}</span>}
                    </td>
                    <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(c.importe)}</td>
                    <td className="px-2 py-1"><Badge variant={ESTADO_VARIANT[c.estado]}>{ESTADO_LABEL[c.estado]}</Badge></td>
                    <td className="px-2 py-1">
                      <div className="max-w-[220px] space-y-0.5">
                        {c.estado === 'DESCONTADO' && c.descuento_neto != null && (
                          <div className="text-[11px] text-ink-2 leading-snug">
                            {c.descuento_entidad ? `${c.descuento_entidad} · ` : ''}Neto {fmtMoney(c.descuento_neto)} ({[
                              ['com', c.descuento_comision], ['int', c.descuento_intereses], ['IVA', c.descuento_iva],
                              ['sell', c.descuento_sellado], ['perc IVA', c.descuento_percepcion_iva],
                              ['perc IIBB', c.descuento_percepcion_iibb], ['otros', c.descuento_otros],
                            ].filter(([, v]) => Number(v) > 0).map(([l, v]) => `${l} ${fmtMoney(Number(v))}`).join(' · ')})
                          </div>
                        )}
                        {(c.observaciones || c.motivo_rechazo)
                          ? <div className="text-[11px] text-ink-2 whitespace-pre-wrap break-words leading-snug">{c.motivo_rechazo ? `Rechazo: ${c.motivo_rechazo}` : c.observaciones}</div>
                          : (c.estado !== 'DESCONTADO' && <span className="text-ink-3">—</span>)}
                      </div>
                    </td>
                    <td className="px-2 py-1 text-right whitespace-nowrap">
                      {pendiente && (
                        <>
                          <Button variant="primary" onClick={() => setAccion({ tipo: 'depositar', cheque: c })}>
                            <ArrowDownToLine className="w-3 h-3" /> Depositar
                          </Button>{' '}
                          <Button variant="secondary" onClick={() => setAccion({ tipo: 'descontar', cheque: c })}
                            title="Vender el cheque con quita (intereses + IVA + comisión). Genera el asiento.">
                            <Percent className="w-3 h-3" /> Descontar
                          </Button>{' '}
                          <Button variant="secondary" onClick={() => setEndosar(c)}
                            title="Entregar el cheque a un proveedor como pago de facturas de compra. Genera OP + asiento.">
                            <Send className="w-3 h-3" /> Endosar
                          </Button>{' '}
                          <Button variant="danger" onClick={() => setAccion({ tipo: 'rechazar', cheque: c })}>
                            <XCircle className="w-3 h-3" /> Rechazar
                          </Button>
                        </>
                      )}
                      {resuelto && (
                        <>
                          {!sinEdicion && (
                            <>
                              <Button variant="secondary" onClick={() => setAccion({ tipo: 'editar', cheque: c })}>
                                <Pencil className="w-3 h-3" /> Editar
                              </Button>{' '}
                            </>
                          )}
                          <Button variant="secondary" disabled={anular.isPending}
                            onClick={() => { if (confirm(
                              c.estado === 'DESCONTADO' ? '¿Anular el descuento? Se revierte el asiento y el cheque vuelve a cartera.'
                              : c.estado === 'ENDOSADO' ? '¿Anular el endoso? Se revierte el asiento, se anula la OP (las facturas recuperan su saldo) y el cheque vuelve a cartera.'
                              : '¿Anular el cobro y devolver el cheque a cartera?')) anular.mutate(c.id); }}>
                            <Undo2 className="w-3 h-3" /> {c.estado === 'DESCONTADO' ? 'Anular descuento' : c.estado === 'ENDOSADO' ? 'Anular endoso' : 'Anular cobro'}
                          </Button>
                        </>
                      )}
                      {c.estado === 'RECHAZADO' && (
                        <Button variant="secondary" disabled={anular.isPending}
                          onClick={() => { if (confirm('¿Revertir el rechazo y devolver el cheque a cartera?')) anular.mutate(c.id); }}>
                          <Undo2 className="w-3 h-3" /> Revertir
                        </Button>
                      )}
                    </td>
                  </tr>
                  );
                })}
                {!isLoading && rows.length === 0 && (
                  <tr><td colSpan={10} className="px-2 py-6 text-center text-ink-3">Sin cheques.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>

      {accion && <AccionModal accion={accion} onClose={() => setAccion(null)} />}
      {endosar && <EndosarModal cheque={endosar} onClose={() => setEndosar(null)} />}
    </div>
  );
}

// Cheques pendientes de cobro a una fecha de corte. Regla: cobrados → fecha
// real de cobro; sin cobrar → estimada (vencimiento + 1 día).
type ChequePendCorte = {
  id: number; numero_cheque: string; banco_emisor: string; cliente_nombre: string | null;
  recibo: string | null; importe: number; fecha_recepcion: string; fecha_vencimiento: string;
  fecha_cobro_efectiva: string; cobro_es_estimado: boolean; estado_actual: string;
};

function PendientesAFechaCard() {
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10));
  const [abierto, setAbierto] = useState(false);
  const { data, isFetching } = useApi<{ fecha: string; cheques: ChequePendCorte[]; cant: number; total: number }>(
    ['cheques-pendientes-fecha', fecha],
    `/api/erp/tesoreria/cheques-recibidos/pendientes-a-fecha?fecha=${fecha}`,
    { enabled: abierto && !!fecha },
  );

  return (
    <Card>
      <CardHeader title={<div className="flex items-center gap-2"><CalendarClock className="w-4 h-4 text-azure" /> Pendientes de cobro a una fecha</div>} />
      <CardBody className="p-4 space-y-3">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Fecha de corte" type="date" value={fecha}
            onChange={(e) => setFecha(e.target.value)} containerClassName="w-[170px]" />
          <Button variant="primary" onClick={() => setAbierto(true)} disabled={!fecha}>
            {isFetching ? 'Consultando…' : 'Consultar'}
          </Button>
          {abierto && data && (
            <div className="text-[13px] ml-2">
              <span className="text-ink-3">Pendientes al {fecha.split('-').reverse().join('/')}:</span>{' '}
              <strong>{data.cant} cheque{data.cant === 1 ? '' : 's'}</strong> ·{' '}
              <strong className="tabular-nums">{fmtMoney(data.total)}</strong>
            </div>
          )}
        </div>
        <div className="text-[11px] text-ink-3">
          Un cheque cuenta como pendiente al corte si ya estaba emitido y todavía no se había cobrado:
          se usa la fecha real de cobro si existe, o la estimada (vencimiento + 1 día) si aún no se cobró.
        </div>

        {abierto && data && data.cheques.length > 0 && (() => {
          // Agrupar por cliente (mayor subtotal primero), cheques por vto.
          const grupos = new Map<string, ChequePendCorte[]>();
          for (const c of data.cheques) {
            const k = c.cliente_nombre ?? 'Sin cliente';
            if (!grupos.has(k)) grupos.set(k, []);
            grupos.get(k)!.push(c);
          }
          const ordenados = Array.from(grupos.entries())
            .map(([cliente, chs]) => ({
              cliente, chs,
              subtotal: Math.round(chs.reduce((a, c) => a + Number(c.importe), 0) * 100) / 100,
            }))
            .sort((a, b) => b.subtotal - a.subtotal);
          return (
            <>
              <div className="border border-line rounded-md overflow-x-auto max-h-[360px] overflow-y-auto">
                <table className="w-full text-[12px]">
                  <thead className="bg-surface-row sticky top-0"><tr className="text-left">
                    <th className="px-2 py-1.5">N° / Banco</th>
                    <th className="px-2 py-1.5">Recibo</th>
                    <th className="px-2 py-1.5">Emisión</th>
                    <th className="px-2 py-1.5">Vto</th>
                    <th className="px-2 py-1.5">Cobro</th>
                    <th className="px-2 py-1.5 text-right">Importe</th>
                    <th className="px-2 py-1.5">Estado hoy</th>
                  </tr></thead>
                  <tbody>
                    {ordenados.map((g) => (
                      <Fragment key={g.cliente}>
                        <tr className="border-t border-line bg-azure-soft/20">
                          <td colSpan={7} className="px-2 py-1 font-semibold text-navy-800">
                            {g.cliente} <span className="text-ink-3 font-normal">({g.chs.length} cheque{g.chs.length === 1 ? '' : 's'})</span>
                          </td>
                        </tr>
                        {g.chs.map((c) => (
                          <tr key={c.id} className="border-t border-line">
                            <td className="px-2 py-1"><strong>{c.numero_cheque}</strong> <span className="text-ink-3 text-[11px]">{c.banco_emisor}</span></td>
                            <td className="px-2 py-1 tabular-nums">{c.recibo ?? '—'}</td>
                            <td className="px-2 py-1 whitespace-nowrap">{fmtDate(c.fecha_recepcion)}</td>
                            <td className="px-2 py-1 whitespace-nowrap">{fmtDate(c.fecha_vencimiento)}</td>
                            <td className="px-2 py-1 whitespace-nowrap">
                              {c.cobro_es_estimado
                                ? <span className="text-ink-3 italic">~ {fmtDate(c.fecha_cobro_efectiva)}</span>
                                : fmtDate(c.fecha_cobro_efectiva)}
                            </td>
                            <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(c.importe)}</td>
                            <td className="px-2 py-1"><Badge variant={ESTADO_VARIANT[c.estado_actual as Cheque['estado']] ?? 'neutral'}>{ESTADO_LABEL[c.estado_actual as Cheque['estado']] ?? c.estado_actual}</Badge></td>
                          </tr>
                        ))}
                        <tr className="border-t border-line bg-surface-row">
                          <td colSpan={5} className="px-2 py-1 text-right text-[11.5px] font-semibold">Subtotal {g.cliente}:</td>
                          <td className="px-2 py-1 text-right tabular-nums font-semibold">{fmtMoney(g.subtotal)}</td>
                          <td></td>
                        </tr>
                      </Fragment>
                    ))}
                  </tbody>
                  <tfoot className="bg-navy-800 text-white sticky bottom-0">
                    <tr>
                      <td colSpan={5} className="px-2 py-1.5 text-right font-semibold">TOTAL PENDIENTE AL CORTE:</td>
                      <td className="px-2 py-1.5 text-right tabular-nums font-bold">{fmtMoney(data.total)}</td>
                      <td></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
              {/* Resumen compacto por cliente (para leer/dictar rápido). */}
              <div className="border border-line rounded-md p-3 text-[12.5px] max-w-md">
                <div className="font-semibold mb-1">Resumen por cliente</div>
                {ordenados.map((g) => (
                  <div key={g.cliente} className="flex justify-between py-0.5">
                    <span className="truncate pr-3">{g.cliente}</span>
                    <span className="tabular-nums">{fmtMoney(g.subtotal)}</span>
                  </div>
                ))}
                <div className="flex justify-between border-t border-line mt-1 pt-1 font-bold">
                  <span>Total pendientes</span>
                  <span className="tabular-nums">{fmtMoney(data.total)}</span>
                </div>
              </div>
            </>
          );
        })()}
        {abierto && data && data.cheques.length === 0 && (
          <div className="text-ink-3 text-[12px] py-2">No había cheques pendientes de cobro a esa fecha.</div>
        )}
      </CardBody>
    </Card>
  );
}

function AccionModal({ accion, onClose }: { accion: { tipo: 'depositar' | 'editar' | 'rechazar' | 'descontar'; cheque: Cheque }; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['cheques-recibidos'], ['cheques-alertas']);
  const { data: bancos } = useApi<CuentaBancaria[]>(['cuentas-bancarias'], '/api/erp/cuentas-bancarias');
  const ch = accion.cheque;
  // Default fecha de cobro = día siguiente al vencimiento (regla del negocio).
  const [form, setForm] = useState({
    cuenta_bancaria_id: ch.cuenta_bancaria_deposito_id ? String(ch.cuenta_bancaria_deposito_id) : '',
    fecha_cobro: accion.tipo === 'editar'
      ? (ch.fecha_acreditacion ?? diaSiguiente(ch.fecha_pago))
      : accion.tipo === 'descontar'
        ? new Date().toISOString().slice(0, 10) // el descuento suele ser antes del vto
        : diaSiguiente(ch.fecha_pago),
    entidad: '',
    intereses: '',
    iva: '',
    comision: '',
    sellado: '',
    percepcion_iva: '',
    percepcion_iibb: '',
    otros: '',
    motivo: '',
    observaciones: ch.observaciones ?? '',
  });

  // Descuento: neto = importe − quita (todos los conceptos; preview en vivo).
  // parseMontoEs tolera pegados con formato es-AR/US ("14.782,45", "14,782.45").
  const quita = parseMontoEs(form.intereses) + parseMontoEs(form.iva) + parseMontoEs(form.comision)
    + parseMontoEs(form.sellado) + parseMontoEs(form.percepcion_iva) + parseMontoEs(form.percepcion_iibb) + parseMontoEs(form.otros);
  const neto = Math.round(((Number(ch.importe) || 0) - quita) * 100) / 100;

  const path = accion.tipo === 'rechazar' ? 'rechazar' : accion.tipo;
  const m = useApiMutation<Cheque, Record<string, unknown>>(
    (v) => api.post(`/api/erp/tesoreria/cheques-recibidos/${ch.id}/${path}`, v),
    {
      onSuccess: () => {
        toast.success(`Cheque ${accion.tipo === 'depositar' ? 'cobrado' : accion.tipo === 'editar' ? 'actualizado' : accion.tipo === 'descontar' ? 'descontado' : 'rechazado'}`);
        invalidate(); onClose();
      },
      onError: (e) => toast.error('No se pudo aplicar la acción', errorMessage(e)),
    },
  );

  const valid =
    accion.tipo === 'depositar' ? !!(form.cuenta_bancaria_id && form.fecha_cobro)
    : accion.tipo === 'editar' ? !!form.fecha_cobro
    : accion.tipo === 'descontar' ? !!(form.cuenta_bancaria_id && form.fecha_cobro && quita > 0 && neto > 0)
    : form.motivo.trim().length >= 5;

  const title =
    accion.tipo === 'depositar' ? `Depositar / cobrar cheque #${ch.numero_cheque}`
    : accion.tipo === 'editar' ? `Editar cobro #${ch.numero_cheque}`
    : accion.tipo === 'descontar' ? `Descontar cheque #${ch.numero_cheque}`
    : `Rechazar cheque #${ch.numero_cheque}`;

  return (
    <Modal open onClose={onClose} title={title} size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant={accion.tipo === 'rechazar' ? 'danger' : 'primary'} disabled={!valid || m.isPending}
            onClick={() => m.mutate(
              accion.tipo === 'depositar' ? {
                cuenta_bancaria_id: Number(form.cuenta_bancaria_id),
                fecha_cobro: form.fecha_cobro,
                observaciones: form.observaciones || undefined,
              }
              : accion.tipo === 'editar' ? {
                fecha_cobro: form.fecha_cobro,
                cuenta_bancaria_id: form.cuenta_bancaria_id ? Number(form.cuenta_bancaria_id) : undefined,
                observaciones: form.observaciones,
              }
              : accion.tipo === 'descontar' ? {
                cuenta_bancaria_id: Number(form.cuenta_bancaria_id),
                entidad: form.entidad.trim() || undefined,
                fecha: form.fecha_cobro,
                intereses: parseMontoEs(form.intereses),
                iva: parseMontoEs(form.iva),
                comision: parseMontoEs(form.comision),
                sellado: parseMontoEs(form.sellado),
                percepcion_iva: parseMontoEs(form.percepcion_iva),
                percepcion_iibb: parseMontoEs(form.percepcion_iibb),
                otros: parseMontoEs(form.otros),
                observaciones: form.observaciones || undefined,
              }
              : { motivo: form.motivo },
            )}>
            {m.isPending ? 'Guardando…' : 'Confirmar'}
          </Button>
        </>
      }>
      <div className="space-y-3 text-[12.5px]">
        <div className="bg-surface-row border border-line rounded-md p-3 grid grid-cols-3 gap-2">
          <div><div className="text-ink-3 text-[11px]">Banco</div><div>{ch.banco_emisor}</div></div>
          <div><div className="text-ink-3 text-[11px]">Vencimiento</div><div>{fmtDate(ch.fecha_pago)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Importe</div><div className="tabular-nums font-semibold">{fmtMoney(ch.importe)}</div></div>
        </div>
        {(accion.tipo === 'depositar' || accion.tipo === 'editar') && (
          <>
            <SelectField label={accion.tipo === 'depositar' ? 'Cuenta donde se deposita *' : 'Cuenta donde se depositó'}
              value={form.cuenta_bancaria_id}
              onChange={(e) => setForm({ ...form, cuenta_bancaria_id: e.target.value })}
              placeholder="Elegí cuenta…"
              options={(bancos ?? []).filter((b) => b.codigo !== 'CHEQUES_CARTERA').map((b) => ({ value: b.id, label: b.nombre }))} />
            <Field label="Fecha de cobro *" type="date" value={form.fecha_cobro}
              onChange={(e) => setForm({ ...form, fecha_cobro: e.target.value })}
              hint="Día en que el cheque acreditó (por defecto, el día siguiente al vencimiento)." />
            <TextareaField label="Observaciones" value={form.observaciones} rows={2}
              onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
          </>
        )}
        {accion.tipo === 'descontar' && (
          <>
            <div className="text-[11.5px] text-ink-2">
              El cheque se "vende" con una quita: se acredita el neto en el banco y cada concepto
              va a su cuenta (intereses, comisión, IVA crédito fiscal, sellado, percepción IIBB, otros).
              Cargá solo los conceptos que te cobraron — el resto queda en 0.
            </div>
            <div className="grid grid-cols-2 gap-2">
              <SelectField label="Banco donde se cobró *" value={form.cuenta_bancaria_id}
                onChange={(e) => setForm({ ...form, cuenta_bancaria_id: e.target.value })}
                placeholder="Elegí cuenta…"
                options={(bancos ?? []).filter((b) => b.codigo !== 'CHEQUES_CARTERA').map((b) => ({ value: b.id, label: b.nombre }))} />
              <Field label="Entidad donde descontamos" value={form.entidad}
                onChange={(e) => setForm({ ...form, entidad: e.target.value })}
                placeholder="Banco / financiera…" />
            </div>
            <Field label="Fecha del descuento *" type="date" value={form.fecha_cobro}
              onChange={(e) => setForm({ ...form, fecha_cobro: e.target.value })} />
            <div className="grid grid-cols-3 gap-2">
              {([
                ['comision', 'Comisiones'],
                ['intereses', 'Intereses'],
                ['iva', 'IVA'],
                ['sellado', 'Sellado'],
                ['percepcion_iva', 'Percepción IVA'],
                ['percepcion_iibb', 'Percepción IIBB'],
                ['otros', 'Otros impuestos'],
              ] as const).map(([k, label]) => (
                <Field key={k} label={label} type="text" inputMode="decimal"
                  value={form[k]}
                  onChange={(e) => setForm({ ...form, [k]: e.target.value })}
                  placeholder="0,00"
                  hint={form[k] ? `= ${fmtMoney(parseMontoEs(form[k]))}` : undefined} />
              ))}
            </div>
            <div className={`flex justify-between font-semibold border rounded p-2 ${neto > 0 ? 'border-line bg-surface-row' : 'border-danger bg-danger-bg/40 text-danger'}`}>
              <span>Neto a acreditar (importe − quita):</span>
              <span className="tabular-nums">{fmtMoney(neto)}</span>
            </div>
            <TextareaField label="Observaciones (detalle de otros impuestos, etc.)" value={form.observaciones} rows={2}
              onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
          </>
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

type Proveedor = { id: number; codigo: string; nombre: string };
type FacturaCC = {
  factura_id: number; tipo: string; pto_vta: number; numero: number;
  fecha_emision: string; imp_total: number; aplicado: number; saldo: number;
};

function EndosarModal({ cheque, onClose }: { cheque: Cheque; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['cheques-recibidos'], ['cheques-alertas']);
  const [proveedorId, setProveedorId] = useState('');
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10));
  const [observaciones, setObservaciones] = useState('');
  // facturaId → importe a imputar (string). Solo las tildadas están acá.
  const [imputa, setImputa] = useState<Record<number, string>>({});

  const { data: proveedores } = useApi<Proveedor[]>(
    ['auxiliares', 'proveedores'], '/api/erp/auxiliares?tipo=Proveedor',
  );
  const { data: facturasData } = useApi<FacturaCC[]>(
    ['facturas-endosables', proveedorId],
    `/api/erp/tesoreria/cheques-recibidos/facturas-endosables?proveedor_id=${proveedorId}`,
    { enabled: !!proveedorId },
  );
  const facturas = facturasData ?? [];

  const importeCheque = Number(cheque.importe) || 0;
  const sumaImputada = Object.values(imputa).reduce((s, v) => s + (Number(v) || 0), 0);
  const restante = Math.round((importeCheque - sumaImputada) * 100) / 100;
  const cuadra = Math.abs(restante) < 0.01;

  const toggleFactura = (f: FacturaCC) => {
    setImputa((prev) => {
      const next = { ...prev };
      if (next[f.factura_id] !== undefined) {
        delete next[f.factura_id];
      } else {
        const rest = importeCheque - Object.values(next).reduce((s, v) => s + (Number(v) || 0), 0);
        next[f.factura_id] = String(Math.max(0, Math.min(f.saldo, Math.round(rest * 100) / 100)));
      }
      return next;
    });
  };

  const m = useApiMutation<Cheque, Record<string, unknown>>(
    (v) => api.post(`/api/erp/tesoreria/cheques-recibidos/${cheque.id}/endosar`, v),
    {
      onSuccess: () => { toast.success('Cheque endosado', 'Se generó la OP y el asiento.'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo endosar', errorMessage(e)),
    },
  );

  const valid = !!proveedorId && !!fecha && Object.keys(imputa).length > 0 && cuadra
    && Object.values(imputa).every((v) => (Number(v) || 0) > 0);

  return (
    <Modal open onClose={onClose} title={`Endosar cheque #${cheque.numero_cheque}`} size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              proveedor_auxiliar_id: Number(proveedorId),
              fecha,
              imputaciones: Object.entries(imputa).map(([fid, imp]) => ({
                factura_compra_id: Number(fid), importe: Number(imp),
              })),
              observaciones: observaciones || undefined,
            })}>
            {m.isPending ? 'Endosando…' : 'Endosar'}
          </Button>
        </>
      }>
      <div className="space-y-3 text-[12.5px]">
        <div className="bg-surface-row border border-line rounded-md p-3 grid grid-cols-3 gap-2">
          <div><div className="text-ink-3 text-[11px]">Banco</div><div>{cheque.banco_emisor}</div></div>
          <div><div className="text-ink-3 text-[11px]">Vencimiento</div><div>{fmtDate(cheque.fecha_pago)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Importe a endosar</div><div className="tabular-nums font-semibold">{fmtMoney(importeCheque)}</div></div>
        </div>
        <div className="text-[11.5px] text-ink-2">
          El cheque se entrega al proveedor por su valor total, imputado contra sus facturas de compra
          (una factura puede quedar con pago parcial — el resto se paga después por otro medio).
        </div>
        <div className="grid grid-cols-2 gap-2">
          <SelectField label="Proveedor *" value={proveedorId}
            onChange={(e) => { setProveedorId(e.target.value); setImputa({}); }}
            placeholder="Elegí proveedor…"
            options={(proveedores ?? []).map((p) => ({ value: p.id, label: `${p.codigo} ${p.nombre}` }))} />
          <Field label="Fecha del endoso *" type="date" value={fecha}
            onChange={(e) => setFecha(e.target.value)} />
        </div>

        {proveedorId && (
          facturas.length === 0 ? (
            <div className="text-ink-3 text-[12px] py-3 text-center border border-line rounded">
              Este proveedor no tiene facturas con saldo pendiente.
            </div>
          ) : (
            <div className="border border-line rounded-md overflow-hidden max-h-[280px] overflow-y-auto">
              <table className="w-full text-[12px]">
                <thead className="bg-surface-row sticky top-0"><tr className="text-left">
                  <th className="px-2 py-1.5 w-6"></th>
                  <th className="px-2 py-1.5">Factura</th>
                  <th className="px-2 py-1.5">Fecha</th>
                  <th className="px-2 py-1.5 text-right">Saldo</th>
                  <th className="px-2 py-1.5 text-right">Imputar</th>
                </tr></thead>
                <tbody>
                  {facturas.map((f) => {
                    const checked = imputa[f.factura_id] !== undefined;
                    return (
                      <tr key={f.factura_id} className="border-t border-line">
                        <td className="px-2 py-1">
                          <input type="checkbox" checked={checked} onChange={() => toggleFactura(f)} />
                        </td>
                        <td className="px-2 py-1 font-mono text-[11.5px]">{f.tipo} {String(f.pto_vta).padStart(4, '0')}-{String(f.numero).padStart(8, '0')}</td>
                        <td className="px-2 py-1">{fmtDate(f.fecha_emision)}</td>
                        <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(f.saldo)}</td>
                        <td className="px-2 py-1 text-right">
                          {checked && (
                            <input type="number" step="0.01" min="0.01" max={f.saldo}
                              value={imputa[f.factura_id]}
                              onChange={(e) => setImputa({ ...imputa, [f.factura_id]: e.target.value })}
                              className="w-32 px-1.5 py-0.5 text-right tabular-nums border border-line-strong rounded" />
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )
        )}

        <div className={`flex justify-between font-semibold border rounded p-2 ${cuadra ? 'border-line bg-surface-row' : 'border-warning bg-warning-bg/40'}`}>
          <span>{cuadra ? 'Imputación completa:' : `Falta imputar ${fmtMoney(restante)} de:`}</span>
          <span className="tabular-nums">{fmtMoney(sumaImputada)} / {fmtMoney(importeCheque)}</span>
        </div>
        <TextareaField label="Observaciones" value={observaciones} rows={2}
          onChange={(e) => setObservaciones(e.target.value)} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
