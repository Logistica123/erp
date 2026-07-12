import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BookUser, Plus, Pencil, Trash2, RotateCcw } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { api, type ApiError } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * v1.55 Bloque C — ABM de auxiliares (reemplaza el placeholder).
 * Usa el index paginado nuevo /auxiliares/abm (incluye inactivos y todos
 * los tipos, cosa que el catálogo para selects no hace).
 */

const TIPOS = ['Cliente', 'Proveedor', 'Distribuidor', 'Empleado', 'Socio', 'Vehiculo', 'Sucursal', 'Colocacion', 'Bien', 'Organismo'] as const;

type Auxiliar = {
  id: number; tipo: string; codigo: string; nombre: string; cuit: string | null;
  activo: boolean | number; tabla_ref: string | null;
  cuenta_contable_default_id: number | null;
  cuenta_default: { id: number; codigo: string; nombre: string } | null;
};
type Paginator<T> = { data: T[]; current_page: number; last_page: number; total: number };
type Cuenta = { id: number; codigo: string; nombre: string };

const TIPO_BADGE: Record<string, 'info' | 'default' | 'warning' | 'success' | 'neutral'> = {
  Cliente: 'info', Proveedor: 'default', Distribuidor: 'success', Empleado: 'warning',
};

export function AuxiliaresAdminPage() {
  const [tipo, setTipo] = useState('');
  const [q, setQ] = useState('');
  const [incluirInactivos, setIncluirInactivos] = useState(false);
  const [page, setPage] = useState(1);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [editar, setEditar] = useState<Auxiliar | null>(null);

  const toast = useToast();
  const invalidate = useInvalidate(['admin-auxiliares']);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (tipo) p.set('tipo', tipo);
    if (q) p.set('q', q);
    if (incluirInactivos) p.set('incluir_inactivos', '1');
    p.set('page', String(page));
    return p.toString();
  }, [tipo, q, incluirInactivos, page]);

  const { data: pag, isLoading, error } = useQuery<Paginator<Auxiliar>, ApiError>({
    queryKey: ['admin-auxiliares', qs],
    queryFn: () => api.get(`/api/erp/auxiliares/abm?${qs}`),
  });

  const desactivar = useApiMutation<unknown, Auxiliar>(
    (a) => api.post(`/api/erp/auxiliares/${a.id}/desactivar`, { desactivar_cc: false }),
    {
      onSuccess: () => { toast.success('Auxiliar desactivado'); invalidate(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );
  const reactivar = useApiMutation<unknown, number>(
    (id) => api.post(`/api/erp/auxiliares/${id}/reactivar`),
    {
      onSuccess: () => { toast.success('Auxiliar reactivado'); invalidate(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );

  const cols: Column<Auxiliar>[] = [
    { key: 'codigo', header: 'Código', width: '150px',
      render: (a) => <code className={`text-[11.5px] font-semibold ${a.activo ? 'text-azure' : 'opacity-50'}`}>{a.codigo}</code> },
    { key: 'nombre', header: 'Nombre',
      render: (a) => (
        <span className={a.activo ? '' : 'opacity-50 italic'}>
          {a.nombre}
          {!a.activo && <span className="ml-2"><Badge variant="warning">INACTIVO</Badge></span>}
          {a.tabla_ref && <span className="ml-2"><Badge variant="neutral">DistriApp</Badge></span>}
        </span>
      ) },
    { key: 'tipo', header: 'Tipo', width: '110px',
      render: (a) => <Badge variant={TIPO_BADGE[a.tipo] ?? 'neutral'}>{a.tipo}</Badge> },
    { key: 'cuit', header: 'CUIT', width: '110px',
      render: (a) => a.cuit ? <span className="font-mono text-[11.5px]">{a.cuit}</span> : <span className="text-ink-muted">—</span> },
    { key: 'cuenta', header: 'Cuenta default',
      render: (a) => a.cuenta_default
        ? <span className="text-[11.5px]"><code>{a.cuenta_default.codigo}</code> {a.cuenta_default.nombre}</span>
        : <span className="text-ink-muted text-[11.5px]">—</span> },
    { key: 'acciones', header: '', align: 'right', width: '90px',
      render: (a) => (
        <div className="flex justify-end gap-1">
          <button onClick={() => setEditar(a)} title="Editar"
            className="p-1 opacity-60 hover:opacity-100 hover:text-azure">
            <Pencil className="w-3 h-3" />
          </button>
          {a.activo ? (
            <button onClick={() => desactivar.mutate(a)} title="Desactivar" disabled={desactivar.isPending}
              className="p-1 opacity-60 hover:opacity-100 hover:text-danger">
              <Trash2 className="w-3 h-3" />
            </button>
          ) : (
            <button onClick={() => reactivar.mutate(a.id)} title="Reactivar" disabled={reactivar.isPending}
              className="p-1 opacity-60 hover:opacity-100 hover:text-success">
              <RotateCcw className="w-3 h-3" />
            </button>
          )}
        </div>
      ) },
  ];

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title={<span className="flex items-center gap-2"><BookUser className="w-4 h-4" /> Auxiliares</span>}
          actions={<Button variant="primary" onClick={() => setNuevoOpen(true)}><Plus className="w-3 h-3" /> Nuevo auxiliar</Button>}
        />
        <CardBody>
          <div className="flex flex-wrap items-center gap-3 mb-3">
            <SelectField value={tipo} onChange={(e) => { setTipo(e.target.value); setPage(1); }}
              placeholder="Todos los tipos"
              options={TIPOS.map((t) => ({ value: t, label: t }))} />
            <input value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }}
              placeholder="Buscar por nombre, código o CUIT…"
              className="px-3 py-1.5 text-[12.5px] border border-line rounded-md w-[280px]" />
            <label className="flex items-center gap-1.5 text-[12px] cursor-pointer">
              <input type="checkbox" checked={incluirInactivos}
                onChange={(e) => { setIncluirInactivos(e.target.checked); setPage(1); }} />
              Incluir inactivos
            </label>
            {pag && pag.last_page > 1 && (
              <div className="flex items-center gap-2 text-[12px] ml-auto">
                <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>‹</Button>
                <span>página {pag.current_page} / {pag.last_page} · {pag.total}</span>
                <Button variant="secondary" disabled={page >= pag.last_page} onClick={() => setPage(page + 1)}>›</Button>
              </div>
            )}
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={cols} rows={pag?.data ?? []} loading={isLoading} empty="Sin auxiliares" />
        </CardBody>
      </Card>

      <NuevoAuxiliarModal open={nuevoOpen} onClose={() => setNuevoOpen(false)}
        onSuccess={() => { setNuevoOpen(false); invalidate(); }} />
      {editar && (
        <EditarAuxiliarModal aux={editar} onClose={() => setEditar(null)}
          onSuccess={() => { setEditar(null); invalidate(); }} />
      )}
    </div>
  );
}

function useCuentasImputables(enabled: boolean) {
  return useApi<Cuenta[]>(['cuentas-imputables'], '/api/erp/cuentas?imputable=1', { enabled });
}

function NuevoAuxiliarModal({ open, onClose, onSuccess }: {
  open: boolean; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const [form, setForm] = useState({ tipo: 'Proveedor', codigo: '', nombre: '', cuit: '', cuenta: '' });
  const [err, setErr] = useState<string | null>(null);
  const { data: cuentas } = useCuentasImputables(open);

  const crear = useApiMutation<unknown, void>(
    () => api.post('/api/erp/auxiliares', {
      tipo: form.tipo,
      manual: {
        codigo: form.codigo || undefined,
        nombre: form.nombre,
        cuit: form.cuit || undefined,
      },
      cuenta_contable_default_id: form.cuenta ? Number(form.cuenta) : undefined,
    }),
    {
      onSuccess: () => {
        toast.success('Auxiliar creado');
        setForm({ tipo: 'Proveedor', codigo: '', nombre: '', cuit: '', cuenta: '' }); setErr(null);
        onSuccess();
      },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  return (
    <Modal open={open} onClose={onClose} title="Nuevo auxiliar (manual)" size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!form.nombre || crear.isPending} onClick={() => crear.mutate()}>
          Crear auxiliar
        </Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        <SelectField label="Tipo" required value={form.tipo}
          onChange={(e) => setForm({ ...form, tipo: e.target.value })}
          placeholder={null}
          options={TIPOS.map((t) => ({ value: t, label: t }))} />
        <Field label="Nombre" required value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })} />
        <Field label="Código" value={form.codigo} placeholder="Se autogenera si queda vacío"
          onChange={(e) => setForm({ ...form, codigo: e.target.value })} />
        <Field label="CUIT" value={form.cuit} placeholder="Sin guiones"
          onChange={(e) => setForm({ ...form, cuit: e.target.value.replace(/[^0-9]/g, '') })} />
        <SelectField label="Cuenta contable default" value={form.cuenta}
          hint="Si queda vacío se asigna la default del tipo (ej: Proveedor → 2.1.1.01)."
          onChange={(e) => setForm({ ...form, cuenta: e.target.value })}
          placeholder="(default por tipo)"
          options={(cuentas ?? []).map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))} />
        <div className="text-[11px] text-ink-muted">
          Los Clientes y Distribuidores de DistriApp se crean desde sus flujos de sync — esta alta
          manual es para auxiliares locales.
        </div>
      </div>
    </Modal>
  );
}

