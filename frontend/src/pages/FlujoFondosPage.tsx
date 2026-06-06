import { useMemo, useState } from 'react';
import { Activity, RefreshCw, Plus, Copy } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { fmtMoney } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Escenario = {
  id: number; nombre: string; tipo: string; anio: number;
  estado: string; descripcion?: string | null; es_default: number | boolean;
};

type Categoria = {
  id: number; codigo: string; nombre: string; tipo: 'INGRESO' | 'EGRESO';
  parent_id: number | null; nivel: number; orden_presentacion: number;
};

type Periodo = { key: string; label: string; fecha_desde: string; fecha_hasta: string };

type Celda = { proy: number; real: number | null; override_manual: boolean; override_count: number };

type MatrizResp = {
  escenario: Escenario;
  categorias: Categoria[];
  periodos: Periodo[];
  celdas: Record<string, Celda>;
};

const ANIO_ACTUAL = new Date().getFullYear();

export function FlujoFondosPage() {
  const [escenarioId, setEscenarioId] = useState<string>('');
  const [anio, setAnio] = useState<number>(ANIO_ACTUAL);
  const [granularidad, setGranularidad] = useState<'DIA' | 'SEMANA' | 'MES'>('MES');
  const [crearOpen, setCrearOpen] = useState(false);
  const [clonarOpen, setClonarOpen] = useState(false);
  const [override, setOverride] = useState<{ categoria: Categoria; periodo: Periodo; actual: number } | null>(null);
  const toast = useToast();
  const invalidate = useInvalidate(['flujo-matriz'], ['flujo-escenarios']);

  const { data: escenarios } = useApi<Escenario[]>(
    ['flujo-escenarios', anio],
    `/api/erp/flujo-fondos/escenarios?empresa_id=1&anio=${anio}`,
  );

  const { data: matriz, isLoading, error } = useApi<MatrizResp>(
    ['flujo-matriz', escenarioId, granularidad],
    escenarioId
      ? `/api/erp/flujo-fondos/${escenarioId}/matriz?granularidad=${granularidad}`
      : '',
    { enabled: !!escenarioId },
  );

  const recalc = useApiMutation<{ insertados_calendario: number }>(
    () => api.post(`/api/erp/flujo-fondos/${escenarioId}/recalcular`),
    {
      onSuccess: (d) => { toast.success('Recalculado', `${d.insertados_calendario} líneas auto-calendario`); invalidate(); },
      onError: (e) => toast.error('No se pudo recalcular', errorMessage(e)),
    },
  );

  const { padres, hijos } = useMemo(() => {
    const padres = (matriz?.categorias ?? []).filter((c) => c.parent_id === null);
    const hijos: Record<number, Categoria[]> = {};
    (matriz?.categorias ?? []).forEach((c) => {
      if (c.parent_id !== null) (hijos[c.parent_id] ??= []).push(c);
    });
    return { padres, hijos };
  }, [matriz]);

  const cellOf = (categoriaId: number, key: string): Celda | undefined =>
    matriz?.celdas[`${categoriaId}::${key}`];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={
            <div className="flex items-center gap-2">
              <Activity className="w-4 h-4 text-azure" /> Flujo de Fondos
            </div>
          }
          actions={
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setCrearOpen(true)}><Plus className="w-3 h-3" /> Nuevo escenario</Button>
              {escenarioId && <>
                <Button variant="secondary" onClick={() => setClonarOpen(true)}><Copy className="w-3 h-3" /> Clonar</Button>
                <Button variant="primary" disabled={recalc.isPending} onClick={() => recalc.mutate(undefined as never)}>
                  <RefreshCw className="w-3 h-3" /> {recalc.isPending ? 'Recalculando…' : 'Recalcular'}
                </Button>
              </>}
            </div>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <Field label="Año" type="number" value={String(anio)}
              onChange={(e) => setAnio(Number(e.target.value) || ANIO_ACTUAL)}
              containerClassName="w-[100px]" />
            <SelectField label="Escenario" value={escenarioId}
              onChange={(e) => setEscenarioId(e.target.value)}
              containerClassName="w-[280px]" placeholder="Elegí escenario…"
              options={(escenarios ?? []).map((e) => ({ value: e.id, label: `${e.nombre} (${e.tipo})` }))} />
            <SelectField label="Granularidad" value={granularidad}
              onChange={(e) => setGranularidad(e.target.value as typeof granularidad)}
              containerClassName="w-[160px]"
              options={[
                { value: 'MES', label: 'Mensual' },
                { value: 'SEMANA', label: 'Semanal' },
                { value: 'DIA', label: 'Diaria' },
              ]} />
          </div>

          {error && <FormError error={errorMessage(error)} />}
          {!escenarioId && <div className="text-ink-3 text-[12.5px] py-6 text-center">Elegí un escenario para ver la matriz.</div>}
          {escenarioId && isLoading && <div className="text-ink-3 text-[12.5px]">Cargando matriz…</div>}

          {matriz && (
            <div className="overflow-x-auto border border-line rounded-md">
              <table className="text-[11.5px] min-w-full">
                <thead className="bg-surface-row sticky top-0">
                  <tr>
                    <th className="px-2 py-1.5 text-left whitespace-nowrap sticky left-0 bg-surface-row z-10">Categoría</th>
                    {matriz.periodos.map((p) => (
                      <th key={p.key} className="px-2 py-1.5 text-right whitespace-nowrap">{p.label}</th>
                    ))}
                    <th className="px-2 py-1.5 text-right whitespace-nowrap">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {padres.map((padre) => {
                    const subs = hijos[padre.id] ?? [];
                    return (
                      <FlujoFila key={padre.id}
                        padre={padre} hijos={subs}
                        periodos={matriz.periodos}
                        cellOf={cellOf}
                        onOverride={(categoria, periodo, actual) => setOverride({ categoria, periodo, actual })}
                      />
                    );
                  })}
                  <FlujoFilaTotal categorias={matriz.categorias} periodos={matriz.periodos} cellOf={cellOf} />
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      {crearOpen && <CrearEscenarioModal anio={anio} onClose={() => { setCrearOpen(false); invalidate(); }} />}
      {clonarOpen && escenarioId && (
        <ClonarEscenarioModal escenarioId={Number(escenarioId)} anio={anio}
          onClose={() => { setClonarOpen(false); invalidate(); }} />
      )}
      {override && escenarioId && (
        <OverrideModal escenarioId={Number(escenarioId)} target={override}
          onClose={() => { setOverride(null); invalidate(); }} />
      )}
    </div>
  );
}

function FlujoFila({
  padre, hijos, periodos, cellOf, onOverride,
}: {
  padre: Categoria; hijos: Categoria[]; periodos: Periodo[];
  cellOf: (categoriaId: number, key: string) => Celda | undefined;
  onOverride: (categoria: Categoria, periodo: Periodo, actual: number) => void;
}) {
  const [open, setOpen] = useState(false);
  const totalsParent: Record<string, number> = {};
  let totalPadre = 0;
  periodos.forEach((p) => {
    let acc = 0;
    hijos.forEach((h) => { acc += cellOf(h.id, p.key)?.proy ?? 0; });
    // Si no tiene hijos, usa propia celda.
    if (hijos.length === 0) acc = cellOf(padre.id, p.key)?.proy ?? 0;
    totalsParent[p.key] = acc;
    totalPadre += acc;
  });

  return (
    <>
      <tr className={`border-t border-line font-semibold ${padre.tipo === 'INGRESO' ? 'bg-success-bg/30' : 'bg-danger-bg/20'}`}>
        <td className="px-2 py-1 sticky left-0 bg-inherit z-10">
          <button onClick={() => setOpen(!open)} className="text-azure underline-offset-2 hover:underline">
            {open ? '▾' : '▸'} {padre.nombre}
          </button>
        </td>
        {periodos.map((p) => (
          <td key={p.key} className="px-2 py-1 text-right tabular-nums">{fmtMoney(totalsParent[p.key])}</td>
        ))}
        <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(totalPadre)}</td>
      </tr>
      {open && hijos.map((h) => (
        <tr key={h.id} className="border-t border-line hover:bg-surface-row">
          <td className="px-2 py-1 pl-6 sticky left-0 bg-surface-base hover:bg-surface-row z-10">{h.nombre}</td>
          {periodos.map((p) => {
            const c = cellOf(h.id, p.key);
            const proy = c?.proy ?? 0;
            return (
              <td key={p.key} className="px-2 py-1 text-right tabular-nums cursor-pointer hover:bg-info-bg/30"
                onClick={() => onOverride(h, p, proy)}>
                {fmtMoney(proy)}
                {c?.override_manual && <span title="Override manual" className="ml-1 text-info">✎</span>}
              </td>
            );
          })}
          <td className="px-2 py-1 text-right tabular-nums">
            {fmtMoney(periodos.reduce((acc, p) => acc + (cellOf(h.id, p.key)?.proy ?? 0), 0))}
          </td>
        </tr>
      ))}
    </>
  );
}

