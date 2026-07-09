import { useEffect, useMemo, useState } from 'react';
import { Calculator, Plus, ClipboardList, Users, Check, Pencil, Trash2, Ban } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Caja = { id: number; codigo: string; nombre: string; saldo_actual?: number };

type Arqueo = {
  id: number;
  caja: Caja;
  fecha: string;
  saldo_teorico: number | string;
  saldo_fisico: number | string;
  diferencia: number | string;
  motivo?: string | null;
  estado?: string | null;
  decision_autorizacion?: string | null;
  asiento_ajuste?: { id: number; numero: number; fecha: string } | null;
  realizado_por?: { id: number; name: string } | null;
  // v1.51
  denominaciones?: Array<{ valor_billete: number | string; cantidad: number }>;
  motivo_anulacion?: string | null;
  asiento_reversa_id?: number | null;
  created_at: string;
};

type Denominacion = { id: number; moneda: string; valor: number | string; descripcion: string };

const ESTADO_VARIANT: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'default'> = {
  CIERRA_OK: 'success',
  CERRADO_CON_AJUSTE: 'info',
  PENDIENTE_AUTORIZACION: 'warning',
  CERRADO_CON_DISCREPANCIA: 'warning',
  RECHAZADO: 'danger',
  ANULADO: 'neutral',
};

/** v1.51 — estados que admiten cada acción del listado. */
const PUEDE_EDITAR_BORRAR = ['PENDIENTE_AUTORIZACION', 'BORRADOR'];
const PUEDE_ANULAR = ['CIERRA_OK', 'CERRADO_CON_AJUSTE', 'CERRADO_CON_DISCREPANCIA'];

