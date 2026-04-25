import { useState } from 'react';
import { Plus, Pencil, Tags, Trash2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Categoria = {
  id: number;
  codigo: string;
  nombre: string;
  descripcion: string | null;
  vida_util_contable_meses: number;
  vida_util_fiscal_meses: number;
  valor_residual_pct: number | string | null;
  metodo_amortizacion: 'LINEAL' | 'UNIDADES' | null;
  cuenta_bien_id: number;
  cuenta_amort_acum_id: number;
  cuenta_amort_ejercicio_id: number;
  cuenta_resultado_baja_pos_id: number;
  cuenta_resultado_baja_neg_id: number;
  umbral_baja_cuantia: number | string | null;
  activa: boolean | number;
};

export function CategoriasAfPage() {
  const { data, isLoading } = useApi<Categoria[]>(['af-categorias'], '/api/erp/af/categorias');

  const [editar, setEditar] = useState<Categoria | null>(null);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [borrar, setBorrar] = useState<Categoria | null>(null);

  const columns: Column<Categoria>[] = [
    { key: 'codigo', header: 'Código', width: '110px',
      render: (r) => <code className="text-[12px]">{r.codigo}</code> },
    { key: 'nombre', header: 'Nombre' },
    { key: 'vu_cont', header: 'VU contable', width: '110px', align: 'right',
      render: (r) => `${r.vida_util_contable_meses} m` },
    { key: 'vu_fisc', header: 'VU fiscal', width: '100px', align: 'right',
      render: (r) => `${r.vida_util_fiscal_meses} m` },
    { key: 'residual', header: 'Residual', align: 'right', width: '90px',
      render: (r) => r.valor_residual_pct != null ? `${Number(r.valor_residual_pct).toFixed(2)}%` : '—' },
    { key: 'metodo', header: 'Método', width: '90px',
      render: (r) => <Badge variant="default">{r.metodo_amortizacion ?? 'LINEAL'}</Badge> },
    { key: 'umbral', header: 'Umbral baja', align: 'right', width: '110px',
      render: (r) => r.umbral_baja_cuantia ? fmtMoney(Number(r.umbral_baja_cuantia)) : '—' },
    { key: 'activa', header: 'Activa', width: '80px',
      render: (r) => r.activa ? <Badge variant="success">SÍ</Badge> : <Badge variant="neutral">NO</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '120px',
      render: (r) => (
        <div className="flex justify-end gap-1.5">
          <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setEditar(r); }}>
            <Pencil className="w-3 h-3" />
          </Button>
          <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setBorrar(r); }}>
            <Trash2 className="w-3 h-3 text-danger" />
          </Button>
        </div>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Tags className="w-4 h-4 text-azure" /> Categorías de Activos Fijos</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Nueva categoría
            </Button>
          }
        />
        <CardBody className="p-4">
          <DataTable columns={columns} rows={data ?? []} loading={isLoading}
            empty="Sin categorías cargadas" />
        </CardBody>
      </Card>

      {nuevoOpen && <CategoriaModal onClose={() => setNuevoOpen(false)} />}
      {editar && <CategoriaModal categoria={editar} onClose={() => setEditar(null)} />}
      {borrar && <DesactivarConfirm categoria={borrar} onClose={() => setBorrar(null)} />}
    </div>
  );
}