function EditarAuxiliarModal({ aux, onClose, onSuccess }: {
  aux: Auxiliar; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const [form, setForm] = useState({
    nombre: aux.nombre, cuit: aux.cuit ?? '',
    cuenta: aux.cuenta_contable_default_id ? String(aux.cuenta_contable_default_id) : '',
  });
  const [err, setErr] = useState<string | null>(null);
  const { data: cuentas } = useCuentasImputables(true);

  const guardar = useApiMutation<unknown, void>(
    () => api.patch(`/api/erp/auxiliares/${aux.id}`, {
      nombre: form.nombre,
      cuit: form.cuit || null,
      cuenta_contable_default_id: form.cuenta ? Number(form.cuenta) : null,
    }),
    {
      onSuccess: () => { toast.success('Auxiliar actualizado'); onSuccess(); },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  return (
    <Modal open onClose={onClose} title={`Editar · ${aux.codigo}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!form.nombre || guardar.isPending} onClick={() => guardar.mutate()}>
          Guardar
        </Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        <div className="text-[11.5px] text-ink-muted">
          Tipo <Badge variant="neutral">{aux.tipo}</Badge> y código <code>{aux.codigo}</code> no
          se editan (identidad contable del auxiliar).
        </div>
        <Field label="Nombre" required value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })} />
        <Field label="CUIT" value={form.cuit} placeholder="Sin guiones"
          onChange={(e) => setForm({ ...form, cuit: e.target.value.replace(/[^0-9]/g, '') })} />
        <SelectField label="Cuenta contable default" value={form.cuenta}
          onChange={(e) => setForm({ ...form, cuenta: e.target.value })}
          placeholder="(sin cuenta default)"
          options={(cuentas ?? []).map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))} />
      </div>
    </Modal>
  );
}
