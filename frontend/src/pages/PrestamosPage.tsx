import { useState } from 'react';
import { CreditCard, Plus, FileText, Upload, Loader2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { fmtMoney, fmtDate } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Auxiliar = { id: number; codigo: string; nombre: string };

type Prestamo = {
  id: number; tipo: 'OTORGADO' | 'RECIBIDO'; nombre: string;
  capital: number | string; moneda: string;
  tasa_mensual: number | string | null; tasa_nominal_anual: number | string | null;
  sistema_amortizacion: 'FRANCES' | 'ALEMAN' | 'AMERICANO' | 'BULLET';
  plazo_cuotas: number; estado: 'VIGENTE' | 'CANCELADO' | 'INCOBRABLE';
  fecha_otorgamiento: string; fecha_primera_cuota: string;
  contraparte_codigo?: string | null; contraparte_nombre?: string | null;
  capital_adeudado: number | string | null;
  cuotas_pagadas: number;
  proxima_fecha: string | null;
  proxima_cuota: number | string | null;
  observaciones?: string | null;
};

type Cuota = {
  id: number; numero_cuota: number; fecha_vencimiento: string;
  capital: number | string; interes: number | string; total_cuota: number | string;
  capital_adeudado_post: number | string;
  estado: 'PENDIENTE' | 'PAGADA' | 'VENCIDA';
  fecha_pago: string | null; importe_pagado: number | string | null;
};

type Detalle = { prestamo: Prestamo; cuotas: Cuota[] };

export function PrestamosPage() {
  const [tipo, setTipo] = useState<'TODOS' | 'OTORGADO' | 'RECIBIDO'>('TODOS');
  const [estado, setEstado] = useState<'VIGENTE' | 'CANCELADO' | 'INCOBRABLE' | 'TODOS'>('VIGENTE');
  const [crearOpen, setCrearOpen] = useState(false);
  const [importarOpen, setImportarOpen] = useState(false);
  const [detalleId, setDetalleId] = useState<number | null>(null);

  const qs = new URLSearchParams({ empresa_id: '1', estado });
  if (tipo !== 'TODOS') qs.set('tipo', tipo);

  const { data, isLoading, error } = useApi<Prestamo[]>(
    ['prestamos', tipo, estado],
    `/api/erp/prestamos?${qs}`,
  );

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><CreditCard className="w-4 h-4 text-azure" /> Préstamos</div>}
          actions={
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setImportarOpen(true)}>
                <Upload className="w-3 h-3" /> Importar plan AFIP (PDF)
              </Button>
              <Button variant="primary" onClick={() => setCrearOpen(true)}>
                <Plus className="w-3 h-3" /> Nuevo préstamo
              </Button>
            </div>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex gap-3">
            <SelectField label="Tipo" value={tipo} onChange={(e) => setTipo(e.target.value as typeof tipo)}
              containerClassName="w-[180px]"
              options={[
                { value: 'TODOS', label: 'Todos' },
                { value: 'OTORGADO', label: 'Otorgados' },
                { value: 'RECIBIDO', label: 'Recibidos' },
              ]} />
            <SelectField label="Estado" value={estado} onChange={(e) => setEstado(e.target.value as typeof estado)}
              containerClassName="w-[180px]"
              options={[
                { value: 'VIGENTE', label: 'Vigentes' },
                { value: 'CANCELADO', label: 'Cancelados' },
                { value: 'INCOBRABLE', label: 'Incobrables' },
                { value: 'TODOS', label: 'Todos' },
              ]} />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
          <div className="space-y-2">
            {(data ?? []).map((p) => (
              <PrestamoCard key={p.id} prestamo={p} onOpen={() => setDetalleId(p.id)} />
            ))}
            {!isLoading && (data ?? []).length === 0 && (
              <div className="text-ink-3 text-[12.5px] py-6 text-center">Sin préstamos.</div>
            )}
          </div>
        </CardBody>
      </Card>

      {crearOpen && <CrearPrestamoModal onClose={() => setCrearOpen(false)} />}
      {importarOpen && <ImportarPlanAfipModal onClose={() => setImportarOpen(false)} />}
      {detalleId && <DetalleModal prestamoId={detalleId} onClose={() => setDetalleId(null)} />}
    </div>
  );
}

