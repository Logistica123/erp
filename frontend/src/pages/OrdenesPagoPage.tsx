import { useState } from 'react';
import { Check, Loader2, Plus, Wallet } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

type EstadoOP = 'BORRADOR' | 'CARGADA_BANCO' | 'LIBERADA' | 'PAGADA' | 'RECHAZADA' | 'ANULADA';

type OP = {
  id: number;
  numero: string;
  fecha: string;
  tipo: string;
  estado: EstadoOP;
  importe: string;
  concepto: string | null;
  auxiliar: { id: number; codigo: string; nombre: string; tipo: string };
  moneda: { id: number; codigo: string };
  asiento_id: number | null;
};

type Auxiliar = { id: number; codigo: string; nombre: string; tipo: string };
type Moneda = { id: number; codigo: string; nombre: string };
type CuentaBancaria = { id: number; codigo: string; nombre: string };

function estadoBadge(estado: EstadoOP) {
  const map: Record<EstadoOP, { v: 'success' | 'danger' | 'warning' | 'neutral' | 'info'; label: string }> = {
    BORRADOR: { v: 'warning', label: 'Borrador' },
    CARGADA_BANCO: { v: 'info', label: 'Cargada banco' },
    LIBERADA: { v: 'info', label: 'Liberada' },
    PAGADA: { v: 'success', label: 'Pagada' },
    RECHAZADA: { v: 'danger', label: 'Rechazada' },
    ANULADA: { v: 'neutral', label: 'Anulada' },
  };
  const m = map[estado];
  return <Badge variant={m.v}>{m.label}</Badge>;
}

