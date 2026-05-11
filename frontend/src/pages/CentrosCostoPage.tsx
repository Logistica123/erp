import { useMemo, useState } from 'react';
import { Building2, Plus, Pencil, Trash2, RotateCcw } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { api, ApiError } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * ADDENDUM v1.14 ampliación 2026-05-10 — ABM completo de Centros de Costo.
 * Reemplaza la pantalla read-only original. Soporta tipo CLIENTE (read-only,
 * gestionado por observer) + tipos MANUAL (GENERAL/PROYECTO/SUCURSAL/OTRO)
 * que se crean a mano para gastos no atribuibles a un cliente.
 */

type CC = {
  id: number;
  codigo: string;
  nombre: string;
  tipo: 'CLIENTE' | 'GENERAL' | 'PROYECTO' | 'SUCURSAL' | 'OTRO';
  activo: number | boolean;
  auxiliar_id: number | null;
  auxiliar_nombre: string | null;
  observaciones: string | null;
  movimientos_count: number;
};

const TIPOS_MANUAL: CC['tipo'][] = ['GENERAL', 'PROYECTO', 'SUCURSAL', 'OTRO'];

const TIPO_BADGES: Record<CC['tipo'], 'info' | 'default' | 'warning' | 'success' | 'neutral'> = {
  CLIENTE: 'info',
  GENERAL: 'default',
  PROYECTO: 'warning',
  SUCURSAL: 'success',
  OTRO: 'neutral',
};

export function CentrosCostoPage() {
  const [tipo, setTipo] = useState('');
  const [q, setQ] = useState('');
  const [incluirInactivos, setIncluirInactivos] = useState(false);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [editar, setEditar] = useState<CC | null>(null);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (tipo) p.set('tipo', tipo);
    if (q) p.set('q', q);
    if (incluirInactivos) p.set('incluir_inactivos', '1');
    return p.toString();
  }, [tipo, q, incluirInactivos]);

  const { data, isLoading, error } = useApi<CC[]>(
    ['cc-abm', qs],
    `/api/erp/centros-costo/abm${qs ? `?${qs}` : ''}`
  );

  const toast = useToast();
  const invalidate = useInvalidate(['cc-abm']);

  const eliminar = useApiMutation<unknown, number>(
    (id) => api.delete(`/api/erp/centros-costo/${id}`),
    {
      onSuccess: () => { toast.success('CC desactivado'); invalidate(); },
      onError: (e) => toast.error('Error al eliminar', errorMessage(e)),
    }
  );
  const reactivar = useApiMutation<unknown, number>(
    (id) => api.post(`/api/erp/centros-costo/${id}/reactivar`),
    {
      onSuccess: () => { toast.success('CC reactivado'); invalidate(); },
      onError: (e) => toast.error('Error al reactivar', errorMessage(e)),
    }
  );

  const cols: Column<CC>[] = [
    { key: 'codigo', header: 'Código', width: '160px',
      render: (r) => (
        <span className={r.activo ? '' : 'opacity-50 italic'}>
          <code className="text-[11.5px] text-azure font-semibold">{r.codigo}</code>
        </span>
      ) },
    { key: 'nombre', header: 'Nombre',
      render: (r) => (
        <span className={r.activo ? '' : 'opacity-50 italic'}>
          {r.nombre}
          {!r.activo && <span className="ml-2"><Badge variant="warning">INACTIVO</Badge></span>}
        </span>
      ) },
    { key: 'tipo', header: 'Tipo', width: '110px',
      render: (r) => <Badge variant={TIPO_BADGES[r.tipo]}>{r.tipo}</Badge> },
    { key: 'auxiliar', header: 'Cliente / Origen',
      render: (r) => r.auxiliar_nombre
        ? <span className="text-[12px]">{r.auxiliar_nombre}</span>
        : <span className="text-ink-muted text-[11.5px]">—</span> },
    { key: 'movimientos_count', header: 'Movs.', align: 'right', width: '90px',
      render: (r) => r.movimientos_count > 0
        ? <Badge variant="default">{r.movimientos_count}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'acciones', header: '', align: 'right', width: '120px',
      render: (r) => (
        <div className="flex justify-end gap-1">
          {r.tipo !== 'CLIENTE' || r.activo ? (
            <button onClick={() => setEditar(r)}
              className="p-1 opacity-60 hover:opacity-100 hover:text-azure"
              title="Editar">
              <Pencil className="w-3 h-3" />
            </button>
          ) : null}
          {r.tipo !== 'CLIENTE' && r.activo && (
            <button onClick={() => {
              if (confirm(`Desactivar CC ${r.codigo} (${r.nombre})?`)) eliminar.mutate(r.id);
            }}
              className="p-1 opacity-60 hover:opacity-100 hover:text-danger"
              title="Desactivar"
              disabled={eliminar.isPending}>
              <Trash2 className="w-3 h-3" />
            </button>
          )}
          {r.tipo !== 'CLIENTE' && !r.activo && (
            <button onClick={() => reactivar.mutate(r.id)}
              className="p-1 opacity-70 hover:opacity-100 hover:text-success"
              title="Reactivar"
              disabled={reactivar.isPending}>
              <RotateCcw className="w-3 h-3" />
            </button>
          )}
        </div>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2">
            <Building2 className="w-4 h-4 text-azure" /> Centros de Costos
          </div>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Nuevo CC manual
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            CCs tipo <strong>CLIENTE</strong> se crean automáticamente al alta de un cliente
            (formato <code>CLI-{`{slug}`}</code>). CCs <strong>manuales</strong> (GENERAL/PROYECTO/SUCURSAL/OTRO)
            se crean acá para gastos no atribuibles a un cliente específico (ej: <code>MANT-FLOTA</code>, <code>ALQUILER-OFI</code>).
          </div>

          <div className="flex flex-wrap gap-3 items-end">
            <SelectField label="Tipo" value={tipo} placeholder="Todos"
              onChange={(e) => setTipo(e.target.value)}
              options={[
                { value: 'CLIENTE', label: 'CLIENTE (auto)' },
                ...TIPOS_MANUAL.map((t) => ({ value: t, label: t })),
              ]}
              containerClassName="w-[170px]" />
            <Field label="Buscar" value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="código o nombre…"
              containerClassName="w-[240px]" />
            <label className="flex items-center gap-1 text-[11.5px] cursor-pointer">
              <input type="checkbox" checked={incluirInactivos}
                onChange={(e) => setIncluirInactivos(e.target.checked)} />
              Mostrar inactivos
            </label>
          </div>

          {error && <FormError error={errorMessage(error)} />}

          <DataTable columns={cols} rows={data ?? []} loading={isLoading}
            empty="Sin CC para mostrar." />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevoCcModal onClose={() => setNuevoOpen(false)} onCreated={() => { invalidate(); setNuevoOpen(false); }} />}
      {editar && <EditarCcModal cc={editar} onClose={() => setEditar(null)} onUpdated={() => { invalidate(); setEditar(null); }} />}
    </div>
  );
}

