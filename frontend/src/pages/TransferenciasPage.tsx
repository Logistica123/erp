import { useMemo, useState } from 'react';
import { ArrowLeftRight, Plus, BookOpen, Trash2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type CuentaCorta = { id: number; codigo: string; nombre: string };
type MonedaCorta = { id: number; codigo: string };

type Transferencia = {
  id: number;
  fecha: string;
  cuenta_origen: CuentaCorta;
  cuenta_destino: CuentaCorta;
  importe_origen: number | string;
  importe_destino: number | string;
  tipo_cambio?: number | string;
  moneda_origen: MonedaCorta;
  moneda_destino: MonedaCorta;
  estado: 'BORRADOR' | 'CONTABILIZADA' | 'ANULADA';
  concepto?: string;
};

const ESTADOS = ['BORRADOR', 'CONTABILIZADA', 'ANULADA'];

function badgeFor(estado: string) {
  switch (estado) {
    case 'CONTABILIZADA': return 'success' as const;
    case 'ANULADA':       return 'danger' as const;
    default:              return 'warning' as const;
  }
}

export function TransferenciasPage() {
  const [estado, setEstado] = useState('');
  const [page, setPage] = useState(1);
  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (estado) p.set('estado', estado);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [estado, page]);

  const { data, isLoading, error } = useApi<Paginator<Transferencia>>(
    ['transferencias', qs],
    `/api/erp/transferencias-internas${qs ? `?${qs}` : ''}`
  );

  const [nuevaOpen, setNuevaOpen] = useState(false);
  const [contabOpen, setContabOpen] = useState<Transferencia | null>(null);
  const [anularOpen, setAnularOpen] = useState<Transferencia | null>(null);

  const columns: Column<Transferencia>[] = [
    { key: 'fecha', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha) },
    { key: 'origen', header: 'Origen',
      render: (r) => `${r.cuenta_origen.codigo} ${r.cuenta_origen.nombre}` },
    { key: 'destino', header: 'Destino',
      render: (r) => `${r.cuenta_destino.codigo} ${r.cuenta_destino.nombre}` },
    { key: 'importe_origen', header: 'Importe', align: 'right', width: '130px',
      render: (r) => `${r.moneda_origen?.codigo} ${fmtMoney(r.importe_origen)}` },
    { key: 'estado', header: 'Estado', width: '130px',
      render: (r) => <Badge variant={badgeFor(r.estado)}>{r.estado}</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '180px',
      render: (r) => (
        <div className="flex justify-end gap-1.5">
          {r.estado === 'BORRADOR' && (
            <Button size="sm" variant="primary" onClick={(e) => { e.stopPropagation(); setContabOpen(r); }}>
              <BookOpen className="w-3 h-3" /> Contabilizar
            </Button>
          )}
          {r.estado !== 'ANULADA' && (
            <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setAnularOpen(r); }}>
              <Trash2 className="w-3 h-3" />
            </Button>
          )}
        </div>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><ArrowLeftRight className="w-4 h-4 text-azure" /> Transferencias internas</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevaOpen(true)}>
              <Plus className="w-3 h-3" /> Nueva transferencia
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <SelectField
            label="Estado"
            value={estado}
            onChange={(e) => { setEstado(e.target.value); setPage(1); }}
            placeholder="Todos"
            containerClassName="w-[180px]"
            options={ESTADOS.map((s) => ({ value: s, label: s }))}
          />
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={columns} paginator={data} loading={isLoading} onPageChange={setPage} />
        </CardBody>
      </Card>

      {nuevaOpen && <NuevaTransferenciaModal onClose={() => setNuevaOpen(false)} />}
      {contabOpen && (
        <ContabilizarConfirm transf={contabOpen} onClose={() => setContabOpen(null)} />
      )}
      {anularOpen && (
        <AnularModal transf={anularOpen} onClose={() => setAnularOpen(null)} />
      )}
    </div>
  );
}

function NuevaTransferenciaModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['transferencias']);
  const { data: cuentas } = useApi<CuentaCorta[]>(['cuentas-bancarias'], '/api/erp/cuentas-bancarias');

  const [form, setForm] = useState({
    fecha: new Date().toISOString().slice(0, 10),
    cuenta_origen_id: '',
    cuenta_destino_id: '',
    importe_origen: '',
    importe_destino: '',
    tipo_cambio: '',
    concepto: '',
  });

  const m = useApiMutation<Transferencia, Record<string, unknown>>(
    (vars) => api.post('/api/erp/transferencias-internas', vars),
    {
      onSuccess: () => {
        toast.success('Transferencia registrada', 'En BORRADOR — contabilizá para impactar saldos');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo registrar', errorMessage(e)),
    }
  );

  const submit = () => {
    const payload: Record<string, unknown> = {
      fecha: form.fecha,
      cuenta_origen_id: Number(form.cuenta_origen_id),
      cuenta_destino_id: Number(form.cuenta_destino_id),
      importe_origen: Number(form.importe_origen),
    };
    if (form.importe_destino) payload.importe_destino = Number(form.importe_destino);
    if (form.tipo_cambio)     payload.tipo_cambio = Number(form.tipo_cambio);
    if (form.concepto)        payload.concepto = form.concepto;
    m.mutate(payload);
  };

  const cuentaOpts = (cuentas ?? []).map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }));
  const valid = form.fecha && form.cuenta_origen_id && form.cuenta_destino_id &&
    form.cuenta_origen_id !== form.cuenta_destino_id && Number(form.importe_origen) > 0;

  return (
    <Modal
      open
      onClose={onClose}
      title="Nueva transferencia interna"
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending} onClick={submit}>
            {m.isPending ? 'Registrando…' : 'Registrar'}
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-3">
        <Field label="Fecha" type="date" required value={form.fecha}
          onChange={(e) => setForm({ ...form, fecha: e.target.value })} />
        <div />
        <SelectField label="Cuenta origen" required value={form.cuenta_origen_id}
          onChange={(e) => setForm({ ...form, cuenta_origen_id: e.target.value })}
          options={cuentaOpts} placeholder="Elegí…" />
        <SelectField label="Cuenta destino" required value={form.cuenta_destino_id}
          onChange={(e) => setForm({ ...form, cuenta_destino_id: e.target.value })}
          options={cuentaOpts} placeholder="Elegí…"
          error={form.cuenta_origen_id && form.cuenta_origen_id === form.cuenta_destino_id
            ? 'Origen y destino no pueden ser iguales' : null} />
        <Field label="Importe origen" type="number" step="0.01" required
          value={form.importe_origen}
          onChange={(e) => setForm({ ...form, importe_origen: e.target.value })} />
        <Field label="Importe destino" type="number" step="0.01"
          value={form.importe_destino}
          onChange={(e) => setForm({ ...form, importe_destino: e.target.value })}
          hint="Si difiere por tipo de cambio (USD ↔ ARS)." />
        <Field label="Tipo de cambio" type="number" step="0.0001"
          value={form.tipo_cambio}
          onChange={(e) => setForm({ ...form, tipo_cambio: e.target.value })}
          hint="Ej: 1 USD = 1500 ARS → 1500" />
        <div />
        <TextareaField containerClassName="col-span-2" label="Concepto"
          value={form.concepto} rows={2}
          onChange={(e) => setForm({ ...form, concepto: e.target.value })} />
      </div>
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}

function ContabilizarConfirm({ transf, onClose }: { transf: Transferencia; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['transferencias']);
  const m = useApiMutation<Transferencia>(
    () => api.post(`/api/erp/transferencias-internas/${transf.id}/contabilizar`),
    {
      onSuccess: () => {
        toast.success('Transferencia contabilizada');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo contabilizar', errorMessage(e)),
    }
  );
  return (
    <ConfirmDialog
      open
      onClose={onClose}
      onConfirm={() => m.mutate(undefined as unknown as void)}
      title="Contabilizar transferencia"
      message={
        <>
          ¿Generar el asiento contable para la transferencia de{' '}
          <strong>{transf.cuenta_origen.codigo}</strong> →{' '}
          <strong>{transf.cuenta_destino.codigo}</strong> por{' '}
          <strong>{transf.moneda_origen?.codigo} {fmtMoney(transf.importe_origen)}</strong>?
          <br />
          La transferencia pasará a estado CONTABILIZADA.
        </>
      }
      loading={m.isPending}
    />
  );
}

function AnularModal({ transf, onClose }: { transf: Transferencia; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['transferencias']);
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<Transferencia, { motivo: string }>(
    (vars) => api.post(`/api/erp/transferencias-internas/${transf.id}/anular`, vars),
    {
      onSuccess: () => {
        toast.success('Transferencia anulada');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo anular', errorMessage(e)),
    }
  );
  return (
    <Modal
      open
      onClose={onClose}
      title={`Anular transferencia #${transf.id}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={motivo.trim().length < 3 || m.isPending}
            onClick={() => m.mutate({ motivo: motivo.trim() })}>
            {m.isPending ? 'Procesando…' : 'Anular'}
          </Button>
        </>
      }
    >
      <Field label="Motivo" required value={motivo}
        onChange={(e) => setMotivo(e.target.value)} placeholder="Mínimo 3 caracteres" />
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}