function FlujoFilaTotal({
  categorias, periodos, cellOf,
}: {
  categorias: Categoria[]; periodos: Periodo[];
  cellOf: (cId: number, key: string) => Celda | undefined;
}) {
  const totals: Record<string, number> = {};
  let totalGeneral = 0;
  periodos.forEach((p) => {
    let acc = 0;
    categorias.forEach((c) => {
      if (c.parent_id === null) {
        const hijos = categorias.filter((h) => h.parent_id === c.id);
        const subs = hijos.length
          ? hijos.reduce((a, h) => a + (cellOf(h.id, p.key)?.proy ?? 0), 0)
          : (cellOf(c.id, p.key)?.proy ?? 0);
        acc += c.tipo === 'INGRESO' ? subs : subs;
      }
    });
    totals[p.key] = acc;
    totalGeneral += acc;
  });

  return (
    <tr className="border-t-2 border-line font-bold bg-surface-row">
      <td className="px-2 py-1.5 sticky left-0 bg-surface-row z-10">Flujo neto proyectado</td>
      {periodos.map((p) => (
        <td key={p.key} className={`px-2 py-1.5 text-right tabular-nums ${totals[p.key] >= 0 ? 'text-success' : 'text-danger'}`}>
          {fmtMoney(totals[p.key])}
        </td>
      ))}
      <td className={`px-2 py-1.5 text-right tabular-nums ${totalGeneral >= 0 ? 'text-success' : 'text-danger'}`}>{fmtMoney(totalGeneral)}</td>
    </tr>
  );
}

