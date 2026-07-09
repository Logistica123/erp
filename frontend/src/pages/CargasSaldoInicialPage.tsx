import { useMemo, useState } from 'react';
import { PiggyBank, Plus, Undo2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { SelectorCuentaContable, type CuentaOpcion } from '@/components/contabilidad/SelectorCuentaContable';
import { api } from '@/lib/api';
import { parseMontoEs } from '@/lib/montos';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type CuentaRef = { id: number; codigo: string; nombre: string };

type Carga = {
  id: number;
  fecha: string;
  monto: number | string;
  motivo_tipo: string;
  motivo_observacion?: string | null;
  estado: 'ACTIVO' | 'REVERTIDO';
  motivo_reversa?: string | null;
  cuenta_destino?: CuentaRef | null;
  cuenta_contrapartida?: CuentaRef | null;
  asiento?: { id: number; numero: number; fecha: string } | null;
  asiento_reversa?: { id: number; numero: number; fecha: string } | null;
  creado_por?: { id: number; name: string } | null;
  revertido_por?: { id: number; name: string } | null;
  caja?: { id: number; codigo: string; nombre: string } | null;
  cuenta_bancaria?: { id: number; codigo: string; nombre: string } | null;
  created_at: string;
};

type CuentaDestino = {
  id: number;
  codigo: string;
  nombre: string;
  entidad?: string | null;
  saldo_operativo?: number | string | null;
};

type Catalogo = {
  cuentas: CuentaDestino[];
  contrapartida_default: CuentaRef | null;
  motivos: Record<string, string>;
};

export function CargasSaldoInicialPage() {
  const [cuentaId, setCuentaId] = useState('');
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');
  const [estado, setEstado] = useState('');
  const [page, setPage] = useState(1);

  const { data: catalogo } = useApi<Catalogo>(
    ['cargas-saldo-inicial-catalogo'],
    '/api/erp/tesoreria/cargas-saldo-inicial/cuentas-destino',
  );

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (cuentaId) p.set('cuenta_id', cuentaId);
    if (desde) p.set('fecha_desde', desde);
    if (hasta) p.set('fecha_hasta', hasta);
    if (estado) p.set('estado', estado);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [cuentaId, desde, hasta, estado, page]);

  const { data, isLoading, error } = useApi<Paginator<Carga>>(
    ['cargas-saldo-inicial', qs],
    `/api/erp/tesoreria/cargas-saldo-inicial${qs ? `?${qs}` : ''}`,
  );

  const [nuevaOpen, setNuevaOpen] = useState(false);
  const [revertirC, setRevertirC] = useState<Carga | null>(null);

  const columns: Column<Carga>[] = [
    { key: 'fecha', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha) },
    { key: 'cuenta_destino', header: 'Cuenta destino',
      render: (r) => r.cuenta_destino
        ? <div>
            <div>{r.cuenta_destino.nombre} <span className="text-ink-3">({r.cuenta_destino.codigo})</span></div>
            {(r.caja || r.cuenta_bancaria) && (
              <div className="text-[10.5px] text-ink-3">{r.caja ? `Caja: ${r.caja.nombre}` : `Banco: ${r.cuenta_bancaria?.nombre}`}</div>
            )}
          </div>
        : '—' },
    { key: 'monto', header: 'Monto', align: 'right', width: '130px',
      render: (r) => <span className="tabular-nums font-medium">{fmtMoney(r.monto)}</span> },
    { key: 'contrapartida', header: 'Contrapartida',
      render: (r) => r.cuenta_contrapartida ? `${r.cuenta_contrapartida.nombre} (${r.cuenta_contrapartida.codigo})` : '—' },
    { key: 'motivo', header: 'Motivo',
      render: (r) => (
        <div>
          <div>{catalogo?.motivos?.[r.motivo_tipo] ?? r.motivo_tipo}</div>
          {r.motivo_observacion && <div className="text-[10.5px] text-ink-3 whitespace-pre-wrap">{r.motivo_observacion}</div>}
        </div>
      ) },
    { key: 'estado', header: 'Estado', width: '110px',
      render: (r) => <Badge variant={r.estado === 'ACTIVO' ? 'success' : 'neutral'}>{r.estado}</Badge> },
    { key: 'asiento', header: 'Asiento', width: '110px',
      render: (r) => (
        <div className="tabular-nums">
          {r.asiento ? `#${r.asiento.numero}` : '—'}
          {r.asiento_reversa && <div className="text-[10.5px] text-ink-3">rev #{r.asiento_reversa.numero}</div>}
        </div>
      ) },
    { key: 'acciones', header: '', width: '110px', align: 'right',
      render: (r) => r.estado === 'ACTIVO' ? (
        <Button size="sm" variant="secondary" title="Revertir: genera asiento espejo con fecha de hoy."
          onClick={() => setRevertirC(r)}>
          <Undo2 className="w-3 h-3" /> Revertir
        </Button>
      ) : null },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><PiggyBank className="w-4 h-4 text-azure" /> Cargas de saldo inicial</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevaOpen(true)}>
              <Plus className="w-3 h-3" /> Nueva carga inicial
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <SelectField label="Cuenta destino" value={cuentaId} placeholder="Todas"
              onChange={(e) => { setCuentaId(e.target.value); setPage(1); }}
              containerClassName="w-[260px]"
              options={(catalogo?.cuentas ?? []).map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))} />
            <Field label="Desde" type="date" value={desde}
              onChange={(e) => { setDesde(e.target.value); setPage(1); }} containerClassName="w-[150px]" />
            <Field label="Hasta" type="date" value={hasta}
              onChange={(e) => { setHasta(e.target.value); setPage(1); }} containerClassName="w-[150px]" />
            <SelectField label="Estado" value={estado} placeholder="Todos"
              onChange={(e) => { setEstado(e.target.value); setPage(1); }}
              containerClassName="w-[140px]"
              options={[{ value: 'ACTIVO', label: 'Activo' }, { value: 'REVERTIDO', label: 'Revertido' }]} />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={columns} paginator={data} loading={isLoading} onPageChange={setPage}
            empty="Sin cargas de saldo inicial" />
        </CardBody>
      </Card>

      {nuevaOpen && catalogo && <NuevaCargaModal catalogo={catalogo} onClose={() => setNuevaOpen(false)} />}
      {revertirC && <RevertirModal carga={revertirC} motivos={catalogo?.motivos ?? {}} onClose={() => setRevertirC(null)} />}
    </div>
  );
}

