import { useState } from 'react';
import { ShieldCheck, Upload, Loader2, Check, Download, Trash2, AlertTriangle, X } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

type Analisis = {
  analisis_ok: boolean; nombre_archivo: string; error?: string; mensaje?: string;
  aseguradora?: string; cuit_aseguradora?: string; fecha_emision?: string | null;
  poliza?: string | null; comprobante_ref?: string | null;
  tipo_comprobante_id?: number; tipo_label?: string; es_baja?: boolean;
  punto_venta?: number; numero?: number;
  imp_neto_gravado_21?: number; imp_iva_21?: number; imp_percepciones_iva?: number;
  imp_otros_tributos?: number; imp_total?: number; control_cuadra?: boolean;
  contenido_hash?: string; duplicado?: boolean; duplicado_id?: number | null;
  crudos?: Record<string, number>;
};
type Fila = Analisis & { _pv: string; _numero: string };
type Comprobante = {
  id: number; aseguradora: string; cuit_aseguradora: string; fecha_emision: string;
  poliza: string | null; tipo_comprobante: number; punto_venta: number; numero: number;
  imp_total: string; nombre_archivo: string | null;
  periodo_anio: number | null; periodo_mes: number | null;
};

const MESES = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

function descargarTxt(nombre: string, contenido: string) {
  const blob = new Blob([contenido], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = nombre; a.click();
  URL.revokeObjectURL(url);
}

export default function ProcesamientoSeguroPage() {
  const qc = useQueryClient();
  const [err, setErr] = useState('');
  const [filas, setFilas] = useState<Fila[]>([]);
  const [sel, setSel] = useState<Set<number>>(new Set());
  const [periodo, setPeriodo] = useState('');        // 'YYYY-MM' para la carga
  const [filtroPeriodo, setFiltroPeriodo] = useState(''); // filtro del listado / emisión TXT
  const [filtroAseguradora, setFiltroAseguradora] = useState(''); // filtro por aseguradora
  const [txtListo, setTxtListo] = useState<{ cbte: string; alicuotas: string; cant: number; suf: string } | null>(null);

  const { data: lista } = useQuery<{ data: Comprobante[] }>({
    queryKey: ['seguros-comprobantes'],
    queryFn: () => api.get('/api/erp/compras/seguros'),
  });
  const comprobantes = lista?.data ?? [];
  const periodosDisponibles = Array.from(new Set(
    comprobantes.filter((c) => c.periodo_anio && c.periodo_mes)
      .map((c) => `${c.periodo_anio}-${String(c.periodo_mes).padStart(2, '0')}`),
  )).sort().reverse();
  const aseguradorasDisponibles = Array.from(new Set(comprobantes.map((c) => c.aseguradora))).sort();
  const comprobantesFiltrados = comprobantes.filter((c) =>
    (!filtroPeriodo || `${c.periodo_anio}-${String(c.periodo_mes).padStart(2, '0')}` === filtroPeriodo)
    && (!filtroAseguradora || c.aseguradora === filtroAseguradora));

  const analizar = useMutation({
    mutationFn: (files: File[]) => {
      const fd = new FormData();
      files.forEach((f) => fd.append('archivos[]', f));
      return api.post<{ data: Analisis[] }>('/api/erp/compras/seguros/analizar', fd);
    },
    onSuccess: (r) => {
      setErr('');
      setFilas((prev) => [
        ...prev,
        ...r.data.map((a) => ({ ...a, _pv: a.punto_venta ? String(a.punto_venta) : '', _numero: a.numero ? String(a.numero) : '' })),
      ]);
    },
    onError: (e: ApiError) => setErr(e.message),
  });

  const cargar = useMutation({
    mutationFn: (items: object[]) => api.post<{ data: { ok: boolean; nombre_archivo: string; error?: string }[] }>(
      '/api/erp/compras/seguros/cargar', { items }),
    onSuccess: (r) => {
      const fallidos = r.data.filter((x) => !x.ok);
      setErr(fallidos.length ? `No se cargaron ${fallidos.length}: ${fallidos.map((f) => `${f.nombre_archivo} (${f.error})`).join(', ')}` : '');
      // dejar en la tabla solo los que fallaron
      const fallidosNombres = new Set(fallidos.map((f) => f.nombre_archivo));
      setFilas((prev) => prev.filter((f) => fallidosNombres.has(f.nombre_archivo) && f.analisis_ok));
      qc.invalidateQueries({ queryKey: ['seguros-comprobantes'] });
    },
    onError: (e: ApiError) => setErr(e.message),
  });

  const borrar = useMutation({
    mutationFn: (id: number) => api.delete(`/api/erp/compras/seguros/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['seguros-comprobantes'] }),
    onError: (e: ApiError) => setErr(e.message),
  });

  const emitir = useMutation({
    mutationFn: (body: { ids: number[]; periodo_anio?: number; periodo_mes?: number }) =>
      api.post<{ data: { cbte: string; alicuotas: string; cant: number } }>('/api/erp/compras/seguros/txt', body),
    onSuccess: (r) => {
      if (!r.data.cant) { setErr('No hay comprobantes para emitir con ese filtro.'); return; }
      setErr('');
      setTxtListo({ ...r.data, suf: filtroPeriodo ? `_${filtroPeriodo}` : '' });
    },
    onError: (e: ApiError) => setErr(e.message),
  });

  const setFila = (idx: number, patch: Partial<Fila>) =>
    setFilas((prev) => prev.map((f, i) => (i === idx ? { ...f, ...patch } : f)));
  const quitarFila = (idx: number) => setFilas((prev) => prev.filter((_, i) => i !== idx));

  const pAnio = periodo ? Number(periodo.slice(0, 4)) : 0;
  const pMes = periodo ? Number(periodo.slice(5, 7)) : 0;
  const listas = filas.filter((f) => f.analisis_ok && !f.duplicado && f._pv !== '' && f._numero !== '');
  const cargarTodos = () => cargar.mutate(listas.map((f) => ({
    aseguradora: f.aseguradora, cuit_aseguradora: f.cuit_aseguradora,
    fecha_emision: f.fecha_emision, periodo_anio: pAnio, periodo_mes: pMes,
    punto_venta: Number(f._pv), numero: Number(f._numero), tipo_comprobante_id: f.tipo_comprobante_id,
    poliza: f.poliza, comprobante_ref: f.comprobante_ref, contenido_hash: f.contenido_hash,
    nombre_archivo: f.nombre_archivo, imp_neto_gravado_21: f.imp_neto_gravado_21, imp_iva_21: f.imp_iva_21,
    imp_percepciones_iva: f.imp_percepciones_iva, imp_otros_tributos: f.imp_otros_tributos,
    imp_total: f.imp_total, crudos: f.crudos ?? null,
  })));

  const toggle = (id: number) => { const n = new Set(sel); n.has(id) ? n.delete(id) : n.add(id); setSel(n); };

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-[18px] font-semibold text-navy-800 flex items-center gap-2">
          <ShieldCheck className="w-5 h-5" /> Procesamiento de Seguro
        </h1>
        <p className="text-[12px] text-ink-2">Módulo autónomo: cargá los PDFs de seguros (no impacta el resto del ERP) y generá el TXT del Libro IVA Digital para importar a AFIP.</p>
      </div>

      {err && <div className="mb-4 p-3 bg-danger-bg text-danger rounded-md text-[12px]">{err}</div>}

      {txtListo && (
        <Card className="mb-4 border-success/40">
          <CardHeader title={`TXT generado (${txtListo.cant} comprobante${txtListo.cant === 1 ? '' : 's'})`} actions={
            <Button variant="ghost" size="sm" onClick={() => setTxtListo(null)}><X className="w-3 h-3" /></Button>
          } />
          <CardBody>
            <p className="text-[12px] text-ink-2 mb-3">Descargá los dos archivos para importar al Libro IVA Digital de AFIP:</p>
            <div className="flex gap-2">
              <Button variant="secondary" size="sm" onClick={() => descargarTxt(`LIBRO_IVA_SEGUROS_CBTE${txtListo.suf}.txt`, txtListo.cbte)}>
                <Download className="w-3 h-3" /> Descargar CBTE.txt
              </Button>
              <Button variant="secondary" size="sm" onClick={() => descargarTxt(`LIBRO_IVA_SEGUROS_ALICUOTAS${txtListo.suf}.txt`, txtListo.alicuotas)}>
                <Download className="w-3 h-3" /> Descargar ALICUOTAS.txt
              </Button>
            </div>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader title="1 · Subir PDFs de pólizas" />
        <CardBody>
          <label className="inline-flex items-center gap-2 cursor-pointer">
            <input type="file" accept="application/pdf" multiple className="hidden"
              onChange={(e) => { const fs = Array.from(e.target.files ?? []); if (fs.length) analizar.mutate(fs); e.currentTarget.value = ''; }} />
            <span className="px-3 py-1.5 bg-navy-700 text-white rounded text-[12px] inline-flex items-center gap-1">
              {analizar.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Upload className="w-3 h-3" />} Elegir PDFs (uno o varios)
            </span>
          </label>
          <span className="text-[11px] text-ink-2 ml-2">Aseguradoras soportadas: La Segunda, San Cristóbal, Mapfre.</span>
        </CardBody>
      </Card>

      {filas.length > 0 && (
        <Card className="mt-4">
          <CardHeader title={`2 · Revisar (${filas.length})`} actions={
            <div className="flex gap-2 items-center text-[12px]">
              <label className="flex items-center gap-1">Período imputación:
                <input type="month" value={periodo} onChange={(e) => setPeriodo(e.target.value)}
                  className="px-2 py-1 border border-line-strong rounded bg-white" />
              </label>
              <Button variant="secondary" size="sm" onClick={() => setFilas([])}>Limpiar</Button>
              <Button variant="primary" size="sm" disabled={!listas.length || !periodo || cargar.isPending} onClick={cargarTodos}>
                {cargar.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />} Cargar {listas.length}/{filas.length}
              </Button>
            </div>
          } />
          <CardBody className="overflow-x-auto">
            <table className="w-full text-[11.5px]">
              <thead><tr className="text-left text-[10px] uppercase text-ink-2 border-b border-line">
                <th className="py-1">Archivo</th><th>Aseguradora</th><th>Fecha</th><th>Tipo</th>
                <th>PV</th><th>Número</th><th className="text-right">Neto 21%</th><th className="text-right">IVA 21%</th>
                <th className="text-right">Perc.IVA</th><th className="text-right">Otros trib.</th><th className="text-right">Total</th><th></th>
              </tr></thead>
              <tbody>
                {filas.map((f, idx) => f.analisis_ok ? (
                  <tr key={idx} className={f.duplicado ? 'bg-warning-bg/20' : idx % 2 ? 'bg-surface-row' : ''}>
                    <td className="py-1 max-w-[150px] truncate" title={f.nombre_archivo}>
                      {f.duplicado && <AlertTriangle className="w-3 h-3 text-warning inline mr-1" />}{f.nombre_archivo}
                    </td>
                    <td className="max-w-[140px] truncate" title={f.aseguradora}>{f.aseguradora}</td>
                    <td>{f.fecha_emision?.slice(0, 10)}</td>
                    <td><span className={f.es_baja ? 'text-danger' : 'text-success'}>{String(f.tipo_comprobante_id).padStart(3, '0')}</span></td>
                    <td><input type="number" value={f._pv} onChange={(e) => setFila(idx, { _pv: e.target.value })} className="w-14 px-1 py-0.5 border border-line rounded" /></td>
                    <td><input type="number" value={f._numero} onChange={(e) => setFila(idx, { _numero: e.target.value })} className="w-20 px-1 py-0.5 border border-line rounded" /></td>
                    {(['imp_neto_gravado_21', 'imp_iva_21', 'imp_percepciones_iva', 'imp_otros_tributos', 'imp_total'] as const).map((k) => (
                      <td key={k} className="text-right"><input type="number" step="0.01" value={Number(f[k])} onChange={(e) => setFila(idx, { [k]: Number(e.target.value) } as Partial<Fila>)} className="w-24 px-1 py-0.5 border border-line rounded text-right tabular" /></td>
                    ))}
                    <td><Button variant="ghost" size="sm" onClick={() => quitarFila(idx)}><X className="w-3 h-3" /></Button></td>
                  </tr>
                ) : (
                  <tr key={idx} className="bg-danger-bg/20">
                    <td className="py-1 max-w-[150px] truncate" title={f.nombre_archivo}>{f.nombre_archivo}</td>
                    <td colSpan={10} className="text-danger text-[11px]">No se pudo analizar: {f.mensaje}</td>
                    <td><Button variant="ghost" size="sm" onClick={() => quitarFila(idx)}><X className="w-3 h-3" /></Button></td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="text-[10.5px] text-ink-2 mt-2">Solo se cargan las filas con PV y Número completos y que no sean duplicados. Las amarillas ya están cargadas (duplicado).</p>
          </CardBody>
        </Card>
      )}

      <Card className="mt-4">
        <CardHeader title={`Comprobantes cargados (${comprobantesFiltrados.length}${comprobantesFiltrados.length !== comprobantes.length ? ` de ${comprobantes.length}` : ''})`} actions={
          <div className="flex gap-2 items-center text-[12px]">
            <label className="flex items-center gap-1">Aseguradora:
              <select value={filtroAseguradora} onChange={(e) => setFiltroAseguradora(e.target.value)}
                className="px-2 py-1 border border-line-strong rounded bg-white max-w-[220px]">
                <option value="">Todas</option>
                {aseguradorasDisponibles.map((a) => <option key={a} value={a}>{a}</option>)}
              </select>
            </label>
            <label className="flex items-center gap-1">Período:
              <select value={filtroPeriodo} onChange={(e) => setFiltroPeriodo(e.target.value)}
                className="px-2 py-1 border border-line-strong rounded bg-white">
                <option value="">Todos</option>
                {periodosDisponibles.map((p) => <option key={p} value={p}>{MESES[Number(p.slice(5, 7))]} {p.slice(0, 4)}</option>)}
              </select>
            </label>
            <Button variant="primary" size="sm" disabled={!comprobantes.length || emitir.isPending}
              onClick={() => emitir.mutate({
                ids: sel.size ? Array.from(sel) : [],
                periodo_anio: filtroPeriodo ? Number(filtroPeriodo.slice(0, 4)) : undefined,
                periodo_mes: filtroPeriodo ? Number(filtroPeriodo.slice(5, 7)) : undefined,
              })}>
              {emitir.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Download className="w-3 h-3" />}
              Emitir TXT {sel.size ? `(${sel.size} sel.)` : filtroPeriodo ? '(período)' : '(todos)'}
            </Button>
          </div>
        } />
        <CardBody>
          {comprobantesFiltrados.length === 0 ? (
            <div className="text-[12px] text-ink-2 py-4 text-center">No hay comprobantes cargados{filtroPeriodo ? ' en ese período' : ''} todavía.</div>
          ) : (
            <table className="w-full text-[12px]">
              <thead><tr className="text-left text-[11px] uppercase text-ink-2 border-b border-line">
                <th className="py-1 w-6"></th><th>Período</th><th>Fecha cbte</th><th>Aseguradora</th><th>CUIT</th><th>Tipo</th><th>PV-Nro</th><th className="text-right">Total</th><th></th>
              </tr></thead>
              <tbody>
                {comprobantesFiltrados.map((c, i) => (
                  <tr key={c.id} className={i % 2 ? 'bg-surface-row' : ''}>
                    <td className="py-1"><input type="checkbox" checked={sel.has(c.id)} onChange={() => toggle(c.id)} /></td>
                    <td>{c.periodo_mes ? `${MESES[c.periodo_mes]} ${c.periodo_anio}` : '—'}</td>
                    <td>{c.fecha_emision?.slice(0, 10)}</td>
                    <td className="max-w-[200px] truncate" title={c.aseguradora}>{c.aseguradora}</td>
                    <td className="tabular">{c.cuit_aseguradora}</td>
                    <td><span className={c.tipo_comprobante === 90 ? 'text-danger' : 'text-success'}>{String(c.tipo_comprobante).padStart(3, '0')}</span></td>
                    <td className="tabular">{c.punto_venta}-{c.numero}</td>
                    <td className="text-right tabular">{fmtMoney(Number(c.imp_total))}</td>
                    <td className="text-right">
                      <Button variant="ghost" size="sm" onClick={() => { if (confirm('¿Borrar este comprobante del módulo?')) borrar.mutate(c.id); }}>
                        <Trash2 className="w-3 h-3" />
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
