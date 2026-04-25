import { useMemo, useState } from 'react';
import { Plus, ClipboardList, Trash2, Upload } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Novedad = {
  id: number; empleado_id: number; periodo: string; concepto_id: number;
  cantidad: number | string; importe: number | string | null; observaciones: string | null;
  empleado?: { id: number; legajo: string; apellido: string; nombre: string };
  concepto?: { id: number; codigo: string; nombre: string; signo: 'HABER' | 'DESCUENTO' };
};

type Concepto = { id: number; codigo: string; nombre: string; signo: 'HABER' | 'DESCUENTO'; tipo: string; activo: boolean };

function defaultPeriodo() {
  return new Date().toISOString().slice(0, 7);
}

export function NovedadesPage() {
  const [filtros, setFiltros] = useState({ periodo: defaultPeriodo(), empleado_id: '', concepto_id: '' });
  const [page, setPage] = useState(1);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [borrar, setBorrar] = useState<Novedad | null>(null);

  const { data: conceptos } = useApi<Concepto[]>(['sueldos-conceptos'], '/api/erp/sueldos/conceptos');

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (filtros.periodo) p.set('periodo', filtros.periodo);
    if (filtros.empleado_id) p.set('empleado_id', filtros.empleado_id);
    if (filtros.concepto_id) p.set('concepto_id', filtros.concepto_id);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [filtros, page]);

  const { data, isLoading, error } = useApi<Paginator<Novedad>>(
    ['sueldos-novedades', qs],
    `/api/erp/sueldos/novedades${qs ? `?${qs}` : ''}`
  );

  const cols: Column<Novedad>[] = [
    { key: 'periodo', header: 'Período', width: '90px' },
    { key: 'empleado', header: 'Empleado',
      render: (r) => r.empleado ? `${r.empleado.apellido}, ${r.empleado.nombre} (${r.empleado.legajo})` : '—' },
    { key: 'concepto', header: 'Concepto',
      render: (r) => r.concepto ? (
        <div>
          <code className="text-[11px]">{r.concepto.codigo}</code>{' '}
          <span>{r.concepto.nombre}</span>
        </div>
      ) : '—' },
    { key: 'signo', header: 'Signo', width: '110px',
      render: (r) => r.concepto && <Badge variant={r.concepto.signo === 'HABER' ? 'success' : 'warning'}>{r.concepto.signo}</Badge> },
    { key: 'cantidad', header: 'Cant.', align: 'right', width: '90px',
      render: (r) => Number(r.cantidad).toFixed(2) },
    { key: 'importe', header: 'Importe', align: 'right', width: '120px',
      render: (r) => r.importe !== null ? fmtMoney(Number(r.importe)) : <span className="text-ink-muted">auto</span> },
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
          title={<div className="flex items-center gap-2"><ClipboardList className="w-4 h-4 text-azure" /> Novedades del mes</div>}
          actions={
            <>
              <Button variant="outline" onClick={() => setBulkOpen(true)}>
                <Upload className="w-3 h-3" /> Carga masiva
              </Button>
              <Button variant="primary" onClick={() => setNuevoOpen(true)}>
                <Plus className="w-3 h-3" /> Nueva novedad
              </Button>
            </>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            HE 50/100, comisiones, ajustes, descuentos puntuales. Si el período tiene
            liquidación APROBADA o PAGADA, queda inmutable (RN-113).
          </div>
          <div className="flex flex-wrap gap-3">
            <Field label="Período" value={filtros.periodo} placeholder="YYYY-MM"
              onChange={(e) => { setFiltros({ ...filtros, periodo: e.target.value }); setPage(1); }}
              containerClassName="w-[140px]" />
            <Field label="ID empleado" type="number" value={filtros.empleado_id}
              onChange={(e) => { setFiltros({ ...filtros, empleado_id: e.target.value }); setPage(1); }}
              containerClassName="w-[150px]" />
            <SelectField label="Concepto" value={filtros.concepto_id} placeholder="Todos"
              onChange={(e) => { setFiltros({ ...filtros, concepto_id: e.target.value }); setPage(1); }}
              options={(conceptos ?? []).map((c) => ({ value: String(c.id), label: `${c.codigo} — ${c.nombre}` }))}
              containerClassName="w-[280px]" />
          </div>

          {error && <FormError error={errorMessage(error)} />}

          <DataTable columns={cols} paginator={data} loading={isLoading}
            onPageChange={setPage} empty="Sin novedades en este filtro" />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevaModal conceptos={conceptos ?? []} periodo={filtros.periodo} onClose={() => setNuevoOpen(false)} />}
      {bulkOpen && <BulkModal conceptos={conceptos ?? []} periodo={filtros.periodo} onClose={() => setBulkOpen(false)} />}
      {borrar && <BorrarConfirm novedad={borrar} onClose={() => setBorrar(null)} />}
    </div>
  );
}

