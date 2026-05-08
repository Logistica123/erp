import { useState, useMemo } from 'react';
import { FileDown, FileText, Send, GitCompare, AlertTriangle, Calendar } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { SelectField, FormError } from '@/components/ui/Field';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { auth } from '@/lib/auth';
import { api, ApiError } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Periodo = {
  id: number;
  anio: number;
  mes: number;
  estado: 'ABIERTO' | 'EN_CIERRE' | 'CERRADO' | 'BLOQUEADO';
};

type Ejercicio = { id: number; numero: number; estado: string };

type Export = {
  id: number;
  empresa_id: number;
  periodo_id: number;
  archivo_cbte_path: string;
  archivo_alicuotas_path: string;
  archivo_cbte_hash: string;
  archivo_alicuotas_hash: string;
  filas_cbte: number;
  filas_alicuotas: number;
  total_neto: string | number;
  total_iva: string | number;
  total_facturas: string | number;
  generado_at: string;
  generado_por: number;
  enviado_afip: boolean;
  enviado_at: string | null;
  observaciones: string | null;
};

type ValidacionError = {
  factura_id: number;
  numero: number;
  codigo: 'CUIT_INVALIDO' | 'TIPO_NO_CATALOGADO' | 'IVA_DESBALANCEADO';
  detalle: string;
};

const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