function CrearEscenarioModal({ anio, onClose }: { anio: number; onClose: () => void }) {
  const toast = useToast();
  const [form, setForm] = useState({ nombre: 'Realista', tipo: 'REALISTA', anio: String(anio), descripcion: '' });

  const m = useApiMutation<{ id: number }, Record<string, unknown>>(
    (v) => api.post('/api/erp/flujo-fondos/escenarios', v),
    {
      onSuccess: () => { toast.success('Escenario creado'); onClose(); },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    },
  );
  const valid = form.nombre && form.tipo && form.anio;

  return (
    <Modal open onClose={onClose} title="Nuevo escenario" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              empresa_id: 1, nombre: form.nombre, tipo: form.tipo, anio: Number(form.anio),
              descripcion: form.descripcion || undefined,
            })}>{m.isPending ? 'Guardando…' : 'Crear'}</Button>
        </>
      }>
      <div className="space-y-3">
        <Field label="Nombre *" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} />
        <SelectField label="Tipo *" value={form.tipo} onChange={(e) => setForm({ ...form, tipo: e.target.value })}
          options={[
            { value: 'REALISTA', label: 'Realista' },
            { value: 'OPTIMISTA', label: 'Optimista' },
            { value: 'PESIMISTA', label: 'Pesimista' },
            { value: 'CUSTOM', label: 'Custom' },
          ]} />
        <Field label="Año *" type="number" value={form.anio} onChange={(e) => setForm({ ...form, anio: e.target.value })} />
        <TextareaField label="Descripción" value={form.descripcion} rows={2}
          onChange={(e) => setForm({ ...form, descripcion: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function ClonarEscenarioModal({ escenarioId, anio, onClose }: { escenarioId: number; anio: number; onClose: () => void }) {
  const toast = useToast();
  const [form, setForm] = useState({ nombre: '', tipo: 'OPTIMISTA', anio: String(anio), factor: '1.10' });

  const m = useApiMutation<{ id: number }, Record<string, unknown>>(
    (v) => api.post(`/api/erp/flujo-fondos/escenarios/${escenarioId}/clonar`, v),
    {
      onSuccess: () => { toast.success('Escenario clonado'); onClose(); },
      onError: (e) => toast.error('No se pudo clonar', errorMessage(e)),
    },
  );
  const valid = form.nombre && form.factor;

  return (
    <Modal open onClose={onClose} title="Clonar escenario" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              nombre: form.nombre, tipo: form.tipo, anio: Number(form.anio),
              factor_proyectado: Number(form.factor),
            })}>{m.isPending ? 'Clonando…' : 'Clonar'}</Button>
        </>
      }>
      <div className="space-y-3">
        <Field label="Nombre nuevo *" value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })} placeholder="Ej: Optimista 2026" />
        <SelectField label="Tipo" value={form.tipo} onChange={(e) => setForm({ ...form, tipo: e.target.value })}
          options={[
            { value: 'OPTIMISTA', label: 'Optimista' },
            { value: 'PESIMISTA', label: 'Pesimista' },
            { value: 'CUSTOM', label: 'Custom' },
          ]} />
        <Field label="Año" type="number" value={form.anio} onChange={(e) => setForm({ ...form, anio: e.target.value })} />
        <Field label="Factor sobre proyectado" type="number" step="0.01" value={form.factor}
          onChange={(e) => setForm({ ...form, factor: e.target.value })}
          hint="1.10 = +10%, 0.85 = -15%" />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function OverrideModal({
  escenarioId, target, onClose,
}: {
  escenarioId: number;
  target: { categoria: Categoria; periodo: Periodo; actual: number };
  onClose: () => void;
}) {
  const toast = useToast();
  const [valor, setValor] = useState(String(target.actual));
  const [motivo, setMotivo] = useState('');

  const m = useApiMutation<{ linea_id: number }, Record<string, unknown>>(
    (v) => api.patch(`/api/erp/flujo-fondos/${escenarioId}/celda`, v),
    {
      onSuccess: () => { toast.success('Override aplicado'); onClose(); },
      onError: (e) => toast.error('No se pudo guardar', errorMessage(e)),
    },
  );

  const valid = motivo.trim().length >= 10 && valor !== '';
  const diff = Number(valor) - target.actual;

  return (
    <Modal open onClose={onClose} size="md"
      title={`Override: ${target.categoria.nombre} · ${target.periodo.label}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              categoria_id: target.categoria.id,
              periodo_key: target.periodo.key,
              nuevo_proyectado: Number(valor),
              motivo,
            })}>{m.isPending ? 'Guardando…' : 'Aplicar override'}</Button>
        </>
      }>
      <div className="space-y-3 text-[12.5px]">
        <div className="bg-surface-row border border-line rounded-md p-3 grid grid-cols-3 gap-3">
          <div><div className="text-ink-3 text-[11px]">Actual</div><div className="tabular-nums">{fmtMoney(target.actual)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Nuevo</div><div className="tabular-nums">{fmtMoney(Number(valor) || 0)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Δ</div>
            <Badge variant={diff > 0 ? 'success' : diff < 0 ? 'danger' : 'neutral'}>{fmtMoney(diff)}</Badge>
          </div>
        </div>
        <Field label="Nuevo proyectado *" type="number" step="0.01" value={valor}
          onChange={(e) => setValor(e.target.value)} />
        <TextareaField label="Motivo *" value={motivo} rows={3}
          onChange={(e) => setMotivo(e.target.value)}
          hint="Mínimo 10 caracteres. Queda en el historial." />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
