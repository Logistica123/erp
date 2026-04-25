import { useMemo, useState } from 'react';
import { Plus, Users, Eye, History } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Convenio = { id: number; codigo: string; nombre: string };
type Categoria = { id: number; convenio_id: number; codigo: string; nombre: string; nivel_jerarquia: number };

type Empleado = {
  id: number;
  legajo: string;
  cuil: string | null;
  cuit: string | null;
  apellido: string;
  nombre: string;
  dni: string | null;
  fecha_ingreso: string;
  fecha_egreso: string | null;
  categoria_id: number | null;
  convenio_id: number | null;
  categoria?: Categoria | null;
  convenio?: Convenio | null;
  regimen: 'FORMAL_PURO' | 'MIXTO' | 'EFECTIVO_PURO' | 'MONOTRIBUTISTA';
  jornada_formal_pct: number;
  es_vendedor: boolean;
  paga_sac: boolean;
  cbu: string | null;
  banco: string | null;
  email: string | null;
  telefono: string | null;
  activo: boolean;
};

const REGIMENES = ['FORMAL_PURO', 'MIXTO', 'EFECTIVO_PURO', 'MONOTRIBUTISTA'];

function regimenColor(r: Empleado['regimen']): 'success' | 'info' | 'warning' | 'default' {
  switch (r) {
    case 'FORMAL_PURO': return 'success';
    case 'MIXTO': return 'info';
    case 'EFECTIVO_PURO': return 'warning';
    case 'MONOTRIBUTISTA': return 'default';
  }
}

export function EmpleadosPage() {
  const [filtros, setFiltros] = useState({ q: '', estado: '', regimen: '', convenio_id: '' });
  const [page, setPage] = useState(1);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [verEmp, setVerEmp] = useState<Empleado | null>(null);

  const { data: convenios } = useApi<Convenio[]>(['sueldos-convenios'], '/api/erp/sueldos/convenios');

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (filtros.q) p.set('q', filtros.q);
    if (filtros.estado) p.set('estado', filtros.estado);
    if (filtros.regimen) p.set('regimen', filtros.regimen);
    if (filtros.convenio_id) p.set('convenio_id', filtros.convenio_id);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [filtros, page]);

  const { data, isLoading, error } = useApi<Paginator<Empleado>>(
    ['sueldos-empleados', qs],
    `/api/erp/sueldos/empleados${qs ? `?${qs}` : ''}`
  );

  const cols: Column<Empleado>[] = [
    { key: 'legajo', header: 'Legajo', width: '110px',
      render: (r) => <code className="text-[12px]">{r.legajo}</code> },
    { key: 'nombre', header: 'Apellido y nombre',
      render: (r) => (
        <div>
          <div>{r.apellido}, {r.nombre}</div>
          {r.cuil && <div className="text-[10.5px] text-ink-muted">CUIL {r.cuil}</div>}
        </div>
      ) },
    { key: 'regimen', header: 'Régimen', width: '130px',
      render: (r) => <Badge variant={regimenColor(r.regimen)}>{r.regimen}</Badge> },
    { key: 'categoria', header: 'Categoría',
      render: (r) => r.categoria?.nombre ?? <span className="text-ink-muted">—</span> },
    { key: 'fecha_ingreso', header: 'Ingreso', width: '95px',
      render: (r) => fmtDate(r.fecha_ingreso) },
    { key: 'estado', header: 'Estado', width: '90px',
      render: (r) => r.fecha_egreso
        ? <Badge variant="danger">BAJA</Badge>
        : r.activo
          ? <Badge variant="success">ACTIVO</Badge>
          : <Badge variant="neutral">INACTIVO</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '70px',
      render: (r) => (
        <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setVerEmp(r); }}>
          <Eye className="w-3 h-3" />
        </Button>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Users className="w-4 h-4 text-azure" /> Padrón de empleados</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Alta empleado
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <Field label="Buscar" value={filtros.q}
              onChange={(e) => { setFiltros({ ...filtros, q: e.target.value }); setPage(1); }}
              placeholder="legajo / apellido / CUIL / DNI"
              containerClassName="w-[260px]" />
            <SelectField label="Régimen" value={filtros.regimen} placeholder="Todos"
              onChange={(e) => { setFiltros({ ...filtros, regimen: e.target.value }); setPage(1); }}
              options={REGIMENES.map((r) => ({ value: r, label: r }))}
              containerClassName="w-[180px]" />
            <SelectField label="Convenio" value={filtros.convenio_id} placeholder="Todos"
              onChange={(e) => { setFiltros({ ...filtros, convenio_id: e.target.value }); setPage(1); }}
              options={(convenios ?? []).map((c) => ({ value: String(c.id), label: c.nombre }))}
              containerClassName="w-[200px]" />
            <SelectField label="Estado" value={filtros.estado} placeholder="Todos"
              onChange={(e) => { setFiltros({ ...filtros, estado: e.target.value }); setPage(1); }}
              options={[{ value: 'ACTIVO', label: 'Activos' }, { value: 'INACTIVO', label: 'Inactivos' }]}
              containerClassName="w-[140px]" />
          </div>

          {error && <FormError error={errorMessage(error)} />}

          <DataTable columns={cols} paginator={data} loading={isLoading}
            onPageChange={setPage} onRowClick={(r) => setVerEmp(r)}
            empty="Sin empleados cargados" />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevoEmpleadoModal convenios={convenios ?? []} onClose={() => setNuevoOpen(false)} />}
      {verEmp && <DetalleDrawer empleado={verEmp} onClose={() => setVerEmp(null)} />}
    </div>
  );
}

