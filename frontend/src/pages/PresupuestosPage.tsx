import { useMemo, useState } from 'react';
import { Plus, FileSpreadsheet, GitBranch, CheckCircle2, X } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Presupuesto = {
  id: number;
  empresa_id: number;
  ejercicio_id: number;
  ejercicio?: { id: number; codigo: string; fecha_inicio: string; fecha_cierre: string };
  nombre: string;
  estado: 'BORRADOR' | 'APROBADO' | 'VIGENTE' | 'HISTORICO' | 'DESCARTADO';
  es_reforecast: boolean;
  forecast_base_id: number | null;
  moneda: string;
  descripcion: string | null;
  aprobado_at: string | null;
  vigente_desde: string | null;
  vigente_hasta: string | null;
  creador?: { id: number; name: string };
  created_at: string;
};

const ESTADOS = ['BORRADOR', 'APROBADO', 'VIGENTE', 'HISTORICO', 'DESCARTADO'];

function badgeFor(estado: Presupuesto['estado']) {
  switch (estado) {
    case 'VIGENTE': return 'success' as const;
    case 'APROBADO': return 'info' as const;
    case 'BORRADOR': return 'warning' as const;
    case 'HISTORICO': return 'neutral' as const;
    case 'DESCARTADO': return 'danger' as const;
  }
}

function transicionesPosibles(estado: Presupuesto['estado']): Array<{ estado: string; accion: string; variant: 'primary' | 'outline' | 'danger' }> {
  switch (estado) {
    case 'BORRADOR': return [
      { estado: 'APROBADO', accion: 'aprobar', variant: 'primary' },
      { estado: 'DESCARTADO', accion: 'descartar', variant: 'danger' },
    ];
    case 'APROBADO': return [
      { estado: 'VIGENTE', accion: 'vigente', variant: 'primary' },
      { estado: 'DESCARTADO', accion: 'descartar', variant: 'danger' },
    ];
    default: return [];
  }
}

export function PresupuestosPage() {
  const [filtros, setFiltros] = useState({ ejercicio_id: '', estado: '' });
  const [page, setPage] = useState(1);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [transOpen, setTransOpen] = useState<{ p: Presupuesto; estado: string; accion: string } | null>(null);
  const [reforOpen, setReforOpen] = useState<Presupuesto | null>(null);
  const [verItems, setVerItems] = useState<Presupuesto | null>(null);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (filtros.ejercicio_id) p.set('ejercicio_id', filtros.ejercicio_id);
    if (filtros.estado) p.set('estado', filtros.estado);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [filtros, page]);

  const { data, isLoading, error } = useApi<Paginator<Presupuesto>>(
    ['presupuestos', qs],
    `/api/erp/presupuestos${qs ? `?${qs}` : ''}`
  );

  const columns: Column<Presupuesto>[] = [
    { key: 'nombre', header: 'Presupuesto',
      render: (r) => (
        <div>
          <div className="text-[12.5px] font-medium">{r.nombre}</div>
          {r.es_reforecast && (
            <div className="text-[10.5px] text-ink-muted flex items-center gap-1">
              <GitBranch className="w-3 h-3" /> reforecast de #{r.forecast_base_id}
            </div>
          )}
        </div>
      ) },
    { key: 'ejercicio', header: 'Ejercicio', width: '130px',
      render: (r) => r.ejercicio ? r.ejercicio.codigo : `#${r.ejercicio_id}` },
    { key: 'moneda', header: 'Moneda', width: '80px' },
    { key: 'estado', header: 'Estado', width: '120px',
      render: (r) => <Badge variant={badgeFor(r.estado)}>{r.estado}</Badge> },
    { key: 'vigente', header: 'Vigencia', width: '180px',
      render: (r) => r.vigente_desde
        ? `${fmtDate(r.vigente_desde)} → ${r.vigente_hasta ? fmtDate(r.vigente_hasta) : 'actual'}`
        : '—' },
    { key: 'creado', header: 'Creado', width: '130px',
      render: (r) => fmtDate(r.created_at) },
    { key: 'acciones', header: '', align: 'right', width: '320px',
      render: (r) => {
        const trans = transicionesPosibles(r.estado);
        return (
          <div className="flex justify-end gap-1.5 flex-wrap">
            <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setVerItems(r); }}>
              Items
            </Button>
            <Link to={`/erp/presupuestos/ejecucion?id=${r.id}`} onClick={(e) => e.stopPropagation()}>
              <Button size="sm" variant="ghost">
                <FileSpreadsheet className="w-3 h-3" /> Ejecución
              </Button>
            </Link>
            {trans.map((t) => (
              <Button key={t.estado} size="sm" variant={t.variant}
                onClick={(e) => { e.stopPropagation(); setTransOpen({ p: r, estado: t.estado, accion: t.accion }); }}>
                {t.estado === 'APROBADO' && <CheckCircle2 className="w-3 h-3" />}
                {t.estado === 'DESCARTADO' && <X className="w-3 h-3" />}
                {t.estado}
              </Button>
            ))}
            {(r.estado === 'APROBADO' || r.estado === 'VIGENTE') && (
              <Button size="sm" variant="ghost"
                onClick={(e) => { e.stopPropagation(); setReforOpen(r); }}>
                <GitBranch className="w-3 h-3" /> Reforecast
              </Button>
            )}
          </div>
        );
      } },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><FileSpreadsheet className="w-4 h-4 text-azure" /> Presupuestos</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Nuevo presupuesto
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <Field label="ID ejercicio" type="number" value={filtros.ejercicio_id}
              onChange={(e) => { setFiltros({ ...filtros, ejercicio_id: e.target.value }); setPage(1); }}
              containerClassName="w-[160px]" />
            <SelectField label="Estado" value={filtros.estado} placeholder="Todos"
              onChange={(e) => { setFiltros({ ...filtros, estado: e.target.value }); setPage(1); }}
              options={ESTADOS.map((s) => ({ value: s, label: s }))}
              containerClassName="w-[160px]" />
          </div>

          {error && <FormError error={errorMessage(error)} />}

          <DataTable columns={columns} paginator={data} loading={isLoading}
            onPageChange={setPage} empty="Sin presupuestos" />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevoModal onClose={() => setNuevoOpen(false)} />}
      {transOpen && <TransicionConfirm {...transOpen} onClose={() => setTransOpen(null)} />}
      {reforOpen && <ReforecastModal p={reforOpen} onClose={() => setReforOpen(null)} />}
      {verItems && <ItemsDrawer presupuesto={verItems} onClose={() => setVerItems(null)} />}
    </div>
  );
}

function NuevoModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['presupuestos']);
  const [form, setForm] = useState({ ejercicio_id: '', nombre: '', moneda: 'ARS', descripcion: '' });

  const m = useApiMutation<Presupuesto, Record<string, unknown>>(
    (vars) => api.post('/api/erp/presupuestos', vars),
    {
      onSuccess: () => {
        toast.success('Presupuesto creado');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    }
  );

  const valid = form.ejercicio_id && form.nombre.trim();

  return (
    <Modal open onClose={onClose} title="Nuevo presupuesto" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              ejercicio_id: Number(form.ejercicio_id),
              nombre: form.nombre.trim(),
              moneda: form.moneda || 'ARS',
              descripcion: form.descripcion.trim() || null,
            })}>
            {m.isPending ? 'Creando…' : 'Crear borrador'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="ID ejercicio" required type="number" value={form.ejercicio_id}
            onChange={(e) => setForm({ ...form, ejercicio_id: e.target.value })} />
          <Field label="Moneda" value={form.moneda} maxLength={3}
            onChange={(e) => setForm({ ...form, moneda: e.target.value.toUpperCase() })} />
        </div>
        <Field label="Nombre" required value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })}
          placeholder="Presupuesto 2026 v1" />
        <TextareaField label="Descripción" rows={3} value={form.descripcion}
          onChange={(e) => setForm({ ...form, descripcion: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function TransicionConfirm({ p, estado, accion, onClose }: { p: Presupuesto; estado: string; accion: string; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['presupuestos']);

  const m = useApiMutation(
    () => api.post(`/api/erp/presupuestos/${p.id}/${accion}`),
    {
      onSuccess: () => {
        toast.success(`Presupuesto → ${estado}`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo transicionar', errorMessage(e)),
    }
  );

  const isDanger = estado === 'DESCARTADO';
  const messages: Record<string, string> = {
    APROBADO: 'Pasa el presupuesto a APROBADO. No se podrá editar la grilla salvo reforecast.',
    VIGENTE: 'Marca este presupuesto como vigente. El presupuesto vigente anterior pasa a HISTORICO (RN-85).',
    DESCARTADO: 'El presupuesto queda descartado de forma definitiva.',
  };

  return (
    <ConfirmDialog open onClose={onClose} variant={isDanger ? 'danger' : 'primary'}
      title={`${p.estado} → ${estado}`}
      message={
        <div className="space-y-2">
          <div>{messages[estado] ?? `Cambiar estado a ${estado}.`}</div>
          <div className="text-[11.5px] text-ink-muted">{p.nombre}</div>
        </div>
      }
      confirmLabel="Confirmar" loading={m.isPending}
      onConfirm={() => m.mutate(undefined as unknown as void)} />
  );
}

function ReforecastModal({ p, onClose }: { p: Presupuesto; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['presupuestos']);
  const [nombre, setNombre] = useState(`${p.nombre} v2`);

  const m = useApiMutation<Presupuesto, { nombre: string }>(
    (vars) => api.post(`/api/erp/presupuestos/${p.id}/reforecast`, vars),
    {
      onSuccess: () => {
        toast.success('Reforecast creado', 'Editá la grilla del nuevo borrador');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo crear reforecast', errorMessage(e)),
    }
  );

  return (
    <Modal open onClose={onClose} title={`Reforecast de ${p.nombre}`} size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={nombre.trim().length < 3 || m.isPending}
            onClick={() => m.mutate({ nombre: nombre.trim() })}>
            {m.isPending ? 'Clonando…' : 'Crear reforecast'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <div className="text-[12px] text-ink-2 bg-info-bg/30 border border-info/30 rounded-md p-3">
          Se clona la grilla en un nuevo borrador. El original conserva su estado;
          al pasar este nuevo a VIGENTE el actual irá a HISTORICO.
        </div>
        <Field label="Nombre del nuevo presupuesto" required value={nombre}
          onChange={(e) => setNombre(e.target.value)} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

type Item = {
  id: number;
  presupuesto_id: number;
  cuenta_id: number;
  centro_costo_id: number | null;
  mes: number;
  importe: number | string;
  notas: string | null;
  cuenta?: { id: number; codigo: string; nombre: string };
  centroCosto?: { id: number; codigo: string; nombre: string };
};

function ItemsDrawer({ presupuesto, onClose }: { presupuesto: Presupuesto; onClose: () => void }) {
  const { data, isLoading } = useApi<Item[]>(
    ['presupuesto-items', presupuesto.id],
    `/api/erp/presupuestos/${presupuesto.id}/items`
  );

  const grilla = useMemo(() => {
    const out: Record<string, { cuenta: string; cc: string | null; meses: Record<number, number> }> = {};
    (data ?? []).forEach((i) => {
      const k = `${i.cuenta_id}|${i.centro_costo_id ?? 0}`;
      out[k] ??= {
        cuenta: i.cuenta ? `${i.cuenta.codigo} ${i.cuenta.nombre}` : `#${i.cuenta_id}`,
        cc: i.centroCosto ? `${i.centroCosto.codigo}` : null,
        meses: {},
      };
      out[k].meses[i.mes] = Number(i.importe);
    });
    return Object.values(out);
  }, [data]);

  return (
    <Modal open onClose={onClose} title={`Items de "${presupuesto.nombre}"`} size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}
    >
      <div className="space-y-3">
        <div className="text-[12px] text-ink-muted">
          {data?.length ?? 0} items en {grilla.length} cuentas/CC.
          Carga masiva mediante POST <code>/items</code> (bulk upsert).
        </div>
        {isLoading ? (
          <div className="py-8 text-center text-ink-muted">Cargando…</div>
        ) : grilla.length === 0 ? (
          <div className="py-8 text-center text-ink-muted">Sin items cargados</div>
        ) : (
          <div className="overflow-x-auto border border-line rounded-md">
            <table className="w-full text-[11.5px]">
              <thead className="bg-[#FAFBFC] text-[10.5px] uppercase text-ink-muted">
                <tr>
                  <th className="px-2 py-2 text-left">Cuenta</th>
                  <th className="px-2 py-2 text-left w-[80px]">CC</th>
                  {Array.from({ length: 12 }, (_, i) => (
                    <th key={i} className="px-2 py-2 text-right">{String(i + 1).padStart(2, '0')}</th>
                  ))}
                  <th className="px-2 py-2 text-right">Total</th>
                </tr>
              </thead>
              <tbody>
                {grilla.map((row, i) => {
                  const total = Object.values(row.meses).reduce((a, b) => a + b, 0);
                  return (
                    <tr key={i} className="border-t border-line/60">
                      <td className="px-2 py-1.5">{row.cuenta}</td>
                      <td className="px-2 py-1.5 text-ink-muted">{row.cc ?? '—'}</td>
                      {Array.from({ length: 12 }, (_, m) => (
                        <td key={m} className="px-2 py-1.5 text-right tabular-nums">
                          {row.meses[m + 1] ? row.meses[m + 1].toLocaleString('es-AR', { maximumFractionDigits: 0 }) : '—'}
                        </td>
                      ))}
                      <td className="px-2 py-1.5 text-right tabular-nums font-semibold">
                        {total.toLocaleString('es-AR', { maximumFractionDigits: 0 })}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </Modal>
  );
}
