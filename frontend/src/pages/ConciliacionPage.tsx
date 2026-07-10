import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, Check, Loader2, Plus, Upload, X, Zap, Link2, Trash2, RefreshCw, Download, SlidersHorizontal } from 'lucide-react';
import { auth } from '@/lib/auth';
import { parseMontoEs } from '@/lib/montos';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

type CuentaBancaria = { id: number; codigo: string; nombre: string; parser_soportado?: boolean };
type MovimientoBancario = {
  id: number;
  fecha: string;
  concepto: string;
  debito: string;
  credito: string;
  estado: 'PENDIENTE' | 'ETIQUETADO' | 'CONCILIADO' | 'IGNORADO'
    | 'CONFIRMADO_CHEQUES_COBRADOS' | 'CONFIRMADO_DESCUENTO_CHEQUE' | 'CONFIRMADO_RECIBO_DIRECTO'
    | 'CONFIRMADO_RECIBO_CON_CHEQUES';
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
  // v1.27 §16 — cuando una regla auto-etiquetó el mov.
  cuenta_contable_propuesta_id?: number | null;
};

// v1.27 Sprint C + §15 — modelo de sugerencias devueltas por GET /sugerencias.
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
  cuit_coincide?: boolean; // §15
  cuit_no_coincide?: boolean; // v1.47.1 Bug #3
};

// §15 — respuesta enriquecida.
type SugerenciasResp = {
  sugerencias: Sugerencia[];
  cuit_detectado: string | null;
  contraparte: { id: number; nombre: string; cuit: string; tipo: string } | null;
  motivo_fallback:
    | null
    | 'CUIT_NO_DETECTADO_EN_CONCEPTO'
    | 'CUIT_NO_REGISTRADO'
    | 'CONTRAPARTE_SIN_FACTURAS_PENDIENTES'
    | 'TIPO_SIN_FACTURAS'
    | 'MOV_SIN_IMPORTE';
};
// v1.49 — candidatos de cheques / descuentos / recibos (GET /candidatos).
type ChequeCandidato = {
  cheque_id: number; numero_cheque: string; banco_emisor: string;
  cliente_id: number | null; cliente_nombre: string | null;
  importe: number; fecha_emision: string; fecha_vencimiento: string;
  estado_actual: string; recibo_origen_id: number | null; recibo_numero: string | null;
  match_score: number;
};
type DescuentoCandidato = {
  asiento_id: number; cheque_id: number; numero_cheque: string; banco_emisor: string;
  entidad_descuento: string | null; cliente_nombre: string | null;
  importe_cheque: number; neto: number; fecha: string;
};
type ChequeAnidado = {
  cheque_id: number; numero_cheque: string; banco_emisor: string; importe: number;
  fecha_pago: string; estado_actual: string; ya_vinculado_otro_mov: boolean; se_auto_confirma: boolean;
};
type ReciboCandidato = {
  recibo_id: number; numero_recibo: string; cliente_id: number | null; cliente_nombre: string | null;
  fecha_emision: string; fecha_cobro: string; monto_cobrado: number;
  medio_cobro: string; medio_es_cheques?: boolean; detalle_cobro: string | null;
  asiento_id: number | null; cheques?: ChequeAnidado[]; match_score: number;
};
type CandidatosV149 = {
  recibos: ReciboCandidato[];
  cheques_sueltos: ChequeCandidato[];
  descuentos_cheque: DescuentoCandidato[];
};

