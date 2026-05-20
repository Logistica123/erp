import { useMemo, useState } from 'react';
import { AlertCircle, Check, Loader2, Plus, Upload, X, Zap, Search } from 'lucide-react';
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
  cuit_contraparte: string | null;
  nombre_contraparte: string | null;
  confianza_match: number | null;
  etiqueta_sugerida: string | null;
  regla_aplicada_id: number | null;
  // v1.27 Sprint A
  tipo_operativo: 'TRANSFERENCIA_RECIBIDA' | 'TRANSFERENCIA_ENVIADA' | 'PAGO_SERVICIO'
    | 'COMISION_BANCARIA' | 'IMPUESTO_DEBITO_CREDITO' | 'DEPOSITO' | 'EXTRACCION'
    | 'INTERES_GANADO' | 'OTRO';
  monto_conciliado: string | number;
};

// v1.27 Sprint C — modelo de sugerencias devueltas por GET /sugerencias.
type Sugerencia = {
  tipo: 'FACTURA_VENTA' | 'FACTURA_COMPRA';
  factura_id: number;
  numero: number;
  tipo_codigo?: string;
  letra?: string;
  cliente_nombre?: string;
  proveedor_nombre?: string;
  cuit?: string;
  imp_total: number;
  saldo_pendiente: number;
  fecha_emision: string;
  score: number;
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
  // v1.27 Sprint C — modal de sugerencias contra factura.
  const [sugerirPara, setSugerirPara] = useState<MovimientoBancario | null>(null);
  const [importExtracto, setImportExtracto] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [bulkAccion, setBulkAccion] = useState<'CONCILIAR_CONTRA_CUENTA' | 'IGNORAR' | null>(null);

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

  // v1.27 Sprint A — mutación conciliar directo (tipos auto).
  const conciliarDirectoMut = useMutation({
    mutationFn: (movId: number) =>
      api.post(`/api/erp/movimientos-bancarios/${movId}/conciliar-directo`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['mov-banc'] });
      qc.invalidateQueries({ queryKey: ['cuentas-bancarias'] });
      setErr(null);
    },
    onError: (e: ApiError) => setErr(e.message),
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
          <Button variant="secondary" onClick={() => setNuevoMov(true)}>
            <Plus className="w-3 h-3" /> Cargar movimiento manual
          </Button>
          <Button variant="primary" onClick={() => setImportExtracto(true)}>
            <Upload className="w-3 h-3" /> Subir extracto bancario
          </Button>
        </div>
      </div>

      {err && (
        <div className="mb-4 p-3 bg-danger-bg text-danger border border-danger/30 rounded-md text-[12px]">{err}</div>
      )}

      {selected.size > 0 && (
        <div className="mb-3 p-3 bg-navy-50 border border-navy-200 rounded-md flex items-center gap-3 text-[12px]">
          <span className="font-medium text-navy-800">{selected.size} mov{selected.size > 1 ? 's' : ''} seleccionado{selected.size > 1 ? 's' : ''}</span>
          <Button size="sm" variant="primary" onClick={() => setBulkAccion('CONCILIAR_CONTRA_CUENTA')}>
            <Check className="w-3 h-3" /> Conciliar contra cuenta…
          </Button>
          <Button size="sm" variant="secondary" onClick={() => setBulkAccion('IGNORAR')}>
            <X className="w-3 h-3" /> Ignorar todos…
          </Button>
          <button className="ml-auto text-ink-muted hover:text-ink-2 text-[11px]" onClick={() => setSelected(new Set())}>
            Limpiar selección
          </button>
        </div>
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
                <th className="w-[28px] px-[6px] py-[7px] text-center">
                  <input
                    type="checkbox"
                    checked={!!movs?.data.length && movs.data.every((m) => selected.has(m.id))}
                    onChange={(e) => {
                      const all = movs?.data.map((m) => m.id) ?? [];
                      setSelected(e.target.checked ? new Set(all) : new Set());
                    }}
                  />
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[90px]">Fecha</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Cuenta banc.</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">Concepto</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[200px]">Contraparte</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[100px]">Débito</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[100px]">Crédito</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Estado</th>
                <th className="w-[200px]" />
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr><td colSpan={9} className="py-10 text-center text-ink-muted"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Cargando…</td></tr>
              )}
              {movs?.data.length === 0 && !isLoading && (
                <tr><td colSpan={9} className="py-10 text-center text-ink-muted"><AlertCircle className="w-4 h-4 inline mr-1" />No hay movimientos.</td></tr>
              )}
              {movs?.data.map((m, i) => (
                <tr key={m.id} className={`border-b border-line hover:bg-surface-hover ${i % 2 ? 'bg-surface-row' : ''} ${selected.has(m.id) ? 'bg-blue-50/50' : ''}`}>
                  <td className="px-[6px] py-[7px] text-center">
                    {(m.estado === 'PENDIENTE' || m.estado === 'ETIQUETADO') && (
                      <input
                        type="checkbox"
                        checked={selected.has(m.id)}
                        onChange={(e) => {
                          const next = new Set(selected);
                          if (e.target.checked) next.add(m.id); else next.delete(m.id);
                          setSelected(next);
                        }}
                      />
                    )}
                  </td>
                  <td className="px-[10px] py-[7px] tabular text-ink-2">{m.fecha.slice(0, 10)}</td>
                  <td className="px-[10px] py-[7px] font-mono text-[11px] text-navy-700">{m.cuenta_bancaria.codigo}</td>
                  <td className="px-[10px] py-[7px] text-ink-2">
                    {m.concepto}
                    {m.etiqueta_sugerida === 'PASANTE_MP' && (
                      <span className="ml-2 px-1.5 py-0.5 text-[10px] rounded bg-amber-100 text-amber-700 font-medium">PASANTE MP</span>
                    )}
                    {m.tipo_operativo && m.tipo_operativo !== 'OTRO' && (
                      <span className={`ml-2 px-1.5 py-0.5 text-[10px] rounded font-medium ${
                        ['COMISION_BANCARIA', 'IMPUESTO_DEBITO_CREDITO'].includes(m.tipo_operativo) ? 'bg-red-50 text-red-700' :
                        m.tipo_operativo === 'INTERES_GANADO' ? 'bg-green-50 text-green-700' :
                        m.tipo_operativo.startsWith('TRANSFERENCIA') ? 'bg-azure-soft/40 text-azure-dark' :
                        'bg-line text-ink-2'
                      }`}>{m.tipo_operativo.replace(/_/g, ' ')}</span>
                    )}
                  </td>
                  <td className="px-[10px] py-[7px] text-[11px]">
                    {m.nombre_contraparte ? (
                      <div>
                        <div className="text-ink-2">{m.nombre_contraparte}</div>
                        <div className="flex items-center gap-1 text-ink-muted text-[10px]">
                          {m.cuit_contraparte && <span className="font-mono">{m.cuit_contraparte}</span>}
                          {m.confianza_match !== null && (
                            <span className={`px-1 rounded ${
                              m.confianza_match >= 80 ? 'bg-success-bg text-success'
                                : m.confianza_match >= 50 ? 'bg-amber-100 text-amber-700'
                                : 'bg-line text-ink-muted'
                            }`}>
                              {m.confianza_match}%
                            </span>
                          )}
                        </div>
                      </div>
                    ) : <span className="text-ink-muted">—</span>}
                  </td>
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
                      <div className="flex gap-1 justify-end flex-wrap">
                        {/* v1.27 Sprint A — Conciliar directo: tipos auto en 1 click */}
                        {['COMISION_BANCARIA', 'IMPUESTO_DEBITO_CREDITO', 'INTERES_GANADO'].includes(m.tipo_operativo) && (
                          <Button size="sm" variant="primary"
                            disabled={conciliarDirectoMut.isPending}
                            onClick={() => conciliarDirectoMut.mutate(m.id)}
                            title={`Concilia automáticamente a la cuenta configurada en banco_config para ${m.tipo_operativo}`}>
                            <Zap className="w-3 h-3" /> Directo
                          </Button>
                        )}
                        {/* v1.27 Sprint C — Sugerir facturas para transferencias */}
                        {['TRANSFERENCIA_RECIBIDA', 'TRANSFERENCIA_ENVIADA', 'PAGO_SERVICIO', 'OTRO'].includes(m.tipo_operativo) && (
                          <Button size="sm" variant="primary" onClick={() => setSugerirPara(m)}>
                            <Search className="w-3 h-3" /> Facturas
                          </Button>
                        )}
                        <Button size="sm" variant="secondary" onClick={() => setConciliar(m)}>
                          <Check className="w-3 h-3" /> Conciliar
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setIgnorar(m)}>
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

      {/* v1.27 Sprint C — Modal de sugerencias top-N para conciliar contra factura */}
      <SugerenciasModal
        mov={sugerirPara}
        onClose={() => { setSugerirPara(null); setErr(null); }}
        onSuccess={() => {
          setSugerirPara(null);
          qc.invalidateQueries({ queryKey: ['mov-banc'] });
          qc.invalidateQueries({ queryKey: ['asientos'] });
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

      <BulkModal
        accion={bulkAccion}
        ids={[...selected]}
        onClose={() => setBulkAccion(null)}
        onSuccess={() => {
          setBulkAccion(null);
          setSelected(new Set());
          qc.invalidateQueries({ queryKey: ['mov-banc'] });
        }}
        onError={setErr}
      />

      <ImportExtractoWizard
        open={importExtracto}
        cuentas={cuentasBanc?.data ?? []}
        onClose={() => setImportExtracto(false)}
        onSuccess={() => {
          qc.invalidateQueries({ queryKey: ['mov-banc'] });
          qc.invalidateQueries({ queryKey: ['cuentas-bancarias'] });
        }}
      />
    </>
  );
}

function BulkModal({
  accion,
  ids,
  onClose,
  onSuccess,
  onError,
}: {
  accion: 'CONCILIAR_CONTRA_CUENTA' | 'IGNORAR' | null;
  ids: number[];
  onClose: () => void;
  onSuccess: () => void;
  onError: (e: string) => void;
}) {
  const [cuentaContableId, setCuentaContableId] = useState<number | ''>('');
  const [motivoId, setMotivoId] = useState<number | ''>('');
  const [observacion, setObservacion] = useState('');

  const { data: cuentasResp } = useQuery<{ data: Cuenta[] }>({
    queryKey: ['cuentas', 'imputables'],
    queryFn: () => api.get('/api/erp/cuentas?imputable=true'),
    enabled: !!accion,
  });
  const { data: motivos } = useQuery<{ data: Motivo[] }>({
    queryKey: ['motivos-ignorado'],
    queryFn: async () => ({
      data: [
        { id: 1, codigo: 'COMISION_BANC', descripcion: 'Comisión bancaria' },
        { id: 2, codigo: 'IMP_LEY_25413', descripcion: 'Imp. Ley 25413' },
        { id: 3, codigo: 'NO_CONCERNIENTE', descripcion: 'No concierne a la empresa' },
      ],
    }),
    enabled: accion === 'IGNORAR',
  });

  const submit = useMutation<{ data: { exitos: number; errores: number } }>({
    mutationFn: () =>
      api.post('/api/erp/movimientos-bancarios/batch', {
        accion,
        ids,
        payload:
          accion === 'CONCILIAR_CONTRA_CUENTA'
            ? { cuenta_contable_contraparte_id: cuentaContableId, observacion: observacion || null }
            : { motivo_ignorado_id: motivoId, observacion: observacion || null },
      }) as Promise<{ data: { exitos: number; errores: number } }>,
    onSuccess: (resp) => {
      if (resp.data.errores > 0) {
        onError(`Bulk: ${resp.data.exitos} OK, ${resp.data.errores} con error.`);
      }
      onSuccess();
    },
    onError: (e) => onError(e instanceof ApiError ? e.message : 'Error'),
  });

  if (!accion) return null;
  const titulo = accion === 'CONCILIAR_CONTRA_CUENTA' ? 'Conciliar en lote' : 'Ignorar en lote';
  const puedeEnviar = accion === 'CONCILIAR_CONTRA_CUENTA' ? !!cuentaContableId : !!motivoId;

  return (
    <Modal
      open={!!accion}
      onClose={onClose}
      title={`${titulo} (${ids.length} mov${ids.length > 1 ? 's' : ''})`}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!puedeEnviar || submit.isPending} onClick={() => submit.mutate()}>
            {submit.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
            Aplicar a {ids.length} movimientos
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {accion === 'CONCILIAR_CONTRA_CUENTA' ? (
          <div>
            <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
              Cuenta contable contraparte
            </label>
            <select
              value={cuentaContableId}
              onChange={(e) => setCuentaContableId(e.target.value ? Number(e.target.value) : '')}
              className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
            >
              <option value="">Seleccionar cuenta…</option>
              {cuentasResp?.data.map((c) => (
                <option key={c.id} value={c.id}>{c.codigo} — {c.nombre}</option>
              ))}
            </select>
          </div>
        ) : (
          <div>
            <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
              Motivo
            </label>
            <select
              value={motivoId}
              onChange={(e) => setMotivoId(e.target.value ? Number(e.target.value) : '')}
              className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
            >
              <option value="">Seleccionar motivo…</option>
              {motivos?.data.map((m) => (
                <option key={m.id} value={m.id}>{m.descripcion}</option>
              ))}
            </select>
          </div>
        )}

        <div>
          <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
            Observación (opcional)
          </label>
          <input
            value={observacion}
            onChange={(e) => setObservacion(e.target.value)}
            placeholder="Aplicada en lote"
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          />
        </div>
      </div>
    </Modal>
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

function ImportExtractoWizard({
  open,
  cuentas,
  onClose,
  onSuccess,
}: {
  open: boolean;
  cuentas: CuentaBancaria[];
  onClose: () => void;
  onSuccess: () => void;
}) {
  type Resultado = {
    extracto_id: number;
    movimientos_importados: number;
    movimientos_duplicados: number;
    etiquetados_auto: number;
    pendientes: number;
    pasantes_mp: number;
    warnings: string[];
  };

  const [paso, setPaso] = useState<1 | 2 | 3>(1);
  const [cuentaId, setCuentaId] = useState<number | ''>('');
  const [archivo, setArchivo] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [resultado, setResultado] = useState<Resultado | null>(null);

  const reset = () => {
    setPaso(1);
    setCuentaId('');
    setArchivo(null);
    setError(null);
    setResultado(null);
  };
  const cerrar = () => {
    reset();
    onClose();
  };

  const cuentaSel = cuentas.find((c) => c.id === cuentaId);

  const subir = useMutation<{ data: Resultado }>({
    mutationFn: async () => {
      const fd = new FormData();
      fd.append('cuenta_id', String(cuentaId));
      fd.append('archivo', archivo!);
      return api.post('/api/erp/extractos/importar', fd) as Promise<{ data: Resultado }>;
    },
    onSuccess: (resp) => {
      setResultado(resp.data);
      setPaso(3);
      onSuccess();
    },
    onError: (e) => setError(e instanceof ApiError ? e.message : 'Error'),
  });

  if (!open) return null;

  return (
    <Modal
      open={open}
      onClose={cerrar}
      title={`Subir extracto bancario · paso ${paso} de 3`}
      size="md"
      footer={
        paso === 1 ? (
          <>
            <Button variant="secondary" onClick={cerrar}>Cancelar</Button>
            <Button
              variant="primary"
              disabled={!cuentaId || !archivo}
              onClick={() => setPaso(2)}
            >
              Continuar
            </Button>
          </>
        ) : paso === 2 ? (
          <>
            <Button variant="secondary" onClick={() => setPaso(1)}>Atrás</Button>
            <Button
              variant="primary"
              disabled={subir.isPending}
              onClick={() => subir.mutate()}
            >
              {subir.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
              Procesar e importar
            </Button>
          </>
        ) : (
          <Button variant="primary" onClick={cerrar}>Cerrar</Button>
        )
      }
    >
      {error && (
        <div className="mb-3 p-3 bg-danger-bg text-danger border border-danger/30 rounded-md text-[12px]">{error}</div>
      )}

      {paso === 1 && (
        <div className="space-y-3">
          <div>
            <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
              Cuenta bancaria destino
            </label>
            <select
              value={cuentaId}
              onChange={(e) => setCuentaId(e.target.value ? Number(e.target.value) : '')}
              className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
            >
              <option value="">Seleccionar cuenta…</option>
              {cuentas.map((c) => (
                <option key={c.id} value={c.id}>{c.codigo} — {c.nombre}</option>
              ))}
            </select>
            <div className="text-[10px] text-ink-muted mt-1">
              El parser se autodetecta a partir del banco de la cuenta seleccionada.
            </div>
          </div>
          <div>
            <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
              Archivo del extracto
            </label>
            <input
              type="file"
              accept=".csv,.txt,.xls,.xlsx"
              onChange={(e) => setArchivo(e.target.files?.[0] ?? null)}
              className="w-full text-[12px] file:mr-3 file:px-3 file:py-1 file:border-0 file:bg-navy-700 file:text-white file:rounded file:cursor-pointer file:text-[11px]"
            />
            {archivo && (
              <div className="text-[11px] text-ink-2 mt-2">
                {archivo.name} · {(archivo.size / 1024).toFixed(1)} KB
              </div>
            )}
          </div>
        </div>
      )}

      {paso === 2 && cuentaSel && archivo && (
        <div className="space-y-3 text-[12px]">
          <div className="p-3 bg-surface-row rounded-md border border-line">
            <div className="text-[11px] font-semibold text-navy-800 uppercase mb-2">Resumen pre-procesamiento</div>
            <dl className="grid grid-cols-2 gap-y-2">
              <dt className="text-ink-muted">Cuenta destino</dt>
              <dd className="font-mono">{cuentaSel.codigo} — {cuentaSel.nombre}</dd>
              <dt className="text-ink-muted">Archivo</dt>
              <dd>{archivo.name}</dd>
              <dt className="text-ink-muted">Tamaño</dt>
              <dd>{(archivo.size / 1024).toFixed(1)} KB</dd>
            </dl>
          </div>
          <div className="text-[11px] text-ink-muted">
            Idempotencia: si ya existe un extracto con el mismo hash SHA-256 para esta cuenta,
            la importación se rechaza con error <code>EXTRACTO_DUPLICADO</code>. El parser detecta automáticamente
            balance, contraparte (CUIT/nombre), y movimientos relacionados (operaciones pasantes MP).
          </div>
        </div>
      )}

      {paso === 3 && resultado && (
        <div className="space-y-3 text-[12px]">
          <div className="p-3 bg-success-bg rounded-md border border-success/30">
            <div className="text-success font-semibold mb-1">✓ Importación exitosa</div>
            <div className="text-[11px] text-ink-2">Extracto #{resultado.extracto_id}</div>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Stat label="Importados" value={resultado.movimientos_importados} />
            <Stat label="Duplicados (skip)" value={resultado.movimientos_duplicados} />
            <Stat label="Auto-etiquetados" value={resultado.etiquetados_auto} accent="success" />
            <Stat label="Pendientes" value={resultado.pendientes} accent="warning" />
            {resultado.pasantes_mp > 0 && (
              <Stat label="Pasantes MP detectados" value={resultado.pasantes_mp} accent="info" />
            )}
          </div>
          {resultado.warnings.length > 0 && (
            <div className="p-2 bg-amber-50 border border-amber-200 rounded text-[11px]">
              <div className="font-semibold text-amber-800 mb-1">Warnings:</div>
              <ul className="list-disc pl-4 text-amber-900">
                {resultado.warnings.map((w, i) => (<li key={i}>{w}</li>))}
              </ul>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}

function Stat({ label, value, accent }: { label: string; value: number; accent?: 'success' | 'warning' | 'info' }) {
  const cls =
    accent === 'success' ? 'text-success'
    : accent === 'warning' ? 'text-amber-700'
    : accent === 'info' ? 'text-navy-700'
    : 'text-ink-2';
  return (
    <div className="p-2 border border-line rounded">
      <div className="text-[10px] uppercase text-ink-muted">{label}</div>
      <div className={`text-lg tabular font-semibold ${cls}`}>{value}</div>
    </div>
  );
}

// v1.27 Sprint C — Modal de sugerencias de facturas para conciliar.
function SugerenciasModal({ mov, onClose, onSuccess, onError }: {
  mov: MovimientoBancario | null;
  onClose: () => void;
  onSuccess: () => void;
  onError: (msg: string) => void;
}) {
  const qc = useQueryClient();
  const [seleccionada, setSeleccionada] = useState<Sugerencia | null>(null);
  const [monto, setMonto] = useState<string>('');

  const { data: sugerencias, isLoading } = useQuery<{ data: Sugerencia[] }>({
    queryKey: ['sugerencias', mov?.id],
    queryFn: () => api.get(`/api/erp/movimientos-bancarios/${mov!.id}/sugerencias?top=10`),
    enabled: !!mov,
  });

  const montoMov = mov ? Math.max(Number(mov.debito), Number(mov.credito)) : 0;

  const conciliarMut = useMutation({
    mutationFn: () =>
      api.post(`/api/erp/movimientos-bancarios/${mov!.id}/conciliar-factura`, {
        tipo_factura: seleccionada!.tipo === 'FACTURA_VENTA' ? 'VENTA' : 'COMPRA',
        factura_id: seleccionada!.factura_id,
        monto: Number(monto || montoMov),
      }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['mov-banc'] }); onSuccess(); },
    onError: (e: ApiError) => onError(e.message),
  });

  if (!mov) return null;

  return (
    <Modal open onClose={onClose} title={`Sugerencias para mov #${mov.id} · $${fmtMoney(montoMov)}`} size="lg">
      <div className="space-y-3 text-[12px]">
        <div className="text-ink-muted">
          {mov.concepto} · {mov.fecha.slice(0, 10)} · tipo: <code>{mov.tipo_operativo}</code>
        </div>
        {isLoading && <div className="text-ink-muted">Buscando sugerencias…</div>}
        {!isLoading && (sugerencias?.data ?? []).length === 0 && (
          <div className="border border-warning/30 bg-warning-bg/20 rounded p-2 text-[11.5px]">
            No se encontraron facturas con saldo pendiente cerca de este monto.
            Probá la opción "Conciliar" general (referencia ASIENTO_MANUAL) para elegir cuenta manualmente.
          </div>
        )}
        <div className="space-y-1 max-h-[300px] overflow-y-auto">
          {(sugerencias?.data ?? []).map((s) => (
            <label key={`${s.tipo}-${s.factura_id}`}
              className={`flex items-start gap-2 p-2 rounded border cursor-pointer transition ${
                seleccionada?.factura_id === s.factura_id && seleccionada.tipo === s.tipo
                  ? 'border-azure bg-azure-soft/30'
                  : 'border-line hover:bg-surface-hover'
              }`}>
              <input type="radio" checked={seleccionada?.factura_id === s.factura_id && seleccionada.tipo === s.tipo}
                onChange={() => { setSeleccionada(s); setMonto(String(Math.min(s.saldo_pendiente, montoMov))); }} />
              <div className="flex-1">
                <div className="font-medium text-ink-2">
                  {s.tipo_codigo} {s.letra ?? ''} {s.numero}
                  <span className="ml-2 text-[10px] px-1 rounded bg-line">
                    {s.tipo === 'FACTURA_VENTA' ? 'VENTA' : 'COMPRA'}
                  </span>
                </div>
                <div className="text-[11px] text-ink-muted">
                  {s.cliente_nombre ?? s.proveedor_nombre ?? '—'}
                  {s.cuit && <span className="font-mono ml-2">{s.cuit}</span>}
                  · {s.fecha_emision?.slice(0, 10)}
                </div>
              </div>
              <div className="text-right text-[11px]">
                <div className="font-semibold tabular">{fmtMoney(s.saldo_pendiente)}</div>
                <div className={`text-[10px] ${s.score >= 95 ? 'text-success' : s.score >= 80 ? 'text-warning' : 'text-ink-muted'}`}>
                  match {s.score}%
                </div>
              </div>
            </label>
          ))}
        </div>

        {seleccionada && (
          <div className="border-t border-line pt-3 space-y-2">
            <div className="text-[11px] text-ink-muted">
              Monto a conciliar (puede ser parcial si la factura es mayor):
            </div>
            <input type="number" step="0.01"
              value={monto} onChange={(e) => setMonto(e.target.value)}
              className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
          </div>
        )}

        <div className="flex justify-end gap-2 pt-2 border-t border-line">
          <Button variant="secondary" onClick={onClose} disabled={conciliarMut.isPending}>
            Cancelar
          </Button>
          <Button variant="primary"
            disabled={!seleccionada || !monto || Number(monto) <= 0 || conciliarMut.isPending}
            onClick={() => conciliarMut.mutate()}>
            {conciliarMut.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />}
            Confirmar conciliación
          </Button>
        </div>
      </div>
    </Modal>
  );
}

