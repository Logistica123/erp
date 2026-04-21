import { useMemo, useState } from 'react';
import { AlertCircle, Check, Loader2, Plus, X } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

type CuentaBancaria = { id: number; codigo: string; nombre: string };
type MovimientoBancario = {
  id: number;
  fecha: string;
  concepto: string;
  debito: string;
  credito: string;
  estado: 'PENDIENTE' | 'ETIQUETADO' | 'CONCILIADO' | 'IGNORADO';
  cuenta_bancaria_id: number;
  cuenta_bancaria: { id: number; codigo: string; nombre: string };
  asiento_id: number | null;
};
type Cuenta = { id: number; codigo: string; nombre: string; imputable: boolean; admite_cc: boolean; admite_auxiliar: boolean };
type Auxiliar = { id: number; codigo: string; nombre: string; tipo: string };
type CC = { id: number; codigo: string; nombre: string };
type Motivo = { id: number; codigo: string; descripcion: string };

export function ConciliacionPage() {
  const qc = useQueryClient();
  const [cuentaBancariaId, setCuentaBancariaId] = useState<number | ''>('');
  const [estado, setEstado] = useState<'PENDIENTE' | 'ETIQUETADO' | 'CONCILIADO' | 'IGNORADO' | ''>('PENDIENTE');
  const [conciliar, setConciliar] = useState<MovimientoBancario | null>(null);
  const [ignorar, setIgnorar] = useState<MovimientoBancario | null>(null);
  const [nuevoMov, setNuevoMov] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const { data: cuentasBanc } = useQuery<{ data: CuentaBancaria[] }>({
    queryKey: ['cuentas-bancarias'],
    queryFn: () => api.get('/api/erp/cuentas-bancarias'),
  });
  const { data: movs, isLoading } = useQuery<{ data: MovimientoBancario[] }>({
    queryKey: ['mov-banc', cuentaBancariaId, estado],
    queryFn: () => {
      const qs = new URLSearchParams();
      if (cuentaBancariaId) qs.set('cuenta_bancaria_id', String(cuentaBancariaId));
      if (estado) qs.set('estado', estado);
      return api.get(`/api/erp/movimientos-bancarios?${qs}`);
    },
  });

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Conciliación bancaria</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            {movs?.data.length ?? 0} movimientos {estado ? `en estado ${estado}` : 'totales'}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="primary" onClick={() => setNuevoMov(true)}>
            <Plus className="w-3 h-3" /> Cargar movimiento manual
          </Button>
        </div>
      </div>

      {err && (
        <div className="mb-4 p-3 bg-danger-bg text-danger border border-danger/30 rounded-md text-[12px]">{err}</div>
      )}

      <Card>
        <CardHeader
          title="Movimientos bancarios"
          actions={
            <div className="flex gap-2 items-center">
              <select
                value={cuentaBancariaId}
                onChange={(e) => setCuentaBancariaId(e.target.value ? Number(e.target.value) : '')}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
              >
                <option value="">Todas las cuentas</option>
                {cuentasBanc?.data.map((c) => (
                  <option key={c.id} value={c.id}>{c.codigo} — {c.nombre}</option>
                ))}
              </select>
              <select
                value={estado}
                onChange={(e) => setEstado(e.target.value as typeof estado)}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
              >
                <option value="">Todos los estados</option>
                <option value="PENDIENTE">Pendiente</option>
                <option value="ETIQUETADO">Etiquetado</option>
                <option value="CONCILIADO">Conciliado</option>
                <option value="IGNORADO">Ignorado</option>
              </select>
            </div>
          }
        />
        <CardBody>
          <table className="w-full border-collapse text-[12px]">
            <thead>
              <tr className="bg-surface-hover border-b border-line-strong">
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[90px]">Fecha</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Cuenta banc.</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">Concepto</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Débito</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Crédito</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Estado</th>
                <th className="w-[200px]" />
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr><td colSpan={7} className="py-10 text-center text-ink-muted"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Cargando…</td></tr>
              )}
              {movs?.data.length === 0 && !isLoading && (
                <tr><td colSpan={7} className="py-10 text-center text-ink-muted"><AlertCircle className="w-4 h-4 inline mr-1" />No hay movimientos.</td></tr>
              )}
              {movs?.data.map((m, i) => (
                <tr key={m.id} className={`border-b border-line hover:bg-surface-hover ${i % 2 ? 'bg-surface-row' : ''}`}>
                  <td className="px-[10px] py-[7px] tabular text-ink-2">{m.fecha.slice(0, 10)}</td>
                  <td className="px-[10px] py-[7px] font-mono text-[11px] text-navy-700">{m.cuenta_bancaria.codigo}</td>
                  <td className="px-[10px] py-[7px] text-ink-2">{m.concepto}</td>
                  <td className={`px-[10px] py-[7px] text-right tabular ${Number(m.debito) ? 'text-danger font-medium' : 'text-ink-muted'}`}>
                    {Number(m.debito) ? fmtMoney(Number(m.debito)) : '—'}
                  </td>
                  <td className={`px-[10px] py-[7px] text-right tabular ${Number(m.credito) ? 'text-success font-medium' : 'text-ink-muted'}`}>
                    {Number(m.credito) ? fmtMoney(Number(m.credito)) : '—'}
                  </td>
                  <td className="px-[10px] py-[7px]">
                    {m.estado === 'CONCILIADO' && <Badge variant="success">Conciliado</Badge>}
                    {m.estado === 'PENDIENTE' && <Badge variant="warning">Pendiente</Badge>}
                    {m.estado === 'ETIQUETADO' && <Badge variant="info">Etiquetado</Badge>}
                    {m.estado === 'IGNORADO' && <Badge variant="neutral">Ignorado</Badge>}
                  </td>
                  <td className="px-[10px] py-[7px] text-right">
                    {(m.estado === 'PENDIENTE' || m.estado === 'ETIQUETADO') && (
                      <div className="flex gap-1 justify-end">
                        <Button size="sm" variant="primary" onClick={() => setConciliar(m)}>
                          <Check className="w-3 h-3" /> Conciliar
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => setIgnorar(m)}>
                          <X className="w-3 h-3" /> Ignorar
                        </Button>
                      </div>
                    )}
                    {m.estado === 'CONCILIADO' && m.asiento_id && (
                      <span className="text-[10px] text-ink-muted">→ Asiento #{m.asiento_id}</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardBody>
      </Card>

      <ConciliarModal
        mov={conciliar}
        onClose={() => {
          setConciliar(null);
          setErr(null);
        }}
        onSuccess={() => {
          setConciliar(null);
          qc.invalidateQueries({ queryKey: ['mov-banc'] });
          qc.invalidateQueries({ queryKey: ['cuentas-bancarias'] });
          qc.invalidateQueries({ queryKey: ['asientos'] });
          qc.invalidateQueries({ queryKey: ['health'] });
        }}
        onError={setErr}
      />

      <IgnorarModal
        mov={ignorar}
        onClose={() => setIgnorar(null)}
        onSuccess={() => {
          setIgnorar(null);
          qc.invalidateQueries({ queryKey: ['mov-banc'] });
        }}
        onError={setErr}
      />

      <NuevoMovModal
        open={nuevoMov}
        onClose={() => setNuevoMov(false)}
        cuentas={cuentasBanc?.data ?? []}
        onSuccess={() => {
          setNuevoMov(false);
          qc.invalidateQueries({ queryKey: ['mov-banc'] });
        }}
        onError={setErr}
      />
    </>
  );
}

function ConciliarModal({
  mov,
  onClose,
  onSuccess,
  onError,
}: {
  mov: MovimientoBancario | null;
  onClose: () => void;
  onSuccess: () => void;
  onError: (e: string) => void;
}) {
  const [cuentaId, setCuentaId] = useState<number | ''>('');
  const [ccId, setCcId] = useState<number | ''>('');
  const [auxId, setAuxId] = useState<number | ''>('');
  const [glosa, setGlosa] = useState('');

  const { data: cuentasResp } = useQuery<{ data: Cuenta[] }>({
    queryKey: ['cuentas', 'imputables'],
    queryFn: () => api.get('/api/erp/cuentas?imputable=true'),
    enabled: !!mov,
  });
  const { data: ccsResp } = useQuery<{ data: CC[] }>({
    queryKey: ['centros-costo'],
    queryFn: () => api.get('/api/erp/centros-costo'),
    enabled: !!mov,
  });
  const { data: auxResp } = useQuery<{ data: Auxiliar[] }>({
    queryKey: ['auxiliares'],
    queryFn: () => api.get('/api/erp/auxiliares'),
    enabled: !!mov,
  });

  const cuentaSeleccionada = useMemo(
    () => cuentasResp?.data.find((c) => c.id === cuentaId),
    [cuentasResp, cuentaId]
  );

  const conciliar = useMutation({
    mutationFn: () =>
      api.post(`/api/erp/movimientos-bancarios/${mov!.id}/conciliar`, {
        cuenta_contable_id: cuentaId,
        centro_costo_id: ccId || null,
        auxiliar_id: auxId || null,
        glosa: glosa || null,
      }),
    onSuccess,
    onError: (e) => onError(e instanceof ApiError ? e.message : 'Error'),
  });

  if (!mov) return null;

  return (
    <Modal
      open={!!mov}
      onClose={onClose}
      title={`Conciliar movimiento #${mov.id}`}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!cuentaId || conciliar.isPending} onClick={() => conciliar.mutate()}>
            {conciliar.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
            Conciliar y generar asiento
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-3 mb-4 p-3 bg-surface-row rounded-md text-[12px]">
        <div>
          <div className="text-[10px] uppercase text-ink-muted font-semibold">Concepto</div>
          <div className="text-ink-2">{mov.concepto}</div>
        </div>
        <div>
          <div className="text-[10px] uppercase text-ink-muted font-semibold">Importe</div>
          <div className={`tabular font-semibold ${Number(mov.credito) > 0 ? 'text-success' : 'text-danger'}`}>
            {fmtMoney(Number(mov.credito) > 0 ? Number(mov.credito) : -Number(mov.debito))}
          </div>
        </div>
        <div>
          <div className="text-[10px] uppercase text-ink-muted font-semibold">Cuenta bancaria</div>
          <div className="font-mono text-[11px]">{mov.cuenta_bancaria.codigo}</div>
        </div>
        <div>
          <div className="text-[10px] uppercase text-ink-muted font-semibold">Fecha</div>
          <div className="tabular">{mov.fecha.slice(0, 10)}</div>
        </div>
      </div>

      <div className="space-y-3">
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
            Cuenta contable contraparte
          </label>
          <select
            value={cuentaId}
            onChange={(e) => setCuentaId(e.target.value ? Number(e.target.value) : '')}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          >
            <option value="">Seleccionar cuenta…</option>
            {cuentasResp?.data.map((c) => (
              <option key={c.id} value={c.id}>
                {c.codigo} — {c.nombre}
              </option>
            ))}
          </select>
          {cuentaSeleccionada && (
            <div className="text-[11px] text-ink-muted mt-1">
              {cuentaSeleccionada.admite_cc && '⚠ Requiere centro de costo · '}
              {cuentaSeleccionada.admite_auxiliar && '⚠ Requiere auxiliar'}
            </div>
          )}
        </div>

        {cuentaSeleccionada?.admite_cc && (
          <div>
            <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
              Centro de costo
            </label>
            <select
              value={ccId}
              onChange={(e) => setCcId(e.target.value ? Number(e.target.value) : '')}
              className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
            >
              <option value="">—</option>
              {ccsResp?.data.map((c) => (
                <option key={c.id} value={c.id}>{c.codigo} — {c.nombre}</option>
              ))}
            </select>
          </div>
        )}

        {cuentaSeleccionada?.admite_auxiliar && (
          <div>
            <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
              Auxiliar
            </label>
            <select
              value={auxId}
              onChange={(e) => setAuxId(e.target.value ? Number(e.target.value) : '')}
              className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
            >
              <option value="">—</option>
              {auxResp?.data.map((a) => (
                <option key={a.id} value={a.id}>{a.tipo.slice(0, 4)}: {a.nombre}</option>
              ))}
            </select>
          </div>
        )}

        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
            Glosa (opcional)
          </label>
          <input
            value={glosa}
            onChange={(e) => setGlosa(e.target.value)}
            placeholder={mov.concepto}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          />
        </div>
      </div>
    </Modal>
  );
}

function IgnorarModal({
  mov,
  onClose,
  onSuccess,
  onError,
}: {
  mov: MovimientoBancario | null;
  onClose: () => void;
  onSuccess: () => void;
  onError: (e: string) => void;
}) {
  const [motivoId, setMotivoId] = useState<number | ''>('');
  const [observacion, setObservacion] = useState('');
  const { data: motivos } = useQuery<{ data: Motivo[] }>({
    queryKey: ['motivos-ignorado'],
    queryFn: async () => ({
      data: [
        { id: 1, codigo: 'COMISION_IMPUTADA_BLOQUE', descripcion: 'Comisión bancaria ya imputada en bloque mensual' },
        { id: 2, codigo: 'MOV_DUPLICADO_BANCO', descripcion: 'Duplicado corregido en siguiente extracto' },
        { id: 3, codigo: 'AJUSTE_CAMBIO_SISTEMA', descripcion: 'Ajuste interno del banco' },
        { id: 4, codigo: 'REVERSO_OPERACION', descripcion: 'Reverso de operación del mismo día' },
        { id: 5, codigo: 'NO_CONTABLE', descripcion: 'No representa movimiento contable' },
        { id: 6, codigo: 'PENDIENTE_DEFINICION', descripcion: 'Pendiente de análisis — no imputar por ahora' },
      ],
    }),
    enabled: !!mov,
  });

  const ignorar = useMutation({
    mutationFn: () =>
      api.post(`/api/erp/movimientos-bancarios/${mov!.id}/ignorar`, {
        motivo_ignorado_id: motivoId,
        observacion: observacion || null,
      }),
    onSuccess,
    onError: (e) => onError(e instanceof ApiError ? e.message : 'Error'),
  });

  if (!mov) return null;

  return (
    <Modal
      open={!!mov}
      onClose={onClose}
      title={`Ignorar movimiento #${mov.id}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={!motivoId || ignorar.isPending} onClick={() => ignorar.mutate()}>
            Ignorar movimiento
          </Button>
        </>
      }
    >
      <p className="text-[12px] text-ink-2 mb-3">
        Ignorar un movimiento significa que no genera asiento contable. Queda registrado con su motivo.
      </p>
      <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Motivo</label>
      <select
        value={motivoId}
        onChange={(e) => setMotivoId(e.target.value ? Number(e.target.value) : '')}
        className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white mb-3"
      >
        <option value="">Seleccionar motivo…</option>
        {motivos?.data.map((m) => (
          <option key={m.id} value={m.id}>{m.descripcion}</option>
        ))}
      </select>
      <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
        Observación (opcional)
      </label>
      <textarea
        value={observacion}
        onChange={(e) => setObservacion(e.target.value)}
        rows={2}
        className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
      />
    </Modal>
  );
}

function NuevoMovModal({
  open,
  onClose,
  cuentas,
  onSuccess,
  onError,
}: {
  open: boolean;
  onClose: () => void;
  cuentas: CuentaBancaria[];
  onSuccess: () => void;
  onError: (e: string) => void;
}) {
  const [cuentaId, setCuentaId] = useState<number | ''>('');
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10));
  const [concepto, setConcepto] = useState('');
  const [tipo, setTipo] = useState<'debito' | 'credito'>('debito');
  const [importe, setImporte] = useState('');

  const crear = useMutation({
    mutationFn: () =>
      api.post('/api/erp/movimientos-bancarios', {
        cuenta_bancaria_id: cuentaId,
        fecha,
        concepto,
        debito: tipo === 'debito' ? Number(importe) : 0,
        credito: tipo === 'credito' ? Number(importe) : 0,
      }),
    onSuccess: () => {
      setConcepto('');
      setImporte('');
      onSuccess();
    },
    onError: (e) => onError(e instanceof ApiError ? e.message : 'Error'),
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Cargar movimiento bancario manual"
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button
            variant="primary"
            disabled={!cuentaId || !concepto || !importe || crear.isPending}
            onClick={() => crear.mutate()}
          >
            Cargar
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-3">
        <div className="col-span-2">
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Cuenta bancaria</label>
          <select
            value={cuentaId}
            onChange={(e) => setCuentaId(e.target.value ? Number(e.target.value) : '')}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          >
            <option value="">Seleccionar…</option>
            {cuentas.map((c) => (
              <option key={c.id} value={c.id}>{c.codigo} — {c.nombre}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Fecha</label>
          <input
            type="date"
            value={fecha}
            onChange={(e) => setFecha(e.target.value)}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          />
        </div>
        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Tipo</label>
          <select
            value={tipo}
            onChange={(e) => setTipo(e.target.value as 'debito' | 'credito')}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          >
            <option value="debito">Débito (egreso)</option>
            <option value="credito">Crédito (ingreso)</option>
          </select>
        </div>
        <div className="col-span-2">
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Concepto</label>
          <input
            value={concepto}
            onChange={(e) => setConcepto(e.target.value)}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          />
        </div>
        <div className="col-span-2">
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Importe</label>
          <input
            type="number"
            step="0.01"
            value={importe}
            onChange={(e) => setImporte(e.target.value)}
            className="w-full px-[9px] py-[6px] text-[13px] text-right tabular border border-line-strong rounded-md bg-white"
          />
        </div>
      </div>
    </Modal>
  );
}
