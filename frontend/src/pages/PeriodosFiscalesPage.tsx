import { useMemo, useState } from 'react';
import { CalendarCheck, Plus, GitBranch } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Periodo = {
  id: number;
  empresa_id: number;
  impuesto: string;
  anio: number;
  mes: number | null;
  ejercicio_id: number | null;
  estado: string;
  fecha_vencimiento: string;
  fecha_presentacion: string | null;
  nro_tramite: string | null;
  observaciones: string | null;
  rectifica_a_id: number | null;
  aprobado_at: string | null;
  presentado_at: string | null;
};

const IMPUESTOS = [
  'IVA', 'SICORE', 'SIRE', 'IIBB_CM', 'IIBB_CABA', 'IIBB_PBA',
  'GAN_ANUAL', 'GAN_ANTICIPO', 'BP_PART',
];
const ESTADOS = ['ABIERTO', 'EN_REVISION', 'APROBADO', 'PRESENTADO', 'CERRADO', 'RECTIFICATIVA'];

function badgeFor(estado: string) {
  switch (estado) {
    case 'PRESENTADO':
    case 'CERRADO':       return 'success' as const;
    case 'APROBADO':      return 'info' as const;
    case 'EN_REVISION':   return 'warning' as const;
    case 'RECTIFICATIVA': return 'danger' as const;
    default:              return 'neutral' as const;
  }
}

function transicionesPosibles(actual: string): string[] {
  switch (actual) {
    case 'ABIERTO':     return ['EN_REVISION'];
    case 'EN_REVISION': return ['APROBADO', 'ABIERTO'];
    case 'APROBADO':    return ['PRESENTADO', 'EN_REVISION'];
    case 'PRESENTADO':  return ['CERRADO'];
    default:            return [];
  }
}