function NuevoEmpleadoModal({ convenios, onClose }: { convenios: Convenio[]; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-empleados']);
  const [form, setForm] = useState({
    legajo: '', cuil: '', cuit: '', apellido: '', nombre: '', dni: '',
    fecha_ingreso: new Date().toISOString().slice(0, 10),
    convenio_id: '', categoria_id: '',
    regimen: 'MIXTO' as Empleado['regimen'],
    jornada_formal_pct: '50',
    es_vendedor: false, paga_sac: true,
    cbu: '', banco: '', alias_cbu: '',
    email: '', telefono: '', domicilio: '', observaciones: '',
    basico_inicial: '', porc_formal: '50', porc_efectivo: '50', porc_mt: '0',
  });

  const { data: categorias } = useApi<Categoria[]>(
    ['sueldos-categorias', form.convenio_id],
    `/api/erp/sueldos/categorias${form.convenio_id ? `?convenio_id=${form.convenio_id}` : ''}`
  );

  const m = useApiMutation<Empleado, Record<string, unknown>>(
    (vars) => api.post('/api/erp/sueldos/empleados', vars),
    {
      onSuccess: () => {
        toast.success('Empleado dado de alta');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    }
  );

  const submit = () => {
    const payload: Record<string, unknown> = {
      legajo: form.legajo.trim(),
      apellido: form.apellido.trim(),
      nombre: form.nombre.trim(),
      fecha_ingreso: form.fecha_ingreso,
      regimen: form.regimen,
      jornada_formal_pct: Number(form.jornada_formal_pct),
      es_vendedor: form.es_vendedor,
      paga_sac: form.paga_sac,
      basico_inicial: Number(form.basico_inicial),
      porc_formal: Number(form.porc_formal),
      porc_efectivo: Number(form.porc_efectivo),
      porc_mt: Number(form.porc_mt),
    };
    for (const k of ['cuil', 'cuit', 'dni', 'cbu', 'banco', 'alias_cbu', 'email', 'telefono', 'domicilio', 'observaciones'] as const) {
      if (form[k].trim()) payload[k] = form[k].trim();
    }
    if (form.convenio_id) payload.convenio_id = Number(form.convenio_id);
    if (form.categoria_id) payload.categoria_id = Number(form.categoria_id);
    m.mutate(payload);
  };

  const sumaComp = Number(form.porc_formal) + Number(form.porc_efectivo) + Number(form.porc_mt);
  const valid = form.legajo && form.apellido && form.nombre && form.fecha_ingreso &&
    Number(form.basico_inicial) > 0 && Math.abs(sumaComp - 100) < 0.01;

  return (
    <Modal open onClose={onClose} title="Alta de empleado" size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending} onClick={submit}>
            {m.isPending ? 'Guardando…' : 'Crear empleado'}
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-3 gap-3">
        <Field label="Legajo" required value={form.legajo}
          onChange={(e) => setForm({ ...form, legajo: e.target.value })} placeholder="E-001" />
        <Field label="CUIL" value={form.cuil}
          onChange={(e) => setForm({ ...form, cuil: e.target.value })} placeholder="20-12345678-9" />
        <Field label="DNI" value={form.dni}
          onChange={(e) => setForm({ ...form, dni: e.target.value })} />

        <Field label="Apellido" required value={form.apellido}
          onChange={(e) => setForm({ ...form, apellido: e.target.value })} />
        <Field label="Nombre" required value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })}
          containerClassName="col-span-2" />

        <Field label="Fecha ingreso" required type="date" value={form.fecha_ingreso}
          onChange={(e) => setForm({ ...form, fecha_ingreso: e.target.value })} />
        <SelectField label="Régimen" required value={form.regimen}
          onChange={(e) => setForm({ ...form, regimen: e.target.value as Empleado['regimen'] })}
          options={REGIMENES.map((r) => ({ value: r, label: r }))} placeholder={null} />
        <Field label="Jornada formal %" type="number" min={0} max={100} value={form.jornada_formal_pct}
          onChange={(e) => setForm({ ...form, jornada_formal_pct: e.target.value })} />

        <SelectField label="Convenio" value={form.convenio_id} placeholder="—"
          onChange={(e) => setForm({ ...form, convenio_id: e.target.value, categoria_id: '' })}
          options={convenios.map((c) => ({ value: String(c.id), label: c.nombre }))} />
        <SelectField label="Categoría" value={form.categoria_id} placeholder="—"
          onChange={(e) => setForm({ ...form, categoria_id: e.target.value })}
          options={(categorias ?? []).map((c) => ({ value: String(c.id), label: c.nombre }))}
          containerClassName="col-span-2" />

        <Field label="CBU" value={form.cbu}
          onChange={(e) => setForm({ ...form, cbu: e.target.value })} maxLength={22} />
        <Field label="Banco" value={form.banco}
          onChange={(e) => setForm({ ...form, banco: e.target.value })} />
        <Field label="Alias CBU" value={form.alias_cbu}
          onChange={(e) => setForm({ ...form, alias_cbu: e.target.value })} />

        <Field label="Email" type="email" value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })} />
        <Field label="Teléfono" value={form.telefono}
          onChange={(e) => setForm({ ...form, telefono: e.target.value })} />
        <div className="flex items-end gap-3 text-[12px]">
          <label className="flex items-center gap-1">
            <input type="checkbox" checked={form.es_vendedor}
              onChange={(e) => setForm({ ...form, es_vendedor: e.target.checked })} /> Vendedor
          </label>
          <label className="flex items-center gap-1">
            <input type="checkbox" checked={form.paga_sac}
              onChange={(e) => setForm({ ...form, paga_sac: e.target.checked })} /> Paga SAC
          </label>
        </div>

        <div className="col-span-3 mt-2 mb-1 text-[11.5px] uppercase text-ink-muted font-semibold">
          Básico inicial + composición (los 3 % deben sumar 100)
        </div>
        <Field label="Básico mensual ARS" required type="number" step="0.01" min={1} value={form.basico_inicial}
          onChange={(e) => setForm({ ...form, basico_inicial: e.target.value })} />
        <Field label="% Formal" required type="number" min={0} max={100} value={form.porc_formal}
          onChange={(e) => setForm({ ...form, porc_formal: e.target.value })} />
        <Field label="% Efectivo" required type="number" min={0} max={100} value={form.porc_efectivo}
          onChange={(e) => setForm({ ...form, porc_efectivo: e.target.value })} />
        <Field label="% MT (Monotributista)" required type="number" min={0} max={100} value={form.porc_mt}
          onChange={(e) => setForm({ ...form, porc_mt: e.target.value })} />
        <div className="col-span-2 flex items-end">
          <div className={`text-[11.5px] ${Math.abs(sumaComp - 100) < 0.01 ? 'text-success' : 'text-danger'}`}>
            Suma: {sumaComp.toFixed(2)} {Math.abs(sumaComp - 100) < 0.01 ? '✓' : '(debe ser 100)'}
          </div>
        </div>

        <TextareaField label="Observaciones" rows={2} value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} containerClassName="col-span-3" />
      </div>
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}

