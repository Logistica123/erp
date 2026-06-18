import { useState } from 'react';
import { ShieldCheck, Upload, Loader2, Check, Download, Trash2, AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

type Analisis = {
  aseguradora: string; cuit_aseguradora: string; fecha_emision: string | null;
  poliza: string | null; comprobante_ref: string | null;
  tipo_comprobante_id: number; tipo_label: string; es_baja: boolean;
  imp_neto_gravado_21: number; imp_iva_21: number; imp_percepciones_iva: number;
  imp_otros_tributos: number; imp_total: number; control_cuadra: boolean;
  contenido_hash: string; nombre_archivo?: string; duplicado: boolean; duplicado_id: number | null;
  crudos?: Record<string, number>;
};
type Comprobante = {
  id: number; aseguradora: string; cuit_aseguradora: string; fecha_emision: string;
  poliza: string | null; tipo_comprobante: number; punto_venta: number; numero: number;
  imp_total: string; nombre_archivo: string | null;
};

function descargarTxt(nombre: string, contenido: string) {
  const blob = new Blob([contenido], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = nombre; a.click();
  URL.revokeObjectURL(url);
}

export default function ProcesamientoSeguroPage() {
  const qc = useQueryClient();
  const [err, setErr] = useState('');
  const [form, setForm] = useState<Analisis | null>(null);
  const [pv, setPv] = useState('');
  const [numero, setNumero] = useState('');
  const [fechaImput, setFechaImput] = useState('');
  const [sel, setSel] = useState<Set<number>>(new Set());

  const { data: lista } = useQuery<{ data: Comprobante[] }>({
    queryKey: ['seguros-comprobantes'],
    queryFn: () => api.get('/api/erp/compras/seguros'),
  });
  const comprobantes = lista?.data ?? [];

  const analizar = useMutation({
    mutationFn: (file: File) => {
      const fd = new FormData(); fd.append('archivo', file);
      return api.post<{ data: Analisis }>('/api/erp/compras/seguros/analizar', fd);
    },
    onSuccess: (r) => { setErr(''); setForm(r.data); setFechaImput(r.data.fecha_emision ?? ''); setPv(''); setNumero(''); },
    onError: (e: ApiError) => { setErr(e.message); setForm(null); },
  });

  const cargar = useMutation({
    mutationFn: () => api.post('/api/erp/compras/seguros/cargar', {
      aseguradora: form!.aseguradora, cuit_aseguradora: form!.cuit_aseguradora,
      fecha_emision: form!.fecha_emision, fecha_imputacion: fechaImput || form!.fecha_emision,
      punto_venta: Number(pv), numero: Number(numero), tipo_comprobante_id: form!.tipo_comprobante_id,
      poliza: form!.poliza, comprobante_ref: form!.comprobante_ref,
      contenido_hash: form!.contenido_hash, nombre_archivo: form!.nombre_archivo,
      imp_neto_gravado_21: form!.imp_neto_gravado_21, imp_iva_21: form!.imp_iva_21,
      imp_percepciones_iva: form!.imp_percepciones_iva, imp_otros_tributos: form!.imp_otros_tributos,
      imp_total: form!.imp_total, crudos: form!.crudos ?? null,
    }),
    onSuccess: () => { setErr(''); setForm(null); qc.invalidateQueries({ queryKey: ['seguros-comprobantes'] }); },
    onError: (e: ApiError) => setErr(e.message),
  });

  const borrar = useMutation({
    mutationFn: (id: number) => api.delete(`/api/erp/compras/seguros/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['seguros-comprobantes'] }),
    onError: (e: ApiError) => setErr(e.message),
  });

  const emitir = useMutation({
    mutationFn: (ids: number[]) => api.post<{ data: { cbte: string; alicuotas: string; cant: number } }>('/api/erp/compras/seguros/txt', { ids }),
    onSuccess: (r) => {
      descargarTxt('LIBRO_IVA_SEGUROS_CBTE.txt', r.data.cbte);
      descargarTxt('LIBRO_IVA_SEGUROS_ALICUOTAS.txt', r.data.alicuotas);
    },
    onError: (e: ApiError) => setErr(e.message),
  });

  const upd = (k: keyof Analisis, v: number) => setForm((f) => (f ? { ...f, [k]: v } : f));
  const puedeCargar = !!form && !form.duplicado && !!pv && !!numero && !!form.fecha_emision;
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

      <Card>
        <CardHeader title="1 · Subir PDF de póliza" />
        <CardBody>
          <label className="inline-flex items-center gap-2 cursor-pointer">
            <input type="file" accept="application/pdf" className="hidden"
              onChange={(e) => { const f = e.target.files?.[0]; if (f) analizar.mutate(f); e.currentTarget.value = ''; }} />
            <span className="px-3 py-1.5 bg-navy-700 text-white rounded text-[12px] inline-flex items-center gap-1">
              {analizar.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Upload className="w-3 h-3" />} Elegir PDF
            </span>
          </label>
          <span className="text-[11px] text-ink-2 ml-2">Aseguradora soportada: La Segunda.</span>
        </CardBody>
      </Card>

      {form && (
        <Card className="mt-4">
          <CardHeader title="2 · Revisar y cargar" />
          <CardBody>
            {form.duplicado && (
              <div className="mb-3 p-2 bg-warning-bg/30 border border-warning/40 rounded text-[11.5px] flex items-center gap-2">
                <AlertTriangle className="w-4 h-4 text-warning" /> Este PDF ya fue cargado (comprobante #{form.duplicado_id}). No se va a duplicar.
              </div>
            )}
            <div className="grid grid-cols-2 gap-3 text-[12px] mb-3">
              <Campo label="Aseguradora"><span className="font-semibold">{form.aseguradora}</span></Campo>
              <Campo label="CUIT">{form.cuit_aseguradora}</Campo>
              <Campo label="Fecha emisión">{form.fecha_emision ?? '—'}</Campo>
              <Campo label="Tipo comprobante"><span className={form.es_baja ? 'text-danger' : 'text-success'}>{form.tipo_label}</span></Campo>
              <Campo label="Póliza">{form.poliza ?? '—'} · ref {form.comprobante_ref ?? '—'}</Campo>
              <Campo label="Fecha imputación"><input type="date" value={fechaImput} onChange={(e) => setFechaImput(e.target.value)} className="px-2 py-1 border border-line-strong rounded w-full" /></Campo>
              <Campo label="Punto de venta (lo definís vos)"><input type="number" value={pv} onChange={(e) => setPv(e.target.value)} placeholder="ej. 1" className="px-2 py-1 border border-line-strong rounded w-full" /></Campo>
              <Campo label="Número (lo definís vos)"><input type="number" value={numero} onChange={(e) => setNumero(e.target.value)} placeholder="ej. 63" className="px-2 py-1 border border-line-strong rounded w-full" /></Campo>
            </div>
            <table className="w-full text-[12px] mb-2">
              <thead><tr className="text-left text-[11px] uppercase text-ink-2 border-b border-line"><th className="py-1">Concepto (Libro IVA)</th><th className="text-right">Importe</th></tr></thead>
              <tbody>
                {([['Neto gravado 21%', 'imp_neto_gravado_21'], ['IVA 21%', 'imp_iva_21'], ['Percepción IVA', 'imp_percepciones_iva'], ['Otros tributos', 'imp_otros_tributos'], ['TOTAL', 'imp_total']] as [string, keyof Analisis][]).map(([lbl, key]) => (
                  <tr key={key} className={key === 'imp_total' ? 'border-t border-line font-semibold' : ''}>
                    <td className="py-1">{lbl}</td>
                    <td className="text-right"><input type="number" step="0.01" value={Number(form[key])} onChange={(e) => upd(key, Number(e.target.value))} className="px-2 py-1 border border-line rounded text-right tabular w-40" /></td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className={`text-[11px] ${form.control_cuadra ? 'text-success' : 'text-warning'}`}>{form.control_cuadra ? '✓ El desglose cuadra con el total.' : '⚠ El desglose no cuadra con el total.'}</div>
            <div className="flex justify-end gap-2 pt-3 border-t border-line mt-3">
              <Button variant="secondary" onClick={() => setForm(null)}>Cancelar</Button>
              <Button variant="primary" disabled={!puedeCargar || cargar.isPending} onClick={() => cargar.mutate()}>
                {cargar.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />} Cargar al módulo
              </Button>
            </div>
          </CardBody>
        </Card>
      )}

      <Card className="mt-4">
        <CardHeader title={`Comprobantes cargados (${comprobantes.length})`} actions={
          <div className="flex gap-2">
            <Button variant="primary" size="sm" disabled={!comprobantes.length || emitir.isPending}
              onClick={() => emitir.mutate(sel.size ? Array.from(sel) : [])}>
              {emitir.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Download className="w-3 h-3" />}
              Emitir TXT {sel.size ? `(${sel.size} sel.)` : '(todos)'}
            </Button>
          </div>
        } />
        <CardBody>
          {comprobantes.length === 0 ? (
            <div className="text-[12px] text-ink-2 py-4 text-center">No hay comprobantes cargados todavía.</div>
          ) : (
            <table className="w-full text-[12px]">
              <thead><tr className="text-left text-[11px] uppercase text-ink-2 border-b border-line">
                <th className="py-1 w-6"></th><th>Fecha</th><th>Aseguradora</th><th>CUIT</th><th>Tipo</th><th>PV-Nro</th><th className="text-right">Total</th><th></th>
              </tr></thead>
              <tbody>
                {comprobantes.map((c, i) => (
                  <tr key={c.id} className={i % 2 ? 'bg-surface-row' : ''}>
                    <td className="py-1"><input type="checkbox" checked={sel.has(c.id)} onChange={() => toggle(c.id)} /></td>
                    <td>{c.fecha_emision?.slice(0, 10)}</td>
                    <td className="max-w-[220px] truncate" title={c.aseguradora}>{c.aseguradora}</td>
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

function Campo({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <div className="text-[10px] uppercase text-ink-muted font-semibold mb-0.5">{label}</div>
      <div>{children}</div>
    </div>
  );
}