export function LibroIvaComprasExportPage() {
  const [periodoId, setPeriodoId] = useState('');
  const [validaciones, setValidaciones] = useState<ValidacionError[] | null>(null);
  const [verExport, setVerExport] = useState<Export | null>(null);

  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-compras-exports']);

  const { data: ejercicios } = useApi<Ejercicio[]>(['ejercicios-export'], '/api/erp/ejercicios');
  const ejercicioActual = ejercicios?.[0];

  const { data: periodos } = useApi<Periodo[]>(
    ['periodos-export', ejercicioActual?.id],
    `/api/erp/periodos?ejercicio_id=${ejercicioActual?.id ?? ''}`,
    { enabled: !!ejercicioActual }
  );

  const { data: exports, isLoading: loadingExports, error: errorExports } = useApi<Export[]>(
    ['libro-iva-compras-exports'],
    '/api/erp/libro-iva-compras/exports'
  );

  const generar = useApiMutation<{ export_id: number; cbte_hash: string; alicuotas_hash: string; filas_cbte: number; filas_alicuotas: number; total_facturas: number }, void>(
    () => api.post(`/api/erp/libro-iva-compras/${periodoId}/exportar-f8001`),
    {
      onSuccess: (r) => {
        toast.success('Archivos F.8001 generados',
          `${r.filas_cbte} cbte / ${r.filas_alicuotas} alicuotas — total ${fmtMoney(r.total_facturas)}`);
        setValidaciones(null);
        invalidate();
      },
      onError: (e) => {
        const apiErr = e as ApiError;
        // VALIDACION_BLOQUEANTE viene del backend con detalle JSON.
        if (apiErr.status === 422 && apiErr.message.startsWith('VALIDACION_BLOQUEANTE')) {
          try {
            const json = apiErr.message.replace(/^VALIDACION_BLOQUEANTE:\s*/, '');
            setValidaciones(JSON.parse(json));
            toast.error('Validaciones bloqueantes',
              'Corregí los errores listados antes de generar.');
          } catch {
            toast.error('Validaciones bloqueantes', apiErr.message);
          }
        } else {
          toast.error('Error al generar', errorMessage(e));
        }
      },
    }
  );

  const opcionesPeriodos = useMemo(() => {
    if (!periodos) return [];
    return periodos.map((p) => ({
      value: String(p.id),
      label: `${MESES[p.mes]} ${p.anio} (${p.estado})`,
    }));
  }, [periodos]);

  const cols: Column<Export>[] = [
    { key: 'id', header: '#', width: '60px',
      render: (r) => <code className="text-[11px]">{r.id}</code> },
    { key: 'periodo', header: 'Período', width: '140px',
      render: (r) => {
        const p = periodos?.find((x) => x.id === r.periodo_id);
        return p ? `${MESES[p.mes]} ${p.anio}` : `#${r.periodo_id}`;
      } },
    { key: 'filas_cbte', header: 'CBTE', align: 'right', width: '80px' },
    { key: 'filas_alicuotas', header: 'ALIC', align: 'right', width: '80px' },
    { key: 'total_neto', header: 'Neto', align: 'right', width: '130px',
      render: (r) => fmtMoney(Number(r.total_neto)) },
    { key: 'total_iva', header: 'IVA', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.total_iva)) },
    { key: 'total_facturas', header: 'Total', align: 'right', width: '140px',
      render: (r) => fmtMoney(Number(r.total_facturas)) },
    { key: 'generado_at', header: 'Generado', width: '120px',
      render: (r) => fmtDate(r.generado_at) },
    { key: 'enviado', header: 'AFIP', width: '90px',
      render: (r) => r.enviado_afip
        ? <Badge variant="success">Enviado</Badge>
        : <Badge variant="warning">Pendiente</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '120px',
      render: (r) => (
        <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setVerExport(r); }}>
          <FileText className="w-3 h-3" /> Ver
        </Button>
      ) },
  ];

  const periodoSel = periodos?.find((p) => String(p.id) === periodoId);

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2">
            <FileDown className="w-4 h-4 text-azure" /> Generar archivos AFIP F.8001 — Libro IVA Compras
          </div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            Genera los TXT del Libro IVA Digital (RG 4597) a partir de las facturas del período
            con <code>no_tomada=0</code>. Cada generación queda registrada con su hash SHA-256
            para auditoría. Re-generar el mismo período crea un registro nuevo (no pisa el anterior).
          </div>

          <div className="flex flex-wrap gap-3 items-end">
            <SelectField label="Período de imputación" value={periodoId} placeholder="Elegí un período"
              onChange={(e) => { setPeriodoId(e.target.value); setValidaciones(null); }}
              options={opcionesPeriodos}
              containerClassName="w-[280px]" />

            {periodoSel && (
              <div className="text-[11.5px] text-ink-muted flex items-center gap-1">
                <Calendar className="w-3 h-3" /> Estado: <Badge variant={
                  periodoSel.estado === 'ABIERTO' ? 'success'
                  : periodoSel.estado === 'CERRADO' ? 'neutral' : 'warning'
                }>{periodoSel.estado}</Badge>
              </div>
            )}

            <Button variant="primary" disabled={!periodoId || generar.isPending}
              onClick={() => generar.mutate()}>
              <FileDown className="w-3 h-3" /> {generar.isPending ? 'Generando…' : 'Generar archivos AFIP'}
            </Button>
          </div>

          {validaciones && validaciones.length > 0 && (
            <div className="border border-danger/30 bg-danger-bg/30 rounded-md p-3 space-y-1">
              <div className="flex items-center gap-1 text-[12px] font-semibold text-danger">
                <AlertTriangle className="w-3.5 h-3.5" />
                {validaciones.length} validación{validaciones.length === 1 ? '' : 'es'} bloqueante{validaciones.length === 1 ? '' : 's'}
              </div>
              <ul className="text-[11.5px] text-ink space-y-0.5 max-h-[200px] overflow-y-auto">
                {validaciones.slice(0, 50).map((v, i) => (
                  <li key={i}>
                    <code>FC #{v.factura_id}</code> nro <code>{v.numero}</code> — <strong>{v.codigo}</strong>: {v.detalle}
                  </li>
                ))}
                {validaciones.length > 50 && (
                  <li className="text-ink-muted">… y {validaciones.length - 50} más</li>
                )}
              </ul>
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2">
            <FileText className="w-4 h-4 text-azure" /> Histórico de generaciones
          </div>
        } />
        <CardBody className="p-4 space-y-3">
          {errorExports && <FormError error={errorMessage(errorExports)} />}
          <DataTable columns={cols} rows={exports ?? []} loading={loadingExports}
            onRowClick={(r) => setVerExport(r)}
            empty="Aún no se generó ningún F.8001 en este sistema" />
        </CardBody>
      </Card>

      {verExport && (
        <ExportDetalleModal
          exp={verExport}
          periodo={periodos?.find((p) => p.id === verExport.periodo_id)}
          onClose={() => setVerExport(null)} />
      )}
    </div>
  );
}