type BasicoHistorial = {
  id: number; basico_total: number | string;
  vigencia_desde: string; vigencia_hasta: string | null;
  motivo: string; observaciones: string | null;
  fecha_aprobacion: string | null;
};

function DetalleDrawer({ empleado, onClose }: { empleado: Empleado; onClose: () => void }) {
  const [tab, setTab] = useState<'datos' | 'basicos' | 'composicion' | 'comisiones'>('datos');

  return (
    <Modal open onClose={onClose}
      title={`${empleado.apellido}, ${empleado.nombre} (${empleado.legajo})`}
      size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}
    >
      <div className="flex gap-2 border-b border-line mb-3">
        {(['datos', 'basicos', 'composicion', 'comisiones'] as const).map((t) => (
          <Button key={t} size="sm" variant="ghost"
            className={tab === t
              ? 'border-b-2 border-azure rounded-none text-azure'
              : 'border-b-2 border-transparent rounded-none'}
            onClick={() => setTab(t)}>
            {t === 'datos' ? 'Datos' : t === 'basicos' ? 'Básicos' : t === 'composicion' ? 'Composición' : 'Comisiones'}
          </Button>
        ))}
      </div>

      {tab === 'datos' && <DatosTab e={empleado} />}
      {tab === 'basicos' && <BasicosTab empleadoId={empleado.id} />}
      {tab === 'composicion' && <ComposicionTab empleadoId={empleado.id} />}
      {tab === 'comisiones' && <ComisionesTab empleadoId={empleado.id} />}
    </Modal>
  );
}