function NuevaModal({ conceptos, periodo, onClose }: { conceptos: Concepto[]; periodo: string; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-novedades']);
  const [form, setForm] = useState({ empleado_id: '', periodo, concepto_id: '', cantidad: '', importe: '', observaciones: '' });
  const m = useApiMutation<Novedad, Record<string, unknown>>(
    (vars) => api.post('/api/erp/sueldos/novedades', vars),
    {
      onSuccess: () => { toast.success('Novedad creada'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    }
  );
  const submit = () => {
    const payload: Record<string, unknown> = {
      empleado_id: Number(form.empleado_id),
      periodo: form.periodo,
      concepto_id: Number(form.concepto_id),
    };
    if (form.cantidad) payload.cantidad = Number(form.cantidad);
    if (form.importe) payload.importe = Number(form.importe);
    if (form.observaciones) payload.observaciones = form.observaciones;
    m.mutate(payload);
  };
  const valid = form.empleado_id && form.periodo && form.concepto_id && (form.cantidad || form.importe);
  return (
    <Modal open onClose={onClose} title="Nueva novedad" size="md"
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
          <Field label="Período" required value={form.periodo} placeholder="YYYY-MM"
            onChange={(e) => setForm({ ...form, periodo: e.target.value })} />
        </div>
        <SelectField label="Concepto" required value={form.concepto_id}
          onChange={(e) => setForm({ ...form, concepto_id: e.target.value })}
          options={conceptos.map((c) => ({ value: String(c.id), label: `${c.codigo} (${c.signo}) — ${c.nombre}` }))} />
        <div className="grid grid-cols-2 gap-3">
          <Field label="Cantidad (horas/días/unidades)" type="number" step="0.01" value={form.cantidad}
            onChange={(e) => setForm({ ...form, cantidad: e.target.value })}
            hint="Para HE_50, HE_100, FALTA_DIA, etc." />
          <Field label="Importe (override)" type="number" step="0.01" value={form.importe}
            onChange={(e) => setForm({ ...form, importe: e.target.value })}
            hint="Solo si no se calcula por fórmula" />
        </div>
        <TextareaField label="Observaciones" rows={2} value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function BulkModal({ conceptos, periodo, onClose }: { conceptos: Concepto[]; periodo: string; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-novedades']);
  const [form, setForm] = useState({ periodo, jsonText: '' });
  const m = useApiMutation<{ creadas: number }, { periodo: string; items: unknown[] }>(
    (vars) => api.post('/api/erp/sueldos/novedades/bulk', vars),
    {
      onSuccess: (res) => { toast.success(`${res.creadas} novedades creadas`); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo importar', errorMessage(e)),
    }
  );
  const submit = () => {
    let items: unknown[] = [];
    try {
      const parsed = JSON.parse(form.jsonText);
      if (! Array.isArray(parsed)) throw new Error('Debe ser un array');
      items = parsed;
    } catch (e) {
      toast.error('JSON inválido', String(e));
      return;
    }
    m.mutate({ periodo: form.periodo, items });
  };
  return (
    <Modal open onClose={onClose} title="Carga masiva de novedades" size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!form.jsonText || m.isPending} onClick={submit}>
            {m.isPending ? 'Importando…' : 'Importar'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <Field label="Período (todas las novedades comparten)" required value={form.periodo}
          onChange={(e) => setForm({ ...form, periodo: e.target.value })} />
        <div className="text-[12px] text-ink-muted">
          Pegá un array JSON con `[{`{`}empleado_id, concepto_id, cantidad?, importe?, observaciones?{`}`}]`. Conceptos disponibles:
          <div className="mt-1 max-h-[100px] overflow-auto bg-bg-soft p-2 text-[11px]">
            {conceptos.map((c) => `#${c.id} ${c.codigo} (${c.signo})`).join(' · ')}
          </div>
        </div>
        <TextareaField label="Items JSON" required rows={10} value={form.jsonText}
          onChange={(e) => setForm({ ...form, jsonText: e.target.value })}
          placeholder='[{"empleado_id":1,"concepto_id":4,"cantidad":10}]'
          containerClassName="font-mono" />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function BorrarConfirm({ novedad, onClose }: { novedad: Novedad; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-novedades']);
  const m = useApiMutation(
    () => api.delete(`/api/erp/sueldos/novedades/${novedad.id}`),
    {
      onSuccess: () => { toast.success('Novedad borrada'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo borrar', errorMessage(e)),
    }
  );
  return (
    <ConfirmDialog open onClose={onClose} variant="danger"
      title="Borrar novedad"
      message={`Empleado #${novedad.empleado_id} · ${novedad.concepto?.codigo ?? ''} · ${novedad.periodo}`}
      confirmLabel="Borrar" loading={m.isPending}
      onConfirm={() => m.mutate(undefined as unknown as void)} />
  );
}
