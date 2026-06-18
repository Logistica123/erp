import { useState } from 'react';
import { ShieldCheck, Upload, Loader2, Check, Download, FileText } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { useMutation } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

type Analisis = {
  aseguradora: string; cuit_aseguradora: string; fecha_emision: string | null;
  poliza: string | null; comprobante_ref: string | null;
  tipo_comprobante_id: number; tipo_label: string; es_baja: boolean;
  imp_neto_gravado_21: number; imp_iva_21: number; imp_percepciones_iva: number;
  imp_otros_tributos: number; imp_total: number; control_cuadra: boolean;
};
type CargaResp = { factura_id: number; txt_cbte: string; txt_alicuotas: string };

function descargarTxt(nombre: string, contenido: string) {
  const blob = new Blob([contenido], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = nombre; a.click();
  URL.revokeObjectURL(url);
}

export default function ProcesamientoSeguroPage() {
  const [err, setErr] = useState('');
  const [form, setForm] = useState<Analisis | null>(null);
  const [pv, setPv] = useState('');
  const [numero, setNumero] = useState('');
  const [fechaImput, setFechaImput] = useState('');
  const [resultado, setResultado] = useState<CargaResp | null>(null);

  const analizar = useMutation({
    mutationFn: (file: File) => {
      const fd = new FormData(); fd.append('archivo', file);
      return api.post<{ data: Analisis }>('/api/erp/compras/seguros/analizar', fd);
    },
    onSuccess: (r) => { setErr(''); setForm(r.data); setResultado(null); setFechaImput(r.data.fecha_emision ?? ''); },
    onError: (e: ApiError) => { setErr(e.message); setForm(null); },
  });

  const cargar = useMutation({
    mutationFn: () => api.post<{ data: CargaResp }>('/api/erp/compras/seguros/cargar', {
      aseguradora: form!.aseguradora, cuit_aseguradora: form!.cuit_aseguradora,
      fecha_emision: form!.fecha_emision, fecha_imputacion: fechaImput || form!.fecha_emision,
      punto_venta: Number(pv), numero: Number(numero), tipo_comprobante_id: form!.tipo_comprobante_id,
      poliza: form!.poliza, comprobante_ref: form!.comprobante_ref,
      imp_neto_gravado_21: form!.imp_neto_gravado_21, imp_iva_21: form!.imp_iva_21,
      imp_percepciones_iva: form!.imp_percepciones_iva, imp_otros_tributos: form!.imp_otros_tributos,
      imp_total: form!.imp_total,
    }),
    onSuccess: (r) => { setErr(''); setResultado(r.data); },
    onError: (e: ApiError) => setErr(e.message),
  });

  const upd = (k: keyof Analisis, v: number) => setForm((f) => (f ? { ...f, [k]: v } : f));
  const puedeCargar = !!form && !!pv && !!numero && !!form.fecha_emision;

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-[18px] font-semibold text-navy-800 flex items-center gap-2">
          <ShieldCheck className="w-5 h-5" /> Procesamiento de Seguro
        </h1>
        <p className="text-[12px] text-ink-2">Subí el PDF de la póliza; el sistema extrae el detalle de facturación, lo carga en el Libro IVA Compras y emite el TXT del Libro IVA Digital.</p>
      </div>

      {err && <div className="mb-4 p-3 bg-danger-bg text-danger rounded-md text-[12px]">{err}</div>}

      <Card>
        <CardHeader title="1 · Subir PDF de póliza" />
        <CardBody>
          <label className="inline-flex items-center gap-2 cursor-pointer">
            <input type="file" accept="application/pdf" className="hidden"
              onChange={(e) => { const f = e.target.files?.[0]; if (f) analizar.mutate(f); }} />
            <span className="px-3 py-1.5 bg-navy-700 text-white rounded text-[12px] inline-flex items-center gap-1">
              {analizar.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Upload className="w-3 h-3" />} Elegir PDF
            </span>
          </label>
          <span className="text-[11px] text-ink-2 ml-2">Aseguradora soportada: La Segunda.</span>
        </CardBody>
      </Card>

      {form && (
        <Card className="mt-4">
          <CardHeader title="2 · Revisar y completar" />
          <CardBody>
            <div className="grid grid-cols-2 gap-3 text-[12px] mb-3">
              <Campo label="Aseguradora"><span className="font-semibold">{form.aseguradora}</span></Campo>
              <Campo label="CUIT">{form.cuit_aseguradora}</Campo>
              <Campo label="Fecha emisión">{form.fecha_emision ?? '—'}</Campo>
              <Campo label="Tipo comprobante"><span className={form.es_baja ? 'text-danger' : 'text-success'}>{form.tipo_label}</span></Campo>
              <Campo label="Póliza">{form.poliza ?? '—'} · ref {form.comprobante_ref ?? '—'}</Campo>
              <Campo label="Fecha imputación">
                <input type="date" value={fechaImput} onChange={(e) => setFechaImput(e.target.value)}
                  className="px-2 py-1 border border-line-strong rounded w-full" />
              </Campo>
              <Campo label="Punto de venta (lo definís vos)">
                <input type="number" value={pv} onChange={(e) => setPv(e.target.value)} placeholder="ej. 1"
                  className="px-2 py-1 border border-line-strong rounded w-full" />
              </Campo>
              <Campo label="Número (lo definís vos)">
                <input type="number" value={numero} onChange={(e) => setNumero(e.target.value)} placeholder="ej. 63"
                  className="px-2 py-1 border border-line-strong rounded w-full" />
              </Campo>
            </div>

            <table className="w-full text-[12px] mb-2">
              <thead><tr className="text-left text-[11px] uppercase text-ink-2 border-b border-line"><th className="py-1">Concepto (Libro IVA)</th><th className="text-right">Importe</th></tr></thead>
              <tbody>
                {([
                  ['Neto gravado 21%', 'imp_neto_gravado_21'],
                  ['IVA 21%', 'imp_iva_21'],
                  ['Percepción IVA', 'imp_percepciones_iva'],
                  ['Otros tributos', 'imp_otros_tributos'],
                  ['TOTAL', 'imp_total'],
                ] as [string, keyof Analisis][]).map(([lbl, key]) => (
                  <tr key={key} className={key === 'imp_total' ? 'border-t border-line font-semibold' : ''}>
                    <td className="py-1">{lbl}</td>
                    <td className="text-right">
                      <input type="number" step="0.01" value={Number(form[key])}
                        onChange={(e) => upd(key, Number(e.target.value))}
                        className="px-2 py-1 border border-line rounded text-right tabular w-40" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className={`text-[11px] ${form.control_cuadra ? 'text-success' : 'text-warning'}`}>
              {form.control_cuadra ? '✓ El desglose cuadra con el total.' : '⚠ El desglose no cuadra con el total — revisá los importes.'}
            </div>

            <div className="flex justify-end gap-2 pt-3 border-t border-line mt-3">
              <Button variant="primary" disabled={!puedeCargar || cargar.isPending} onClick={() => cargar.mutate()}>
                {cargar.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />} Cargar en el módulo y emitir TXT
              </Button>
            </div>
          </CardBody>
        </Card>
      )}

      {resultado && (
        <Card className="mt-4">
          <CardHeader title="3 · Cargado ✓" />
          <CardBody>
            <p className="text-[12px] text-ink-2 mb-3">Comprobante cargado en el Libro IVA Compras (factura compra #{resultado.factura_id}). Descargá el TXT del Libro IVA Digital:</p>
            <div className="flex gap-2">
              <Button variant="secondary" size="sm" onClick={() => descargarTxt(`F8001_seguro_CBTE_${resultado.factura_id}.txt`, resultado.txt_cbte)}>
                <FileText className="w-3 h-3" /> TXT Comprobantes
              </Button>
              <Button variant="secondary" size="sm" onClick={() => descargarTxt(`F8001_seguro_ALICUOTAS_${resultado.factura_id}.txt`, resultado.txt_alicuotas)}>
                <Download className="w-3 h-3" /> TXT Alícuotas
              </Button>
            </div>
          </CardBody>
        </Card>
      )}
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