function DatosTab({ e }: { e: Empleado }) {
  return (
    <div className="grid grid-cols-2 gap-3 text-[12.5px]">
      <Stat label="Legajo" value={<code>{e.legajo}</code>} />
      <Stat label="Régimen" value={<Badge variant={regimenColor(e.regimen)}>{e.regimen}</Badge>} />
      <Stat label="CUIL" value={e.cuil ?? '—'} />
      <Stat label="DNI" value={e.dni ?? '—'} />
      <Stat label="Convenio" value={e.convenio?.nombre ?? '—'} />
      <Stat label="Categoría" value={e.categoria?.nombre ?? '—'} />
      <Stat label="Fecha ingreso" value={fmtDate(e.fecha_ingreso)} />
      <Stat label="Fecha egreso" value={e.fecha_egreso ? fmtDate(e.fecha_egreso) : '—'} />
      <Stat label="Jornada formal" value={`${e.jornada_formal_pct}%`} />
      <Stat label="Vendedor" value={e.es_vendedor ? 'Sí' : 'No'} />
      <Stat label="Paga SAC" value={e.paga_sac ? 'Sí' : 'No'} />
      <Stat label="Estado" value={e.fecha_egreso ? <Badge variant="danger">BAJA</Badge> : e.activo ? <Badge variant="success">ACTIVO</Badge> : <Badge variant="neutral">INACTIVO</Badge>} />
      <Stat label="CBU" value={e.cbu ?? '—'} />
      <Stat label="Banco" value={e.banco ?? '—'} />
      <Stat label="Email" value={e.email ?? '—'} />
      <Stat label="Teléfono" value={e.telefono ?? '—'} />
    </div>
  );
}