function NuevaCargaModal({ catalogo, onClose }: { catalogo: Catalogo; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['cargas-saldo-inicial']);
  const [cuentaDestinoId, setCuentaDestinoId] = useState('');
  const [montoTxt, setMontoTxt] = useState('');
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10));
  const [motivoTipo, setMotivoTipo] = useState('');
  const [obs, setObs] = useState('');
  const [contrapartidaId, setContrapartidaId] = useState<number | null>(catalogo.contrapartida_default?.id ?? null);
  const [contrapartidaMeta, setContrapartidaMeta] = useState<CuentaOpcion | null>(null);

  const monto = parseMontoEs(montoTxt);
  const destino = catalogo.cuentas.find((c) => String(c.id) === cuentaDestinoId);
  const esOtro = motivoTipo === 'OTRO';
  const contrapartidaLabel = contrapartidaMeta
    ? `${contrapartidaMeta.codigo} ${contrapartidaMeta.nombre}`
    : contrapartidaId === catalogo.contrapartida_default?.id
      ? `${catalogo.contrapartida_default?.codigo} ${catalogo.contrapartida_default?.nombre}`
      : '';
  const warnAuxiliar = !!contrapartidaMeta?.admite_auxiliar;

  const valid = cuentaDestinoId && monto > 0 && fecha && motivoTipo && contrapartidaId
    && (!esOtro || obs.trim().length >= 10) && !warnAuxiliar;

  const m = useApiMutation<Carga, void>(
    () => api.post('/api/erp/tesoreria/cargas-saldo-inicial', {
      cuenta_contable_destino_id: Number(cuentaDestinoId),
      monto,
      fecha,
      motivo_tipo: motivoTipo,
      motivo_observacion: obs.trim() || undefined,
      cuenta_contable_contrapartida_id: contrapartidaId,
    }),
    {
      onSuccess: () => { toast.success('Carga inicial registrada', 'Asiento contable generado.'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo registrar', errorMessage(e)),
    },
  );

  return (
    <Modal open onClose={onClose} title="Nueva carga de saldo inicial" size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending} onClick={() => m.mutate()}>
            {m.isPending ? 'Registrando…' : 'Confirmar carga'}
          </Button>
        </>
      }>
      <div className="grid grid-cols-2 gap-3">
        <SelectField label="Cuenta destino (Caja o Banco)" required value={cuentaDestinoId}
          onChange={(e) => setCuentaDestinoId(e.target.value)} placeholder="Elegí…"
          options={catalogo.cuentas.map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}${c.entidad ? ` — ${c.entidad}` : ''}` }))} />
        <div>
          <label className="text-[11.5px] font-semibold text-ink-2 mb-1 block">Monto *</label>
          <input type="text" inputMode="decimal" value={montoTxt} placeholder="0,00"
            className="w-full border border-line rounded-md px-3 py-2 text-[12.5px] tabular-nums focus:outline-none focus:border-azure"
            onChange={(e) => setMontoTxt(e.target.value)} />
          {montoTxt && (
            <div className={`mt-1 text-[11px] ${monto > 0 ? 'text-ink-3' : 'text-danger'}`}>
              {monto > 0 ? `= ${fmtMoney(monto)}` : 'Monto inválido'}
            </div>
          )}
        </div>
        <Field label="Fecha" type="date" required value={fecha}
          onChange={(e) => setFecha(e.target.value)}
          hint="Debe estar en un período contable abierto." />
        <SelectField label="Motivo" required value={motivoTipo} placeholder="Elegí motivo…"
          onChange={(e) => setMotivoTipo(e.target.value)}
          options={Object.entries(catalogo.motivos).map(([value, label]) => ({ value, label }))} />
      </div>

      <div className="mt-3">
        <TextareaField label={`Observación${esOtro ? ' *' : ''}`} rows={2} value={obs}
          onChange={(e) => setObs(e.target.value)}
          hint={esOtro ? 'Obligatoria con motivo "Otro" (mínimo 10 caracteres).' : 'Opcional.'} />
      </div>

      <div className="mt-3">
        <label className="text-[11.5px] font-semibold text-ink-2 mb-1 block">Cuenta contrapartida (editable)</label>
        <SelectorCuentaContable value={contrapartidaId}
          onChange={(id, meta) => { setContrapartidaId(id); setContrapartidaMeta(meta ?? null); }} />
        {warnAuxiliar && (
          <div className="mt-1 text-[11px] text-danger">
            Esa cuenta exige auxiliar {contrapartidaMeta?.tipo_auxiliar} — elegí una cuenta patrimonial sin auxiliar (ej. 3.3.01).
          </div>
        )}
        {contrapartidaMeta && !warnAuxiliar && !contrapartidaMeta.codigo.startsWith('3') && (
          <div className="mt-1 text-[11px] text-warning">
            ⚠️ La contrapartida típica es una cuenta patrimonial (3.x). Permitido, pero revisá que sea intencional.
          </div>
        )}
      </div>

      {destino && monto > 0 && contrapartidaId && (
        <div className="mt-4 border border-line rounded-md p-3">
          <div className="text-[11px] font-semibold text-ink-2 mb-1">Asiento a generar · {fmtDate(fecha)}</div>
          <div className="font-mono text-[11.5px] space-y-0.5">
            <div className="flex justify-between">
              <span>D&nbsp;&nbsp;{destino.codigo} {destino.nombre}</span>
              <span className="tabular-nums">{fmtMoney(monto)}</span>
            </div>
            <div className="flex justify-between">
              <span>H&nbsp;&nbsp;{contrapartidaLabel || `cuenta #${contrapartidaId}`}</span>
              <span className="tabular-nums">{fmtMoney(monto)}</span>
            </div>
          </div>
          <div className="text-[10.5px] text-ink-3 mt-1">
            Glosa: "Carga inicial saldo — {catalogo.motivos[motivoTipo] ?? '…'}{obs.trim() ? ` (${obs.trim()})` : ''}"
            {destino.entidad && <> · Actualiza el saldo operativo de {destino.entidad}.</>}
          </div>
        </div>
      )}

      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}