export function PeriodosFiscalesPage() {
  const [impuesto, setImpuesto] = useState('');
  const [estado, setEstado] = useState('');
  const [anio, setAnio] = useState('');
  const [page, setPage] = useState(1);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (impuesto) p.set('impuesto', impuesto);
    if (estado) p.set('estado', estado);
    if (anio) p.set('anio', anio);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [impuesto, estado, anio, page]);

  const { data, isLoading, error } = useApi<Paginator<Periodo>>(
    ['periodos', qs],
    `/api/erp/impuestos/periodos${qs ? `?${qs}` : ''}`
  );

  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [transOpen, setTransOpen] = useState<{ p: Periodo; nuevo: string } | null>(null);
  const [rectOpen, setRectOpen] = useState<Periodo | null>(null);

  const columns: Column<Periodo>[] = [
    { key: 'impuesto', header: 'Impuesto', width: '120px' },
    { key: 'periodo', header: 'Período', width: '110px',
      render: (r) => r.mes ? `${r.anio}/${String(r.mes).padStart(2, '0')}` : `Ejer ${r.anio}` },
    { key: 'fecha_vencimiento', header: 'Vence', width: '95px',
      render: (r) => fmtDate(r.fecha_vencimiento) },
    { key: 'estado', header: 'Estado', width: '140px',
      render: (r) => <Badge variant={badgeFor(r.estado)}>{r.estado}</Badge> },
    { key: 'nro_tramite', header: 'Nº trámite', width: '130px',
      render: (r) => r.nro_tramite || '—' },
    { key: 'rectifica_a_id', header: 'Rectifica',
      render: (r) => r.rectifica_a_id ? <Badge variant="danger">Rect. de #{r.rectifica_a_id}</Badge> : '—' },
    { key: 'acciones', header: '', align: 'right', width: '300px',
      render: (r) => {
        const trans = transicionesPosibles(r.estado);
        return (
          <div className="flex justify-end gap-1.5 flex-wrap">
            {trans.map((t) => (
              <Button key={t} size="sm" variant="outline"
                onClick={(e) => { e.stopPropagation(); setTransOpen({ p: r, nuevo: t }); }}>
                → {t}
              </Button>
            ))}
            {(r.estado === 'PRESENTADO' || r.estado === 'CERRADO') && !r.rectifica_a_id && (
              <Button size="sm" variant="ghost"
                onClick={(e) => { e.stopPropagation(); setRectOpen(r); }}>
                <GitBranch className="w-3 h-3" /> Rectificar
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
          title={<div className="flex items-center gap-2"><CalendarCheck className="w-4 h-4 text-azure" /> Períodos fiscales</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Nuevo período
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <SelectField label="Impuesto" value={impuesto} placeholder="Todos"
              onChange={(e) => { setImpuesto(e.target.value); setPage(1); }}
              containerClassName="w-[170px]"
              options={IMPUESTOS.map((i) => ({ value: i, label: i }))} />
            <SelectField label="Estado" value={estado} placeholder="Todos"
              onChange={(e) => { setEstado(e.target.value); setPage(1); }}
              containerClassName="w-[160px]"
              options={ESTADOS.map((s) => ({ value: s, label: s }))} />
            <Field label="Año" type="number" value={anio} placeholder="2026"
              onChange={(e) => { setAnio(e.target.value); setPage(1); }}
              containerClassName="w-[120px]" />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={columns} paginator={data} loading={isLoading} onPageChange={setPage} />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevoPeriodoModal onClose={() => setNuevoOpen(false)} />}
      {transOpen && <TransicionModal {...transOpen} onClose={() => setTransOpen(null)} />}
      {rectOpen && <RectificarModal periodo={rectOpen} onClose={() => setRectOpen(null)} />}
    </div>
  );
}

function NuevoPeriodoModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['periodos']);
  const [form, setForm] = useState({
    impuesto: 'IVA', anio: new Date().getFullYear(), mes: '', ejercicio_id: '',
  });
  const isAnual = form.impuesto === 'GAN_ANUAL' || form.impuesto === 'BP_PART';

  const m = useApiMutation<Periodo, Record<string, unknown>>(
    (vars) => api.post('/api/erp/impuestos/periodos', vars),
    {
      onSuccess: () => {
        toast.success('Período creado');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    }
  );

  const submit = () => {
    const payload: Record<string, unknown> = {
      impuesto: form.impuesto, anio: Number(form.anio),
    };
    if (!isAnual && form.mes) payload.mes = Number(form.mes);
    if (isAnual && form.ejercicio_id) payload.ejercicio_id = Number(form.ejercicio_id);
    m.mutate(payload);
  };

  const valid = form.impuesto && form.anio &&
    (isAnual ? form.ejercicio_id : form.mes && Number(form.mes) >= 1 && Number(form.mes) <= 12);

  return (
    <Modal open onClose={onClose} title="Nuevo período fiscal" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending} onClick={submit}>
            {m.isPending ? 'Creando…' : 'Crear'}
          </Button>
        </>
      }>
      <div className="grid grid-cols-2 gap-3">
        <SelectField label="Impuesto" required value={form.impuesto}
          onChange={(e) => setForm({ ...form, impuesto: e.target.value })}
          options={IMPUESTOS.map((i) => ({ value: i, label: i }))} placeholder={null} />
        <Field label="Año" required type="number" value={String(form.anio)}
          onChange={(e) => setForm({ ...form, anio: Number(e.target.value) })} />
        {!isAnual && (
          <Field label="Mes (1..12)" required type="number" min={1} max={12} value={form.mes}
            onChange={(e) => setForm({ ...form, mes: e.target.value })} />
        )}
        {isAnual && (
          <Field label="ID Ejercicio" required type="number" value={form.ejercicio_id}
            onChange={(e) => setForm({ ...form, ejercicio_id: e.target.value })}
            hint="Necesario para Ganancias y BP" />
        )}
      </div>
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}

function TransicionModal({ p, nuevo, onClose }: { p: Periodo; nuevo: string; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['periodos']);
  const [nroTramite, setNroTramite] = useState('');
  const [obs, setObs] = useState('');
  const requiereTramite = nuevo === 'PRESENTADO';

  const m = useApiMutation<Periodo, Record<string, unknown>>(
    (vars) => api.patch(`/api/erp/impuestos/periodos/${p.id}`, vars),
    {
      onSuccess: () => {
        toast.success(`Período → ${nuevo}`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo transicionar', errorMessage(e)),
    }
  );
  const submit = () => {
    const payload: Record<string, unknown> = { estado: nuevo };
    if (nroTramite) payload.nro_tramite = nroTramite;
    if (obs) payload.observaciones = obs;
    m.mutate(payload);
  };
  return (
    <Modal open onClose={onClose}
      title={`${p.estado} → ${nuevo}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary"
            disabled={(requiereTramite && !nroTramite.trim()) || m.isPending}
            onClick={submit}>
            {m.isPending ? 'Procesando…' : 'Confirmar'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="text-[12.5px] text-ink-2">
          {p.impuesto} {p.mes ? `${p.anio}/${String(p.mes).padStart(2, '0')}` : `Ejer ${p.anio}`}
        </div>
        {requiereTramite && (
          <Field label="Nº de trámite AFIP/AGIP/ARBA" required value={nroTramite}
            onChange={(e) => setNroTramite(e.target.value)} placeholder="Acuse de presentación" />
        )}
        <TextareaField label="Observaciones" value={obs}
          onChange={(e) => setObs(e.target.value)} rows={2} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function RectificarModal({ periodo, onClose }: { periodo: Periodo; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['periodos']);
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<Periodo, { motivo: string }>(
    (vars) => api.post(`/api/erp/impuestos/periodos/${periodo.id}/rectificativa`, vars),
    {
      onSuccess: () => {
        toast.success('Rectificativa creada', 'Edita el nuevo período');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo rectificar', errorMessage(e)),
    }
  );
  return (
    <Modal open onClose={onClose}
      title={`Rectificativa de ${periodo.impuesto} ${periodo.anio}/${periodo.mes ?? ''}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={motivo.trim().length < 5 || m.isPending}
            onClick={() => m.mutate({ motivo: motivo.trim() })}>
            {m.isPending ? 'Creando…' : 'Crear rectificativa'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="text-[12px] text-ink-2 bg-warning-bg/40 border border-warning/30 rounded-md p-3">
          RN-44: el período original no se modifica. Se crea uno nuevo con
          <code className="px-1 mx-1 bg-white">estado=RECTIFICATIVA</code>
          y referencia al original. Editá los datos correctos en el nuevo.
        </div>
        <TextareaField label="Motivo de la rectificativa" required rows={3}
          value={motivo} onChange={(e) => setMotivo(e.target.value)}
          placeholder="Ej: Olvidé NC del cliente X" />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