function BasicosTab({ empleadoId }: { empleadoId: number }) {
  const { data, isLoading } = useApi<BasicoHistorial[]>(
    ['sueldos-basicos', empleadoId],
    `/api/erp/sueldos/empleados/${empleadoId}/basicos`
  );
  const [open, setOpen] = useState(false);
  return (
    <>
      <div className="flex justify-between items-center mb-2">
        <div className="text-[12px] text-ink-muted">Historial de básicos vigentes/cerrados (RN-103: sin overlap).</div>
        <Button size="sm" variant="primary" onClick={() => setOpen(true)}>
          <Plus className="w-3 h-3" /> Nuevo básico
        </Button>
      </div>
      <table className="w-full text-[12px]">
        <thead className="bg-bg-soft text-[11px] uppercase text-ink-muted">
          <tr>
            <th className="text-left p-2">Vigente desde</th>
            <th className="text-left p-2">Hasta</th>
            <th className="text-right p-2">Importe</th>
            <th className="text-left p-2">Motivo</th>
          </tr>
        </thead>
        <tbody>
          {isLoading ? <tr><td colSpan={4} className="p-3 text-center text-ink-muted">Cargando…</td></tr>
            : !data || data.length === 0 ? <tr><td colSpan={4} className="p-3 text-center text-ink-muted">Sin historial</td></tr>
            : data.map((b) => (
              <tr key={b.id} className="border-t border-line/60">
                <td className="p-2">{fmtDate(b.vigencia_desde)}</td>
                <td className="p-2">{b.vigencia_hasta ? fmtDate(b.vigencia_hasta) : <Badge variant="success">VIGENTE</Badge>}</td>
                <td className="p-2 text-right tabular-nums">{fmtMoney(Number(b.basico_total))}</td>
                <td className="p-2"><Badge variant="default">{b.motivo}</Badge></td>
              </tr>
            ))}
        </tbody>
      </table>
      {open && <NuevoBasicoModal empleadoId={empleadoId} onClose={() => setOpen(false)} />}
    </>
  );
}