function PrestamoCard({ prestamo, onOpen }: { prestamo: Prestamo; onOpen: () => void }) {
  const pct = prestamo.plazo_cuotas > 0 ? Math.round((prestamo.cuotas_pagadas / prestamo.plazo_cuotas) * 100) : 0;
  return (
    <div className="border border-line rounded-md p-3 flex items-center justify-between gap-3">
      <div className="flex-1 grid grid-cols-2 md:grid-cols-5 gap-3 text-[12.5px]">
        <div>
          <div className="text-ink-3 text-[11px]">{prestamo.tipo} · {prestamo.sistema_amortizacion}</div>
          <div className="font-medium">{prestamo.nombre}</div>
          <div className="text-ink-3 text-[11px]">{prestamo.contraparte_codigo} {prestamo.contraparte_nombre}</div>
        </div>
        <div>
          <div className="text-ink-3 text-[11px]">Capital</div>
          <div className="tabular-nums">{fmtMoney(prestamo.capital)} {prestamo.moneda}</div>
        </div>
        <div>
          <div className="text-ink-3 text-[11px]">Adeudado</div>
          <div className="tabular-nums">{fmtMoney(prestamo.capital_adeudado ?? 0)}</div>
        </div>
        <div>
          <div className="text-ink-3 text-[11px]">Próxima cuota</div>
          <div className="tabular-nums">{prestamo.proxima_cuota ? fmtMoney(prestamo.proxima_cuota) : '—'}</div>
          <div className="text-ink-3 text-[11px]">{prestamo.proxima_fecha ? fmtDate(prestamo.proxima_fecha) : ''}</div>
        </div>
        <div>
          <div className="text-ink-3 text-[11px]">Cuotas {prestamo.cuotas_pagadas}/{prestamo.plazo_cuotas}</div>
          <div className="w-full h-2 bg-surface-row rounded">
            <div className="h-2 bg-success rounded" style={{ width: `${pct}%` }} />
          </div>
          <div className="text-ink-3 text-[11px] mt-1">{pct}%</div>
        </div>
      </div>
      <Button variant="secondary" onClick={onOpen}><FileText className="w-3 h-3" /> Cronograma</Button>
    </div>
  );
}

function CrearPrestamoModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['prestamos']);
  const [form, setForm] = useState({
    tipo: 'RECIBIDO', nombre: '', contraparte_auxiliar_id: '',
    capital: '', moneda: 'ARS',
    tasa_mensual: '', tasa_nominal_anual: '',
    sistema_amortizacion: 'FRANCES', plazo_cuotas: '12',
    fecha_otorgamiento: new Date().toISOString().slice(0, 10),
    fecha_primera_cuota: '',
    observaciones: '',
  });

  // Lookup auxiliares
  const { data: auxiliares } = useApi<Auxiliar[]>(
    ['auxiliares', 'lookup-prestamos'],
    '/api/erp/auxiliares',
  );

  const m = useApiMutation<{ id: number }, Record<string, unknown>>(
    (v) => api.post('/api/erp/prestamos', v),
    {
      onSuccess: () => { toast.success('Préstamo creado con cronograma'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    },
  );

  const valid = form.nombre && form.contraparte_auxiliar_id && form.capital
    && form.plazo_cuotas && form.fecha_otorgamiento && form.fecha_primera_cuota;

  return (
    <Modal open onClose={onClose} title="Nuevo préstamo" size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              empresa_id: 1,
              tipo: form.tipo,
              contraparte_auxiliar_id: Number(form.contraparte_auxiliar_id),
              nombre: form.nombre,
              capital: Number(form.capital),
              moneda: form.moneda,
              tasa_mensual: form.tasa_mensual ? Number(form.tasa_mensual) : undefined,
              tasa_nominal_anual: form.tasa_nominal_anual ? Number(form.tasa_nominal_anual) : undefined,
              sistema_amortizacion: form.sistema_amortizacion,
              plazo_cuotas: Number(form.plazo_cuotas),
              fecha_otorgamiento: form.fecha_otorgamiento,
              fecha_primera_cuota: form.fecha_primera_cuota,
              observaciones: form.observaciones || undefined,
            })}>{m.isPending ? 'Generando cronograma…' : 'Crear con cronograma'}</Button>
        </>
      }>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <SelectField label="Tipo *" value={form.tipo} onChange={(e) => setForm({ ...form, tipo: e.target.value })}
            options={[
              { value: 'RECIBIDO', label: 'Recibido (nos prestaron)' },
              { value: 'OTORGADO', label: 'Otorgado (prestamos)' },
            ]} />
          <Field label="Nombre *" value={form.nombre}
            onChange={(e) => setForm({ ...form, nombre: e.target.value })}
            placeholder="Ej: Préstamo Joel, Prtmo Bco Galicia" />
        </div>
        <SelectField label="Contraparte *" value={form.contraparte_auxiliar_id}
          onChange={(e) => setForm({ ...form, contraparte_auxiliar_id: e.target.value })}
          placeholder="Elegí auxiliar…"
          options={(auxiliares ?? []).map((a) => ({ value: a.id, label: `${a.codigo} ${a.nombre}` }))} />
        <div className="grid grid-cols-3 gap-3">
          <Field label="Capital *" type="number" step="0.01" value={form.capital}
            onChange={(e) => setForm({ ...form, capital: e.target.value })} />
          <SelectField label="Moneda" value={form.moneda}
            onChange={(e) => setForm({ ...form, moneda: e.target.value })}
            options={[{ value: 'ARS', label: 'ARS' }, { value: 'USD', label: 'USD' }]} />
          <SelectField label="Sistema *" value={form.sistema_amortizacion}
            onChange={(e) => setForm({ ...form, sistema_amortizacion: e.target.value })}
            options={[
              { value: 'FRANCES', label: 'Francés (cuota fija)' },
              { value: 'ALEMAN', label: 'Alemán (capital fijo)' },
              { value: 'AMERICANO', label: 'Americano (interés mensual + capital al final)' },
              { value: 'BULLET', label: 'Bullet (todo al vencimiento)' },
            ]} />
        </div>
        <div className="grid grid-cols-3 gap-3">
          <Field label="Tasa mensual (%)" type="number" step="0.0001" value={form.tasa_mensual}
            onChange={(e) => setForm({ ...form, tasa_mensual: e.target.value, tasa_nominal_anual: '' })}
            hint="O TNA (no ambas)" />
          <Field label="TNA (%)" type="number" step="0.0001" value={form.tasa_nominal_anual}
            onChange={(e) => setForm({ ...form, tasa_nominal_anual: e.target.value, tasa_mensual: '' })} />
          <Field label="Plazo (cuotas) *" type="number" value={form.plazo_cuotas}
            onChange={(e) => setForm({ ...form, plazo_cuotas: e.target.value })} />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Fecha otorgamiento *" type="date" value={form.fecha_otorgamiento}
            onChange={(e) => setForm({ ...form, fecha_otorgamiento: e.target.value })} />
          <Field label="Fecha 1ra cuota *" type="date" value={form.fecha_primera_cuota}
            onChange={(e) => setForm({ ...form, fecha_primera_cuota: e.target.value })} />
        </div>
        <TextareaField label="Observaciones" rows={2} value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

type PlanAfip = {
  numero_plan: string; cuit: string | null; nombre: string | null;
  fecha_consolidacion: string | null; total_capital: number; total_interes: number; total: number;
  cuotas: Array<{ numero: number; capital: number; interes: number; total: number; fecha_venc: string }>;
};

function ImportarPlanAfipModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['prestamos']);
  const [archivo, setArchivo] = useState<File | null>(null);
  const [plan, setPlan] = useState<PlanAfip | null>(null);

  const analizar = useApiMutation<PlanAfip, File>(
    (file) => { const fd = new FormData(); fd.append('archivo', file); return api.post('/api/erp/prestamos/plan-afip/analizar', fd); },
    {
      onSuccess: (plan) => setPlan(plan),
      onError: (e) => { setPlan(null); toast.error('No se pudo leer el PDF', errorMessage(e)); },
    },
  );
  const importar = useApiMutation<{ prestamo_id: number; numero_plan: string; cuotas: number }, File>(
    (file) => { const fd = new FormData(); fd.append('archivo', file); fd.append('empresa_id', '1'); return api.post('/api/erp/prestamos/plan-afip/importar', fd); },
    {
      onSuccess: (d) => { toast.success(`Plan ${d.numero_plan} cargado (${d.cuotas} cuotas)`); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo importar', errorMessage(e)),
    },
  );

  const onFile = (f: File | null) => {
    setArchivo(f); setPlan(null);
    if (f) analizar.mutate(f);
  };

  return (
    <Modal open onClose={onClose} title="Importar plan de facilidades ARCA/AFIP" size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!plan || !archivo || importar.isPending}
            onClick={() => archivo && importar.mutate(archivo)}>
            {importar.isPending ? 'Importando…' : 'Importar como préstamo'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <div className="text-[12px] text-ink-2">
          Subí el PDF "Mis Facilidades" que descargás de ARCA. Se carga como <strong>préstamo recibido</strong> con
          el cronograma del 1° vencimiento, contra la cuenta <code>2.1.3.13 Planes de Pago Fiscales</code>. Al pagar
          cada cuota podés generar el asiento eligiendo el medio de pago.
        </div>
        <label className="inline-flex items-center gap-2 cursor-pointer">
          <input type="file" accept="application/pdf" className="hidden"
            onChange={(e) => { const f = e.target.files?.[0] ?? null; onFile(f); e.currentTarget.value = ''; }} />
          <span className="px-3 py-1.5 bg-navy-700 text-white rounded text-[12px] inline-flex items-center gap-1">
            {analizar.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Upload className="w-3 h-3" />} Elegir PDF
          </span>
          {archivo && <span className="text-[11.5px] text-ink-2">{archivo.name}</span>}
        </label>

        {plan && (
          <>
            <div className="bg-surface-row border border-line rounded-md p-3 grid grid-cols-4 gap-3 text-[12.5px]">
              <div><div className="text-ink-3 text-[11px]">Plan</div><div className="font-medium">{plan.numero_plan}</div></div>
              <div><div className="text-ink-3 text-[11px]">CUIT</div><div className="tabular-nums">{plan.cuit ?? '—'}</div></div>
              <div><div className="text-ink-3 text-[11px]">Consolidación</div><div>{plan.fecha_consolidacion ? fmtDate(plan.fecha_consolidacion) : '—'}</div></div>
              <div><div className="text-ink-3 text-[11px]">Cuotas</div><div>{plan.cuotas.length}</div></div>
              <div className="col-span-2"><div className="text-ink-3 text-[11px]">Titular</div><div className="truncate">{plan.nombre ?? '—'}</div></div>
              <div><div className="text-ink-3 text-[11px]">Capital</div><div className="tabular-nums">{fmtMoney(plan.total_capital)}</div></div>
              <div><div className="text-ink-3 text-[11px]">Total</div><div className="tabular-nums font-medium">{fmtMoney(plan.total)}</div></div>
            </div>
            <div className="border border-line rounded-md overflow-hidden max-h-[300px] overflow-y-auto">
              <table className="w-full text-[12px]">
                <thead className="bg-surface-row sticky top-0"><tr className="text-left">
                  <th className="px-2 py-1.5">#</th><th className="px-2 py-1.5">1° Vencimiento</th>
                  <th className="px-2 py-1.5 text-right">Capital</th><th className="px-2 py-1.5 text-right">Interés</th>
                  <th className="px-2 py-1.5 text-right">Total</th>
                </tr></thead>
                <tbody>
                  {plan.cuotas.map((c) => (
                    <tr key={c.numero} className="border-t border-line">
                      <td className="px-2 py-1">{c.numero}</td>
                      <td className="px-2 py-1">{fmtDate(c.fecha_venc)}</td>
                      <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(c.capital)}</td>
                      <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(c.interes)}</td>
                      <td className="px-2 py-1 text-right tabular-nums font-medium">{fmtMoney(c.total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
        <FormError error={analizar.error ? errorMessage(analizar.error) : importar.error ? errorMessage(importar.error) : null} />
      </div>
    </Modal>
  );
}

function DetalleModal({ prestamoId, onClose }: { prestamoId: number; onClose: () => void }) {
  const [pagar, setPagar] = useState<Cuota | null>(null);
  const { data, isLoading } = useApi<Detalle>(
    ['prestamo-detalle', prestamoId],
    `/api/erp/prestamos/${prestamoId}`,
  );

  return (
    <Modal open onClose={onClose} size="lg"
      title={data ? `${data.prestamo.nombre} · ${data.prestamo.sistema_amortizacion}` : 'Cronograma'}
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}>
      {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
      {data && (
        <>
          <div className="bg-surface-row border border-line rounded-md p-3 grid grid-cols-4 gap-3 text-[12.5px] mb-3">
            <div><div className="text-ink-3 text-[11px]">Capital</div><div className="tabular-nums">{fmtMoney(data.prestamo.capital)} {data.prestamo.moneda}</div></div>
            <div><div className="text-ink-3 text-[11px]">Tasa</div><div>
              {data.prestamo.tasa_mensual ? `${Number(data.prestamo.tasa_mensual).toFixed(4)}% mensual` : null}
              {data.prestamo.tasa_nominal_anual ? `${Number(data.prestamo.tasa_nominal_anual).toFixed(2)}% TNA` : null}
              {!data.prestamo.tasa_mensual && !data.prestamo.tasa_nominal_anual ? 'sin interés' : null}
            </div></div>
            <div><div className="text-ink-3 text-[11px]">Plazo</div><div>{data.prestamo.plazo_cuotas} cuotas</div></div>
            <div><div className="text-ink-3 text-[11px]">Estado</div>
              <Badge variant={data.prestamo.estado === 'VIGENTE' ? 'success' : data.prestamo.estado === 'CANCELADO' ? 'neutral' : 'danger'}>
                {data.prestamo.estado}
              </Badge>
            </div>
          </div>
          <div className="border border-line rounded-md overflow-hidden max-h-[400px] overflow-y-auto">
            <table className="w-full text-[12.5px]">
              <thead className="bg-surface-row sticky top-0">
                <tr className="text-left">
                  <th className="px-2 py-1.5">#</th>
                  <th className="px-2 py-1.5">Vencimiento</th>
                  <th className="px-2 py-1.5 text-right">Capital</th>
                  <th className="px-2 py-1.5 text-right">Interés</th>
                  <th className="px-2 py-1.5 text-right">Total cuota</th>
                  <th className="px-2 py-1.5 text-right">Saldo post</th>
                  <th className="px-2 py-1.5">Estado</th>
                  <th className="px-2 py-1.5"></th>
                </tr>
              </thead>
              <tbody>
                {data.cuotas.map((c) => (
                  <tr key={c.id} className="border-t border-line">
                    <td className="px-2 py-1">{c.numero_cuota}</td>
                    <td className="px-2 py-1">{fmtDate(c.fecha_vencimiento)}</td>
                    <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(c.capital)}</td>
                    <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(c.interes)}</td>
                    <td className="px-2 py-1 text-right tabular-nums font-medium">{fmtMoney(c.total_cuota)}</td>
                    <td className="px-2 py-1 text-right tabular-nums text-ink-3">{fmtMoney(c.capital_adeudado_post)}</td>
                    <td className="px-2 py-1">
                      <Badge variant={c.estado === 'PAGADA' ? 'success' : c.estado === 'VENCIDA' ? 'danger' : 'neutral'}>
                        {c.estado}
                      </Badge>
                    </td>
                    <td className="px-2 py-1 text-right">
                      {c.estado !== 'PAGADA' && (
                        <Button variant="primary" onClick={() => setPagar(c)}>Pagar</Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
      {pagar && data && (
        <PagarCuotaModal prestamoId={prestamoId} cuota={pagar} tipo={data.prestamo.tipo}
          onClose={() => setPagar(null)} />
      )}
    </Modal>
  );
}

function PagarCuotaModal({
  prestamoId, cuota, tipo, onClose,
}: {
  prestamoId: number; cuota: Cuota;
  tipo: 'OTORGADO' | 'RECIBIDO';
  onClose: () => void;
}) {
  const toast = useToast();
  const invalidate = useInvalidate(['prestamo-detalle', prestamoId], ['prestamos']);
  const [form, setForm] = useState({
    fecha_pago: new Date().toISOString().slice(0, 10),
    importe_pagado: String(cuota.total_cuota),
    medio_pago_id: '',
    observaciones: '',
  });
  const { data: bancos = [] } = useApi<Array<{ id: number; nombre: string }>>(
    ['cuentas-bancarias'], '/api/erp/cuentas-bancarias',
  );

  const m = useApiMutation<{ ok: true }, Record<string, unknown>>(
    (v) => api.post(`/api/erp/prestamos/${prestamoId}/cuotas/${cuota.id}/pagar`, v),
    {
      onSuccess: () => { toast.success(tipo === 'RECIBIDO' ? 'Pago registrado' : 'Cobro registrado'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo registrar', errorMessage(e)),
    },
  );
  const valid = form.fecha_pago && Number(form.importe_pagado) > 0;

  return (
    <Modal open onClose={onClose} size="md"
      title={`${tipo === 'RECIBIDO' ? 'Pago' : 'Cobro'} cuota #${cuota.numero_cuota}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              fecha_pago: form.fecha_pago,
              importe_pagado: Number(form.importe_pagado),
              medio_pago_id: form.medio_pago_id ? Number(form.medio_pago_id) : undefined,
              observaciones: form.observaciones || undefined,
            })}>{m.isPending ? 'Guardando…' : 'Confirmar'}</Button>
        </>
      }>
      <div className="space-y-3">
        <div className="bg-surface-row border border-line rounded-md p-3 grid grid-cols-3 gap-3 text-[12.5px]">
          <div><div className="text-ink-3 text-[11px]">Capital</div><div className="tabular-nums">{fmtMoney(cuota.capital)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Interés</div><div className="tabular-nums">{fmtMoney(cuota.interes)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Total</div><div className="tabular-nums font-medium">{fmtMoney(cuota.total_cuota)}</div></div>
        </div>
        <Field label="Fecha de pago *" type="date" value={form.fecha_pago}
          onChange={(e) => setForm({ ...form, fecha_pago: e.target.value })} />
        <Field label="Importe pagado *" type="number" step="0.01" value={form.importe_pagado}
          onChange={(e) => setForm({ ...form, importe_pagado: e.target.value })} />
        <SelectField label={tipo === 'RECIBIDO' ? 'Medio de pago (genera asiento)' : 'Cuenta de cobro (genera asiento)'}
          value={form.medio_pago_id}
          onChange={(e) => setForm({ ...form, medio_pago_id: e.target.value })}
          options={[{ value: '', label: 'Sin asiento (solo seguimiento)' },
            ...bancos.map((b) => ({ value: b.id, label: b.nombre }))]}
          hint="Si elegís un medio, se genera el asiento: capital + intereses contra el banco." />
        <TextareaField label="Observaciones" rows={2} value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
