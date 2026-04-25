import { useMemo, useState } from 'react';
import { Plus, Wrench, Eye, ArrowUpCircle, ArrowDownCircle, RefreshCw, Hammer } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Bien = {
  id: number;
  empresa_id: number;
  nro_inventario: string;
  categoria_id: number;
  categoria?: { id: number; codigo: string; nombre: string };
  descripcion: string;
  marca: string | null;
  modelo: string | null;
  nro_serie: string | null;
  patente: string | null;
  fecha_alta: string;
  valor_origen: number | string;
  valor_residual_cfg: number | string | null;
  vida_util_contable_meses: number | null;
  vida_util_fiscal_meses: number | null;
  estado: 'ALTA' | 'EN_REPARACION' | 'PRESTADO' | 'BAJA';
  fecha_baja: string | null;
  motivo_baja: string | null;
  ubicacion: string | null;
  centro_costo_id: number | null;
};

type Categoria = { id: number; codigo: string; nombre: string };

type Movimiento = {
  id: number;
  tipo: 'ALTA' | 'MEJORA' | 'REVALUO' | 'BAJA' | 'TRANSFERENCIA' | 'AMORTIZACION';
  fecha: string;
  importe: number | string | null;
  descripcion: string | null;
  asiento_id: number | null;
};

const ESTADOS = ['ALTA', 'EN_REPARACION', 'PRESTADO', 'BAJA'];

function badgeFor(estado: Bien['estado']) {
  switch (estado) {
    case 'ALTA': return 'success' as const;
    case 'EN_REPARACION': return 'warning' as const;
    case 'PRESTADO': return 'info' as const;
    case 'BAJA': return 'danger' as const;
    default: return 'neutral' as const;
  }
}

export function BienesPage() {
  const [filtros, setFiltros] = useState({ q: '', estado: '', categoria_id: '' });
  const [page, setPage] = useState(1);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [verBien, setVerBien] = useState<Bien | null>(null);

  const { data: cats } = useApi<Categoria[]>(['af-categorias'], '/api/erp/af/categorias');

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (filtros.q) p.set('q', filtros.q);
    if (filtros.estado) p.set('estado', filtros.estado);
    if (filtros.categoria_id) p.set('categoria_id', filtros.categoria_id);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [filtros, page]);

  const { data, isLoading, error } = useApi<Paginator<Bien>>(
    ['af-bienes', qs],
    `/api/erp/af/bienes${qs ? `?${qs}` : ''}`
  );

  const columns: Column<Bien>[] = [
    { key: 'nro_inventario', header: 'Inventario', width: '140px',
      render: (r) => <code className="text-[12px]">{r.nro_inventario}</code> },
    { key: 'descripcion', header: 'Descripción',
      render: (r) => (
        <div>
          <div>{r.descripcion}</div>
          {(r.marca || r.modelo) && (
            <div className="text-[10.5px] text-ink-muted">{[r.marca, r.modelo].filter(Boolean).join(' / ')}</div>
          )}
        </div>
      ) },
    { key: 'categoria', header: 'Categoría', width: '160px',
      render: (r) => r.categoria ? <Badge variant="default">{r.categoria.codigo}</Badge> : '—' },
    { key: 'fecha_alta', header: 'Alta', width: '100px',
      render: (r) => fmtDate(r.fecha_alta) },
    { key: 'valor_origen', header: 'Valor origen', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.valor_origen)) },
    { key: 'estado', header: 'Estado', width: '120px',
      render: (r) => <Badge variant={badgeFor(r.estado)}>{r.estado}</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '70px',
      render: (r) => (
        <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setVerBien(r); }}>
          <Eye className="w-3 h-3" />
        </Button>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Wrench className="w-4 h-4 text-azure" /> Bienes de uso</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Alta de bien
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <Field label="Buscar" value={filtros.q}
              onChange={(e) => { setFiltros({ ...filtros, q: e.target.value }); setPage(1); }}
              placeholder="inventario / descripción / serie / patente"
              containerClassName="w-[280px]" />
            <SelectField label="Estado" value={filtros.estado} placeholder="Todos"
              onChange={(e) => { setFiltros({ ...filtros, estado: e.target.value }); setPage(1); }}
              options={ESTADOS.map((s) => ({ value: s, label: s }))}
              containerClassName="w-[160px]" />
            <SelectField label="Categoría" value={filtros.categoria_id} placeholder="Todas"
              onChange={(e) => { setFiltros({ ...filtros, categoria_id: e.target.value }); setPage(1); }}
              options={(cats ?? []).map((c) => ({ value: String(c.id), label: `${c.codigo} — ${c.nombre}` }))}
              containerClassName="w-[260px]" />
          </div>

          {error && <FormError error={errorMessage(error)} />}

          <DataTable columns={columns} paginator={data} loading={isLoading}
            onPageChange={setPage} onRowClick={(r) => setVerBien(r)}
            empty="Sin bienes registrados" />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevoBienModal categorias={cats ?? []} onClose={() => setNuevoOpen(false)} />}
      {verBien && <DetalleBienDrawer bien={verBien} onClose={() => setVerBien(null)} />}
    </div>
  );
}