function NuevoBasicoModal({ empleadoId, onClose }: { empleadoId: number; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-basicos']);
  const [form, setForm] = useState({
    basico_total: '', vigencia_desde: new Date().toISOString().slice(0, 10),
    motivo: 'AUMENTO_PARITARIA', observaciones: '',
  });
  const m = useApiMutation<BasicoHistorial, Record<string, unknown>>(
    (vars) => api.post(`/api/erp/sueldos/empleados/${empleadoId}/basicos`, vars),
    {
      onSuccess: () => { toast.success('Básico aprobado'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo aprobar', errorMessage(e)),
    }
  );
  return (
    <Modal open onClose={onClose} title="Nuevo básico (cierra el vigente)" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!Number(form.basico_total) || m.isPending}
            onClick={() => m.mutate({ ...form, basico_total: Number(form.basico_total) })}>
            {m.isPending ? 'Guardando…' : 'Aprobar'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <Field label="Importe ARS" required type="number" step="0.01" value={form.basico_total}
          onChange={(e) => setForm({ ...form, basico_total: e.target.value })} />
        <Field label="Vigente desde" required type="date" value={form.vigencia_desde}
          onChange={(e) => setForm({ ...form, vigencia_desde: e.target.value })} />
        <SelectField label="Motivo" required value={form.motivo}
          onChange={(e) => setForm({ ...form, motivo: e.target.value })}
          options={[
            { value: 'AUMENTO_PARITARIA', label: 'Aumento paritaria' },
            { value: 'AUMENTO_GERENCIAL', label: 'Aumento gerencial' },
            { value: 'RECATEGORIZACION', label: 'Recategorización' },
            { value: 'CORRECCION', label: 'Corrección' },
          ]} placeholder={null} />
        <TextareaField label="Observaciones" rows={2} value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

type Composicion = {
  id: number; porc_formal: number | string; porc_efectivo: number | string; porc_mt: number | string;
  vigencia_desde: string; vigencia_hasta: string | null; observaciones: string | null;
};

function ComposicionTab({ empleadoId }: { empleadoId: number }) {
  const { data, isLoading } = useApi<Composicion[]>(
    ['sueldos-composiciones', empleadoId],
    `/api/erp/sueldos/empleados/${empleadoId}/composiciones`
  );
  const [open, setOpen] = useState(false);
  return (
    <>
      <div className="flex justify-between items-center mb-2">
        <div className="text-[12px] text-ink-muted">Composición % Formal/Efectivo/MT (RN-102: suma 100).</div>
        <Button size="sm" variant="primary" onClick={() => setOpen(true)}>
          <History className="w-3 h-3" /> Nueva composición
        </Button>
      </div>
      <table className="w-full text-[12px]">
        <thead className="bg-bg-soft text-[11px] uppercase text-ink-muted">
          <tr>
            <th className="text-left p-2">Desde</th>
            <th className="text-left p-2">Hasta</th>
            <th className="text-right p-2">% Formal</th>
            <th className="text-right p-2">% Efectivo</th>
            <th className="text-right p-2">% MT</th>
          </tr>
        </thead>
        <tbody>
          {isLoading ? <tr><td colSpan={5} className="p-3 text-center text-ink-muted">Cargando…</td></tr>
            : !data || data.length === 0 ? <tr><td colSpan={5} className="p-3 text-center text-ink-muted">Sin historial</td></tr>
            : data.map((c) => (
              <tr key={c.id} className="border-t border-line/60">
                <td className="p-2">{fmtDate(c.vigencia_desde)}</td>
                <td className="p-2">{c.vigencia_hasta ? fmtDate(c.vigencia_hasta) : <Badge variant="success">VIGENTE</Badge>}</td>
                <td className="p-2 text-right">{Number(c.porc_formal).toFixed(2)}%</td>
                <td className="p-2 text-right">{Number(c.porc_efectivo).toFixed(2)}%</td>
                <td className="p-2 text-right">{Number(c.porc_mt).toFixed(2)}%</td>
              </tr>
            ))}
        </tbody>
      </table>
      {open && <NuevaComposicionModal empleadoId={empleadoId} onClose={() => setOpen(false)} />}
    </>
  );
}

function NuevaComposicionModal({ empleadoId, onClose }: { empleadoId: number; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-composiciones']);
  const [form, setForm] = useState({ porc_formal: '50', porc_efectivo: '50', porc_mt: '0', vigencia_desde: new Date().toISOString().slice(0, 10), observaciones: '' });
  const suma = Number(form.porc_formal) + Number(form.porc_efectivo) + Number(form.porc_mt);
  const m = useApiMutation<Composicion, Record<string, unknown>>(
    (vars) => api.post(`/api/erp/sueldos/empleados/${empleadoId}/composiciones`, vars),
    {
      onSuccess: () => { toast.success('Composición actualizada'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo actualizar', errorMessage(e)),
    }
  );
  return (
    <Modal open onClose={onClose} title="Nueva composición (cierra la vigente)" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={Math.abs(suma - 100) > 0.01 || m.isPending}
            onClick={() => m.mutate({
              porc_formal: Number(form.porc_formal),
              porc_efectivo: Number(form.porc_efectivo),
              porc_mt: Number(form.porc_mt),
              vigencia_desde: form.vigencia_desde,
              observaciones: form.observaciones || undefined,
            })}>
            {m.isPending ? 'Guardando…' : 'Aplicar'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <div className="grid grid-cols-3 gap-2">
          <Field label="% Formal" required type="number" min={0} max={100} value={form.porc_formal}
            onChange={(e) => setForm({ ...form, porc_formal: e.target.value })} />
          <Field label="% Efectivo" required type="number" min={0} max={100} value={form.porc_efectivo}
            onChange={(e) => setForm({ ...form, porc_efectivo: e.target.value })} />
          <Field label="% MT" required type="number" min={0} max={100} value={form.porc_mt}
            onChange={(e) => setForm({ ...form, porc_mt: e.target.value })} />
        </div>
        <div className={`text-[12px] ${Math.abs(suma - 100) < 0.01 ? 'text-success' : 'text-danger'}`}>
          Suma: {suma.toFixed(2)} {Math.abs(suma - 100) < 0.01 ? '✓' : '(debe ser 100)'}
        </div>
        <Field label="Vigente desde" required type="date" value={form.vigencia_desde}
          onChange={(e) => setForm({ ...form, vigencia_desde: e.target.value })} />
        <TextareaField label="Observaciones" rows={2} value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

type ComisionEsq = {
  id: number; base: string; porcentaje: number | string | null; importe_unitario: number | string | null;
  importe_fijo: number | string | null; tope_mensual: number | string | null;
  vigencia_desde: string; vigencia_hasta: string | null;
};

function ComisionesTab({ empleadoId }: { empleadoId: number }) {
  const { data, isLoading } = useApi<ComisionEsq[]>(
    ['sueldos-comisiones', empleadoId],
    `/api/erp/sueldos/empleados/${empleadoId}/comisiones`
  );
  return (
    <>
      <div className="text-[12px] text-ink-muted mb-2">
        Esquemas de comisión vigentes y previos (vendedores remotos). El cálculo del mes se carga como novedad.
      </div>
      <table className="w-full text-[12px]">
        <thead className="bg-bg-soft text-[11px] uppercase text-ink-muted">
          <tr>
            <th className="text-left p-2">Vigente desde</th>
            <th className="text-left p-2">Base</th>
            <th className="text-right p-2">%</th>
            <th className="text-right p-2">Unitario</th>
            <th className="text-right p-2">Fijo</th>
            <th className="text-right p-2">Tope</th>
          </tr>
        </thead>
        <tbody>
          {isLoading ? <tr><td colSpan={6} className="p-3 text-center text-ink-muted">Cargando…</td></tr>
            : !data || data.length === 0 ? <tr><td colSpan={6} className="p-3 text-center text-ink-muted">Sin esquemas</td></tr>
            : data.map((c) => (
              <tr key={c.id} className="border-t border-line/60">
                <td className="p-2">{fmtDate(c.vigencia_desde)}</td>
                <td className="p-2"><Badge variant="default">{c.base}</Badge></td>
                <td className="p-2 text-right">{c.porcentaje !== null ? Number(c.porcentaje).toFixed(2) + '%' : '—'}</td>
                <td className="p-2 text-right">{c.importe_unitario !== null ? fmtMoney(Number(c.importe_unitario)) : '—'}</td>
                <td className="p-2 text-right">{c.importe_fijo !== null ? fmtMoney(Number(c.importe_fijo)) : '—'}</td>
                <td className="p-2 text-right">{c.tope_mensual !== null ? fmtMoney(Number(c.tope_mensual)) : '—'}</td>
              </tr>
            ))}
        </tbody>
      </table>
    </>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="text-[10.5px] uppercase text-ink-muted">{label}</div>
      <div className="font-medium tabular-nums">{value}</div>
    </div>
  );
}
