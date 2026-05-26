import { useState } from 'react';
import { Check, Loader2, Plus, Wallet } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

type EstadoOP = 'BORRADOR' | 'EMITIDA' | 'CARGADA_BANCO' | 'LIBERADA' | 'PAGADA' | 'RECHAZADA' | 'ANULADA';

type OP = {
  id: number;
  numero: string;
  fecha: string;
  tipo: string;
  origen: 'LOCAL' | 'DISTRIAPP'; // v1.35
  distriapp_numero_correlativo?: string | null;
  estado: EstadoOP;
  importe: string;
  importe_ars_equivalente?: string | null;
  concepto: string | null;
  contabilizada: boolean; // v1.35
  auxiliar: { id: number; codigo: string; nombre: string; tipo: string; cuit?: string | null };
  moneda: { id: number; codigo: string };
  tipo_op?: { id: number; codigo: string; nombre: string } | null;
  asiento_id: number | null;
};

type Auxiliar = { id: number; codigo: string; nombre: string; tipo: string };
type CuentaBancaria = { id: number; codigo: string; nombre: string };
type TipoOp = { id: number; codigo: string; nombre: string };

function estadoBadge(estado: EstadoOP) {
  const map: Record<EstadoOP, { v: 'success' | 'danger' | 'warning' | 'neutral' | 'info'; label: string }> = {
    BORRADOR: { v: 'warning', label: 'Borrador' },
    EMITIDA: { v: 'info', label: 'Emitida' },
    CARGADA_BANCO: { v: 'info', label: 'Cargada banco' },
    LIBERADA: { v: 'info', label: 'Liberada' },
    PAGADA: { v: 'success', label: 'Pagada' },
    RECHAZADA: { v: 'danger', label: 'Rechazada' },
    ANULADA: { v: 'neutral', label: 'Anulada' },
  };
  const m = map[estado];
  return <Badge variant={m.v}>{m.label}</Badge>;
}

function origenBadge(op: OP) {
  return op.origen === 'DISTRIAPP'
    ? <Badge variant="success" title={op.distriapp_numero_correlativo ?? undefined}>DistriApp</Badge>
    : <Badge variant="info">Local</Badge>;
}