export function ArqueosPage() {
  const [cajaId, setCajaId] = useState('');
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');
  const [page, setPage] = useState(1);

  const { data: cajas } = useApi<Caja[]>(['cajas'], '/api/erp/cajas');

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (cajaId) p.set('caja_id', cajaId);
    if (desde)  p.set('desde', desde);
    if (hasta)  p.set('hasta', hasta);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [cajaId, desde, hasta, page]);

  const { data, isLoading, error } = useApi<Paginator<Arqueo>>(
    ['arqueos', qs],
    `/api/erp/caja/arqueos${qs ? `?${qs}` : ''}`
  );

  const [nuevoOpen, setNuevoOpen] = useState(false);
  // v1.51 — acciones directas.
  const [autorizarA, setAutorizarA] = useState<Arqueo | null>(null);
  const [editarA, setEditarA] = useState<Arqueo | null>(null);
  const [borrarA, setBorrarA] = useState<Arqueo | null>(null);
  const [anularA, setAnularA] = useState<Arqueo | null>(null);

  const columns: Column<Arqueo>[] = [
    { key: 'fecha', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha) },
    { key: 'caja', header: 'Caja',
      render: (r) => `${r.caja.codigo} ${r.caja.nombre}` },
    { key: 'saldo_teorico', header: 'Saldo teórico', align: 'right', width: '130px',
      render: (r) => fmtMoney(r.saldo_teorico) },
    { key: 'saldo_fisico', header: 'Saldo físico', align: 'right', width: '130px',
      render: (r) => fmtMoney(r.saldo_fisico) },
    { key: 'diferencia', header: 'Diferencia', align: 'right', width: '120px',
      render: (r) => {
        const d = Number(r.diferencia);
        const variant = Math.abs(d) < 0.01 ? 'success' : d > 0 ? 'warning' : 'danger';
        return <Badge variant={variant}>{fmtMoney(d)}</Badge>;
      } },
    { key: 'estado', header: 'Estado', width: '180px',
      render: (r) => {
        const e = r.estado ?? '—';
        const v = ESTADO_VARIANT[e] ?? 'neutral';
        return <Badge variant={v}>{e.replaceAll('_', ' ')}</Badge>;
      } },
    { key: 'realizado_por', header: 'Realizado por',
      render: (r) => r.realizado_por?.name ?? '—' },
    { key: 'asiento_ajuste', header: 'Asiento', width: '90px',
      render: (r) => r.asiento_ajuste ? `#${r.asiento_ajuste.numero}` : '—' },
    // v1.51 — acciones contextuales según estado.
    { key: 'acciones', header: '', width: '210px', align: 'right',
      render: (r) => {
        const e = r.estado ?? '';
        return (
          <div className="flex gap-1 justify-end">
            {PUEDE_EDITAR_BORRAR.includes(e) && (
              <>
                <Button size="sm" variant="primary" title="Autorizar con ajuste (genera el asiento de sobrante/faltante)."
                  onClick={() => setAutorizarA(r)}>
                  <Check className="w-3 h-3" /> Autorizar
                </Button>
                <Button size="sm" variant="secondary" title="Editar el arqueo pendiente."
                  onClick={() => setEditarA(r)}>
                  <Pencil className="w-3 h-3" />
                </Button>
                <Button size="sm" variant="danger" title="Borrar el arqueo pendiente (irreversible)."
                  onClick={() => setBorrarA(r)}>
                  <Trash2 className="w-3 h-3" />
                </Button>
              </>
            )}
            {PUEDE_ANULAR.includes(e) && (
              <Button size="sm" variant="secondary" title="Anular: genera reversa del asiento (si tiene) y deja el arqueo marcado."
                onClick={() => setAnularA(r)}>
                <Ban className="w-3 h-3" /> Anular
              </Button>
            )}
          </div>
        );
      } },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Calculator className="w-4 h-4 text-azure" /> Arqueos de caja</div>}
          actions={
            <div className="flex items-center gap-2">
              <Link to="/erp/tesoreria/caja-efectivo/operadores">
                <Button variant="secondary"><Users className="w-3 h-3" /> Operadores</Button>
              </Link>
              <Link to="/erp/tesoreria/caja-efectivo/arqueos-pendientes">
                <Button variant="secondary"><ClipboardList className="w-3 h-3" /> Pendientes</Button>
              </Link>
              <Button variant="primary" onClick={() => setNuevoOpen(true)}>
                <Plus className="w-3 h-3" /> Registrar arqueo
              </Button>
            </div>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <SelectField label="Caja" value={cajaId} placeholder="Todas"
              onChange={(e) => { setCajaId(e.target.value); setPage(1); }}
              containerClassName="w-[220px]"
              options={(cajas ?? []).map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))} />
            <Field label="Desde" type="date" value={desde}
              onChange={(e) => { setDesde(e.target.value); setPage(1); }}
              containerClassName="w-[150px]" />
            <Field label="Hasta" type="date" value={hasta}
              onChange={(e) => { setHasta(e.target.value); setPage(1); }}
              containerClassName="w-[150px]" />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={columns} paginator={data} loading={isLoading} onPageChange={setPage}
            empty="Sin arqueos en el rango" />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevoArqueoModal cajas={cajas ?? []} onClose={() => setNuevoOpen(false)} />}
      {editarA && <NuevoArqueoModal cajas={cajas ?? []} arqueo={editarA} onClose={() => setEditarA(null)} />}
      {autorizarA && <AutorizarModal arqueo={autorizarA} onClose={() => setAutorizarA(null)} />}
      {borrarA && <BorrarModal arqueo={borrarA} onClose={() => setBorrarA(null)} />}
      {anularA && <AnularModal arqueo={anularA} onClose={() => setAnularA(null)} />}
    </div>
  );
}