type Cuenta = { id: number; codigo: string; nombre: string; imputable: boolean; admite_cc: boolean; admite_auxiliar: boolean };
type Auxiliar = { id: number; codigo: string; nombre: string; tipo: string };
type CC = { id: number; codigo: string; nombre: string };
type Motivo = { id: number; codigo: string; descripcion: string };
// v1.48 Bloque D — catálogo de motivos de diferencia.
type MotivoDif = {
  id: number; codigo: string; nombre: string; tipo: string; signo_esperado: string;
  requiere_auxiliar_tipo: string | null; cuenta_ajuste_id: number | null;
  cuenta_codigo: string | null; cuenta_nombre: string | null; observaciones: string | null;
};
// v1.48 Anexo A — anticipo otorgado pendiente del auxiliar.
type Anticipo = { mov_id: number; fecha: string; monto: string; glosa: string | null; dias_pendiente: number };

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
  // v1.50 Bloque A — chips + filtros avanzados.
  const [tipoChip, setTipoChip] = useState<'' | 'DEBITO' | 'CREDITO'>('');
  const [categorias, setCategorias] = useState<Set<string>>(new Set());
  const [fDesde, setFDesde] = useState('');
  const [fHasta, setFHasta] = useState('');
  const [impDesde, setImpDesde] = useState('');
  const [impHasta, setImpHasta] = useState('');
  const [filtrosOpen, setFiltrosOpen] = useState(false);

  const { data: cuentasBanc } = useQuery<{ data: CuentaBancaria[] }>({
    queryKey: ['cuentas-bancarias'],
    queryFn: () => api.get('/api/erp/cuentas-bancarias'),
  });
  const catsKey = Array.from(categorias).sort().join(',');
  const armarQs = () => {
    const qs = new URLSearchParams();
    if (cuentaBancariaId) qs.set('cuenta_bancaria_id', String(cuentaBancariaId));
    if (estado) qs.set('estado', estado);
    if (tipoChip) qs.set('tipo', tipoChip);
    categorias.forEach((c) => qs.append('categorias[]', c));
    if (fDesde) qs.set('desde', fDesde);
    if (fHasta) qs.set('hasta', fHasta);
    if (impDesde) qs.set('importe_desde', String(parseMontoEs(impDesde)));
    if (impHasta) qs.set('importe_hasta', String(parseMontoEs(impHasta)));
    return qs;
  };
  type MetaTotales = { total_movs: number; total_debitos: number; total_creditos: number; total_neto: number };
  const { data: movs, isLoading } = useQuery<{ data: MovimientoBancario[]; meta?: Partial<MetaTotales> }>({
    queryKey: ['mov-banc', cuentaBancariaId, estado, tipoChip, catsKey, fDesde, fHasta, impDesde, impHasta],
    queryFn: () => api.get(`/api/erp/movimientos-bancarios?${armarQs()}`),
  });
  const meta = movs?.meta;

  // v1.50 — filtros guardados por usuario.
  const { data: filtrosGuardados } = useQuery<{ data: Array<{ id: number; nombre: string; filtros_json: string }> }>({
    queryKey: ['mov-banc-filtros'],
    queryFn: () => api.get('/api/erp/movimientos-bancarios/filtros-guardados'),
  });
  const guardarFiltroMut = useMutation({
    mutationFn: (nombre: string) => api.post('/api/erp/movimientos-bancarios/filtros-guardados', {
      nombre,
      filtros: { cuentaBancariaId, estado, tipoChip, categorias: Array.from(categorias), fDesde, fHasta, impDesde, impHasta },
    }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['mov-banc-filtros'] }),
    onError: (e: ApiError) => setErr(e.message),
  });
  const aplicarFiltroGuardado = (json: string) => {
    try {
      const f = JSON.parse(json);
      setCuentaBancariaId(f.cuentaBancariaId ?? '');
      setEstado((f.estado ?? '') as typeof estado);
      setTipoChip(f.tipoChip ?? '');
      setCategorias(new Set(f.categorias ?? []));
      setFDesde(f.fDesde ?? ''); setFHasta(f.fHasta ?? '');
      setImpDesde(f.impDesde ?? ''); setImpHasta(f.impHasta ?? '');
      setFiltrosOpen(true);
    } catch { /* json inválido: ignorar */ }
  };

  // v1.50 — export xlsx del listado filtrado (o de la selección).
  const [exportando, setExportando] = useState(false);
  const exportarExcel = (soloSeleccion: boolean) => {
    const qs = armarQs();
    if (soloSeleccion) selected.forEach((id) => qs.append('ids[]', String(id)));
    const token = auth.getToken();
    setExportando(true);
    fetch(`/api/erp/movimientos-bancarios/export?${qs}`, { headers: token ? { Authorization: `Bearer ${token}` } : {} })
      .then((r) => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.blob(); })
      .then((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `conciliacion_${new Date().toISOString().slice(0, 10)}.xlsx`;
        a.click();
      })
      .catch(() => setErr('No se pudo exportar el Excel.'))
      .finally(() => setExportando(false));
  };

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

  // v1.49 — reversa de vinculaciones contra cheques/descuento/recibo.
  const revertirV149Mut = useMutation({
    mutationFn: ({ movId, motivo }: { movId: number; motivo: string }) =>
      api.post(`/api/erp/movimientos-bancarios/${movId}/desconciliar`, { motivo }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['mov-banc'] }); setErr(null); },
    onError: (e: ApiError) => setErr(e.message),
  });
  const revertirV149 = (movId: number) => {
    const motivo = prompt('Motivo de la reversa:');
    if (motivo && motivo.trim().length >= 3) revertirV149Mut.mutate({ movId, motivo: motivo.trim() });
  };

  // v1.27 §16 — permisos del operador (para mostrar/ocultar botón Borrar).
  const { data: misPermisos } = useQuery<{ data?: Array<{ codigo: string }> } | Array<{ codigo: string }>>({
    queryKey: ['mis-permisos'],
    queryFn: () => api.get('/api/erp/mi-permisos'),
  });
  const permisosArr = useMemo(() => Array.isArray(misPermisos) ? misPermisos : (misPermisos?.data ?? []), [misPermisos]);
  const puedeBorrarBulk = useMemo(() => permisosArr.some((p) => p.codigo === 'tesoreria.movimientos.borrar_bulk'), [permisosArr]);
  const puedeBorrarImport = useMemo(() => permisosArr.some((p) => p.codigo === 'tesoreria.extractos.borrar_import'), [permisosArr]);
  const [borrarImportOpen, setBorrarImportOpen] = useState(false);

  // v1.27 §16 — confirmar bulk auto-etiquetados.
  const autoEtiquetadosIds = useMemo(() => {
    return (movs?.data ?? [])
      .filter((m) => m.estado === 'ETIQUETADO' && (m as { cuenta_contable_propuesta_id?: number | null }).cuenta_contable_propuesta_id)
      .map((m) => m.id);
  }, [movs]);
  const confirmarAutoMut = useMutation({
    mutationFn: (ids: number[]) =>
      api.post('/api/erp/movimientos-bancarios/confirmar-auto-etiquetados', { ids }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['mov-banc'] });
      qc.invalidateQueries({ queryKey: ['cuentas-bancarias'] });
      setErr(null);
    },
    onError: (e: ApiError) => setErr(e.message),
  });

  // v1.27 §16 — borrar bulk.
  const [borrarBulkOpen, setBorrarBulkOpen] = useState(false);
  const [rematchOpen, setRematchOpen] = useState(false);

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
          {puedeBorrarImport && (
            <Button variant="danger" onClick={() => setBorrarImportOpen(true)}>
              <Trash2 className="w-3 h-3" /> Borrar import
            </Button>
          )}
          <Button variant="secondary" onClick={() => setRematchOpen(true)}>
            <RefreshCw className="w-3 h-3" /> Re-aplicar reglas y emparejar
          </Button>
        </div>
      </div>

      {err && (
        <div className="mb-4 p-3 bg-danger-bg text-danger border border-danger/30 rounded-md text-[12px]">{err}</div>
      )}

      {/* v1.50 Bloque A — chips de filtro rápido + filtros avanzados + sumatoria */}
      <div className="mb-3 space-y-2">
        <div className="flex flex-wrap gap-1.5 items-center text-[11.5px]">
          {([['', 'Todos'], ['DEBITO', 'Débitos'], ['CREDITO', 'Créditos']] as const).map(([v, l]) => (
            <button key={l} type="button" onClick={() => setTipoChip(v)}
              className={`px-2.5 py-1 rounded-full border transition ${tipoChip === v
                ? 'bg-navy-800 text-white border-navy-800'
                : 'bg-white border-line-strong text-ink-2 hover:bg-surface-hover'}`}>
              {l}
            </button>
          ))}
          <span className="text-line-strong mx-1">|</span>
          {([['SIN_ETIQUETAR', 'Sin etiquetar'], ['IMPUESTOS', 'Impuestos'], ['COMISIONES', 'Comisiones'],
            ['SERVICIOS', 'Servicios'], ['SUELDOS', 'Sueldos'], ['TRANSFERENCIAS_INTERNAS', 'Transf. internas'],
            ['CHEQUES', 'Cheques'], ['COBROS', 'Cobros']] as const).map(([v, l]) => (
            <button key={v} type="button"
              onClick={() => {
                const n = new Set(categorias);
                n.has(v) ? n.delete(v) : n.add(v);
                setCategorias(n);
              }}
              className={`px-2.5 py-1 rounded-full border transition ${categorias.has(v)
                ? 'bg-azure text-white border-azure'
                : 'bg-white border-line-strong text-ink-2 hover:bg-surface-hover'}`}>
              {l}
            </button>
          ))}
          <button type="button" onClick={() => setFiltrosOpen(!filtrosOpen)}
            className="ml-auto px-2.5 py-1 rounded-full border border-line-strong bg-white text-ink-2 hover:bg-surface-hover inline-flex items-center gap-1">
            <SlidersHorizontal className="w-3 h-3" /> Filtros avanzados {filtrosOpen ? '▴' : '▾'}
          </button>
        </div>

        {filtrosOpen && (
          <div className="p-3 border border-line rounded-md bg-surface-row/40 flex flex-wrap items-end gap-3 text-[11.5px]">
            <label className="flex flex-col gap-0.5">Desde
              <input type="date" value={fDesde} onChange={(e) => setFDesde(e.target.value)}
                className="px-2 py-1 border border-line-strong rounded bg-white" />
            </label>
            <label className="flex flex-col gap-0.5">Hasta
              <input type="date" value={fHasta} onChange={(e) => setFHasta(e.target.value)}
                className="px-2 py-1 border border-line-strong rounded bg-white" />
            </label>
            <label className="flex flex-col gap-0.5">Importe desde
              <input type="text" inputMode="decimal" value={impDesde} onChange={(e) => setImpDesde(e.target.value)}
                placeholder="0,00" className="px-2 py-1 border border-line-strong rounded bg-white w-[110px]" />
            </label>
            <label className="flex flex-col gap-0.5">Importe hasta
              <input type="text" inputMode="decimal" value={impHasta} onChange={(e) => setImpHasta(e.target.value)}
                placeholder="0,00" className="px-2 py-1 border border-line-strong rounded bg-white w-[110px]" />
            </label>
            <Button size="sm" variant="ghost" onClick={() => {
              setTipoChip(''); setCategorias(new Set()); setFDesde(''); setFHasta('');
              setImpDesde(''); setImpHasta(''); setEstado('');
            }}>Limpiar filtros</Button>
            <span className="mx-1 text-line-strong">|</span>
            <select className="px-2 py-1 border border-line-strong rounded bg-white"
              value="" onChange={(e) => {
                const f = (filtrosGuardados?.data ?? []).find((x) => String(x.id) === e.target.value);
                if (f) aplicarFiltroGuardado(f.filtros_json);
              }}>
              <option value="">Filtros guardados…</option>
              {(filtrosGuardados?.data ?? []).map((f) => <option key={f.id} value={f.id}>{f.nombre}</option>)}
            </select>
            <Button size="sm" variant="secondary" disabled={guardarFiltroMut.isPending}
              onClick={() => {
                const nombre = prompt('Nombre del filtro (ej: "Impuestos abril"):');
                if (nombre?.trim()) guardarFiltroMut.mutate(nombre.trim());
              }}>Guardar filtro actual</Button>
          </div>
        )}

        {meta && (
          <div className="flex flex-wrap gap-4 text-[11.5px] text-ink-2 px-1">
            <span><strong>{meta.total_movs ?? 0}</strong> movs en el filtro</span>
            <span>Débitos: <strong className="tabular text-danger">{fmtMoney(meta.total_debitos ?? 0)}</strong></span>
            <span>Créditos: <strong className="tabular text-success">{fmtMoney(meta.total_creditos ?? 0)}</strong></span>
            <span>Neto: <strong className="tabular">{fmtMoney(meta.total_neto ?? 0)}</strong></span>
            <button type="button" className="text-azure hover:underline inline-flex items-center gap-1"
              disabled={exportando} onClick={() => exportarExcel(false)}>
              <Download className="w-3 h-3" /> {exportando ? 'Exportando…' : 'Exportar filtro a Excel'}
            </button>
          </div>
        )}
      </div>

      {selected.size > 0 && (() => {
        const selMovs = (movs?.data ?? []).filter((m) => selected.has(m.id));
        const selDeb = selMovs.reduce((a, m) => a + Number(m.debito), 0);
        const selCred = selMovs.reduce((a, m) => a + Number(m.credito), 0);
        return (
        <div className="mb-3 p-3 bg-navy-50 border border-navy-200 rounded-md flex items-center gap-3 text-[12px] flex-wrap sticky top-0 z-10 shadow-sm">
          <span className="font-medium text-navy-800">{selected.size} mov{selected.size > 1 ? 's' : ''} seleccionado{selected.size > 1 ? 's' : ''}</span>
          <span className="text-[11.5px] text-ink-2">
            Débitos: <strong className="tabular">{fmtMoney(selDeb)}</strong> ·
            Créditos: <strong className="tabular">{fmtMoney(selCred)}</strong> ·
            Neto: <strong className="tabular">{fmtMoney(selCred - selDeb)}</strong>
          </span>
          <Button size="sm" variant="secondary" disabled={exportando} onClick={() => exportarExcel(true)}>
            <Download className="w-3 h-3" /> Exportar a Excel
          </Button>
          <Button size="sm" variant="primary" onClick={() => setBulkAccion('CONCILIAR_CONTRA_CUENTA')}>
            <Check className="w-3 h-3" /> Conciliar contra cuenta…
          </Button>
          <Button size="sm" variant="secondary" onClick={() => setBulkAccion('IGNORAR')}>
            <X className="w-3 h-3" /> Ignorar todos…
          </Button>
          {/* v1.27 §16 — Borrar bulk (solo super_admin) */}
          {puedeBorrarBulk && (
            <Button size="sm" variant="danger" onClick={() => setBorrarBulkOpen(true)}>
              <Trash2 className="w-3 h-3" /> Borrar {selected.size}
            </Button>
          )}
          <button className="ml-auto text-ink-muted hover:text-ink-2 text-[11px]" onClick={() => setSelected(new Set())}>
            Limpiar selección
          </button>
        </div>
        );
      })()}

      {/* v1.27 §16 — banner global "Confirmar auto-etiquetados" cuando hay ≥1 */}
      {autoEtiquetadosIds.length > 0 && (
        <div className="mb-3 p-3 bg-amber-50 border border-amber-200 rounded-md flex items-center gap-3 text-[12px]">
          <Zap className="w-3.5 h-3.5 text-amber-700" />
          <span className="font-medium text-amber-800">
            {autoEtiquetadosIds.length} movimiento{autoEtiquetadosIds.length === 1 ? '' : 's'} auto-etiquetado{autoEtiquetadosIds.length === 1 ? '' : 's'} con cuenta sugerida.
          </span>
          <Button size="sm" variant="primary"
            disabled={confirmarAutoMut.isPending}
            onClick={() => confirmarAutoMut.mutate(autoEtiquetadosIds)}>
            {confirmarAutoMut.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />}
            Confirmar {autoEtiquetadosIds.length} en bulk
          </Button>
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
                    {m.estado === 'CONFIRMADO_CHEQUES_COBRADOS' && <Badge variant="success">Cheques cobrados</Badge>}
                    {m.estado === 'CONFIRMADO_DESCUENTO_CHEQUE' && <Badge variant="success">Descuento vinculado</Badge>}
                    {m.estado === 'CONFIRMADO_RECIBO_DIRECTO' && <Badge variant="success">Recibo vinculado</Badge>}
                    {m.estado === 'CONFIRMADO_RECIBO_CON_CHEQUES' && <Badge variant="success">Recibo + cheques</Badge>}
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
                            <Link2 className="w-3 h-3" /> Vincular
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
                    {['CONFIRMADO_CHEQUES_COBRADOS', 'CONFIRMADO_DESCUENTO_CHEQUE', 'CONFIRMADO_RECIBO_DIRECTO', 'CONFIRMADO_RECIBO_CON_CHEQUES'].includes(m.estado) && (
                      <div className="flex items-center gap-1 justify-end">
                        {m.asiento_id && <span className="text-[10px] text-ink-muted">→ Asiento #{m.asiento_id}</span>}
                        {(m as { asiento_descuento_vinculado_id?: number | null }).asiento_descuento_vinculado_id && (
                          <span className="text-[10px] text-ink-muted">→ Asiento desc. #{(m as { asiento_descuento_vinculado_id?: number | null }).asiento_descuento_vinculado_id}</span>
                        )}
                        <Button size="sm" variant="ghost" title="Revierte la vinculación (los cheques vuelven a cartera / se desvincula el recibo o descuento)."
                          disabled={revertirV149Mut.isPending}
                          onClick={() => revertirV149(m.id)}>
                          <X className="w-3 h-3" /> Revertir
                        </Button>
                      </div>
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

      {/* v1.27 §16 — modal de borrado bulk */}
      <BorrarBulkModal
        open={borrarBulkOpen}
        ids={Array.from(selected)}
        movs={(movs?.data ?? []).filter((m) => selected.has(m.id))}
        onClose={() => setBorrarBulkOpen(false)}
        onSuccess={() => {
          setBorrarBulkOpen(false);
          setSelected(new Set());
          qc.invalidateQueries({ queryKey: ['mov-banc'] });
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

      {borrarImportOpen && (
        <BorrarImportModal
          cuentaBancariaId={cuentaBancariaId || ''}
          onClose={() => setBorrarImportOpen(false)}
          onSuccess={() => {
            qc.invalidateQueries({ queryKey: ['mov-banc'] });
            qc.invalidateQueries({ queryKey: ['cuentas-bancarias'] });
          }}
        />
      )}

      {rematchOpen && (
        <RematchModal
          cuentas={cuentasBanc?.data ?? []}
          onClose={() => setRematchOpen(false)}
          onSuccess={() => {
            qc.invalidateQueries({ queryKey: ['mov-banc'] });
            qc.invalidateQueries({ queryKey: ['cuentas-bancarias'] });
          }}
        />
      )}
    </>
  );
}

/**
 * v1.48 Anexo B Fix 3 — Re-aplicar reglas + detectar transferencias internas
 * retroactivas + emparejar espejos. Para movs cargados antes del v1.48.
 */
function RematchModal({ cuentas, onClose, onSuccess }: {
  cuentas: CuentaBancaria[]; onClose: () => void; onSuccess: () => void;
}) {
  const [cuentaId, setCuentaId] = useState<number | ''>('');
  const [res, setRes] = useState<{ reaplicadas: number; transferencias_detectadas: number; emparejadas: number; pendientes_transf: number } | null>(null);
  const [err, setErr] = useState('');

  const run = useMutation({
    mutationFn: () => api.post<{ data: typeof res }>('/api/erp/tesoreria/reaplicar-reglas', { cuenta_bancaria_id: cuentaId || null }),
    onSuccess: (r) => { setErr(''); setRes(r.data); onSuccess(); },
    onError: (e: ApiError) => setErr(e.message),
  });

  return (
    <Modal open onClose={onClose} title="Re-aplicar reglas y emparejar transferencias" size="md"
      footer={res ? (
        <Button variant="primary" onClick={onClose}>Cerrar</Button>
      ) : (
        <>
          <Button variant="secondary" onClick={onClose} disabled={run.isPending}>Cancelar</Button>
          <Button variant="primary" disabled={run.isPending} onClick={() => run.mutate()}>
            {run.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />} Ejecutar
          </Button>
        </>
      )}
    >
      <div className="space-y-3 text-[12px]">
        {err && <div className="p-2 bg-danger-bg text-danger rounded text-[11.5px]">{err}</div>}
        {!res ? (
          <>
            <p className="text-ink-2">
              Re-evalúa reglas sobre movs en <code>PENDIENTE</code> / <code>ETIQUETADO</code> (sin cuenta) / <code>MATCH_AUTO</code>,
              marca transferencias internas (CUIT propio) y empareja espejos. Los movs <code>CONCILIADO</code> / <code>IGNORADO</code> quedan intactos.
            </p>
            <div>
              <label className="block text-[11px] font-semibold text-ink-muted uppercase mb-1">Cuenta bancaria</label>
              <select value={cuentaId} onChange={(e) => setCuentaId(e.target.value ? Number(e.target.value) : '')}
                className="w-full px-2 py-1.5 border border-line-strong rounded bg-white">
                <option value="">Todas las cuentas</option>
                {cuentas.map((c) => <option key={c.id} value={c.id}>{c.codigo} — {c.nombre}</option>)}
              </select>
            </div>
          </>
        ) : (
          <div className="space-y-2">
            <div className="flex justify-between"><span>Movs re-evaluados:</span><strong>{res.reaplicadas}</strong></div>
            <div className="flex justify-between"><span>Transferencias internas detectadas:</span><strong>{res.transferencias_detectadas}</strong></div>
            <div className="flex justify-between"><span>Emparejadas con espejo:</span><strong className="text-success">{res.emparejadas}</strong></div>
            <div className="flex justify-between"><span>Pendientes de espejo:</span><strong>{res.pendientes_transf}</strong></div>
          </div>
        )}
      </div>
    </Modal>
  );
}

/**
 * v1.45.1 — Borrar un import de extracto bancario completo (super_admin).
 * Lista los imports de la cuenta, valida 409 si hay asientos / vínculos,
 * pide motivo opcional y registra audit log con snapshot.
 */
function BorrarImportModal({
  cuentaBancariaId,
  onClose,
  onSuccess,
}: {
  cuentaBancariaId: number | '';
  onClose: () => void;
  onSuccess: () => void;
}) {
  const qc = useQueryClient();
  const [cuentaSel, setCuentaSel] = useState<number | ''>(cuentaBancariaId);
  const [confirmar, setConfirmar] = useState<{ id: number; nombre: string } | null>(null);
  const [motivo, setMotivo] = useState('');
  const [err, setErr] = useState<string | null>(null);

  const { data: cuentas } = useQuery<{ data: { id: number; codigo: string; nombre: string }[] }>({
    queryKey: ['cuentas-bancarias'], queryFn: () => api.get('/api/erp/cuentas-bancarias'),
  });
  const qs = cuentaSel ? `?cuenta_id=${cuentaSel}` : '';
  const { data: extractos, isLoading } = useQuery<{ data: { data: Array<{ id: number; nombre_archivo: string; fecha_desde: string; fecha_hasta: string; cant_movimientos: number; importado_at: string }> } }>({
    queryKey: ['extractos-import', cuentaSel], queryFn: () => api.get(`/api/erp/extractos${qs}`),
  });

  const borrar = useMutation({
    mutationFn: (id: number) => api.delete(`/api/erp/extractos/${id}`, { motivo: motivo || undefined }),
    onSuccess: () => {
      setConfirmar(null); setMotivo(''); setErr(null);
      qc.invalidateQueries({ queryKey: ['extractos-import'] });
      onSuccess();
    },
    onError: (e: ApiError) => setErr(e.message),
  });

  const lista = extractos?.data?.data ?? [];

  return (
    <Modal open onClose={onClose} title="Borrar import de extracto bancario" size="lg">
      <div className="space-y-3 text-[12.5px]">
        <div className="bg-red-50 border border-red-200 rounded p-2 text-[11.5px] text-red-700">
          Acción irreversible (solo super_admin). Se bloquea si algún movimiento tiene asiento
          contable o está vinculado a eCheq / cobros / transferencias. Queda registrado en el audit log.
        </div>
        <label className="block">
          <span className="text-[11.5px] font-medium text-ink-muted">Cuenta bancaria</span>
          <select className="mt-1 w-full border border-line rounded-md px-2 py-1.5"
            value={cuentaSel} onChange={(e) => setCuentaSel(e.target.value ? Number(e.target.value) : '')}>
            <option value="">Todas</option>
            {(cuentas?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.codigo} {c.nombre}</option>)}
          </select>
        </label>

        {err && <div className="text-red-600 text-[12px]">{err}</div>}
        {isLoading && <div className="text-ink-3">Cargando imports…</div>}

        <div className="border border-line rounded-md overflow-hidden max-h-[360px] overflow-y-auto">
          <table className="w-full text-[12px]">
            <thead className="bg-surface-row sticky top-0"><tr className="text-left">
              <th className="px-2 py-1.5">Archivo</th><th className="px-2 py-1.5">Período</th>
              <th className="px-2 py-1.5 text-right">Movs</th><th className="px-2 py-1.5">Importado</th><th className="px-2 py-1.5"></th>
            </tr></thead>
            <tbody>
              {lista.map((e) => (
                <tr key={e.id} className="border-t border-line">
                  <td className="px-2 py-1">{e.nombre_archivo}</td>
                  <td className="px-2 py-1">{e.fecha_desde} a {e.fecha_hasta}</td>
                  <td className="px-2 py-1 text-right tabular-nums">{e.cant_movimientos}</td>
                  <td className="px-2 py-1 text-ink-3">{String(e.importado_at).slice(0, 16)}</td>
                  <td className="px-2 py-1 text-right">
                    <Button variant="danger" onClick={() => { setConfirmar({ id: e.id, nombre: e.nombre_archivo }); setErr(null); }}>
                      <Trash2 className="w-3 h-3" /> Borrar
                    </Button>
                  </td>
                </tr>
              ))}
              {!isLoading && lista.length === 0 && (
                <tr><td colSpan={5} className="px-2 py-4 text-center text-ink-3">Sin imports.</td></tr>
              )}
            </tbody>
          </table>
        </div>

        {confirmar && (
          <div className="border border-red-300 rounded-md p-3 bg-red-50 space-y-2">
            <div className="font-medium text-red-700">Confirmar borrado de "{confirmar.nombre}"</div>
            <textarea className="w-full border border-line rounded-md px-2 py-1.5" rows={2}
              placeholder="Motivo (opcional)" value={motivo} onChange={(e) => setMotivo(e.target.value)} />
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setConfirmar(null)}>Cancelar</Button>
              <Button variant="danger" disabled={borrar.isPending} onClick={() => borrar.mutate(confirmar.id)}>
                {borrar.isPending ? 'Borrando…' : 'Borrar definitivamente'}
              </Button>
            </div>
          </div>
        )}
      </div>
    </Modal>
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

  // v1.48 Anexo B Fix 4 — precargar la cuenta propuesta por la regla aplicada
  // (si el mov está auto-etiquetado). El operador puede cambiarla. No rompe el
  // principio de las reglas: etiquetar = saber la cuenta.
  useEffect(() => {
    if (mov?.cuenta_contable_propuesta_id) setCuentaId(mov.cuenta_contable_propuesta_id);
    else setCuentaId('');
    setCcId(''); setAuxId(''); setGlosa('');
  }, [mov?.id]);

  // ⚠ la regla propuso una cuenta que no está en el listado de imputables.
  const propuestaHuerfana = !!mov?.cuenta_contable_propuesta_id
    && !!cuentasResp?.data
    && !cuentasResp.data.some((c) => c.id === mov.cuenta_contable_propuesta_id);

  const conciliar = useMutation({
    mutationFn: () =>
      api.post(`/api/erp/movimientos-bancarios/${mov!.id}/conciliar`, {
        referencia_tipo: 'ASIENTO_MANUAL',
        cuenta_contable_contraparte_id: cuentaId,
        centro_costo_id: ccId || null,
        auxiliar_id: auxId || null,
        glosa: glosa || null,
      }),
    onSuccess,
    onError: (e) => onError(e instanceof ApiError ? e.message : 'Error'),
  });

  if (!mov) return null;

  // v1.48 Anexo A — si la cuenta admite auxiliar, es obligatorio.
  const faltaAux = !!cuentaSeleccionada?.admite_auxiliar && !auxId;

  return (
    <Modal
      open={!!mov}
      onClose={onClose}
      title={`Conciliar movimiento #${mov.id}`}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!cuentaId || faltaAux || conciliar.isPending} onClick={() => conciliar.mutate()}>
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
          {mov.cuenta_contable_propuesta_id && cuentaId === mov.cuenta_contable_propuesta_id && (
            <div className="text-[11px] text-success mt-1">✓ Cuenta precargada por la regla aplicada (podés cambiarla)</div>
          )}
          {propuestaHuerfana && (
            <div className="text-[11px] text-danger mt-1">⚠️ La regla aplicada propone una cuenta no imputable. Reportar a administrador.</div>
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
    // v1.49 — auto-vinculación de descuentos de cheque.
    descuentos_vinculados?: number;
    descuentos_ambiguos?: number;
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
              {/* v1.55 Bloque B — solo cuentas con parser registrado (fuera Galicia). */}
              {cuentas.filter((c) => c.parser_soportado !== false).map((c) => (
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
            {(resultado.descuentos_vinculados ?? 0) > 0 && (
              <Stat label="Auto-vinculados a descuentos de cheques" value={resultado.descuentos_vinculados!} accent="success" />
            )}
            {(resultado.descuentos_ambiguos ?? 0) > 0 && (
              <Stat label="Descuentos ambiguos (revisar manualmente)" value={resultado.descuentos_ambiguos!} accent="warning" />
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
  // v1.47.2 — multi-select: claves "tipo-factura_id".
  const [seleccionadas, setSeleccionadas] = useState<Set<string>>(new Set());
  const [manualOpen, setManualOpen] = useState(false);
  const [permitirDif, setPermitirDif] = useState(false);
  const [motivoDif, setMotivoDif] = useState('');
  const [cuentaAjuste, setCuentaAjuste] = useState('');
  // v1.48 Bloque D — motivo del catálogo (auto-completa cuenta de ajuste).
  const [motivoDifId, setMotivoDifId] = useState('');

  const { data: sugerencias, isLoading } = useQuery<{ data: SugerenciasResp }>({
    queryKey: ['sugerencias', mov?.id],
    queryFn: () => api.get(`/api/erp/movimientos-bancarios/${mov!.id}/sugerencias?top=10`),
    enabled: !!mov,
  });
  // v1.49 — candidatos de cheques / descuentos / recibos (solo créditos).
  const esCredito = !!mov && Number(mov.credito) > 0.005;
  const { data: candResp } = useQuery<{ data: CandidatosV149 }>({
    queryKey: ['candidatos-v149', mov?.id],
    queryFn: () => api.get(`/api/erp/movimientos-bancarios/${mov!.id}/candidatos`),
    enabled: esCredito,
  });
  const descuentos = candResp?.data?.descuentos_cheque ?? [];
  const chequesCand = candResp?.data?.cheques_sueltos ?? [];
  const recibosCand = candResp?.data?.recibos ?? [];
  const [selCheques, setSelCheques] = useState<Set<number>>(new Set());
  const [selDescuento, setSelDescuento] = useState<number | null>(null);
  const [selRecibo, setSelRecibo] = useState<number | null>(null);
  const [obsCheques, setObsCheques] = useState('');
  // v1.50 D-50-2 — casos especiales colapsados por defecto.
  const [especialesOpen, setEspecialesOpen] = useState(false);

  const chequesSel = chequesCand.filter((c) => selCheques.has(c.cheque_id));
  const sumCheques = Math.round(chequesSel.reduce((a, c) => a + Number(c.importe), 0) * 100) / 100;

  const vincularDescuentoMut = useMutation({
    mutationFn: (asientoId: number) =>
      api.post(`/api/erp/movimientos-bancarios/${mov!.id}/vincular-asiento-descuento`, { asiento_id: asientoId }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['mov-banc'] }); onSuccess(); },
    onError: (e: ApiError) => onError(e.message),
  });
  const conciliarChequesMut = useMutation({
    mutationFn: () =>
      api.post(`/api/erp/movimientos-bancarios/${mov!.id}/conciliar-cheques`, {
        cheques: chequesSel.map((c) => ({ cheque_id: c.cheque_id, monto: Number(c.importe) })),
        observaciones: obsCheques || undefined,
      }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['mov-banc'] }); onSuccess(); },
    onError: (e: ApiError) => onError(e.message),
  });
  const vincularReciboMut = useMutation({
    mutationFn: (r: ReciboCandidato) =>
      api.post(`/api/erp/movimientos-bancarios/${mov!.id}/vincular-recibo-directo`, {
        recibos: [{ recibo_id: r.recibo_id, monto: Number(r.monto_cobrado) }],
      }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['mov-banc'] }); onSuccess(); },
    onError: (e: ApiError) => onError(e.message),
  });
  const { data: cuentasResp } = useQuery<{ data: Array<{ id: number; codigo: string; nombre: string }> }>({
    queryKey: ['cuentas-imputables'], queryFn: () => api.get('/api/erp/cuentas?imputable=1'), enabled: !!mov,
  });
  const { data: motivosResp } = useQuery<{ data: MotivoDif[] }>({
    queryKey: ['conciliacion-motivos'], queryFn: () => api.get('/api/erp/conciliacion/motivos'), enabled: !!mov,
  });
  // v1.48 Anexo A — anticipos pendientes del auxiliar detectado.
  const auxDetectadoId = sugerencias?.data?.contraparte?.id ?? null;
  const { data: antResp } = useQuery<{ data: Anticipo[]; meta: { total: number } }>({
    queryKey: ['anticipos-pendientes', auxDetectadoId],
    queryFn: () => api.get(`/api/erp/auxiliares/${auxDetectadoId}/anticipos-pendientes`),
    enabled: !!auxDetectadoId,
  });
  const anticipos = antResp?.data ?? [];
  const totalAnt = Math.round(anticipos.reduce((a, x) => a + Number(x.monto), 0) * 100) / 100;
  const [descontarAnt, setDescontarAnt] = useState(true);

  const montoMov = mov ? Math.max(Number(mov.debito), Number(mov.credito)) : 0;
  const resp = sugerencias?.data;
  const lista = resp?.sugerencias ?? [];
  const keyOf = (s: Sugerencia) => `${s.tipo}-${s.factura_id}`;
  const selObjs = lista.filter((s) => seleccionadas.has(keyOf(s)));
  const totalSel = selObjs.reduce((a, s) => a + Number(s.saldo_pendiente), 0);
  const diff = Math.round((montoMov - totalSel) * 100) / 100;
  const exacto = Math.abs(diff) <= 1;
  // v1.48 Anexo A — match dual: si se descuentan anticipos, el banco solo debe
  // cubrir (facturas − anticipos). diffConAnt = diff + totalAnt.
  const usaAnt = descontarAnt && totalAnt > 0.01 && selObjs.length > 0;
  const diffConAnt = Math.round((diff + totalAnt) * 100) / 100;
  const diffEf = usaAnt ? diffConAnt : diff;
  const exactoEf = Math.abs(diffEf) <= 1;

  // Con motivo del catálogo la cuenta queda resuelta en backend (salvo OTRO,
  // que requiere elegir cuenta manual). Sin catálogo, motivo libre ≥10 chars.
  const motivoSel = (motivosResp?.data ?? []).find((m) => String(m.id) === motivoDifId);
  const difOk = permitirDif && (
    motivoSel
      ? (!!motivoSel.cuenta_ajuste_id || !!cuentaAjuste)
      : (motivoDif.trim().length >= 10 && !!cuentaAjuste)
  );
  // Con anticipos cuadrando no hace falta motivo/cuenta de ajuste manual.
  const puedeConfirmar = selObjs.length > 0 && (exactoEf || (usaAnt && exactoEf) || difOk);

  const conciliarMut = useMutation({
    mutationFn: () =>
      api.post(`/api/erp/movimientos-bancarios/${mov!.id}/conciliar-multiple`, {
        facturas: selObjs.map((s) => ({
          id: s.factura_id, tipo: s.tipo === 'FACTURA_VENTA' ? 'VENTA' : 'COMPRA',
          monto_imputado: Number(s.saldo_pendiente),
        })),
        // Si los anticipos cubren la diferencia, el backend rutea el ajuste a
        // 1.1.5.01 — no se envía motivo/cuenta manual.
        motivo: !usaAnt && permitirDif && !exacto ? (motivoSel?.nombre ?? motivoDif) : null,
        permitir_diferencia: !usaAnt && permitirDif && !exacto,
        cuenta_ajuste_id: !usaAnt && permitirDif && !exacto && cuentaAjuste ? Number(cuentaAjuste) : null,
        motivo_diferencia_id: !usaAnt && permitirDif && !exacto && motivoDifId ? Number(motivoDifId) : null,
        anticipos_a_cancelar: usaAnt ? anticipos.map((a) => ({ mov_id: a.mov_id, monto: Number(a.monto) })) : [],
      }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['mov-banc'] }); onSuccess(); },
    onError: (e: ApiError) => onError(e.message),
  });

  if (!mov) return null;

  const toggle = (s: Sugerencia) => {
    if (s.cuit_no_coincide === true) return; // Flujo A no permite CUIT distinto.
    const k = keyOf(s);
    const n = new Set(seleccionadas);
    n.has(k) ? n.delete(k) : n.add(k);
    setSeleccionadas(n);
  };

  return (
    <Modal open onClose={onClose} title={`Vincular movimiento #${mov.id} · $${fmtMoney(montoMov)}`} size="lg">
      <div className="space-y-3 text-[12px]">
        <div className="text-ink-muted">
          {mov.concepto} · {mov.fecha.slice(0, 10)} · tipo: <code>{mov.tipo_operativo}</code>
        </div>

        {/* ══ v1.50 §5.2 — Sección principal: RECIBOS candidatos ══ */}
        {recibosCand.length > 0 && (
          <div className="border-2 border-navy-800/30 rounded p-2 space-y-2 bg-white">
            <div className="font-semibold text-[12px] text-navy-800">🧾 Recibos candidatos</div>
            {recibosCand.map((r) => {
              const chs = r.cheques ?? [];
              const autoConf = chs.filter((c) => c.se_auto_confirma);
              const resueltos = chs.filter((c) => !c.se_auto_confirma && c.estado_actual !== 'EN_CARTERA' && c.estado_actual !== 'VENCIDO_NO_COBRADO');
              return (
                <div key={r.recibo_id}
                  className={`rounded border p-2 ${selRecibo === r.recibo_id ? 'border-azure bg-azure-soft/20' : 'border-line'}`}>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="v150-rec" checked={selRecibo === r.recibo_id}
                      onChange={() => setSelRecibo(r.recibo_id)} />
                    <span className="flex-1">
                      <strong>RC {r.numero_recibo}</strong> · {r.cliente_nombre ?? '—'} · cobro {r.fecha_cobro.slice(0, 10)}
                      <span className="text-ink-muted"> · Medio: {r.medio_cobro}{chs.length === 0 ? ' · Sin cheques' : ''}</span>
                      {r.match_score === 100 && <span className="ml-1 text-[10px] px-1 rounded bg-success-bg/60 text-success">✓ match</span>}
                    </span>
                    <span className="tabular font-semibold">{fmtMoney(r.monto_cobrado)}</span>
                  </label>
                  {chs.length > 0 && (
                    <div className="ml-6 mt-1 space-y-0.5 text-[11px] text-ink-2">
                      {chs.map((c) => (
                        <div key={c.cheque_id} className="flex justify-between">
                          <span>└─ #{c.numero_cheque} · {c.banco_emisor} · vto {c.fecha_pago.slice(0, 10)} ·{' '}
                            <span className={c.se_auto_confirma ? 'text-azure' : 'text-ink-muted'}>{c.estado_actual}</span>
                            {c.ya_vinculado_otro_mov && <span className="text-danger"> · ya vinculado a otro mov</span>}
                          </span>
                          <span className="tabular">{fmtMoney(c.importe)}</span>
                        </div>
                      ))}
                      {autoConf.length > 0 && (
                        <div className="text-warning text-[10.5px] pt-0.5">
                          ⚠️ Al vincular, {autoConf.length} cheque{autoConf.length === 1 ? ' se auto-confirma' : 's se auto-confirman'} como COBRADO con fecha del movimiento.
                        </div>
                      )}
                      {resueltos.length > 0 && (
                        <div className="text-ink-muted text-[10.5px]">
                          ⓘ {resueltos.length} cheque{resueltos.length === 1 ? '' : 's'} ya resuelto{resueltos.length === 1 ? '' : 's'} por otro camino — no se modifican.
                        </div>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
            <Button size="sm" variant="primary"
              disabled={!selRecibo || vincularReciboMut.isPending}
              onClick={() => {
                const r = recibosCand.find((x) => x.recibo_id === selRecibo);
                if (r) vincularReciboMut.mutate(r);
              }}>
              {vincularReciboMut.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />}
              Vincular recibo (sin duplicar asiento)
            </Button>
          </div>
        )}

        {/* ── v1.50 — Cheques sueltos (no asociados a los recibos de arriba) ── */}
        {chequesCand.length > 0 && (
          <div className="border border-azure/40 bg-azure-soft/15 rounded p-2 space-y-1.5">
            <div className="font-semibold text-[11.5px]">
              📥 Cheques sueltos pendientes de acreditación <span className="font-normal text-ink-muted">(genera asiento D banco / H Valores al Cobro)</span>
            </div>
            <div className="max-h-[160px] overflow-y-auto space-y-1">
              {chequesCand.map((c) => (
                <label key={c.cheque_id} className="flex items-center gap-2 cursor-pointer text-[11.5px]">
                  <input type="checkbox" checked={selCheques.has(c.cheque_id)}
                    onChange={() => {
                      const n = new Set(selCheques);
                      n.has(c.cheque_id) ? n.delete(c.cheque_id) : n.add(c.cheque_id);
                      setSelCheques(n);
                    }} />
                  <span className="flex-1">
                    Cheque #{c.numero_cheque} · {c.banco_emisor}
                    {c.cliente_nombre ? ` · ${c.cliente_nombre}` : ''} · vto {c.fecha_vencimiento.slice(0, 10)}
                    {c.estado_actual === 'COBRADO' && <span className="ml-1 text-[10px] px-1 rounded bg-success-bg text-success">ya cobrado</span>}
                  </span>
                  <span className="tabular font-semibold">{fmtMoney(c.importe)}</span>
                </label>
              ))}
            </div>
            {selCheques.size > 0 && (
              <>
                <div className={`flex justify-between text-[11.5px] font-semibold border-t border-azure/30 pt-1 ${Math.abs(sumCheques - montoMov) <= 1 ? 'text-success' : 'text-danger'}`}>
                  <span>Suma: {fmtMoney(sumCheques)}</span>
                  <span>Diferencia: {fmtMoney(Math.round((montoMov - sumCheques) * 100) / 100)}</span>
                </div>
                <input type="text" placeholder="Observaciones (opcional)" value={obsCheques}
                  onChange={(e) => setObsCheques(e.target.value)}
                  className="w-full px-2 py-1 border border-line rounded text-[11.5px]" />
              </>
            )}
            <Button size="sm" variant="primary"
              disabled={selCheques.size === 0 || Math.abs(sumCheques - montoMov) > 1 || conciliarChequesMut.isPending}
              onClick={() => conciliarChequesMut.mutate()}>
              {conciliarChequesMut.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />}
              Confirmar cobro de cheques ({selCheques.size})
            </Button>
          </div>
        )}

        {/* ══ v1.50 §5.2 — Casos especiales, colapsados por defecto ══ */}
        <button type="button" onClick={() => setEspecialesOpen(!especialesOpen)}
          className="text-[11.5px] text-azure hover:underline">
          {especialesOpen ? '− Ocultar' : '+ Ver'} otros tipos de vinculación (descuentos, facturas, anticipos)
        </button>

        <div className={especialesOpen ? 'space-y-3' : 'hidden'}>
        {/* §15 — Banner de matching de CUIT */}
        {!isLoading && resp && (
          <>
            {resp.contraparte && !resp.motivo_fallback && (
              <div className="border border-success/30 bg-success-bg/20 rounded p-2 text-[11.5px]">
                ✓ <strong>CUIT detectado: {resp.cuit_detectado}</strong> — {resp.contraparte.nombre} ({resp.contraparte.tipo})
              </div>
            )}
            {resp.motivo_fallback === 'CONTRAPARTE_SIN_FACTURAS_PENDIENTES' && resp.contraparte && (
              <div className="border border-warning/30 bg-warning-bg/20 rounded p-2 text-[11.5px]">
                ⓘ CUIT {resp.cuit_detectado} ({resp.contraparte.nombre}) no tiene facturas pendientes.
                Cargá la factura primero o usá "Conciliar" general para asiento directo.
              </div>
            )}
            {resp.motivo_fallback === 'CUIT_NO_REGISTRADO' && (
              <div className="border border-warning/30 bg-warning-bg/20 rounded p-2 text-[11.5px]">
                ⚠ CUIT {resp.cuit_detectado} no está registrado en Auxiliares.
                Mostrando sugerencias por monto (verificá manualmente la coincidencia).
              </div>
            )}
            {resp.motivo_fallback === 'CUIT_NO_DETECTADO_EN_CONCEPTO' && (
              <div className="border border-warning/30 bg-warning-bg/20 rounded p-2 text-[11.5px]">
                ⚠ No se detectó CUIT en el concepto. Mostrando sugerencias por monto solamente.
              </div>
            )}
          </>
        )}

        {/* ── v1.49 §7 — Sección 1: descuento de cheque detectado ────────── */}
        {descuentos.length > 0 && (
          <div className="border border-success/40 bg-success-bg/15 rounded p-2 space-y-1.5">
            <div className="font-semibold text-[11.5px]">
              🎯 Match — Descuento de cheque detectado <span className="font-normal text-ink-muted">(el asiento ya existe, vincular sin duplicar)</span>
            </div>
            {descuentos.map((d) => (
              <label key={d.asiento_id} className="flex items-center gap-2 cursor-pointer text-[11.5px]">
                <input type="radio" name="v149-desc" checked={selDescuento === d.asiento_id}
                  onChange={() => setSelDescuento(d.asiento_id)} />
                <span className="flex-1">
                  Asiento #{d.asiento_id} · {d.fecha.slice(0, 10)} · cheque #{d.numero_cheque}
                  {d.entidad_descuento ? ` · ${d.entidad_descuento}` : ''}
                  {d.cliente_nombre ? ` · ${d.cliente_nombre}` : ''}
                </span>
                <span className="tabular font-semibold">neto {fmtMoney(d.neto)}</span>
              </label>
            ))}
            <div className="pt-1">
              <Button size="sm" variant="primary"
                disabled={!selDescuento || vincularDescuentoMut.isPending}
                onClick={() => selDescuento && vincularDescuentoMut.mutate(selDescuento)}>
                {vincularDescuentoMut.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />}
                Vincular sin duplicar asiento
              </Button>
            </div>
          </div>
        )}

        {isLoading && <div className="text-ink-muted">Buscando sugerencias…</div>}
        {!isLoading && lista.length === 0 && descuentos.length === 0 && chequesCand.length === 0 && recibosCand.length === 0 && (
          <div className="border border-warning/30 bg-warning-bg/20 rounded p-2 text-[11.5px]">
            No se encontraron facturas con saldo pendiente cerca de este monto.
            Probá la opción "Conciliar" general (referencia ASIENTO_MANUAL) para elegir cuenta manualmente.
          </div>
        )}

        <div className="space-y-1 max-h-[300px] overflow-y-auto">
          {lista.map((s) => (
            <label key={`${s.tipo}-${s.factura_id}`}
              className={`flex items-start gap-2 p-2 rounded border transition ${
                s.cuit_no_coincide === true
                  ? 'border-line opacity-50 cursor-not-allowed'
                  : seleccionadas.has(`${s.tipo}-${s.factura_id}`)
                    ? 'border-azure bg-azure-soft/30 cursor-pointer'
                    : 'border-line hover:bg-surface-hover cursor-pointer'
              }`}>
              <input type="checkbox"
                disabled={s.cuit_no_coincide === true}
                title={s.cuit_no_coincide === true ? 'CUIT distinto — usá "Conciliar manualmente con motivo"' : undefined}
                checked={seleccionadas.has(`${s.tipo}-${s.factura_id}`)}
                onChange={() => toggle(s)} />
              <div className="flex-1">
                <div className="font-medium text-ink-2 flex items-center gap-2">
                  {s.tipo_codigo} {s.letra ?? ''} {s.numero}
                  <span className="text-[10px] px-1 rounded bg-line">
                    {s.tipo === 'FACTURA_VENTA' ? 'VENTA' : 'COMPRA'}
                  </span>
                  {/* §15 — badge de match de CUIT */}
                  {s.cuit_coincide === true && (
                    <span className="text-[10px] px-1.5 py-0.5 rounded bg-success-bg/40 text-success font-medium">
                      ✓ CUIT coincide
                    </span>
                  )}
                  {s.cuit_no_coincide === true && (
                    <span className="text-[10px] px-1.5 py-0.5 rounded bg-danger-bg/40 text-danger font-semibold">
                      ⚠ CUIT NO COINCIDE
                    </span>
                  )}
                  {s.cuit_coincide === false && s.cuit_no_coincide !== true && (
                    <span className="text-[10px] px-1.5 py-0.5 rounded bg-line text-ink-muted">
                      sin CUIT de referencia
                    </span>
                  )}
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

        {/* v1.48 Anexo A — anticipos pendientes del auxiliar */}
        {totalAnt > 0.01 && (
          <div className="border border-azure/40 bg-azure/10 rounded p-2 text-[11.5px] space-y-1">
            <div className="font-semibold">⚠️ Este auxiliar tiene ANTICIPOS pendientes:</div>
            {anticipos.map((a) => (
              <div key={a.mov_id} className="flex justify-between text-ink-muted">
                <span>• {a.fecha.slice(0, 10)} — ref mov #{a.mov_id}</span>
                <span className="tabular">{fmtMoney(Number(a.monto))}</span>
              </div>
            ))}
            <div className="flex justify-between font-semibold border-t border-azure/30 pt-1">
              <span>Total anticipos:</span><span className="tabular">{fmtMoney(totalAnt)}</span>
            </div>
            <label className="flex items-center gap-2 cursor-pointer pt-1">
              <input type="checkbox" checked={descontarAnt} onChange={(e) => setDescontarAnt(e.target.checked)} />
              <span>Descontar anticipos al imputar (recomendado)</span>
            </label>
          </div>
        )}

        {selObjs.length > 0 && (
          <div className="border-t border-line pt-3 space-y-2 text-[11.5px]">
            <div className="flex justify-between"><span>Total seleccionado ({selObjs.length} factura{selObjs.length === 1 ? '' : 's'}):</span><span className="tabular font-semibold">{fmtMoney(totalSel)}</span></div>
            {usaAnt && <div className="flex justify-between text-azure"><span>− Anticipos a descontar:</span><span className="tabular">{fmtMoney(totalAnt)}</span></div>}
            <div className="flex justify-between"><span>Movimiento (banco):</span><span className="tabular">{fmtMoney(montoMov)}</span></div>
            <div className={`flex justify-between font-semibold ${exactoEf ? 'text-success' : 'text-danger'}`}>
              <span>Diferencia{usaAnt ? ' (con anticipo)' : ''}:</span><span className="tabular">{fmtMoney(diffEf)}</span>
            </div>
            {/* v1.55 Bloque F — diferencias ≤$1 balancean solas contra 5.6.06. */}
            {exactoEf && !usaAnt && Math.abs(diffEf) > 0.01 && (
              <div className="text-[10.5px] text-ink-muted">
                ⓘ Se genera una línea automática de redondeo (5.6.06) por la diferencia.
              </div>
            )}
            {!exactoEf && (
              <div className="border border-warning/40 bg-warning-bg/20 rounded p-2 space-y-2">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input type="checkbox" checked={permitirDif} onChange={(e) => setPermitirDif(e.target.checked)} />
                  <span>Permitir diferencia (genera línea de ajuste)</span>
                </label>
                {permitirDif && (
                  <>
                    <select value={motivoDifId}
                      onChange={(e) => {
                        setMotivoDifId(e.target.value);
                        const m = (motivosResp?.data ?? []).find((x) => String(x.id) === e.target.value);
                        if (m?.cuenta_ajuste_id) setCuentaAjuste(String(m.cuenta_ajuste_id));
                        else if (m) setCuentaAjuste('');
                      }}
                      className="w-full px-2 py-1 border border-line rounded">
                      <option value="">Motivo del catálogo…</option>
                      {(motivosResp?.data ?? []).map((m) => (
                        <option key={m.id} value={m.id}>
                          {m.nombre}{m.cuenta_codigo ? ` → ${m.cuenta_codigo}` : ' (elegir cuenta)'}
                        </option>
                      ))}
                    </select>
                    {/* Cuenta de ajuste: auto-resuelta por el motivo, editable para OTRO. */}
                    {(!motivoSel || !motivoSel.cuenta_ajuste_id) && (
                      <select value={cuentaAjuste} onChange={(e) => setCuentaAjuste(e.target.value)}
                        className="w-full px-2 py-1 border border-line rounded">
                        <option value="">Cuenta de ajuste…</option>
                        {(cuentasResp?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.codigo} {c.nombre}</option>)}
                      </select>
                    )}
                    {!motivoDifId && (
                      <input type="text" placeholder="…o motivo libre (mín 10 chars)" value={motivoDif}
                        onChange={(e) => setMotivoDif(e.target.value)}
                        className="w-full px-2 py-1 border border-line rounded" />
                    )}
                    {motivoSel?.tipo === 'ANTICIPO_PROVEEDOR' && (
                      <div className="text-[10.5px] text-warning">
                        ⓘ Quedará como <strong>pendiente de facturar</strong> (el distribuidor debe emitir NC).
                      </div>
                    )}
                  </>
                )}
              </div>
            )}
          </div>
        )}

        </div>{/* fin casos especiales colapsables */}

        {/* v1.50 §5.2 sección 4 — vinculación manual, siempre visible */}
        <div className="border-t border-line pt-2">
          <button type="button"
            onClick={() => setManualOpen(true)}
            className="text-[11.5px] text-azure hover:underline">
            ↪ Vincular manualmente con motivo (elegir cuenta libre / factura de otro auxiliar)
          </button>
        </div>

        <div className="flex justify-end gap-2 pt-2 border-t border-line">
          <Button variant="secondary" onClick={onClose} disabled={conciliarMut.isPending}>
            Cancelar
          </Button>
          <Button variant="primary"
            disabled={!puedeConfirmar || conciliarMut.isPending}
            title={!especialesOpen && !puedeConfirmar ? 'Para conciliar contra facturas, abrí "otros tipos de vinculación".' : ''}
            onClick={() => conciliarMut.mutate()}>
            {conciliarMut.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />}
            Confirmar conciliación (facturas)
          </Button>
        </div>
      </div>

      {/* §15.5 — modal de conciliación manual con motivo */}
      <ConciliarManualModal
        mov={mov}
        open={manualOpen}
        onClose={() => setManualOpen(false)}
        onSuccess={() => { setManualOpen(false); onSuccess(); }}
        onError={onError}
      />
    </Modal>
  );
}

// v1.27 §15.5 — Modal de conciliación manual con motivo obligatorio.
function ConciliarManualModal({ mov, open, onClose, onSuccess, onError }: {
  mov: MovimientoBancario | null;
  open: boolean;
  onClose: () => void;
  onSuccess: () => void;
  onError: (msg: string) => void;
}) {
  const [tipoDestino, setTipoDestino] = useState<'VENTA' | 'COMPRA'>('COMPRA');
  const [auxiliarQ, setAuxiliarQ] = useState('');
  const [auxiliar, setAuxiliar] = useState<{ id: number; nombre: string; cuit: string | null } | null>(null);
  // v1.47.2 — multi-select: N facturas, pueden ser de auxiliares (y tipos)
  // distintos; la selección sobrevive al cambio de auxiliar. Monto editable
  // por fila para imputación parcial.
  const [seleccionadas, setSeleccionadas] = useState<Array<{
    id: number; tipo: 'VENTA' | 'COMPRA'; etiqueta: string; auxiliar: string; monto: string;
  }>>([]);
  const [motivo, setMotivo] = useState('');
  const [permitirDif, setPermitirDif] = useState(false);
  const [cuentaAjuste, setCuentaAjuste] = useState('');

  const montoMov = mov ? Math.max(Number(mov.debito), Number(mov.credito)) : 0;
  const totalSel = Math.round(seleccionadas.reduce((a, s) => a + (Number(s.monto) || 0), 0) * 100) / 100;
  const diff = Math.round((montoMov - totalSel) * 100) / 100;
  // v1.55 Bloque F — hasta $1 el backend balancea solo con una línea
  // automática contra 5.6.06 Redondeos; más de $1 exige toggle + cuenta.
  const exacto = seleccionadas.length > 0 && Math.abs(diff) <= 1;
  const esRedondeoAuto = exacto && Math.abs(diff) > 0.01;

  // Inferir tipo destino del tipo_operativo del movimiento.
  // Solo se ejecuta cuando se abre el modal.
  useMemo(() => {
    if (!mov || !open) return;
    if (Number(mov.debito) > 0) setTipoDestino('COMPRA');
    else if (Number(mov.credito) > 0) setTipoDestino('VENTA');
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, mov?.id]);

  const { data: auxRes } = useQuery<{ data: Array<{ id: number; nombre: string; cuit: string | null; codigo: string }> }>({
    queryKey: ['buscar-aux', tipoDestino, auxiliarQ],
    queryFn: () => api.get(`/api/erp/movimientos-bancarios/buscar-auxiliar?tipo=${tipoDestino === 'COMPRA' ? 'Proveedor' : 'Cliente'}&q=${encodeURIComponent(auxiliarQ)}`),
    enabled: open && auxiliarQ.length >= 2,
  });

  const { data: facturasRes } = useQuery<{ data: Array<{ id: number; numero: number; imp_total: number; fecha_emision: string; tipo_codigo: string; letra: string | null }> }>({
    queryKey: ['facturas-pend', auxiliar?.id, tipoDestino],
    queryFn: () => api.get(`/api/erp/movimientos-bancarios/facturas-pendientes?auxiliar_id=${auxiliar!.id}&tipo=${tipoDestino}`),
    enabled: !!auxiliar?.id,
  });

  // v1.47.2 — cuentas imputables para la línea de ajuste por diferencia.
  const { data: cuentasResp } = useQuery<{ data: Array<{ id: number; codigo: string; nombre: string }> }>({
    queryKey: ['cuentas-imputables'], queryFn: () => api.get('/api/erp/cuentas?imputable=1'),
    enabled: open,
  });

  const submitMut = useMutation({
    mutationFn: () =>
      api.post(`/api/erp/movimientos-bancarios/${mov!.id}/conciliar-multiple`, {
        facturas: seleccionadas.map((s) => ({ id: s.id, tipo: s.tipo, monto_imputado: Number(s.monto) })),
        motivo: motivo.trim(),
        permitir_diferencia: !exacto && permitirDif,
        cuenta_ajuste_id: !exacto && permitirDif && cuentaAjuste ? Number(cuentaAjuste) : null,
      }),
    onSuccess: () => {
      // Reset.
      setAuxiliarQ(''); setAuxiliar(null); setSeleccionadas([]);
      setMotivo(''); setPermitirDif(false); setCuentaAjuste('');
      onSuccess();
    },
    onError: (e: ApiError) => onError(e.message),
  });

  if (!mov) return null;

  const toggleFactura = (f: { id: number; numero: number; imp_total: number; tipo_codigo: string; letra: string | null }) => {
    setSeleccionadas((prev) => {
      const i = prev.findIndex((s) => s.tipo === tipoDestino && s.id === f.id);
      if (i >= 0) return prev.filter((_, j) => j !== i);
      return [...prev, {
        id: f.id, tipo: tipoDestino,
        etiqueta: `${f.tipo_codigo} ${f.letra ?? ''} ${f.numero}`.replace(/\s+/g, ' '),
        auxiliar: auxiliar?.nombre ?? '—',
        monto: String(f.imp_total),
      }];
    });
  };
  const montosOk = seleccionadas.every((s) => Number(s.monto) > 0);
  const valid = seleccionadas.length > 0 && montosOk && motivo.trim().length >= 10
    && (exacto || (permitirDif && !!cuentaAjuste));

  return (
    <Modal open={open} onClose={onClose} title={`📝 Conciliar manualmente · Mov #${mov.id} · $${fmtMoney(montoMov)}`} size="lg">
      <div className="space-y-3 text-[12px]">
        <div className="border border-warning/30 bg-warning-bg/20 rounded p-2 text-[11px]">
          Esta conciliación queda marcada como <strong>MANUAL</strong> con tu motivo en el audit log.
          Usá esto solo si la sugerencia automática no encontró match correcto.
        </div>

        <div>
          <div className="text-[11px] text-ink-muted mb-1">Tipo de destino</div>
          <div className="flex gap-3">
            <label className="flex items-center gap-1.5 cursor-pointer">
              <input type="radio" checked={tipoDestino === 'VENTA'} onChange={() => { setTipoDestino('VENTA'); setAuxiliar(null); }} />
              <span>Factura de venta</span>
            </label>
            <label className="flex items-center gap-1.5 cursor-pointer">
              <input type="radio" checked={tipoDestino === 'COMPRA'} onChange={() => { setTipoDestino('COMPRA'); setAuxiliar(null); }} />
              <span>Factura de compra</span>
            </label>
          </div>
        </div>

        <div>
          <div className="text-[11px] text-ink-muted mb-1">
            {tipoDestino === 'COMPRA' ? 'Proveedor *' : 'Cliente *'}
          </div>
          <input type="text" value={auxiliar ? `${auxiliar.nombre} (${auxiliar.cuit ?? 'sin CUIT'})` : auxiliarQ}
            onChange={(e) => { setAuxiliar(null); setAuxiliarQ(e.target.value); }}
            placeholder="Buscar por nombre o CUIT (mín 2 chars)..."
            className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
          {!auxiliar && auxiliarQ.length >= 2 && (auxRes?.data ?? []).length > 0 && (
            <div className="border border-line rounded mt-1 max-h-[150px] overflow-y-auto">
              {(auxRes?.data ?? []).map((a) => (
                <button key={a.id} type="button"
                  onClick={() => { setAuxiliar({ id: a.id, nombre: a.nombre, cuit: a.cuit }); setAuxiliarQ(''); }}
                  className="w-full text-left p-1.5 hover:bg-surface-hover border-b border-line/40 last:border-b-0">
                  <div className="text-[11.5px] text-ink-2">{a.nombre}</div>
                  <div className="text-[10px] text-ink-muted font-mono">{a.cuit ?? 'sin CUIT'} · {a.codigo}</div>
                </button>
              ))}
            </div>
          )}
        </div>

        {auxiliar && (
          <div>
            <div className="text-[11px] text-ink-muted mb-1">Factura pendiente *</div>
            {(facturasRes?.data ?? []).length === 0 ? (
              <div className="text-[11px] text-ink-muted italic">
                Sin facturas pendientes para este {tipoDestino === 'COMPRA' ? 'proveedor' : 'cliente'}.
              </div>
            ) : (
              <div className="border border-line rounded max-h-[180px] overflow-y-auto">
                {(facturasRes?.data ?? []).map((f) => {
                  const sel = seleccionadas.some((s) => s.tipo === tipoDestino && s.id === f.id);
                  return (
                    <label key={f.id}
                      className={`flex items-center gap-2 p-1.5 border-b border-line/40 last:border-b-0 cursor-pointer ${
                        sel ? 'bg-azure-soft/30' : 'hover:bg-surface-hover'
                      }`}>
                      <input type="checkbox" checked={sel} onChange={() => toggleFactura(f)} />
                      <div className="flex-1">
                        <div className="text-[11.5px] text-ink-2">
                          {f.tipo_codigo} {f.letra ?? ''} {f.numero} · {f.fecha_emision?.slice(0, 10)}
                        </div>
                      </div>
                      <div className="font-semibold tabular text-[11.5px]">{fmtMoney(f.imp_total)}</div>
                    </label>
                  );
                })}
              </div>
            )}
          </div>
        )}

        {/* v1.47.2 — seleccionadas con monto editable + sumatoria/diferencia */}
        {seleccionadas.length > 0 && (
          <div className="border-t border-line pt-2 space-y-2">
            <div className="text-[11px] text-ink-muted">
              Facturas seleccionadas (el monto es editable para imputación parcial)
            </div>
            {seleccionadas.map((s, i) => (
              <div key={`${s.tipo}-${s.id}`} className="flex items-center gap-2">
                <button type="button" title="Quitar"
                  onClick={() => setSeleccionadas(seleccionadas.filter((_, j) => j !== i))}
                  className="text-danger text-[11px] px-1 hover:font-bold">✕</button>
                <div className="flex-1 text-[11.5px] text-ink-2">
                  {s.etiqueta}
                  <span className="text-[10px] px-1 rounded bg-line ml-1.5">{s.tipo}</span>
                  <span className="text-ink-muted ml-1.5">{s.auxiliar}</span>
                </div>
                <input type="number" step="0.01" value={s.monto}
                  onChange={(e) => setSeleccionadas(seleccionadas.map((x, j) => j === i ? { ...x, monto: e.target.value } : x))}
                  className="w-[130px] px-2 py-0.5 text-[11.5px] text-right tabular border border-azure-soft rounded focus:outline-none focus:border-azure" />
              </div>
            ))}
            <div className="text-[11.5px] space-y-1 border-t border-line/60 pt-1.5">
              <div className="flex justify-between">
                <span>Total seleccionado ({seleccionadas.length} factura{seleccionadas.length === 1 ? '' : 's'}):</span>
                <span className="tabular font-semibold">{fmtMoney(totalSel)}</span>
              </div>
              <div className="flex justify-between"><span>Movimiento (banco):</span><span className="tabular">{fmtMoney(montoMov)}</span></div>
              <div className={`flex justify-between font-semibold ${exacto ? 'text-success' : 'text-danger'}`}>
                <span>Diferencia:</span><span className="tabular">{fmtMoney(diff)}</span>
              </div>
              {esRedondeoAuto && (
                <div className="text-[10.5px] text-ink-muted">
                  ⓘ Se genera una línea automática de redondeo (5.6.06) por la diferencia.
                </div>
              )}
            </div>
            {!exacto && (
              <div className="border border-warning/40 bg-warning-bg/20 rounded p-2 space-y-2">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input type="checkbox" checked={permitirDif} onChange={(e) => setPermitirDif(e.target.checked)} />
                  <span>Permitir diferencia (genera línea de ajuste)</span>
                </label>
                {permitirDif && (
                  <select value={cuentaAjuste} onChange={(e) => setCuentaAjuste(e.target.value)}
                    className="w-full px-2 py-1 border border-line rounded">
                    <option value="">Cuenta de ajuste…</option>
                    {(cuentasResp?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.codigo} {c.nombre}</option>)}
                  </select>
                )}
              </div>
            )}
          </div>
        )}

        <div>
          <div className="text-[11px] text-ink-muted mb-1">
            Motivo * <span className="text-[10px]">(mínimo 10 caracteres, queda en audit log)</span>
          </div>
          <textarea rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)}
            maxLength={500}
            placeholder="Ej: Pago de servicios extra acordado verbalmente, imputar a FC C 42 de Ruefli..."
            className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
          <div className="text-[10px] text-ink-muted mt-0.5">
            {motivo.length} / 500 — {motivo.trim().length < 10 ? `faltan ${10 - motivo.trim().length}` : '✓'}
          </div>
        </div>

        <div className="flex justify-end gap-2 pt-2 border-t border-line">
          <Button variant="secondary" onClick={onClose} disabled={submitMut.isPending}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || submitMut.isPending}
            onClick={() => submitMut.mutate()}>
            {submitMut.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />}
            Confirmar conciliación manual
          </Button>
        </div>
      </div>
    </Modal>
  );
}



// v1.27 §16 — Modal de borrado bulk de movimientos.
function BorrarBulkModal({ open, ids, movs, onClose, onSuccess, onError }: {
  open: boolean;
  ids: number[];
  movs: MovimientoBancario[];
  onClose: () => void;
  onSuccess: () => void;
  onError: (msg: string) => void;
}) {
  const [motivo, setMotivo] = useState("");
  const totalDebito = movs.reduce((a, m) => a + Number(m.debito || 0), 0);
  const totalCredito = movs.reduce((a, m) => a + Number(m.credito || 0), 0);
  const conciliados = movs.filter((m) => m.estado === "CONCILIADO");
  const tieneConciliados = conciliados.length > 0;

  const mut = useMutation({
    mutationFn: () =>
      api.delete(`/api/erp/movimientos-bancarios/bulk`, {
        ids,
        motivo: motivo.trim() || undefined,
      }),
    onSuccess: () => { setMotivo(""); onSuccess(); },
    onError: (e: ApiError) => onError(e.message),
  });

  if (!open) return null;

  return (
    <Modal open={open} onClose={onClose} title={`🗑️ Borrar ${ids.length} movimiento${ids.length === 1 ? "" : "s"}`} size="lg">
      <div className="space-y-3 text-[12px]">
        <div className="text-ink-muted">
          Vas a borrar <strong>{ids.length}</strong> movimientos definitivamente.
        </div>
        <dl className="grid grid-cols-[120px_1fr] gap-y-1 gap-x-2 text-[11px] bg-azure-soft/30 rounded p-2">
          <dt className="text-ink-muted">Total débito</dt>
          <dd className="font-semibold tabular text-danger">${fmtMoney(totalDebito)}</dd>
          <dt className="text-ink-muted">Total crédito</dt>
          <dd className="font-semibold tabular text-success">${fmtMoney(totalCredito)}</dd>
        </dl>

        <div className="max-h-[180px] overflow-y-auto border border-line rounded text-[11px]">
          {movs.slice(0, 30).map((m) => (
            <div key={m.id} className="flex items-center justify-between p-1.5 border-b border-line/40 last:border-b-0">
              <div className="flex-1 truncate">
                <span className="text-ink-muted">{m.fecha.slice(0, 10)}</span>{" · "}
                <span className="text-ink-2">{m.concepto}</span>
              </div>
              <div className="tabular font-semibold ml-2">
                {Number(m.debito) > 0 && <span className="text-danger">-${fmtMoney(Number(m.debito))}</span>}
                {Number(m.credito) > 0 && <span className="text-success">+${fmtMoney(Number(m.credito))}</span>}
              </div>
            </div>
          ))}
          {movs.length > 30 && <div className="text-ink-muted italic p-1.5">… y {movs.length - 30} más</div>}
        </div>

        {tieneConciliados && (
          <div className="border border-danger/40 bg-danger-bg/30 rounded p-2 text-[11.5px]">
            ⚠ <strong>{conciliados.length} movimiento(s) están CONCILIADOS</strong> — no se pueden borrar.
            Desconciliá primero o desmarcalos antes de confirmar.
          </div>
        )}

        <div className="bg-red-50 border border-red-200 rounded p-2 text-[11px]">
          ⚠ <strong>Acción IRREVERSIBLE.</strong> Los movimientos se eliminan físicamente.
          Audit log queda con snapshot completo. Si querés re-cargar el archivo, ahora podés.
        </div>

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">Motivo (opcional)</label>
          <textarea rows={2} value={motivo} onChange={(e) => setMotivo(e.target.value)}
            maxLength={500}
            placeholder="Ej: Limpieza de tests del 2026-05-21"
            className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
        </div>

        <div className="flex justify-end gap-2 pt-2 border-t border-line">
          <Button variant="secondary" onClick={onClose} disabled={mut.isPending}>Cancelar</Button>
          <Button variant="danger" disabled={mut.isPending || tieneConciliados}
            onClick={() => mut.mutate()}>
            <Trash2 className="w-3 h-3" />
            Borrar {ids.length} {ids.length === 1 ? "movimiento" : "movimientos"}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
