import { useMemo, useState } from 'react';
import { Calculator, Plus } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Caja = { id: number; codigo: string; nombre: string; saldo_actual?: number };

type Arqueo = {
  id: number;
  caja: Caja;
  fecha: string;
  saldo_sistema: number | string;
  saldo_fisico: number | string;
  diferencia: number | string;
  motivo?: string;
  asiento_ajuste?: { id: number; numero: number; fecha: string };
  realizado_por?: { id: number; name: string };
  created_at: string;
};

export function ArqueosPage() {
  const [cajaId, setCajaId] = useState('');
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');
  const [page, setPage] = useState(1);

  const { data: cajas } = useApi<Caja[]>(['cajas'], '/api/erp/cajas');

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (cajaId) p.set('caja_id', cajaId);
    if (desde)  p.set('desde', desde);
    if (hasta)  p.set('hasta', hasta);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [cajaId, desde, hasta, page]);

  const { data, isLoading, error } = useApi<Paginator<Arqueo>>(
    ['arqueos', qs],
    `/api/erp/caja/arqueos${qs ? `?${qs}` : ''}`
  );

  const [nuevoOpen, setNuevoOpen] = useState(false);

  const columns: Column<Arqueo>[] = [
    { key: 'fecha', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha) },
    { key: 'caja', header: 'Caja',
      render: (r) => `${r.caja.codigo} ${r.caja.nombre}` },
    { key: 'saldo_sistema', header: 'Saldo sistema', align: 'right', width: '130px',
      render: (r) => fmtMoney(r.saldo_sistema) },
    { key: 'saldo_fisico', header: 'Saldo físico', align: 'right', width: '130px',
      render: (r) => fmtMoney(r.saldo_fisico) },
    { key: 'diferencia', header: 'Diferencia', align: 'right', width: '120px',
      render: (r) => {
        const d = Number(r.diferencia);
        const variant = Math.abs(d) < 0.01 ? 'success' : d > 0 ? 'warning' : 'danger';
        return <Badge variant={variant}>{fmtMoney(d)}</Badge>;
      } },
    { key: 'realizado_por', header: 'Realizado por',
      render: (r) => r.realizado_por?.name ?? '—' },
    { key: 'asiento_ajuste', header: 'Asiento ajuste', width: '130px',
      render: (r) => r.asiento_ajuste ? `#${r.asiento_ajuste.numero}` : '—' },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Calculator className="w-4 h-4 text-azure" /> Arqueos de caja</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Registrar arqueo
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <SelectField label="Caja" value={cajaId} placeholder="Todas"
              onChange={(e) => { setCajaId(e.target.value); setPage(1); }}
              containerClassName="w-[220px]"
              options={(cajas ?? []).map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))} />
            <Field label="Desde" type="date" value={desde}
              onChange={(e) => { setDesde(e.target.value); setPage(1); }}
              containerClassName="w-[150px]" />
            <Field label="Hasta" type="date" value={hasta}
              onChange={(e) => { setHasta(e.target.value); setPage(1); }}
              containerClassName="w-[150px]" />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={columns} paginator={data} loading={isLoading} onPageChange={setPage}
            empty="Sin arqueos en el rango" />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevoArqueoModal cajas={cajas ?? []} onClose={() => setNuevoOpen(false)} />}
    </div>
  );
}

function NuevoArqueoModal({ cajas, onClose }: { cajas: Caja[]; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['arqueos']);
  const [form, setForm] = useState({
    caja_id: '', fecha: new Date().toISOString().slice(0, 10),
    saldo_fisico: '', motivo: '',
  });

  const cajaSel = cajas.find((c) => String(c.id) === form.caja_id);
  const saldoSistema = cajaSel?.saldo_actual ?? null;
  const dif =
    form.saldo_fisico && saldoSistema !== null
      ? Number(form.saldo_fisico) - Number(saldoSistema)
      : null;

  const m = useApiMutation<Arqueo, Record<string, unknown>>(
    (vars) => api.post('/api/erp/caja/arqueos', vars),
    {
      onSuccess: () => {
        toast.success('Arqueo registrado');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo registrar', errorMessage(e)),
    }
  );

  const valid = form.caja_id && form.fecha && form.saldo_fisico !== '';

  return (
    <Modal
      open
      onClose={onClose}
      title="Registrar arqueo de caja"
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              caja_id: Number(form.caja_id),
              fecha: form.fecha,
              saldo_fisico: Number(form.saldo_fisico),
              motivo: form.motivo || undefined,
            })}>
            {m.isPending ? 'Registrando…' : 'Confirmar arqueo'}
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-3">
        <SelectField label="Caja" required value={form.caja_id}
          onChange={(e) => setForm({ ...form, caja_id: e.target.value })}
          options={cajas.map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))} placeholder="Elegí…" />
        <Field label="Fecha" type="date" required value={form.fecha}
          onChange={(e) => setForm({ ...form, fecha: e.target.value })} />
        {saldoSistema !== null && (
          <div className="col-span-2 bg-surface-row border border-line rounded-md p-3 text-[12px] text-ink-2">
            Saldo del sistema: <strong className="tabular-nums">{fmtMoney(saldoSistema)}</strong>
          </div>
        )}
        <Field label="Saldo físico contado" type="number" step="0.01" required
          value={form.saldo_fisico}
          onChange={(e) => setForm({ ...form, saldo_fisico: e.target.value })} />
        <div>
          {dif !== null && (
            <div className="block">
              <label className="text-[11.5px] font-semibold text-ink-2 mb-1 block">Diferencia</label>
              <div className={`px-3 py-2 rounded-md border text-[12.5px] tabular-nums font-medium ${
                Math.abs(dif) < 0.01
                  ? 'border-success/40 bg-success-bg/30 text-success'
                  : dif > 0
                  ? 'border-warning/40 bg-warning-bg/40 text-warning'
                  : 'border-danger/40 bg-danger-bg/30 text-danger'
              }`}>
                {fmtMoney(dif)}
              </div>
            </div>
          )}
        </div>
        <TextareaField containerClassName="col-span-2" label="Motivo (si hay diferencia)"
          value={form.motivo} rows={2}
          onChange={(e) => setForm({ ...form, motivo: e.target.value })}
          hint="Si la diferencia es ≠ 0, el sistema generará un asiento de ajuste contra Sobrantes/Faltantes de Caja (RN-23)." />
      </div>
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}