function NuevoBienModal({ categorias, onClose }: { categorias: Categoria[]; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['af-bienes']);
  const [form, setForm] = useState({
    categoria_id: '',
    nro_inventario: '',
    descripcion: '',
    fecha_alta: new Date().toISOString().slice(0, 10),
    valor_origen: '',
    marca: '',
    modelo: '',
    nro_serie: '',
    patente: '',
    ubicacion: '',
    centro_costo_id: '',
    responsable_user_id: '',
  });

  const m = useApiMutation<Bien, Record<string, unknown>>(
    (vars) => api.post('/api/erp/af/bienes', vars),
    {
      onSuccess: () => {
        toast.success('Bien dado de alta');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    }
  );

  const submit = () => {
    const payload: Record<string, unknown> = {
      categoria_id: Number(form.categoria_id),
      nro_inventario: form.nro_inventario.trim(),
      descripcion: form.descripcion.trim(),
      fecha_alta: form.fecha_alta,
      valor_origen: Number(form.valor_origen),
    };
    for (const k of ['marca', 'modelo', 'nro_serie', 'patente', 'ubicacion'] as const) {
      if (form[k].trim()) payload[k] = form[k].trim();
    }
    if (form.centro_costo_id) payload.centro_costo_id = Number(form.centro_costo_id);
    if (form.responsable_user_id) payload.responsable_user_id = Number(form.responsable_user_id);
    m.mutate(payload);
  };

  const valid = form.categoria_id && form.nro_inventario && form.descripcion && form.fecha_alta &&
    Number(form.valor_origen) > 0;

  return (
    <Modal open onClose={onClose} title="Alta de bien" size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending} onClick={submit}>
            {m.isPending ? 'Guardando…' : 'Crear'}
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-3 gap-3">
        <SelectField label="Categoría" required value={form.categoria_id}
          onChange={(e) => setForm({ ...form, categoria_id: e.target.value })}
          options={categorias.map((c) => ({ value: String(c.id), label: `${c.codigo} — ${c.nombre}` }))}
          containerClassName="col-span-2" />
        <Field label="Nº inventario" required value={form.nro_inventario}
          onChange={(e) => setForm({ ...form, nro_inventario: e.target.value })} placeholder="MUE-001" />

        <Field label="Descripción" required value={form.descripcion}
          onChange={(e) => setForm({ ...form, descripcion: e.target.value })} containerClassName="col-span-3" />

        <Field label="Marca" value={form.marca}
          onChange={(e) => setForm({ ...form, marca: e.target.value })} />
        <Field label="Modelo" value={form.modelo}
          onChange={(e) => setForm({ ...form, modelo: e.target.value })} />
        <Field label="Nº serie" value={form.nro_serie}
          onChange={(e) => setForm({ ...form, nro_serie: e.target.value })} />

        <Field label="Patente" value={form.patente}
          onChange={(e) => setForm({ ...form, patente: e.target.value })} />
        <Field label="Fecha alta" required type="date" value={form.fecha_alta}
          onChange={(e) => setForm({ ...form, fecha_alta: e.target.value })} />
        <Field label="Valor origen" required type="number" step="0.01" value={form.valor_origen}
          onChange={(e) => setForm({ ...form, valor_origen: e.target.value })} />

        <Field label="Ubicación" value={form.ubicacion}
          onChange={(e) => setForm({ ...form, ubicacion: e.target.value })} containerClassName="col-span-3" />
        <Field label="Centro de costo (id)" type="number" value={form.centro_costo_id}
          onChange={(e) => setForm({ ...form, centro_costo_id: e.target.value })} />
        <Field label="Responsable (user id)" type="number" value={form.responsable_user_id}
          onChange={(e) => setForm({ ...form, responsable_user_id: e.target.value })} />
      </div>
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}

function DetalleBienDrawer({ bien, onClose }: { bien: Bien; onClose: () => void }) {
  const { data: movs } = useApi<Movimiento[]>(
    ['af-bienes-movs', bien.id],
    `/api/erp/af/bienes/${bien.id}/movimientos`
  );

  const [accion, setAccion] = useState<'mejora' | 'revaluo' | 'baja' | null>(null);

  return (
    <Modal open onClose={onClose}
      title={`Bien ${bien.nro_inventario} — ${bien.descripcion}`}
      size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}
    >
      <div className="space-y-4">
        <div className="grid grid-cols-3 gap-3 text-[12.5px]">
          <Stat label="Estado" value={<Badge variant={badgeFor(bien.estado)}>{bien.estado}</Badge>} />
          <Stat label="Categoría" value={bien.categoria ? `${bien.categoria.codigo} ${bien.categoria.nombre}` : '—'} />
          <Stat label="Fecha alta" value={fmtDate(bien.fecha_alta)} />
          <Stat label="Valor origen" value={fmtMoney(Number(bien.valor_origen))} />
          <Stat label="Residual" value={bien.valor_residual_cfg ? fmtMoney(Number(bien.valor_residual_cfg)) : '—'} />
          <Stat label="VU contable" value={bien.vida_util_contable_meses ? `${bien.vida_util_contable_meses} m` : '—'} />
          <Stat label="VU fiscal" value={bien.vida_util_fiscal_meses ? `${bien.vida_util_fiscal_meses} m` : '—'} />
          <Stat label="Ubicación" value={bien.ubicacion || '—'} />
          <Stat label="CC" value={bien.centro_costo_id ?? '—'} />
        </div>

        {bien.estado !== 'BAJA' && (
          <div className="flex flex-wrap gap-2 border-t border-line pt-3">
            <Button size="sm" variant="outline" onClick={() => setAccion('mejora')}>
              <Hammer className="w-3 h-3" /> Mejora
            </Button>
            <Button size="sm" variant="outline" onClick={() => setAccion('revaluo')}>
              <RefreshCw className="w-3 h-3" /> Revalúo
            </Button>
            <Button size="sm" variant="danger" onClick={() => setAccion('baja')}>
              <ArrowDownCircle className="w-3 h-3" /> Dar de baja
            </Button>
          </div>
        )}

        <div>
          <div className="text-[11.5px] uppercase font-semibold text-ink-muted mb-2">Timeline</div>
          {!movs || movs.length === 0 ? (
            <div className="text-[12px] text-ink-muted">Sin movimientos registrados</div>
          ) : (
            <ol className="border-l-2 border-line ml-2 space-y-2">
              {movs.map((mv) => (
                <li key={mv.id} className="ml-4 relative">
                  <span className="absolute -left-[19px] top-1.5 w-3 h-3 rounded-full bg-azure" />
                  <div className="flex items-baseline gap-2">
                    <Badge variant="default">{mv.tipo}</Badge>
                    <span className="text-[11px] text-ink-muted">{fmtDate(mv.fecha)}</span>
                    {mv.importe != null && (
                      <span className="text-[12px] font-medium tabular-nums">
                        {Number(mv.importe) >= 0 ? <ArrowUpCircle className="w-3 h-3 inline text-success" />
                          : <ArrowDownCircle className="w-3 h-3 inline text-danger" />}{' '}
                        {fmtMoney(Number(mv.importe))}
                      </span>
                    )}
                    {mv.asiento_id && (
                      <span className="text-[10.5px] text-ink-muted">(asiento #{mv.asiento_id})</span>
                    )}
                  </div>
                  {mv.descripcion && <div className="text-[11.5px] text-ink-2">{mv.descripcion}</div>}
                </li>
              ))}
            </ol>
          )}
        </div>
      </div>

      {accion && <AccionModal bienId={bien.id} accion={accion} onClose={() => setAccion(null)} />}
    </Modal>
  );
}

function AccionModal({ bienId, accion, onClose }: { bienId: number; accion: 'mejora' | 'revaluo' | 'baja'; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['af-bienes', 'af-bienes-movs']);

  const m = useApiMutation<unknown, Record<string, unknown>>(
    (vars) => api.post(`/api/erp/af/bienes/${bienId}/${accion}`, vars),
    {
      onSuccess: () => {
        toast.success('Movimiento registrado');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo registrar', errorMessage(e)),
    }
  );

  if (accion === 'mejora') return <MejoraForm m={m} onClose={onClose} />;
  if (accion === 'revaluo') return <RevaluoForm m={m} onClose={onClose} />;
  return <BajaForm m={m} onClose={onClose} />;
}

type Mut = { mutate: (v: Record<string, unknown>) => void; isPending: boolean; error: unknown };

function MejoraForm({ m, onClose }: { m: Mut; onClose: () => void }) {
  const [form, setForm] = useState({ importe: '', fecha: '', descripcion: '', vu_extension_meses: '' });
  const valid = Number(form.importe) > 0;
  return (
    <Modal open onClose={onClose} title="Registrar mejora" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              importe: Number(form.importe),
              fecha: form.fecha || undefined,
              descripcion: form.descripcion || undefined,
              vu_extension_meses: form.vu_extension_meses ? Number(form.vu_extension_meses) : undefined,
            })}>
            {m.isPending ? 'Guardando…' : 'Registrar'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <Field label="Importe" required type="number" step="0.01" value={form.importe}
          onChange={(e) => setForm({ ...form, importe: e.target.value })} />
        <div className="grid grid-cols-2 gap-3">
          <Field label="Fecha" type="date" value={form.fecha}
            onChange={(e) => setForm({ ...form, fecha: e.target.value })} />
          <Field label="Extensión VU (meses)" type="number" value={form.vu_extension_meses}
            onChange={(e) => setForm({ ...form, vu_extension_meses: e.target.value })} hint="Opcional" />
        </div>
        <TextareaField label="Descripción" rows={2} value={form.descripcion}
          onChange={(e) => setForm({ ...form, descripcion: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function RevaluoForm({ m, onClose }: { m: Mut; onClose: () => void }) {
  const [form, setForm] = useState({ nuevo_valor: '', fecha: '', descripcion: '' });
  const valid = Number(form.nuevo_valor) > 0;
  return (
    <Modal open onClose={onClose} title="Revalúo técnico" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              nuevo_valor: Number(form.nuevo_valor),
              fecha: form.fecha || undefined,
              descripcion: form.descripcion || undefined,
            })}>
            {m.isPending ? 'Guardando…' : 'Registrar'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <Field label="Nuevo valor" required type="number" step="0.01" value={form.nuevo_valor}
          onChange={(e) => setForm({ ...form, nuevo_valor: e.target.value })} />
        <Field label="Fecha" type="date" value={form.fecha}
          onChange={(e) => setForm({ ...form, fecha: e.target.value })} />
        <TextareaField label="Descripción" rows={2} value={form.descripcion}
          onChange={(e) => setForm({ ...form, descripcion: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function BajaForm({ m, onClose }: { m: Mut; onClose: () => void }) {
  const [form, setForm] = useState({ motivo: '', fecha: '', valor_recupero: '' });
  const valid = form.motivo.trim().length >= 3;
  return (
    <Modal open onClose={onClose} title="Dar de baja" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              motivo: form.motivo.trim(),
              fecha: form.fecha || undefined,
              valor_recupero: form.valor_recupero ? Number(form.valor_recupero) : undefined,
            })}>
            {m.isPending ? 'Procesando…' : 'Dar de baja'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <div className="text-[12px] text-ink-2 bg-warning-bg/40 border border-warning/30 rounded-md p-3">
          La baja genera asiento contable (resultado positivo o negativo según valor recupero vs VR).
        </div>
        <Field label="Motivo" required value={form.motivo}
          onChange={(e) => setForm({ ...form, motivo: e.target.value })} />
        <div className="grid grid-cols-2 gap-3">
          <Field label="Fecha" type="date" value={form.fecha}
            onChange={(e) => setForm({ ...form, fecha: e.target.value })} />
          <Field label="Valor recupero" type="number" step="0.01" value={form.valor_recupero}
            onChange={(e) => setForm({ ...form, valor_recupero: e.target.value })}
            hint="Opcional — venta del bien" />
        </div>
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
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