function RevertirModal({ carga, motivos, onClose }: { carga: Carga; motivos: Record<string, string>; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['cargas-saldo-inicial']);
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<Carga, void>(
    () => api.post(`/api/erp/tesoreria/cargas-saldo-inicial/${carga.id}/revertir`, { motivo_reversa: motivo.trim() }),
    {
      onSuccess: () => { toast.success('Carga revertida', 'Asiento de reversa generado con fecha de hoy.'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo revertir', errorMessage(e)),
    },
  );
  return (
    <Modal open onClose={onClose} title="Revertir carga de saldo inicial" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={motivo.trim().length < 10 || m.isPending} onClick={() => m.mutate()}>
            {m.isPending ? 'Revirtiendo…' : 'Revertir'}
          </Button>
        </>
      }>
      <div className="space-y-3 text-[12.5px]">
        <div className="border border-warning/40 bg-warning-bg/30 rounded p-2 text-[11.5px]">
          ⚠️ Genera un asiento reversa (D/H espejo) <strong>con fecha de hoy</strong>. La carga queda
          marcada como REVERTIDA pero sigue visible para trazabilidad.
        </div>
        <div className="bg-surface-row border border-line rounded-md p-3 text-[12px]">
          <div>Carga #{carga.id} · {carga.cuenta_destino?.nombre} ({carga.cuenta_destino?.codigo})</div>
          <div>Monto: <strong className="tabular-nums">{fmtMoney(carga.monto)}</strong> · Fecha: {fmtDate(carga.fecha)}</div>
          <div>Motivo original: {motivos[carga.motivo_tipo] ?? carga.motivo_tipo}</div>
          <div>Asiento original: #{carga.asiento?.numero ?? '—'}</div>
        </div>
        <TextareaField label="Motivo de la reversa *" rows={3} value={motivo}
          onChange={(e) => setMotivo(e.target.value)} hint="Mínimo 10 caracteres." />
      </div>
    </Modal>
  );
}