function NuevoCcModal({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const toast = useToast();
  const [form, setForm] = useState({ codigo: '', nombre: '', tipo: 'GENERAL' as CC['tipo'], observaciones: '' });
  const [err, setErr] = useState<string | null>(null);

  const m = useApiMutation<unknown, typeof form>(
    (vars) => api.post('/api/erp/centros-costo', vars),
    {
      onSuccess: () => { toast.success('CC creado'); onCreated(); },
      onError: (e) => setErr((e as ApiError).message),
    }
  );

  return (
    <Modal open onClose={onClose} title="Nuevo Centro de Costos manual" size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!form.codigo || !form.nombre || m.isPending}
          onClick={() => { setErr(null); m.mutate(form); }}>
          {m.isPending ? 'Creando…' : 'Crear'}
        </Button>
      </>}
    >
      <div className="space-y-3">
        <Field label="Código" required value={form.codigo}
          onChange={(e) => setForm({ ...form, codigo: e.target.value.toUpperCase() })}
          placeholder="MANT-FLOTA" hint="Mayúsculas, sin espacios. Ej: ALQUILER-OFI, SUC-NORTE" />
        <Field label="Nombre" required value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })}
          placeholder="Mantenimiento de Flota" />
        <SelectField label="Tipo" required value={form.tipo}
          onChange={(e) => setForm({ ...form, tipo: e.target.value as CC['tipo'] })}
          options={TIPOS_MANUAL.map((t) => ({ value: t, label: t }))} />
        <Field label="Observaciones (opcional)" value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={err} />
      </div>
    </Modal>
  );
}

function EditarCcModal({ cc, onClose, onUpdated }: { cc: CC; onClose: () => void; onUpdated: () => void }) {
  const toast = useToast();
  const [form, setForm] = useState({
    nombre: cc.nombre,
    codigo: cc.codigo,
    observaciones: cc.observaciones ?? '',
  });
  const [err, setErr] = useState<string | null>(null);

  const codigoReadOnly = cc.tipo === 'CLIENTE' || cc.movimientos_count > 0;

  const m = useApiMutation<unknown, typeof form>(
    (vars) => api.put(`/api/erp/centros-costo/${cc.id}`, vars),
    {
      onSuccess: () => { toast.success('CC actualizado'); onUpdated(); },
      onError: (e) => setErr((e as ApiError).message),
    }
  );

  return (
    <Modal open onClose={onClose} title={`Editar ${cc.codigo}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!form.nombre || m.isPending}
          onClick={() => { setErr(null); m.mutate(form); }}>
          {m.isPending ? 'Guardando…' : 'Guardar'}
        </Button>
      </>}
    >
      <div className="space-y-3">
        <div className="text-[11.5px] text-ink-muted">
          Tipo: <Badge variant={TIPO_BADGES[cc.tipo]}>{cc.tipo}</Badge>
          {cc.movimientos_count > 0 && <> · {cc.movimientos_count} movimiento(s) registrado(s)</>}
        </div>
        <Field label="Código" required value={form.codigo}
          onChange={(e) => setForm({ ...form, codigo: e.target.value.toUpperCase() })}
          disabled={codigoReadOnly}
          hint={codigoReadOnly
            ? (cc.tipo === 'CLIENTE'
                ? 'Read-only en CCs tipo CLIENTE (auto-generado desde el cliente).'
                : 'Read-only — el CC ya tiene movimientos contables.')
            : undefined} />
        <Field label="Nombre" required value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })} />
        <Field label="Observaciones" value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={err} />
      </div>
    </Modal>
  );
}