function ExportDetalleModal({
  exp, periodo, onClose,
}: {
  exp: Export;
  periodo: Periodo | undefined;
  onClose: () => void;
}) {
  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-compras-exports']);
  const [comparing, setComparing] = useState(false);
  const [cbteLiber, setCbteLiber] = useState<File | null>(null);
  const [alicLiber, setAlicLiber] = useState<File | null>(null);
  const [cmpResult, setCmpResult] = useState<null | {
    cbte_match: boolean; alicuotas_match: boolean;
    cbte_primera_diferencia: { linea: number; erp: string; liber: string } | null;
    alicuotas_primera_diferencia: { linea: number; erp: string; liber: string } | null;
  }>(null);

  const marcarEnviado = useApiMutation(
    () => api.post(`/api/erp/libro-iva-compras/exports/${exp.id}/marcar-enviado`),
    {
      onSuccess: () => {
        toast.success('Marcado como enviado a AFIP');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );

  const comparar = useApiMutation<typeof cmpResult, FormData>(
    (fd) => api.post(`/api/erp/libro-iva-compras/exports/${exp.id}/comparar-liber`, fd),
    {
      onSuccess: (r) => { setCmpResult(r); setComparing(true); },
      onError: (e) => toast.error('Error al comparar', errorMessage(e)),
    }
  );

  const submitComparar = () => {
    if (!cbteLiber || !alicLiber) return;
    const fd = new FormData();
    fd.append('cbte_liber', cbteLiber);
    fd.append('alicuotas_liber', alicLiber);
    comparar.mutate(fd);
  };

  const descargar = (kind: 'cbte' | 'alicuotas') => {
    const url = `/api/erp/libro-iva-compras/exports/${exp.id}/${kind}`;
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        const periodoStr = periodo ? `${periodo.anio}-${String(periodo.mes).padStart(2, '0')}` : exp.periodo_id;
        a.download = `F8001_${kind}_${periodoStr}.txt`;
        a.click();
      })
      .catch(() => toast.error('No se pudo descargar el archivo'));
  };

  return (
    <Modal open onClose={onClose} size="lg"
      title={`Export #${exp.id} — ${periodo ? `${MESES[periodo.mes]} ${periodo.anio}` : `período ${exp.periodo_id}`}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cerrar</Button>
          {!exp.enviado_afip && (
            <Button variant="primary" disabled={marcarEnviado.isPending}
              onClick={() => marcarEnviado.mutate(undefined as unknown as void)}>
              <Send className="w-3 h-3" /> {marcarEnviado.isPending ? 'Marcando…' : 'Marcar enviado a AFIP'}
            </Button>
          )}
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-3 gap-2 text-[12px]">
          <Stat label="Filas CBTE" value={exp.filas_cbte} />
          <Stat label="Filas ALIC" value={exp.filas_alicuotas} />
          <Stat label="Generado" value={fmtDate(exp.generado_at)} />
          <Stat label="Total neto" value={fmtMoney(Number(exp.total_neto))} />
          <Stat label="Total IVA" value={fmtMoney(Number(exp.total_iva))} />
          <Stat label="Total facturas" value={fmtMoney(Number(exp.total_facturas))} />
        </div>

        <div className="grid grid-cols-2 gap-2 text-[11px]">
          <div className="border border-line rounded-md p-2 bg-surface-row">
            <div className="text-[10.5px] uppercase text-ink-muted mb-1">Hash CBTE</div>
            <code className="break-all">{exp.archivo_cbte_hash}</code>
          </div>
          <div className="border border-line rounded-md p-2 bg-surface-row">
            <div className="text-[10.5px] uppercase text-ink-muted mb-1">Hash ALICUOTAS</div>
            <code className="break-all">{exp.archivo_alicuotas_hash}</code>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" onClick={() => descargar('cbte')}>
            <FileDown className="w-3 h-3" /> Descargar CBTE.txt
          </Button>
          <Button variant="outline" size="sm" onClick={() => descargar('alicuotas')}>
            <FileDown className="w-3 h-3" /> Descargar ALICUOTAS.txt
          </Button>
          <Button variant="ghost" size="sm" onClick={() => setComparing((v) => !v)}>
            <GitCompare className="w-3 h-3" /> Comparar con LIBER
          </Button>
        </div>

        {exp.enviado_afip && exp.enviado_at && (
          <div className="text-[11.5px] text-success bg-success-bg/30 border border-success/30 rounded-md p-2">
            ✓ Enviado a AFIP el {fmtDate(exp.enviado_at)}
          </div>
        )}

        {comparing && (
          <div className="border border-line rounded-md p-3 space-y-2 bg-surface-row">
            <div className="text-[12px] font-semibold text-navy-800">Comparar con archivos de LIBER</div>
            <div className="text-[11px] text-ink-muted">
              Subí los TXT que generó el estudio para el mismo período. El backend compara
              hashes y, si difieren, devuelve la primera línea distinta.
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-[11.5px] font-semibold text-ink-2 mb-1">CBTE.txt de LIBER</label>
                <input type="file" accept=".txt" onChange={(e) => setCbteLiber(e.target.files?.[0] ?? null)}
                  className="w-full text-[11.5px] file:mr-2 file:py-1.5 file:px-2 file:border-0 file:bg-azure file:text-white file:rounded file:cursor-pointer file:text-[11px]" />
              </div>
              <div>
                <label className="block text-[11.5px] font-semibold text-ink-2 mb-1">ALICUOTAS.txt de LIBER</label>
                <input type="file" accept=".txt" onChange={(e) => setAlicLiber(e.target.files?.[0] ?? null)}
                  className="w-full text-[11.5px] file:mr-2 file:py-1.5 file:px-2 file:border-0 file:bg-azure file:text-white file:rounded file:cursor-pointer file:text-[11px]" />
              </div>
            </div>
            <Button variant="primary" size="sm" disabled={!cbteLiber || !alicLiber || comparar.isPending}
              onClick={submitComparar}>
              {comparar.isPending ? 'Comparando…' : 'Comparar'}
            </Button>

            {cmpResult && (
              <div className="space-y-2 mt-2">
                <div className={`text-[11.5px] rounded-md p-2 ${cmpResult.cbte_match
                  ? 'bg-success-bg/30 border border-success/30 text-success'
                  : 'bg-danger-bg/30 border border-danger/30 text-danger'}`}>
                  CBTE: {cmpResult.cbte_match ? '✓ idénticos' : '✗ difieren'}
                  {!cmpResult.cbte_match && cmpResult.cbte_primera_diferencia && (
                    <div className="mt-1 text-[10.5px] text-ink space-y-0.5">
                      <div>Primera diferencia: línea {cmpResult.cbte_primera_diferencia.linea}</div>
                      <div><code>ERP:   {cmpResult.cbte_primera_diferencia.erp.slice(0, 100)}…</code></div>
                      <div><code>LIBER: {cmpResult.cbte_primera_diferencia.liber.slice(0, 100)}…</code></div>
                    </div>
                  )}
                </div>
                <div className={`text-[11.5px] rounded-md p-2 ${cmpResult.alicuotas_match
                  ? 'bg-success-bg/30 border border-success/30 text-success'
                  : 'bg-danger-bg/30 border border-danger/30 text-danger'}`}>
                  ALICUOTAS: {cmpResult.alicuotas_match ? '✓ idénticos' : '✗ difieren'}
                  {!cmpResult.alicuotas_match && cmpResult.alicuotas_primera_diferencia && (
                    <div className="mt-1 text-[10.5px] text-ink space-y-0.5">
                      <div>Primera diferencia: línea {cmpResult.alicuotas_primera_diferencia.linea}</div>
                      <div><code>ERP:   {cmpResult.alicuotas_primera_diferencia.erp}</code></div>
                      <div><code>LIBER: {cmpResult.alicuotas_primera_diferencia.liber}</code></div>
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="border border-line rounded-md p-2 bg-white">
      <div className="text-[10.5px] uppercase text-ink-muted">{label}</div>
      <div className="font-semibold tabular-nums text-[12px]">{value}</div>
    </div>
  );
}
