import { useMemo, useState } from 'react';
import { Plus, CalendarCheck, Trash2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Ausencia = {
  id: number; empleado_id: number; tipo: string;
  fecha_desde: string; fecha_hasta: string; dias_habiles: number;
  paga: boolean; observaciones: string | null;
  empleado?: { id: number; legajo: string; apellido: string; nombre: string };
};

const TIPOS = ['CARPETA_MEDICA', 'LICENCIA_ESPECIAL', 'VACACIONES', 'FALTA_INJUSTIFICADA', 'SUSPENSION', 'OTROS'];

function tipoColor(t: string): 'info' | 'warning' | 'success' | 'danger' | 'neutral' | 'default' {
  switch (t) {
    case 'CARPETA_MEDICA': return 'info';
    case 'LICENCIA_ESPECIAL': return 'default';
    case 'VACACIONES': return 'success';
    case 'FALTA_INJUSTIFICADA': return 'danger';
    case 'SUSPENSION': return 'warning';
    default: return 'neutral';
  }
}

export function AusenciasPage() {
  const [filtros, setFiltros] = useState({ empleado_id: '', tipo: '', desde: '', hasta: '' });
  const [page, setPage] = useState(1);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [borrar, setBorrar] = useState<Ausencia | null>(null);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (filtros.empleado_id) p.set('empleado_id', filtros.empleado_id);
    if (filtros.tipo) p.set('tipo', filtros.tipo);
    if (filtros.desde) p.set('desde', filtros.desde);
    if (filtros.hasta) p.set('hasta', filtros.hasta);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [filtros, page]);

  const { data, isLoading, error } = useApi<Paginator<Ausencia>>(
    ['sueldos-ausencias', qs],
    `/api/erp/sueldos/ausencias${qs ? `?${qs}` : ''}`
  );

  const cols: Column<Ausencia>[] = [
    { key: 'empleado', header: 'Empleado',
      render: (r) => r.empleado ? `${r.empleado.apellido}, ${r.empleado.nombre} (${r.empleado.legajo})` : '—' },
    { key: 'tipo', header: 'Tipo', width: '170px',
      render: (r) => <Badge variant={tipoColor(r.tipo)}>{r.tipo}</Badge> },
    { key: 'rango', header: 'Período',
      render: (r) => `${fmtDate(r.fecha_desde)} → ${fmtDate(r.fecha_hasta)}` },
    { key: 'dias', header: 'Días háb.', align: 'right', width: '90px',
      render: (r) => r.dias_habiles },
    { key: 'paga', header: 'Paga', width: '70px',
      render: (r) => r.paga ? <Badge variant="success">SÍ</Badge> : <Badge variant="neutral">NO</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '60px',
      render: (r) => (
        <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setBorrar(r); }}>
          <Trash2 className="w-3 h-3 text-danger" />
        </Button>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><CalendarCheck className="w-4 h-4 text-azure" /> Ausencias</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Nueva ausencia
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            Carpetas médicas, vacaciones, faltas y suspensiones. Las faltas injustificadas
            y suspensiones descuentan días de la liquidación; las vacaciones agregan VACACIONES.
          </div>
          <div className="flex flex-wrap gap-3">
            <Field label="ID empleado" type="number" value={filtros.empleado_id}
              onChange={(e) => { setFiltros({ ...filtros, empleado_id: e.target.value }); setPage(1); }}
              containerClassName="w-[150px]" />
            <SelectField label="Tipo" value={filtros.tipo} placeholder="Todos"
              onChange={(e) => { setFiltros({ ...filtros, tipo: e.target.value }); setPage(1); }}
              options={TIPOS.map((t) => ({ value: t, label: t }))}
              containerClassName="w-[200px]" />
            <Field label="Desde" type="date" value={filtros.desde}
              onChange={(e) => { setFiltros({ ...filtros, desde: e.target.value }); setPage(1); }}
              containerClassName="w-[150px]" />
            <Field label="Hasta" type="date" value={filtros.hasta}
              onChange={(e) => { setFiltros({ ...filtros, hasta: e.target.value }); setPage(1); }}
              containerClassName="w-[150px]" />
          </div>

          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={cols} paginator={data} loading={isLoading}
            onPageChange={setPage} empty="Sin ausencias en el filtro" />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevaAusenciaModal onClose={() => setNuevoOpen(false)} />}
      {borrar && <BorrarConfirm ausencia={borrar} onClose={() => setBorrar(null)} />}
    </div>
  );
}

function NuevaAusenciaModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-ausencias']);
  const [form, setForm] = useState({
    empleado_id: '', tipo: 'CARPETA_MEDICA',
    fecha_desde: new Date().toISOString().slice(0, 10),
    fecha_hasta: new Date().toISOString().slice(0, 10),
    dias_habiles: '', paga: true, observaciones: '',
  });
  const m = useApiMutation<Ausencia, Record<string, unknown>>(
    (vars) => api.post('/api/erp/sueldos/ausencias', vars),
    {
      onSuccess: () => { toast.success('Ausencia registrada'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    }
  );
  const submit = () => {
    const payload: Record<string, unknown> = {
      empleado_id: Number(form.empleado_id),
      tipo: form.tipo,
      fecha_desde: form.fecha_desde,
      fecha_hasta: form.fecha_hasta,
      paga: form.paga,
    };
    if (form.dias_habiles) payload.dias_habiles = Number(form.dias_habiles);
    if (form.observaciones) payload.observaciones = form.observaciones;
    m.mutate(payload);
  };
  const valid = form.empleado_id && form.tipo && form.fecha_desde && form.fecha_hasta;
  return (
    <Modal open onClose={onClose} title="Nueva ausencia" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending} onClick={submit}>
            {m.isPending ? 'Guardando…' : 'Crear'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="ID empleado" required type="number" value={form.empleado_id}
            onChange={(e) => setForm({ ...form, empleado_id: e.target.value })} />
          <SelectField label="Tipo" required value={form.tipo}
            onChange={(e) => setForm({ ...form, tipo: e.target.value })}
            options={TIPOS.map((t) => ({ value: t, label: t }))} placeholder={null} />
        </div>
        <div className="grid grid-cols-3 gap-3">
          <Field label="Desde" required type="date" value={form.fecha_desde}
            onChange={(e) => setForm({ ...form, fecha_desde: e.target.value })} />
          <Field label="Hasta" required type="date" value={form.fecha_hasta}
            onChange={(e) => setForm({ ...form, fecha_hasta: e.target.value })} />
          <Field label="Días hábiles" type="number" min={0} value={form.dias_habiles}
            onChange={(e) => setForm({ ...form, dias_habiles: e.target.value })}
            hint="Vacío = calcula auto" />
        </div>
        <label className="flex items-center gap-2 text-[12.5px]">
          <input type="checkbox" checked={form.paga}
            onChange={(e) => setForm({ ...form, paga: e.target.checked })} />
          La ausencia se paga (no descuenta del sueldo)
        </label>
        <TextareaField label="Observaciones" rows={2} value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function BorrarConfirm({ ausencia, onClose }: { ausencia: Ausencia; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-ausencias']);
  const m = useApiMutation(
    () => api.delete(`/api/erp/sueldos/ausencias/${ausencia.id}`),
    {
      onSuccess: () => { toast.success('Ausencia borrada'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo borrar', errorMessage(e)),
    }
  );
  return (
    <ConfirmDialog open onClose={onClose} variant="danger"
      title="Borrar ausencia"
      message={`${ausencia.tipo} · ${ausencia.fecha_desde} → ${ausencia.fecha_hasta}`}
      confirmLabel="Borrar" loading={m.isPending}
      onConfirm={() => m.mutate(undefined as unknown as void)} />
  );
}
