import { useState } from 'react';
import { BookOpen, Plus, Pencil } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * v1.55 Bloque C — ABM de diarios contables (reemplaza el placeholder).
 * Sin borrado: los diarios llevan el numerador legal de asientos (RN-9);
 * se desactivan y AsientoService ya rechaza diarios inactivos.
 */

const TIPOS = ['MANUAL', 'SISTEMA', 'BANCO', 'VENTAS', 'COMPRAS', 'TESORERIA', 'AJUSTE', 'APERTURA', 'CIERRE'] as const;

type Diario = {
  id: number; codigo: string; nombre: string; descripcion: string | null;
  tipo: string; numerador_actual: number; activo: boolean | number; asientos_count: number;
};

export function DiariosAdminPage() {
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [editar, setEditar] = useState<Diario | null>(null);

  const toast = useToast();
  const invalidate = useInvalidate(['admin-diarios']);
  const { data: diarios, isLoading, error } = useApi<Diario[]>(['admin-diarios'], '/api/erp/admin/diarios');

  const toggleActivo = useApiMutation<unknown, Diario>(
    (d) => api.patch(`/api/erp/admin/diarios/${d.id}`, { activo: !d.activo }),
    {
      onSuccess: () => { toast.success('Diario actualizado'); invalidate(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );

  const cols: Column<Diario>[] = [
    { key: 'codigo', header: 'Código', width: '110px',
      render: (d) => <code className={`text-[11.5px] font-semibold ${d.activo ? 'text-azure' : 'opacity-50'}`}>{d.codigo}</code> },
    { key: 'nombre', header: 'Nombre',
      render: (d) => (
        <span className={d.activo ? '' : 'opacity-50 italic'}>
          {d.nombre}
          {!d.activo && <span className="ml-2"><Badge variant="warning">INACTIVO</Badge></span>}
          {d.descripcion && <span className="block text-[10.5px] text-ink-muted">{d.descripcion}</span>}
        </span>
      ) },
    { key: 'tipo', header: 'Tipo', width: '110px', render: (d) => <Badge variant="default">{d.tipo}</Badge> },
    { key: 'numerador_actual', header: 'Numerador', align: 'right', width: '100px',
      render: (d) => <span className="tabular text-[12px]">{d.numerador_actual}</span> },
    { key: 'asientos_count', header: 'Asientos', align: 'right', width: '90px',
      render: (d) => d.asientos_count > 0
        ? <Badge variant="default">{d.asientos_count}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'acciones', header: '', align: 'right', width: '130px',
      render: (d) => (
        <div className="flex justify-end items-center gap-1">
          <button onClick={() => setEditar(d)} title="Editar"
            className="p-1 opacity-60 hover:opacity-100 hover:text-azure">
            <Pencil className="w-3 h-3" />
          </button>
          <button onClick={() => toggleActivo.mutate(d)} disabled={toggleActivo.isPending}
            className={`px-1.5 text-[10.5px] rounded border ${d.activo
              ? 'border-danger/40 text-danger hover:bg-danger-bg/30'
              : 'border-success/40 text-success hover:bg-success-bg/30'}`}>
            {d.activo ? 'Desactivar' : 'Activar'}
          </button>
        </div>
      ) },
  ];

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title={<span className="flex items-center gap-2"><BookOpen className="w-4 h-4" /> Diarios contables</span>}
          actions={<Button variant="primary" onClick={() => setNuevoOpen(true)}><Plus className="w-3 h-3" /> Nuevo diario</Button>}
        />
        <CardBody>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={cols} rows={diarios ?? []} loading={isLoading} empty="Sin diarios" />
          <div className="text-[11px] text-ink-muted mt-2">
            El numerador es el correlativo legal de asientos del diario — no se edita. Los diarios
            con asientos no se borran: se desactivan.
          </div>
        </CardBody>
      </Card>

      <DiarioModal open={nuevoOpen} diario={null}
        onClose={() => setNuevoOpen(false)}
        onSuccess={() => { setNuevoOpen(false); invalidate(); }} />
      {editar && (
        <DiarioModal open diario={editar}
          onClose={() => setEditar(null)}
          onSuccess={() => { setEditar(null); invalidate(); }} />
      )}
    </div>
  );
}

function DiarioModal({ open, diario, onClose, onSuccess }: {
  open: boolean; diario: Diario | null; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const [form, setForm] = useState({
    codigo: diario?.codigo ?? '',
    nombre: diario?.nombre ?? '',
    descripcion: diario?.descripcion ?? '',
    tipo: diario?.tipo ?? 'MANUAL',
  });
  const [err, setErr] = useState<string | null>(null);

  const guardar = useApiMutation<unknown, void>(
    () => diario
      ? api.patch(`/api/erp/admin/diarios/${diario.id}`, {
          nombre: form.nombre, descripcion: form.descripcion || null, tipo: form.tipo,
        })
      : api.post('/api/erp/admin/diarios', {
          codigo: form.codigo, nombre: form.nombre,
          descripcion: form.descripcion || null, tipo: form.tipo,
        }),
    {
      onSuccess: () => {
        toast.success(diario ? 'Diario actualizado' : 'Diario creado');
        setForm({ codigo: '', nombre: '', descripcion: '', tipo: 'MANUAL' }); setErr(null);
        onSuccess();
      },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  const valid = form.nombre && (diario || form.codigo);

  return (
    <Modal open={open} onClose={onClose} size="md"
      title={diario ? `Editar diario · ${diario.codigo}` : 'Nuevo diario'}
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!valid || guardar.isPending} onClick={() => guardar.mutate()}>
          {diario ? 'Guardar' : 'Crear diario'}
        </Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        {!diario && (
          <Field label="Código" required value={form.codigo} placeholder="ej: SEG"
            hint="Mayúsculas, números, guión. No se puede cambiar después."
            onChange={(e) => setForm({ ...form, codigo: e.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '') })} />
        )}
        <Field label="Nombre" required value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })} />
        <TextareaField label="Descripción" rows={2} value={form.descripcion}
          onChange={(e) => setForm({ ...form, descripcion: e.target.value })} />
        <SelectField label="Tipo" required value={form.tipo}
          onChange={(e) => setForm({ ...form, tipo: e.target.value })}>
          {TIPOS.map((t) => <option key={t} value={t}>{t}</option>)}
        </SelectField>
      </div>
    </Modal>
  );
}