// v1.51 Bloque A — Autorizar directo con preview del asiento a generar.
function AutorizarModal({ arqueo, onClose }: { arqueo: Arqueo; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['arqueos']);
  const dif = Number(arqueo.diferencia);
  const sobrante = dif > 0;
  const m = useApiMutation<Arqueo, void>(
    () => api.post(`/api/erp/caja/arqueos/${arqueo.id}/autorizar`, { decision: 'AJUSTAR', motivo: arqueo.motivo ?? undefined }),
    {
      onSuccess: () => { toast.success('Arqueo autorizado', 'Asiento de ajuste generado.'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo autorizar', errorMessage(e)),
    },
  );
  return (
    <Modal open onClose={onClose} title="Autorizar arqueo de caja" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={m.isPending} onClick={() => m.mutate()}>
            {m.isPending ? 'Autorizando…' : 'Autorizar y generar asiento'}
          </Button>
        </>
      }>
      <div className="space-y-3 text-[12.5px]">
        <div className="bg-surface-row border border-line rounded-md p-3 grid grid-cols-2 gap-2">
          <div><div className="text-ink-3 text-[11px]">Caja</div><div>{arqueo.caja.codigo} {arqueo.caja.nombre}</div></div>
          <div><div className="text-ink-3 text-[11px]">Fecha</div><div>{fmtDate(arqueo.fecha)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Saldo teórico</div><div className="tabular-nums">{fmtMoney(arqueo.saldo_teorico)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Saldo físico</div><div className="tabular-nums">{fmtMoney(arqueo.saldo_fisico)}</div></div>
          <div className="col-span-2"><div className="text-ink-3 text-[11px]">Diferencia</div>
            <div className={`tabular-nums font-semibold ${sobrante ? 'text-success' : 'text-danger'}`}>
              {fmtMoney(dif)} ({sobrante ? 'sobrante a favor' : 'faltante'})
            </div>
          </div>
        </div>
        <div className="border border-line rounded-md p-3">
          <div className="text-[11px] font-semibold text-ink-2 mb-1">Asiento contable a generar</div>
          {sobrante ? (
            <div className="font-mono text-[11.5px] space-y-0.5">
              <div className="flex justify-between"><span>D&nbsp;&nbsp;Caja ({arqueo.caja.codigo})</span><span className="tabular-nums">{fmtMoney(Math.abs(dif))}</span></div>
              <div className="flex justify-between"><span>H&nbsp;&nbsp;4.2.07 Sobrante de Caja</span><span className="tabular-nums">{fmtMoney(Math.abs(dif))}</span></div>
            </div>
          ) : (
            <div className="font-mono text-[11.5px] space-y-0.5">
              <div className="flex justify-between"><span>D&nbsp;&nbsp;5.4.09 Faltante de Caja</span><span className="tabular-nums">{fmtMoney(Math.abs(dif))}</span></div>
              <div className="flex justify-between"><span>H&nbsp;&nbsp;Caja ({arqueo.caja.codigo})</span><span className="tabular-nums">{fmtMoney(Math.abs(dif))}</span></div>
            </div>
          )}
          <div className="text-[10.5px] text-ink-3 mt-1">El saldo de la caja se ajusta al físico contado.</div>
        </div>
      </div>
    </Modal>
  );
}

// v1.51 Bloque C — Confirmación de borrado.
function BorrarModal({ arqueo, onClose }: { arqueo: Arqueo; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['arqueos']);
  const m = useApiMutation<{ ok: boolean }, void>(
    () => api.delete(`/api/erp/caja/arqueos/${arqueo.id}`),
    {
      onSuccess: () => { toast.success('Arqueo borrado'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo borrar', errorMessage(e)),
    },
  );
  return (
    <Modal open onClose={onClose} title="Borrar arqueo de caja" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={m.isPending} onClick={() => m.mutate()}>
            {m.isPending ? 'Borrando…' : 'Borrar'}
          </Button>
        </>
      }>
      <div className="space-y-2 text-[12.5px]">
        <p>¿Confirmás el borrado del arqueo?</p>
        <div className="bg-surface-row border border-line rounded-md p-3 text-[12px]">
          <div>Caja: <strong>{arqueo.caja.codigo}</strong></div>
          <div>Fecha: <strong>{fmtDate(arqueo.fecha)}</strong></div>
          <div>Saldo físico: <strong className="tabular-nums">{fmtMoney(arqueo.saldo_fisico)}</strong></div>
        </div>
        <p className="text-danger text-[11.5px]">Esta acción es irreversible (se borran también las denominaciones).</p>
      </div>
    </Modal>
  );
}

// v1.51 Bloque D — Anulación con motivo obligatorio + reversa del asiento.
function AnularModal({ arqueo, onClose }: { arqueo: Arqueo; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['arqueos']);
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<Arqueo, void>(
    () => api.post(`/api/erp/caja/arqueos/${arqueo.id}/anular`, { motivo: motivo.trim() }),
    {
      onSuccess: () => { toast.success('Arqueo anulado', arqueo.asiento_ajuste ? 'Se generó el asiento de reversa.' : 'No tenía asiento — solo cambió el estado.'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo anular', errorMessage(e)),
    },
  );
  return (
    <Modal open onClose={onClose} title="Anular arqueo autorizado" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={motivo.trim().length < 10 || m.isPending} onClick={() => m.mutate()}>
            {m.isPending ? 'Anulando…' : 'Anular'}
          </Button>
        </>
      }>
      <div className="space-y-3 text-[12.5px]">
        {arqueo.asiento_ajuste ? (
          <div className="border border-warning/40 bg-warning-bg/30 rounded p-2 text-[11.5px]">
            ⚠️ El arqueo generó el asiento #{arqueo.asiento_ajuste.numero}. La anulación crea un
            asiento de reversa (D/H espejo) <strong>con fecha de hoy</strong> y revierte el ajuste del saldo de la caja.
          </div>
        ) : (
          <div className="border border-line bg-surface-row rounded p-2 text-[11.5px]">
            El arqueo no generó asiento — la anulación solo cambia el estado.
          </div>
        )}
        <div className="bg-surface-row border border-line rounded-md p-3 text-[12px]">
          <div>Caja: <strong>{arqueo.caja.codigo}</strong> · Fecha: <strong>{fmtDate(arqueo.fecha)}</strong></div>
          <div>Diferencia: <strong className="tabular-nums">{fmtMoney(arqueo.diferencia)}</strong></div>
        </div>
        <TextareaField label="Motivo de anulación *" rows={3} value={motivo}
          onChange={(e) => setMotivo(e.target.value)}
          hint="Mínimo 10 caracteres." />
      </div>
    </Modal>
  );
}

// v1.51 — `arqueo` opcional: si viene, el modal edita (PUT) en vez de crear.
function NuevoArqueoModal({ cajas, arqueo, onClose }: { cajas: Caja[]; arqueo?: Arqueo; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['arqueos']);
  const [form, setForm] = useState({
    caja_id: arqueo ? String(arqueo.caja.id) : '',
    fecha: arqueo ? String(arqueo.fecha).slice(0, 10) : new Date().toISOString().slice(0, 10),
    motivo: arqueo?.motivo ?? '',
  });

  // Catálogo de denominaciones ARS (v1.42).
  const { data: catalogo } = useApi<Denominacion[]>(
    ['denominaciones-catalogo', 'ARS'],
    '/api/erp/caja/denominaciones-catalogo?moneda=ARS',
  );

  const [cantidades, setCantidades] = useState<Record<string, string>>({});
  useEffect(() => {
    if (catalogo && Object.keys(cantidades).length === 0) {
      const init: Record<string, string> = {};
      catalogo.forEach((d) => { init[String(d.valor)] = ''; });
      // v1.51 — pre-poblar la grilla con las denominaciones del arqueo a editar
      // (las keys de la grilla son String(valor) del catálogo, ej "1000.00").
      (arqueo?.denominaciones ?? []).forEach((d) => {
        const cat = catalogo.find((c) => Number(c.valor) === Number(d.valor_billete));
        if (cat) init[String(cat.valor)] = String(d.cantidad);
      });
      setCantidades(init);
    }
  }, [catalogo, cantidades, arqueo]);

  const saldoFisicoCalc = useMemo(() => {
    return (catalogo ?? []).reduce((acc, d) => {
      const cant = Number(cantidades[String(d.valor)] ?? 0) || 0;
      return acc + Number(d.valor) * cant;
    }, 0);
  }, [catalogo, cantidades]);

  const cajaSel = cajas.find((c) => String(c.id) === form.caja_id);
  const saldoTeorico = cajaSel?.saldo_actual ?? null;
  const dif = saldoTeorico !== null ? Number(saldoFisicoCalc) - Number(saldoTeorico) : null;
  const necesitaMotivo = dif !== null && Math.abs(dif) > 0.01;

  const m = useApiMutation<Arqueo, Record<string, unknown>>(
    (vars) => arqueo
      ? api.put(`/api/erp/caja/arqueos/${arqueo.id}`, vars)
      : api.post('/api/erp/caja/arqueo', vars),
    {
      onSuccess: (data) => {
        const estado = (data as Arqueo)?.estado ?? '';
        if (estado === 'PENDIENTE_AUTORIZACION') {
          toast.info(
            'Arqueo guardado — pendiente de autorización',
            `La diferencia supera $1 (tolerancia). Un supervisor debe resolverlo.`,
          );
        } else if (estado === 'CERRADO_CON_AJUSTE') {
          toast.success(arqueo ? 'Arqueo actualizado' : 'Arqueo registrado', 'Auto-ajuste por diferencia ≤ $1 con asiento contable.');
        } else {
          toast.success(arqueo ? 'Arqueo actualizado' : 'Arqueo registrado');
        }
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo registrar', errorMessage(e)),
    }
  );

  const denominacionesPayload = useMemo(() => {
    return (catalogo ?? [])
      .map((d) => ({ valor: Number(d.valor), cantidad: Number(cantidades[String(d.valor)] ?? 0) || 0 }))
      .filter((d) => d.cantidad > 0);
  }, [catalogo, cantidades]);

  const valid = form.caja_id && form.fecha && saldoFisicoCalc >= 0
    && (!necesitaMotivo || (form.motivo.trim().length >= 10));

  return (
    <Modal
      open
      onClose={onClose}
      title={arqueo ? `Editar arqueo #${arqueo.id}` : 'Registrar arqueo de caja'}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              caja_id: Number(form.caja_id),
              fecha: form.fecha,
              saldo_fisico: Number(saldoFisicoCalc.toFixed(2)),
              motivo: form.motivo || undefined,
              denominaciones: denominacionesPayload.length ? denominacionesPayload : undefined,
            })}>
            {m.isPending ? 'Guardando…' : arqueo ? 'Guardar cambios' : 'Confirmar arqueo'}
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-3">
        <SelectField label="Caja" required value={form.caja_id}
          onChange={(e) => setForm({ ...form, caja_id: e.target.value })}
          options={cajas.map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))} placeholder="Elegí…" />
        <Field label="Fecha" type="date" required value={form.fecha}
          onChange={(e) => setForm({ ...form, fecha: e.target.value })} />
      </div>

      {saldoTeorico !== null && (
        <div className="mt-3 bg-surface-row border border-line rounded-md p-3 text-[12px] text-ink-2">
          Saldo teórico del sistema: <strong className="tabular-nums">{fmtMoney(saldoTeorico)}</strong>
        </div>
      )}

      <div className="mt-3">
        <div className="text-[11.5px] font-semibold text-ink-2 mb-1">Grilla billete a billete (ARS)</div>
        <div className="border border-line rounded-md overflow-hidden">
          <table className="w-full text-[12.5px]">
            <thead className="bg-surface-row">
              <tr className="text-left">
                <th className="px-2 py-1.5">Denominación</th>
                <th className="px-2 py-1.5 text-right">Cantidad</th>
                <th className="px-2 py-1.5 text-right">Subtotal</th>
              </tr>
            </thead>
            <tbody>
              {(catalogo ?? []).map((d) => {
                const k = String(d.valor);
                const cant = Number(cantidades[k] ?? 0) || 0;
                const sub = Number(d.valor) * cant;
                return (
                  <tr key={d.id} className="border-t border-line">
                    <td className="px-2 py-1 tabular-nums">{fmtMoney(d.valor)} <span className="text-ink-3">{d.descripcion}</span></td>
                    <td className="px-2 py-1 text-right">
                      <input type="number" min={0} step={1}
                        className="w-24 text-right border border-line rounded-md px-2 py-1 tabular-nums focus:outline-none focus:border-azure"
                        value={cantidades[k] ?? ''}
                        onChange={(e) => setCantidades({ ...cantidades, [k]: e.target.value })} />
                    </td>
                    <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(sub)}</td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot className="bg-surface-row">
              <tr>
                <td className="px-2 py-1.5 font-semibold">Saldo físico contado</td>
                <td></td>
                <td className="px-2 py-1.5 text-right font-semibold tabular-nums">{fmtMoney(saldoFisicoCalc)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      {dif !== null && (
        <div className="mt-3 grid grid-cols-2 gap-3">
          <div className="block">
            <label className="text-[11.5px] font-semibold text-ink-2 mb-1 block">Diferencia (físico - teórico)</label>
            <div className={`px-3 py-2 rounded-md border text-[12.5px] tabular-nums font-medium ${
              Math.abs(dif) < 0.01
                ? 'border-success/40 bg-success-bg/30 text-success'
                : Math.abs(dif) <= 1.0
                ? 'border-info/40 bg-info-bg/30 text-info'
                : 'border-warning/40 bg-warning-bg/40 text-warning'
            }`}>
              {fmtMoney(dif)}
            </div>
            <div className="mt-1 text-[11px] text-ink-3">
              {Math.abs(dif) < 0.01 ? 'Cierra OK — sin asiento.'
                : Math.abs(dif) <= 1.0 ? 'Auto-ajuste ≤ $1 — genera asiento RN-23 al confirmar.'
                : 'Diferencia > $1 — queda en PENDIENTE_AUTORIZACION para supervisor.'}
            </div>
          </div>
          <TextareaField label={`Motivo${necesitaMotivo ? ' *' : ''}`}
            value={form.motivo} rows={3}
            onChange={(e) => setForm({ ...form, motivo: e.target.value })}
            hint={necesitaMotivo ? 'Mínimo 10 caracteres (requerido si hay diferencia ≠ 0).' : 'Opcional.'} />
        </div>
      )}

      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}