export function OrdenesPagoPage() {
  const qc = useQueryClient();
  const [estado, setEstado] = useState<EstadoOP | ''>('');
  const [origen, setOrigen] = useState<'' | 'LOCAL' | 'DISTRIAPP'>('');
  const [soloNoContab, setSoloNoContab] = useState(false);
  const [pagar, setPagar] = useState<OP | null>(null);
  const [contabilizar, setContabilizar] = useState<OP | null>(null);
  const [nueva, setNueva] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const qs = new URLSearchParams();
  if (estado) qs.set('estado', estado);
  if (origen) qs.set('origen', origen);
  if (soloNoContab) qs.set('solo_no_contabilizadas', '1');

  const { data: ops, isLoading } = useQuery<{ data: OP[] }>({
    queryKey: ['op', estado, origen, soloNoContab],
    queryFn: () => api.get(`/api/erp/ordenes-pago${qs.toString() ? `?${qs.toString()}` : ''}`),
  });

  const sync = useMutation({
    mutationFn: () => api.post('/api/erp/ordenes-pago/sync', {}),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['op'] });
      setErr(null);
    },
    onError: (e) => setErr(e instanceof ApiError ? e.message : 'Error sync'),
  });

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Órdenes de pago</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            {ops?.data.length ?? 0} órdenes {estado ? `en estado ${estado}` : 'totales'}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => sync.mutate()} disabled={sync.isPending}>
            {sync.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : '↻'} Sync DistriApp
          </Button>
          <Button variant="primary" onClick={() => setNueva(true)}>
            <Plus className="w-3 h-3" /> Nueva orden de pago
          </Button>
        </div>
      </div>

      {err && <div className="mb-4 p-3 bg-danger-bg text-danger rounded-md text-[12px]">{err}</div>}

      <Card>
        <CardHeader
          title="Listado"
          actions={
            <div className="flex items-center gap-2">
              <select value={origen} onChange={(e) => setOrigen(e.target.value as typeof origen)}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white">
                <option value="">Todos los orígenes</option>
                <option value="LOCAL">Local</option>
                <option value="DISTRIAPP">DistriApp</option>
              </select>
              <select value={estado} onChange={(e) => setEstado(e.target.value as typeof estado)}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white">
                <option value="">Todos los estados</option>
                <option value="BORRADOR">Borrador</option>
                <option value="EMITIDA">Emitida</option>
                <option value="PAGADA">Pagada</option>
                <option value="ANULADA">Anulada</option>
              </select>
              <label className="flex items-center gap-1 text-[11.5px] text-ink-2">
                <input type="checkbox" checked={soloNoContab} onChange={(e) => setSoloNoContab(e.target.checked)} />
                Sin contabilizar
              </label>
            </div>
          }
        />
        <CardBody>
          <table className="w-full border-collapse text-[12px]">
            <thead>
              <tr className="bg-surface-hover border-b border-line-strong">
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">N°</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[90px]">Origen</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[90px]">Fecha</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">Beneficiario</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">Concepto</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[110px]">Estado</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[140px]">Importe</th>
                <th className="w-[160px]" />
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={8} className="py-10 text-center text-ink-muted"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Cargando…</td></tr>}
              {ops?.data.map((op, i) => (
                <tr key={op.id} className={`border-b border-line hover:bg-surface-hover ${i % 2 ? 'bg-surface-row' : ''}`}>
                  <td className="px-[10px] py-[7px] font-mono text-navy-700">{op.numero}</td>
                  <td className="px-[10px] py-[7px]">{origenBadge(op)}</td>
                  <td className="px-[10px] py-[7px] tabular text-ink-2">{op.fecha.slice(0, 10)}</td>
                  <td className="px-[10px] py-[7px] text-ink-2">
                    {op.auxiliar.nombre}
                    {op.auxiliar.cuit && <span className="block text-[10px] text-ink-muted font-mono">{op.auxiliar.cuit}</span>}
                  </td>
                  <td className="px-[10px] py-[7px] text-ink-2">
                    {op.concepto ?? '—'}
                    {op.tipo_op && <span className="block text-[10px] text-ink-muted">{op.tipo_op.nombre}</span>}
                  </td>
                  <td className="px-[10px] py-[7px]">{estadoBadge(op.estado)}</td>
                  <td className="px-[10px] py-[7px] text-right tabular font-semibold">
                    {op.moneda.codigo} {fmtMoney(Number(op.importe))}
                    {op.importe_ars_equivalente && <span className="block text-[10px] text-ink-muted">≈ ARS {fmtMoney(Number(op.importe_ars_equivalente))}</span>}
                  </td>
                  <td className="px-[10px] py-[7px] text-right">
                    {op.origen === 'LOCAL' && ['BORRADOR', 'EMITIDA', 'CARGADA_BANCO', 'LIBERADA'].includes(op.estado) && (
                      <Button size="sm" variant="ghost" onClick={() => setPagar(op)}>
                        <Wallet className="w-3 h-3" /> Pagar
                      </Button>
                    )}
                    {!op.contabilizada && ['EMITIDA', 'PAGADA'].includes(op.estado) && (
                      <Button size="sm" variant="primary" onClick={() => setContabilizar(op)}>
                        <Check className="w-3 h-3" /> Contabilizar
                      </Button>
                    )}
                    {op.contabilizada && op.asiento_id && (
                      <span className="text-[10px] text-success">✓ Asiento #{op.asiento_id}</span>
                    )}
                  </td>
                </tr>
              ))}
              {ops?.data.length === 0 && !isLoading && (
                <tr><td colSpan={8} className="py-10 text-center text-ink-muted">Sin órdenes.</td></tr>
              )}
            </tbody>
          </table>
        </CardBody>
      </Card>

      <NuevaOPModal
        open={nueva}
        onClose={() => setNueva(false)}
        onSuccess={() => {
          setNueva(false);
          qc.invalidateQueries({ queryKey: ['op'] });
        }}
        onError={setErr}
      />
      <PagarOPModal
        op={pagar}
        onClose={() => setPagar(null)}
        onSuccess={() => {
          setPagar(null);
          qc.invalidateQueries({ queryKey: ['op'] });
          qc.invalidateQueries({ queryKey: ['cuentas-bancarias'] });
          qc.invalidateQueries({ queryKey: ['mov-banc'] });
        }}
        onError={setErr}
      />
      <ContabilizarOPModal
        op={contabilizar}
        onClose={() => setContabilizar(null)}
        onSuccess={() => {
          setContabilizar(null);
          qc.invalidateQueries({ queryKey: ['op'] });
        }}
        onError={setErr}
      />
    </>
  );
}