function CategoriaModal({ categoria, onClose }: { categoria?: Categoria; onClose: () => void }) {
  const editar = !!categoria;
  const toast = useToast();
  const invalidate = useInvalidate(['af-categorias']);
  const [form, setForm] = useState({
    codigo: categoria?.codigo ?? '',
    nombre: categoria?.nombre ?? '',
    descripcion: categoria?.descripcion ?? '',
    vida_util_contable_meses: String(categoria?.vida_util_contable_meses ?? 60),
    vida_util_fiscal_meses: String(categoria?.vida_util_fiscal_meses ?? 60),
    valor_residual_pct: categoria?.valor_residual_pct != null ? String(categoria.valor_residual_pct) : '',
    metodo_amortizacion: categoria?.metodo_amortizacion ?? 'LINEAL',
    cuenta_bien_id: String(categoria?.cuenta_bien_id ?? ''),
    cuenta_amort_acum_id: String(categoria?.cuenta_amort_acum_id ?? ''),
    cuenta_amort_ejercicio_id: String(categoria?.cuenta_amort_ejercicio_id ?? ''),
    cuenta_resultado_baja_pos_id: String(categoria?.cuenta_resultado_baja_pos_id ?? ''),
    cuenta_resultado_baja_neg_id: String(categoria?.cuenta_resultado_baja_neg_id ?? ''),
    umbral_baja_cuantia: categoria?.umbral_baja_cuantia != null ? String(categoria.umbral_baja_cuantia) : '',
  });

  const m = useApiMutation<Categoria, Record<string, unknown>>(
    (vars) => editar
      ? api.put(`/api/erp/af/categorias/${categoria!.id}`, vars)
      : api.post('/api/erp/af/categorias', vars),
    {
      onSuccess: () => {
        toast.success(editar ? 'Categoría actualizada' : 'Categoría creada');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo guardar', errorMessage(e)),
    }
  );

  const submit = () => {
    const payload: Record<string, unknown> = {
      codigo: form.codigo.trim(),
      nombre: form.nombre.trim(),
      descripcion: form.descripcion.trim() || null,
      vida_util_contable_meses: Number(form.vida_util_contable_meses),
      vida_util_fiscal_meses: Number(form.vida_util_fiscal_meses),
      metodo_amortizacion: form.metodo_amortizacion,
      cuenta_bien_id: Number(form.cuenta_bien_id),
      cuenta_amort_acum_id: Number(form.cuenta_amort_acum_id),
      cuenta_amort_ejercicio_id: Number(form.cuenta_amort_ejercicio_id),
      cuenta_resultado_baja_pos_id: Number(form.cuenta_resultado_baja_pos_id),
      cuenta_resultado_baja_neg_id: Number(form.cuenta_resultado_baja_neg_id),
    };
    if (form.valor_residual_pct) payload.valor_residual_pct = Number(form.valor_residual_pct);
    if (form.umbral_baja_cuantia) payload.umbral_baja_cuantia = Number(form.umbral_baja_cuantia);
    m.mutate(payload);
  };

  return (
    <Modal open onClose={onClose} title={editar ? `Editar categoría ${categoria!.codigo}` : 'Nueva categoría'} size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={m.isPending} onClick={submit}>
            {m.isPending ? 'Guardando…' : 'Guardar'}
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-3 gap-3">
        <Field label="Código" required value={form.codigo}
          onChange={(e) => setForm({ ...form, codigo: e.target.value })} placeholder="MUEBLES" />
        <Field label="Nombre" required value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })} containerClassName="col-span-2" />
        <TextareaField label="Descripción" value={form.descripcion} rows={2}
          onChange={(e) => setForm({ ...form, descripcion: e.target.value })} containerClassName="col-span-3" />

        <Field label="VU contable (meses)" required type="number" value={form.vida_util_contable_meses}
          onChange={(e) => setForm({ ...form, vida_util_contable_meses: e.target.value })} />
        <Field label="VU fiscal (meses)" required type="number" value={form.vida_util_fiscal_meses}
          onChange={(e) => setForm({ ...form, vida_util_fiscal_meses: e.target.value })} />
        <Field label="Residual (%)" type="number" step="0.01" value={form.valor_residual_pct}
          onChange={(e) => setForm({ ...form, valor_residual_pct: e.target.value })} placeholder="0" />

        <SelectField label="Método amortización" value={form.metodo_amortizacion}
          onChange={(e) => setForm({ ...form, metodo_amortizacion: e.target.value as 'LINEAL' | 'UNIDADES' })}
          options={[{ value: 'LINEAL', label: 'Lineal' }, { value: 'UNIDADES', label: 'Unidades producidas' }]}
          placeholder={null} />
        <Field label="Umbral baja directa" type="number" step="0.01" value={form.umbral_baja_cuantia}
          onChange={(e) => setForm({ ...form, umbral_baja_cuantia: e.target.value })}
          hint="Cuantías menores se dan de baja directa" containerClassName="col-span-2" />

        <div className="col-span-3 mt-2 mb-1 text-[11.5px] uppercase text-ink-muted font-semibold">Cuentas contables</div>
        <Field label="Cta. Bien (Activo)" required type="number" value={form.cuenta_bien_id}
          onChange={(e) => setForm({ ...form, cuenta_bien_id: e.target.value })} hint="ID cuenta plan" />
        <Field label="Cta. Amort. acumulada" required type="number" value={form.cuenta_amort_acum_id}
          onChange={(e) => setForm({ ...form, cuenta_amort_acum_id: e.target.value })} />
        <Field label="Cta. Amort. del ejercicio" required type="number" value={form.cuenta_amort_ejercicio_id}
          onChange={(e) => setForm({ ...form, cuenta_amort_ejercicio_id: e.target.value })} />
        <Field label="Cta. Resultado baja (+)" required type="number" value={form.cuenta_resultado_baja_pos_id}
          onChange={(e) => setForm({ ...form, cuenta_resultado_baja_pos_id: e.target.value })} />
        <Field label="Cta. Resultado baja (−)" required type="number" value={form.cuenta_resultado_baja_neg_id}
          onChange={(e) => setForm({ ...form, cuenta_resultado_baja_neg_id: e.target.value })} />
      </div>
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}

function DesactivarConfirm({ categoria, onClose }: { categoria: Categoria; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['af-categorias']);
  const m = useApiMutation(
    () => api.delete(`/api/erp/af/categorias/${categoria.id}`),
    {
      onSuccess: () => {
        toast.success('Categoría desactivada');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo', errorMessage(e)),
    }
  );
  return (
    <ConfirmDialog open variant="danger" onClose={onClose}
      title={`Desactivar ${categoria.codigo}?`}
      message="La categoría dejará de estar disponible para nuevos bienes. Los bienes existentes no se afectan."
      confirmLabel="Desactivar" loading={m.isPending}
      onConfirm={() => m.mutate(undefined as unknown as void)} />
  );
}
