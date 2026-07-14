import { useState } from 'react';
import { Wallet, Plus, Eye, Coins } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type CC = {
  id: number; empleado_id: number; tipo: string;
  cuenta_contable_id: number;
  saldo_actual: number | string; limite_credito: number | string | null;
  activa: boolean;
  empleado?: { id: number; legajo: string; apellido: string; nombre: string };
  cuenta?: { id: number; codigo: string; nombre: string };
};

type Prestamo = {
  id: number; empleado_id: number;
  fecha_otorgamiento: string; capital: number | string;
  cuotas_total: number; cuotas_pagadas: number; cuota_mensual: number | string;
  saldo_capital: number | string; primera_cuota_periodo: string;
  estado: 'VIGENTE' | 'CANCELADO' | 'REFINANCIADO' | 'BAJA';
  observaciones: string | null;
  empleado?: { id: number; legajo: string; apellido: string; nombre: string };
};

type Movimiento = {
  id: number; cc_id: number; fecha: string; tipo_mov: string;
  importe: number | string; saldo_posterior: number | string;
  referencia: string | null; observaciones: string | null;
};

const TIPOS_CC = ['PRESTAMO', 'ADELANTO', 'COMBUSTIBLE', 'POLIZA', 'SANCION', 'OTRO'];

export function CCPrestamosPage() {
  const [tab, setTab] = useState<'cc' | 'prestamos'>('cc');
  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><Wallet className="w-4 h-4 text-azure" /> CC Empleado + Préstamos</div>
        } />
        <CardBody className="p-4">
          <div className="flex gap-2 border-b border-line mb-3">
            <Button size="sm" variant="ghost"
              className={tab === 'cc' ? 'border-b-2 border-azure rounded-none text-azure' : 'border-b-2 border-transparent rounded-none'}
              onClick={() => setTab('cc')}>
              Cuentas corrientes
            </Button>
            <Button size="sm" variant="ghost"
              className={tab === 'prestamos' ? 'border-b-2 border-azure rounded-none text-azure' : 'border-b-2 border-transparent rounded-none'}
              onClick={() => setTab('prestamos')}>
              Préstamos
            </Button>
          </div>
          {tab === 'cc' ? <CCTab /> : <PrestamosTab />}
        </CardBody>
      </Card>
    </div>
  );
}

function CCTab() {
  const [filtros, setFiltros] = useState({ empleado_id: '', tipo: '' });
  const qs = (() => {
    const p = new URLSearchParams();
    if (filtros.empleado_id) p.set('empleado_id', filtros.empleado_id);
    if (filtros.tipo) p.set('tipo', filtros.tipo);
    return p.toString();
  })();
  const { data, isLoading, error } = useApi<CC[]>(['sueldos-cc', qs], `/api/erp/sueldos/cc${qs ? `?${qs}` : ''}`);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [verCC, setVerCC] = useState<CC | null>(null);

  const cols: Column<CC>[] = [
    { key: 'empleado', header: 'Empleado',
      render: (r) => r.empleado ? `${r.empleado.apellido}, ${r.empleado.nombre} (${r.empleado.legajo})` : `#${r.empleado_id}` },
    { key: 'tipo', header: 'Tipo', width: '130px',
      render: (r) => <Badge variant="default">{r.tipo}</Badge> },
    { key: 'cuenta', header: 'Cuenta',
      render: (r) => r.cuenta ? `${r.cuenta.codigo} ${r.cuenta.nombre}` : `#${r.cuenta_contable_id}` },
    { key: 'saldo', header: 'Saldo', align: 'right', width: '130px',
      render: (r) => <span className={Number(r.saldo_actual) > 0 ? 'text-warning font-medium' : ''}>{fmtMoney(Number(r.saldo_actual))}</span> },
    { key: 'limite', header: 'Límite', align: 'right', width: '120px',
      render: (r) => r.limite_credito !== null ? fmtMoney(Number(r.limite_credito)) : '—' },
    { key: 'estado', header: '', width: '90px',
      render: (r) => r.activa ? <Badge variant="success">ACTIVA</Badge> : <Badge variant="neutral">INACTIVA</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '70px',
      render: (r) => (
        <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setVerCC(r); }}>
          <Eye className="w-3 h-3" />
        </Button>
      ) },
  ];

  return (
    <>
      <div className="flex flex-wrap gap-3 items-end mb-3">
        <Field label="ID empleado" type="number" value={filtros.empleado_id}
          onChange={(e) => setFiltros({ ...filtros, empleado_id: e.target.value })}
          containerClassName="w-[150px]" />
        <SelectField label="Tipo" value={filtros.tipo} placeholder="Todos"
          onChange={(e) => setFiltros({ ...filtros, tipo: e.target.value })}
          options={TIPOS_CC.map((t) => ({ value: t, label: t }))}
          containerClassName="w-[180px]" />
        <Button variant="primary" size="sm" onClick={() => setNuevoOpen(true)}>
          <Plus className="w-3 h-3" /> Nueva CC
        </Button>
      </div>
      {error && <FormError error={errorMessage(error)} />}
      <DataTable columns={cols} rows={data ?? []} loading={isLoading}
        onRowClick={(r) => setVerCC(r)} empty="Sin CCs" />
      {nuevoOpen && <NuevaCCModal onClose={() => setNuevoOpen(false)} />}
      {verCC && <DrawerMovimientos cc={verCC} onClose={() => setVerCC(null)} />}
    </>
  );
}

function NuevaCCModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-cc']);
  const [form, setForm] = useState({ empleado_id: '', tipo: 'ADELANTO', limite_credito: '' });
  const m = useApiMutation<CC, Record<string, unknown>>(
    (vars) => api.post('/api/erp/sueldos/cc', vars),
    {
      onSuccess: () => { toast.success('CC creada'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    }
  );
  return (
    <Modal open onClose={onClose} title="Nueva CC empleado" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!form.empleado_id || m.isPending}
            onClick={() => m.mutate({
              empleado_id: Number(form.empleado_id),
              tipo: form.tipo,
              limite_credito: form.limite_credito ? Number(form.limite_credito) : undefined,
            })}>
            {m.isPending ? 'Creando…' : 'Crear'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <Field label="ID empleado" required type="number" value={form.empleado_id}
          onChange={(e) => setForm({ ...form, empleado_id: e.target.value })} />
        <SelectField label="Tipo" required value={form.tipo}
          onChange={(e) => setForm({ ...form, tipo: e.target.value })}
          options={TIPOS_CC.map((t) => ({ value: t, label: t }))} placeholder={null} />
        <Field label="Límite crédito (opcional)" type="number" step="0.01" value={form.limite_credito}
          onChange={(e) => setForm({ ...form, limite_credito: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function DrawerMovimientos({ cc, onClose }: { cc: CC; onClose: () => void }) {
  const { data, isLoading } = useApi<Paginator<Movimiento>>(
    ['sueldos-cc-movs', cc.id],
    `/api/erp/sueldos/cc/${cc.id}/movimientos`
  );
  const [open, setOpen] = useState(false);
  return (
    <Modal open onClose={onClose}
      title={`CC ${cc.tipo} — ${cc.empleado?.apellido}, ${cc.empleado?.nombre}`}
      size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}>
      <div className="space-y-3">
        <div className="grid grid-cols-3 gap-3">
          <Stat label="Saldo actual" value={fmtMoney(Number(cc.saldo_actual))} />
          <Stat label="Límite" value={cc.limite_credito !== null ? fmtMoney(Number(cc.limite_credito)) : '—'} />
          <Stat label="Cuenta" value={cc.cuenta ? cc.cuenta.codigo : '—'} />
        </div>
        <div className="flex justify-between items-center">
          <div className="text-[12px] text-ink-muted">Movimientos (más recientes primero)</div>
          <Button size="sm" variant="primary" onClick={() => setOpen(true)}>
            <Plus className="w-3 h-3" /> Cargar movimiento
          </Button>
        </div>
        <table className="w-full text-[12px]">
          <thead className="bg-bg-soft text-[11px] uppercase text-ink-muted">
            <tr>
              <th className="text-left p-2">Fecha</th>
              <th className="text-left p-2">Tipo</th>
              <th className="text-right p-2">Importe</th>
              <th className="text-right p-2">Saldo</th>
              <th className="text-left p-2">Referencia</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? <tr><td colSpan={5} className="p-3 text-center text-ink-muted">Cargando…</td></tr>
              : !data || data.data.length === 0 ? <tr><td colSpan={5} className="p-3 text-center text-ink-muted">Sin movimientos</td></tr>
              : data.data.map((m) => (
                <tr key={m.id} className="border-t border-line/60">
                  <td className="p-2">{fmtDate(m.fecha)}</td>
                  <td className="p-2"><Badge variant="default">{m.tipo_mov}</Badge></td>
                  <td className="p-2 text-right tabular-nums">{fmtMoney(Number(m.importe))}</td>
                  <td className="p-2 text-right tabular-nums">{fmtMoney(Number(m.saldo_posterior))}</td>
                  <td className="p-2 text-[11px] text-ink-muted">{m.referencia ?? m.observaciones ?? ''}</td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>
      {open && <NuevoMovimientoModal ccId={cc.id} onClose={() => setOpen(false)} />}
    </Modal>
  );
}

function NuevoMovimientoModal({ ccId, onClose }: { ccId: number; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-cc-movs', 'sueldos-cc']);
  const [form, setForm] = useState({
    fecha: new Date().toISOString().slice(0, 10),
    tipo_mov: 'CARGO', importe: '', referencia: '', observaciones: '',
  });
  const m = useApiMutation<Movimiento, Record<string, unknown>>(
    (vars) => api.post(`/api/erp/sueldos/cc/${ccId}/movimientos`, vars),
    {
      onSuccess: () => { toast.success('Movimiento creado'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo registrar', errorMessage(e)),
    }
  );
  return (
    <Modal open onClose={onClose} title="Cargar movimiento CC" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!Number(form.importe) || m.isPending}
            onClick={() => m.mutate({
              fecha: form.fecha, tipo_mov: form.tipo_mov,
              importe: Number(form.importe),
              referencia: form.referencia || undefined,
              observaciones: form.observaciones || undefined,
            })}>
            {m.isPending ? 'Guardando…' : 'Crear'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Fecha" required type="date" value={form.fecha}
            onChange={(e) => setForm({ ...form, fecha: e.target.value })} />
          <SelectField label="Tipo" required value={form.tipo_mov}
            onChange={(e) => setForm({ ...form, tipo_mov: e.target.value })}
            options={[
              { value: 'CARGO', label: 'CARGO (suma saldo)' },
              { value: 'PAGO', label: 'PAGO (resta saldo)' },
              { value: 'AJUSTE', label: 'AJUSTE (manual)' },
            ]} placeholder={null} />
        </div>
        <Field label="Importe" required type="number" step="0.01" value={form.importe}
          onChange={(e) => setForm({ ...form, importe: e.target.value })} />
        <Field label="Referencia" value={form.referencia}
          onChange={(e) => setForm({ ...form, referencia: e.target.value })}
          placeholder="N° factura combustible / N° póliza / N° sanción" />
        <TextareaField label="Observaciones" rows={2} value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

// ---- Préstamos -------------------------------------------------------------

function PrestamosTab() {
  const [filtros, setFiltros] = useState({ empleado_id: '', estado: '' });
  const qs = (() => {
    const p = new URLSearchParams();
    if (filtros.empleado_id) p.set('empleado_id', filtros.empleado_id);
    if (filtros.estado) p.set('estado', filtros.estado);
    return p.toString();
  })();
  const { data, isLoading, error } = useApi<Paginator<Prestamo>>(
    ['sueldos-prestamos', qs],
    `/api/erp/sueldos/prestamos${qs ? `?${qs}` : ''}`
  );
  const [nuevoOpen, setNuevoOpen] = useState(false);

  const cols: Column<Prestamo>[] = [
    { key: 'id', header: '#', width: '70px',
      render: (r) => <code className="text-[11px]">{r.id}</code> },
    { key: 'empleado', header: 'Empleado',
      render: (r) => r.empleado ? `${r.empleado.apellido}, ${r.empleado.nombre} (${r.empleado.legajo})` : `#${r.empleado_id}` },
    { key: 'fecha', header: 'Otorgado', width: '95px',
      render: (r) => fmtDate(r.fecha_otorgamiento) },
    { key: 'capital', header: 'Capital', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.capital)) },
    { key: 'cuotas', header: 'Cuotas', width: '110px',
      render: (r) => (
        <span>
          {r.cuotas_pagadas} / {r.cuotas_total}{' '}
          {r.estado === 'VIGENTE' && r.cuotas_pagadas >= r.cuotas_total - 1 && (
            <Badge variant="warning">{r.cuotas_pagadas >= r.cuotas_total ? 'CUMPLIDO — confirmar' : 'última cuota'}</Badge>
          )}
        </span>
      ) },
    { key: 'cuota', header: 'Cuota mens.', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.cuota_mensual)) },
    { key: 'saldo', header: 'Saldo', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.saldo_capital)) },
    { key: 'estado', header: 'Estado', width: '120px',
      render: (r) => (
        <Badge variant={r.estado === 'VIGENTE' ? 'info' : r.estado === 'CANCELADO' ? 'success' : 'neutral'}>
          {r.estado}
        </Badge>
      ) },
  ];

  return (
    <>
      <div className="flex flex-wrap gap-3 items-end mb-3">
        <Field label="ID empleado" type="number" value={filtros.empleado_id}
          onChange={(e) => setFiltros({ ...filtros, empleado_id: e.target.value })}
          containerClassName="w-[150px]" />
        <SelectField label="Estado" value={filtros.estado} placeholder="Todos"
          onChange={(e) => setFiltros({ ...filtros, estado: e.target.value })}
          options={['VIGENTE', 'CANCELADO', 'REFINANCIADO', 'BAJA'].map((s) => ({ value: s, label: s }))}
          containerClassName="w-[160px]" />
        <Button variant="primary" size="sm" onClick={() => setNuevoOpen(true)}>
          <Coins className="w-3 h-3" /> Otorgar préstamo
        </Button>
      </div>
      {error && <FormError error={errorMessage(error)} />}
      <DataTable columns={cols} paginator={data} loading={isLoading} empty="Sin préstamos" />
      {nuevoOpen && <NuevoPrestamoModal onClose={() => setNuevoOpen(false)} />}
    </>
  );
}

function NuevoPrestamoModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-prestamos', 'sueldos-cc']);
  const [form, setForm] = useState({
    empleado_id: '',
    fecha_otorgamiento: new Date().toISOString().slice(0, 10),
    capital: '', cuotas_total: '6',
    primera_cuota_periodo: new Date().toISOString().slice(0, 7),
    observaciones: '',
  });
  const cuotaMensual = Number(form.capital) > 0 && Number(form.cuotas_total) > 0
    ? Number(form.capital) / Number(form.cuotas_total) : 0;
  const m = useApiMutation<Prestamo, Record<string, unknown>>(
    (vars) => api.post('/api/erp/sueldos/prestamos', vars),
    {
      onSuccess: () => { toast.success('Préstamo otorgado'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo otorgar', errorMessage(e)),
    }
  );
  const valid = form.empleado_id && Number(form.capital) > 0 && Number(form.cuotas_total) > 0;
  return (
    <Modal open onClose={onClose} title="Otorgar préstamo al empleado" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              empleado_id: Number(form.empleado_id),
              fecha_otorgamiento: form.fecha_otorgamiento,
              capital: Number(form.capital),
              cuotas_total: Number(form.cuotas_total),
              primera_cuota_periodo: form.primera_cuota_periodo,
              observaciones: form.observaciones || undefined,
            })}>
            {m.isPending ? 'Procesando…' : 'Otorgar'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="ID empleado" required type="number" value={form.empleado_id}
            onChange={(e) => setForm({ ...form, empleado_id: e.target.value })} />
          <Field label="Fecha otorgamiento" required type="date" value={form.fecha_otorgamiento}
            onChange={(e) => setForm({ ...form, fecha_otorgamiento: e.target.value })} />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Capital ARS" required type="number" step="0.01" min={1} value={form.capital}
            onChange={(e) => setForm({ ...form, capital: e.target.value })} />
          <Field label="Cuotas totales" required type="number" min={1} max={120} value={form.cuotas_total}
            onChange={(e) => setForm({ ...form, cuotas_total: e.target.value })} />
        </div>
        <Field label="Primera cuota período (YYYY-MM)" required value={form.primera_cuota_periodo}
          onChange={(e) => setForm({ ...form, primera_cuota_periodo: e.target.value })}
          hint={cuotaMensual > 0 ? `Cuota mensual estimada: ${fmtMoney(cuotaMensual)}` : undefined} />
        <TextareaField label="Observaciones" rows={2} value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="border border-line rounded-md p-2 bg-white">
      <div className="text-[10.5px] uppercase text-ink-muted">{label}</div>
      <div className="font-medium tabular-nums">{value}</div>
    </div>
  );
}