function NuevaOPModal({
  open,
  onClose,
  onSuccess,
  onError,
}: {
  open: boolean;
  onClose: () => void;
  onSuccess: () => void;
  onError: (e: string) => void;
}) {
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10));
  const [tipoOpId, setTipoOpId] = useState<number | ''>('');
  const [auxId, setAuxId] = useState<number | ''>('');
  const [moneda, setMoneda] = useState<'ARS' | 'USD'>('ARS');
  const [cotizacionUsd, setCotizacionUsd] = useState('');
  const [importe, setImporte] = useState('');
  const [concepto, setConcepto] = useState('');

  const { data: auxiliares } = useQuery<{ data: Auxiliar[] }>({
    queryKey: ['auxiliares'],
    queryFn: () => api.get('/api/erp/auxiliares'),
    enabled: open,
  });
  const { data: tipos } = useQuery<{ data: TipoOp[] }>({
    queryKey: ['op-tipos'],
    queryFn: () => api.get('/api/erp/ordenes-pago/tipos'),
    enabled: open,
  });
  // El tipo DIST es exclusivo del sync — se excluye del form local.
  const tiposLocales = (tipos?.data ?? []).filter((t) => t.codigo !== 'DIST');

  const crear = (emitir: boolean) =>
    api.post('/api/erp/ordenes-pago/local', {
      fecha,
      tipo_op_id: tipoOpId,
      beneficiario_id: auxId,
      moneda,
      cotizacion_usd: moneda === 'USD' ? Number(cotizacionUsd) : undefined,
      importe: Number(importe),
      concepto,
      emitir,
    });

  const mut = useMutation({
    mutationFn: (emitir: boolean) => crear(emitir),
    onSuccess: () => {
      setAuxId(''); setImporte(''); setConcepto(''); setCotizacionUsd('');
      onSuccess();
    },
    onError: (e) => onError(e instanceof ApiError ? e.message : 'Error'),
  });

  const valid = auxId && tipoOpId && Number(importe) > 0 && concepto.trim().length >= 3
    && (moneda === 'ARS' || Number(cotizacionUsd) > 0);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Nueva orden de pago (local)"
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="ghost" disabled={!valid || mut.isPending} onClick={() => mut.mutate(false)}>
            {mut.isPending && <Loader2 className="w-3 h-3 animate-spin" />} Guardar borrador
          </Button>
          <Button variant="primary" disabled={!valid || mut.isPending} onClick={() => mut.mutate(true)}>
            Emitir
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-3">
        <div className="col-span-2">
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Beneficiario *</label>
          <select value={auxId} onChange={(e) => setAuxId(e.target.value ? Number(e.target.value) : '')}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white">
            <option value="">Seleccionar…</option>
            {auxiliares?.data.map((a) => <option key={a.id} value={a.id}>{a.tipo}: {a.nombre}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Fecha *</label>
          <input type="date" value={fecha} onChange={(e) => setFecha(e.target.value)}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white" />
        </div>
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Tipo de OP *</label>
          <select value={tipoOpId} onChange={(e) => setTipoOpId(e.target.value ? Number(e.target.value) : '')}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white">
            <option value="">Seleccionar…</option>
            {tiposLocales.map((t) => <option key={t.id} value={t.id}>{t.nombre}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Moneda *</label>
          <select value={moneda} onChange={(e) => setMoneda(e.target.value as 'ARS' | 'USD')}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white">
            <option value="ARS">ARS</option>
            <option value="USD">USD</option>
          </select>
        </div>
        {moneda === 'USD' && (
          <div>
            <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Cotización USD *</label>
            <input type="number" step="0.0001" value={cotizacionUsd} onChange={(e) => setCotizacionUsd(e.target.value)}
              className="w-full px-[9px] py-[6px] text-[13px] text-right tabular border border-line-strong rounded-md bg-white" />
          </div>
        )}
        <div className={moneda === 'USD' ? '' : 'col-span-1'}>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Importe *</label>
          <input type="number" step="0.01" value={importe} onChange={(e) => setImporte(e.target.value)}
            className="w-full px-[9px] py-[6px] text-[13px] text-right tabular border border-line-strong rounded-md bg-white" />
        </div>
        <div className="col-span-2">
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Concepto * (mín 3)</label>
          <input value={concepto} onChange={(e) => setConcepto(e.target.value)}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white" />
        </div>
      </div>
    </Modal>
  );
}

function ContabilizarOPModal({
  op,
  onClose,
  onSuccess,
  onError,
}: {
  op: OP | null;
  onClose: () => void;
  onSuccess: () => void;
  onError: (e: string) => void;
}) {
  const [cuentaDebeId, setCuentaDebeId] = useState<number | ''>('');
  const [cuentaHaberId, setCuentaHaberId] = useState<number | ''>('');

  const { data: cuentas } = useQuery<{ data: { id: number; codigo: string; nombre: string }[] }>({
    queryKey: ['cuentas-contables-list'],
    queryFn: () => api.get('/api/erp/cuentas-contables?limit=500'),
    enabled: !!op,
  });

  const contabilizar = useMutation({
    mutationFn: () => api.post(`/api/erp/ordenes-pago/${op!.id}/contabilizar`, {
      cuenta_debe_id: cuentaDebeId || undefined,
      cuenta_haber_id: cuentaHaberId || undefined,
    }),
    onSuccess: () => { setCuentaDebeId(''); setCuentaHaberId(''); onSuccess(); },
    onError: (e) => onError(e instanceof ApiError ? e.message : 'Error'),
  });

  if (!op) return null;
  const imp = Number(op.importe_ars_equivalente ?? op.importe);

  return (
    <Modal open={!!op} onClose={onClose} title={`Contabilizar OP ${op.numero}`} size="md" footer={
      <>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={contabilizar.isPending} onClick={() => contabilizar.mutate()}>
          {contabilizar.isPending && <Loader2 className="w-3 h-3 animate-spin" />} Confirmar y generar asiento
        </Button>
      </>
    }>
      <div className="space-y-3 text-[12px]">
        <div className="bg-surface-row border border-line rounded p-2">
          <div><strong>{op.auxiliar.nombre}</strong> · {op.concepto}</div>
          <div className="text-ink-muted">Importe: {op.moneda.codigo} {fmtMoney(Number(op.importe))}
            {op.importe_ars_equivalente && ` (≈ ARS ${fmtMoney(imp)})`}</div>
        </div>
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
            Cuenta débito (gasto) {op.tipo_op && '— sugerida del tipo si está vacía'}
          </label>
          <select value={cuentaDebeId} onChange={(e) => setCuentaDebeId(e.target.value ? Number(e.target.value) : '')}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white">
            <option value="">(usar default del tipo / auxiliar)</option>
            {cuentas?.data.map((c) => <option key={c.id} value={c.id}>{c.codigo} {c.nombre}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
            Cuenta haber (banco/caja)
          </label>
          <select value={cuentaHaberId} onChange={(e) => setCuentaHaberId(e.target.value ? Number(e.target.value) : '')}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white">
            <option value="">(usar cuenta del medio de pago / mapeo)</option>
            {cuentas?.data.map((c) => <option key={c.id} value={c.id}>{c.codigo} {c.nombre}</option>)}
          </select>
        </div>
        <div className="text-[11px] text-ink-muted">
          DEBE cuenta gasto · HABER cuenta banco/caja — por ${fmtMoney(imp)}. Si dejás vacío,
          el sistema usa los defaults; si faltan, te pedirá elegirlas.
        </div>
      </div>
    </Modal>
  );
}

function PagarOPModal({
  op,
  onClose,
  onSuccess,
  onError,
}: {
  op: OP | null;
  onClose: () => void;
  onSuccess: () => void;
  onError: (e: string) => void;
}) {
  const [cuentaBancariaId, setCuentaBancariaId] = useState<number | ''>('');
  const [concepto, setConcepto] = useState('');

  const { data: cuentas } = useQuery<{ data: CuentaBancaria[] }>({
    queryKey: ['cuentas-bancarias'],
    queryFn: () => api.get('/api/erp/cuentas-bancarias'),
    enabled: !!op,
  });

  const pagar = useMutation({
    mutationFn: () =>
      api.post(`/api/erp/ordenes-pago/${op!.id}/pagar`, {
        cuenta_bancaria_id: cuentaBancariaId,
        concepto: concepto || null,
      }),
    onSuccess: () => {
      setCuentaBancariaId('');
      setConcepto('');
      onSuccess();
    },
    onError: (e) => onError(e instanceof ApiError ? e.message : 'Error'),
  });

  if (!op) return null;

  return (
    <Modal
      open={!!op}
      onClose={onClose}
      title={`Pagar ${op.numero}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="success" disabled={!cuentaBancariaId || pagar.isPending} onClick={() => pagar.mutate()}>
            {pagar.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
            <Check className="w-3 h-3" /> Confirmar pago
          </Button>
        </>
      }
    >
      <div className="mb-3 p-3 bg-surface-row rounded-md text-[12px] grid grid-cols-2 gap-2">
        <div>
          <div className="text-[10px] uppercase text-ink-muted font-semibold">Beneficiario</div>
          <div className="text-ink-2">{op.auxiliar.nombre}</div>
        </div>
        <div>
          <div className="text-[10px] uppercase text-ink-muted font-semibold">Importe</div>
          <div className="tabular font-semibold text-navy-800">{fmtMoney(Number(op.importe))}</div>
        </div>
      </div>

      <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
        Cuenta bancaria de pago
      </label>
      <select
        value={cuentaBancariaId}
        onChange={(e) => setCuentaBancariaId(e.target.value ? Number(e.target.value) : '')}
        className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white mb-3"
      >
        <option value="">Seleccionar cuenta…</option>
        {cuentas?.data.map((c) => <option key={c.id} value={c.id}>{c.codigo} — {c.nombre}</option>)}
      </select>

      <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
        Concepto del movimiento bancario (opcional)
      </label>
      <input
        value={concepto}
        onChange={(e) => setConcepto(e.target.value)}
        placeholder={`Pago ${op.numero} · ${op.auxiliar.nombre}`}
        className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
      />

      <p className="text-[11px] text-ink-muted mt-3">
        Al confirmar, se creará automáticamente un movimiento bancario CONCILIADO y un asiento contable. La OP pasará
        a estado PAGADA.
      </p>
    </Modal>
  );
}