export function OrdenesPagoPage() {
  const qc = useQueryClient();
  const [estado, setEstado] = useState<EstadoOP | ''>('');
  const [pagar, setPagar] = useState<OP | null>(null);
  const [nueva, setNueva] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const { data: ops, isLoading } = useQuery<{ data: OP[] }>({
    queryKey: ['op', estado],
    queryFn: () => api.get(`/api/erp/ordenes-pago${estado ? `?estado=${estado}` : ''}`),
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
        <Button variant="primary" onClick={() => setNueva(true)}>
          <Plus className="w-3 h-3" /> Nueva orden de pago
        </Button>
      </div>

      {err && <div className="mb-4 p-3 bg-danger-bg text-danger rounded-md text-[12px]">{err}</div>}

      <Card>
        <CardHeader
          title="Listado"
          actions={
            <select
              value={estado}
              onChange={(e) => setEstado(e.target.value as typeof estado)}
              className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
            >
              <option value="">Todos los estados</option>
              <option value="BORRADOR">Borrador</option>
              <option value="LIBERADA">Liberada</option>
              <option value="PAGADA">Pagada</option>
              <option value="ANULADA">Anulada</option>
            </select>
          }
        />
        <CardBody>
          <table className="w-full border-collapse text-[12px]">
            <thead>
              <tr className="bg-surface-hover border-b border-line-strong">
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">N°</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[90px]">Fecha</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">Beneficiario</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">Concepto</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[130px]">Estado</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[140px]">Importe</th>
                <th className="w-[130px]" />
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={7} className="py-10 text-center text-ink-muted"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Cargando…</td></tr>}
              {ops?.data.map((op, i) => (
                <tr key={op.id} className={`border-b border-line hover:bg-surface-hover ${i % 2 ? 'bg-surface-row' : ''}`}>
                  <td className="px-[10px] py-[7px] font-mono text-navy-700">{op.numero}</td>
                  <td className="px-[10px] py-[7px] tabular text-ink-2">{op.fecha.slice(0, 10)}</td>
                  <td className="px-[10px] py-[7px] text-ink-2">{op.auxiliar.nombre}</td>
                  <td className="px-[10px] py-[7px] text-ink-2">{op.concepto ?? '—'}</td>
                  <td className="px-[10px] py-[7px]">{estadoBadge(op.estado)}</td>
                  <td className="px-[10px] py-[7px] text-right tabular font-semibold">{fmtMoney(Number(op.importe))}</td>
                  <td className="px-[10px] py-[7px] text-right">
                    {['BORRADOR', 'CARGADA_BANCO', 'LIBERADA'].includes(op.estado) && (
                      <Button size="sm" variant="primary" onClick={() => setPagar(op)}>
                        <Wallet className="w-3 h-3" /> Pagar
                      </Button>
                    )}
                    {op.estado === 'PAGADA' && op.asiento_id && (
                      <span className="text-[10px] text-ink-muted">→ Asiento #{op.asiento_id}</span>
                    )}
                  </td>
                </tr>
              ))}
              {ops?.data.length === 0 && !isLoading && (
                <tr><td colSpan={7} className="py-10 text-center text-ink-muted">Sin órdenes.</td></tr>
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
  const [tipo, setTipo] = useState<'PROVEEDOR' | 'DISTRIBUIDOR' | 'OTROS'>('PROVEEDOR');
  const [auxId, setAuxId] = useState<number | ''>('');
  const [monedaId, setMonedaId] = useState<number | ''>('');
  const [importe, setImporte] = useState('');
  const [concepto, setConcepto] = useState('');

  const { data: auxiliares } = useQuery<{ data: Auxiliar[] }>({
    queryKey: ['auxiliares'],
    queryFn: () => api.get('/api/erp/auxiliares'),
    enabled: open,
  });
  const { data: monedas } = useQuery<{ data: Moneda[] }>({
    queryKey: ['monedas'],
    queryFn: () => api.get('/api/erp/monedas'),
    enabled: open,
  });

  const crear = useMutation({
    mutationFn: () =>
      api.post('/api/erp/ordenes-pago', {
        fecha,
        tipo,
        auxiliar_id: auxId,
        moneda_id: monedaId || monedas?.data.find((m) => m.codigo === 'ARS')?.id,
        importe: Number(importe),
        concepto: concepto || null,
      }),
    onSuccess: () => {
      setAuxId('');
      setImporte('');
      setConcepto('');
      onSuccess();
    },
    onError: (e) => onError(e instanceof ApiError ? e.message : 'Error'),
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Nueva orden de pago"
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!auxId || !importe || crear.isPending} onClick={() => crear.mutate()}>
            {crear.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
            Crear OP (borrador)
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Fecha</label>
          <input type="date" value={fecha} onChange={(e) => setFecha(e.target.value)}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white" />
        </div>
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Tipo</label>
          <select value={tipo} onChange={(e) => setTipo(e.target.value as typeof tipo)}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white">
            <option value="PROVEEDOR">Proveedor</option>
            <option value="DISTRIBUIDOR">Distribuidor</option>
            <option value="OTROS">Otros</option>
          </select>
        </div>
        <div className="col-span-2">
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Beneficiario</label>
          <select value={auxId} onChange={(e) => setAuxId(e.target.value ? Number(e.target.value) : '')}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white">
            <option value="">Seleccionar…</option>
            {auxiliares?.data.map((a) => <option key={a.id} value={a.id}>{a.tipo}: {a.nombre}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Moneda</label>
          <select value={monedaId} onChange={(e) => setMonedaId(e.target.value ? Number(e.target.value) : '')}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white">
            <option value="">ARS (default)</option>
            {monedas?.data.map((m) => <option key={m.id} value={m.id}>{m.codigo}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Importe</label>
          <input type="number" step="0.01" value={importe} onChange={(e) => setImporte(e.target.value)}
            className="w-full px-[9px] py-[6px] text-[13px] text-right tabular border border-line-strong rounded-md bg-white" />
        </div>
        <div className="col-span-2">
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Concepto</label>
          <input value={concepto} onChange={(e) => setConcepto(e.target.value)}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white" />
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
