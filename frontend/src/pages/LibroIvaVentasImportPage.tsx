import { useState, useMemo } from 'react';
import { Upload, ScrollText, FileSpreadsheet, Check, AlertTriangle, ArrowRight, ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { SelectField, FormError } from '@/components/ui/Field';
import { DataTable, fmtDate, type Column } from '@/components/ui/DataTable';
import { api, ApiError } from '@/lib/api';
import { auth } from '@/lib/auth';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * v1.45 — Wizard de import del Libro IVA Ventas (espejo del v1.9 compras
 * adaptado para ventas). Acepta CSV/XLSX standard de AFIP "Mis Comprobantes
 * Emitidos" + 3 columnas extras opcionales del contador.
 */

type Periodo = {
  id: number;
  anio: number;
  mes: number;
  estado: 'ABIERTO' | 'EN_CIERRE' | 'CERRADO' | 'BLOQUEADO';
};
type Ejercicio = { id: number; numero: number; estado: string };

type Import = {
  id: number;
  archivo_nombre: string;
  archivo_hash: string;
  periodo_afip: string | null;
  filas_totales: number;
  filas_ok: number;
  filas_skipped: number;
  filas_error: number;
  clientes_creados?: number;
  estado: 'PROCESANDO' | 'COMPLETO' | 'OK_CON_WARNINGS' | 'ERROR_TOTAL';
  importado_at: string;
  facturas_count?: number;
  puede_borrar?: boolean;
};

type PreviewResp = {
  hash: string;
  archivo_nombre: string;
  encoding_detectado?: string | null;
  filas_totales: number;
  periodo_afip: string | null;
  columnas_extras_detectadas: string[];
  import_existente: { id: number; importado_at: string; estado: string } | null;
};

type ConfirmResp = {
  import_id: number;
  estado?: string;
  stats: {
    totales: number; skipped: number;
    errores: number; warnings?: number;
    clientes_creados: number; clientes_no_mapeados: number;
  };
  errores: Array<{
    row?: number; fila?: number;
    motivo?: string; mensaje?: string; codigo?: string; detalle?: string;
  }>;
  warnings?: Array<{ row?: number; motivo?: string }>;
  clientes_no_mapeados: Array<{ valor: string; row?: number }>;
};

const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// v1.45 — 3 columnas opcionales del contador (mirror simplificado del
// patrón de compras). Las obligatorias son las del CSV AFIP estándar.
const EXTRAS_OBLIGATORIAS = ['cod jurisd', 'comentario', 'periodo trabajado'];

const ESTADO_BADGES: Record<Import['estado'], 'success' | 'danger' | 'warning'> = {
  COMPLETO: 'success',
  PROCESANDO: 'warning',
  OK_CON_WARNINGS: 'warning',
  ERROR_TOTAL: 'danger',
};

export function LibroIvaVentasImportPage() {
  const [wizardOpen, setWizardOpen] = useState(false);
  const [borrarTarget, setBorrarTarget] = useState<Import | null>(null);

  const { data: imports, isLoading, error } = useApi<Import[]>(
    ['libro-iva-ventas-imports'],
    '/api/erp/libro-iva-ventas/imports'
  );

  const mostrarAcciones = (imports ?? []).some((r) => r.puede_borrar !== undefined);

  const cols: Column<Import>[] = [
    { key: 'id', header: '#', width: '60px',
      render: (r) => <code className="text-[11px]">{r.id}</code> },
    { key: 'archivo_nombre', header: 'Archivo',
      render: (r) => (
        <div>
          <div className="text-[12px]">{r.archivo_nombre}</div>
          <div className="text-[10px] text-ink-muted font-mono">{r.archivo_hash.slice(0, 16)}…</div>
        </div>
      ) },
    { key: 'periodo_afip', header: 'Período AFIP', width: '110px',
      render: (r) => r.periodo_afip ?? <span className="text-ink-muted">—</span> },
    { key: 'filas_totales', header: 'Filas', align: 'right', width: '80px' },
    { key: 'filas_ok', header: 'OK', align: 'right', width: '80px',
      render: (r) => r.filas_ok
        ? <Badge variant="success">{r.filas_ok}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'filas_error', header: 'Errores', align: 'right', width: '90px',
      render: (r) => r.filas_error
        ? <Badge variant="danger">{r.filas_error}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'clientes_creados', header: 'Clientes +', align: 'right', width: '90px',
      render: (r) => r.clientes_creados
        ? <Badge variant="success">{r.clientes_creados}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'estado', header: 'Estado', width: '140px',
      render: (r) => <Badge variant={ESTADO_BADGES[r.estado]}>{r.estado}</Badge> },
    { key: 'importado_at', header: 'Importado', width: '120px',
      render: (r) => fmtDate(r.importado_at) },
  ];

  if (mostrarAcciones) {
    cols.push({
      key: 'acciones', header: '', width: '60px', align: 'center',
      render: (r) => {
        const tieneFacturas = (r.facturas_count ?? 0) > 0;
        if (r.puede_borrar || tieneFacturas) {
          return (
            <button type="button" onClick={() => setBorrarTarget(r)}
              className="text-red-600 hover:text-red-800 p-1 rounded transition"
              title={tieneFacturas
                ? `Este import generó ${r.facturas_count} facturas. El modal te ofrece borrarlas en cascada.`
                : 'Borrar upload'}>
              <Trash2 className="w-3.5 h-3.5" />
            </button>
          );
        }
        return null;
      },
    });
  }

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2">
            <ScrollText className="w-4 h-4 text-azure" /> Libro IVA Ventas — importador desde AFIP
          </div>}
          actions={
            <div className="flex gap-2">
              <Link to="/erp/facturacion/nueva-manual">
                <Button variant="outline">
                  <Plus className="w-3 h-3" /> Cargar manual
                </Button>
              </Link>
              <Button variant="primary" onClick={() => setWizardOpen(true)}>
                <Upload className="w-3 h-3" /> Importar archivo
              </Button>
            </div>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            Importa el CSV/Excel de AFIP "Mis Comprobantes Emitidos". Las 3 columnas
            extras opcionales (<code>COD JURISD</code>, <code>COMENTARIO</code>,{' '}
            <code>PERIODO TRABAJADO</code>) se persisten si están en el archivo.
            Cada factura importada genera asiento contable automático y upsert de
            cliente por CUIT.
          </div>

          {error && <FormError error={errorMessage(error)} />}

          <DataTable columns={cols} rows={imports ?? []} loading={isLoading}
            empty="Aún no se importó ningún archivo del Libro IVA Ventas" />
        </CardBody>
      </Card>

      {wizardOpen && <ImportWizardModal onClose={() => setWizardOpen(false)} />}
      {borrarTarget && (
        <BorrarImportModal imp={borrarTarget} onClose={() => setBorrarTarget(null)} />
      )}
    </div>
  );
}

function BorrarImportModal({ imp, onClose }: { imp: Import; onClose: () => void }) {
  const [motivo, setMotivo] = useState('');
  const tieneFacturas = (imp.facturas_count ?? 0) > 0;
  const [cascada, setCascada] = useState(false);
  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-ventas-imports']);
  const facturasInvalidate = useInvalidate(['facturas-venta']);

  const deleteMut = useApiMutation<unknown, { motivo?: string }>(
    (body) => {
      const qs = tieneFacturas && cascada ? '?cascada=true' : '';
      return api.delete(`/api/erp/libro-iva-ventas/imports/${imp.id}${qs}`, body);
    },
    {
      onSuccess: () => {
        toast.success('Upload borrado',
          `#${imp.id} (${imp.archivo_nombre})${tieneFacturas && cascada ? ` + ${imp.facturas_count} facturas` : ''}`);
        invalidate();
        if (tieneFacturas && cascada) facturasInvalidate();
        onClose();
      },
      onError: (e) => {
        if (e instanceof ApiError && e.status === 409) {
          toast.error('No se puede borrar',
            'El import tiene facturas vinculadas. Marcá "cascada" para borrar todo.');
          return;
        }
        toast.error('Error al borrar', errorMessage(e));
      },
    },
  );

  return (
    <Modal open onClose={onClose} title="Borrar import del Libro IVA Ventas">
      <div className="space-y-3 text-[12px]">
        <div className="text-ink-muted">
          Vas a borrar el upload <code>#{imp.id}</code>.
        </div>
        <dl className="grid grid-cols-[120px_1fr] gap-y-1 gap-x-2 text-[11px] bg-azure-soft/30 rounded p-2">
          <dt className="text-ink-muted">Archivo</dt><dd>{imp.archivo_nombre}</dd>
          <dt className="text-ink-muted">Hash</dt><dd className="font-mono">{imp.archivo_hash.slice(0, 16)}…</dd>
          <dt className="text-ink-muted">Período AFIP</dt>
          <dd>{imp.periodo_afip ?? <span className="text-ink-muted">—</span>}</dd>
          <dt className="text-ink-muted">Filas</dt><dd>{imp.filas_totales}</dd>
          <dt className="text-ink-muted">Facturas vinculadas</dt><dd>{imp.facturas_count ?? 0}</dd>
          <dt className="text-ink-muted">Estado</dt>
          <dd><Badge variant={ESTADO_BADGES[imp.estado]}>{imp.estado}</Badge></dd>
          <dt className="text-ink-muted">Importado</dt><dd>{fmtDate(imp.importado_at)}</dd>
        </dl>

        {tieneFacturas && (
          <div className="border border-warning/40 bg-warning-bg/30 rounded p-2 text-[11px] space-y-2">
            <div className="flex items-start gap-1.5">
              <AlertTriangle className="w-3 h-3 text-warning mt-[2px] flex-shrink-0" />
              <div>
                Este import tiene <strong>{imp.facturas_count}</strong> facturas con asientos.
                Para borrar todo en cascada (facturas + asientos + upload), marcá la opción.
              </div>
            </div>
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={cascada} onChange={(e) => setCascada(e.target.checked)} />
              <span>Borrar en cascada (anular facturas + asientos)</span>
            </label>
          </div>
        )}

        <div className="bg-red-50 border border-red-200 rounded p-2 text-[11px]">
          <AlertTriangle className="inline w-3 h-3 text-red-700 mr-1" />
          <strong>Esta acción es irreversible.</strong> Queda en audit log inmutable.
        </div>

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">Motivo (opcional)</label>
          <textarea rows={2} value={motivo} onChange={(e) => setMotivo(e.target.value)}
            maxLength={500} className="w-full text-[12px] border border-azure-soft rounded px-2 py-1 focus:outline-none focus:border-azure" />
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <Button variant="outline" onClick={onClose} disabled={deleteMut.isPending}>Cancelar</Button>
          <Button variant="danger"
            onClick={() => deleteMut.mutate(motivo.trim() ? { motivo: motivo.trim() } : {})}
            disabled={deleteMut.isPending || (tieneFacturas && !cascada)}>
            <Trash2 className="w-3 h-3" />
            {tieneFacturas && cascada ? 'Borrar todo (cascada)' : 'Borrar'}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

function ImportWizardModal({ onClose }: { onClose: () => void }) {
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [archivo, setArchivo] = useState<File | null>(null);
  const [preview, setPreview] = useState<PreviewResp | null>(null);
  const [periodoId, setPeriodoId] = useState('');
  const [resultado, setResultado] = useState<ConfirmResp | null>(null);

  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-ventas-imports']);

  const { data: ejercicios } = useApi<Ejercicio[]>(['ejercicios-import'], '/api/erp/ejercicios');
  const ejercicioActual = ejercicios?.[0];
  const { data: periodos } = useApi<Periodo[]>(
    ['periodos-import', ejercicioActual?.id],
    `/api/erp/periodos?ejercicio_id=${ejercicioActual?.id ?? ''}`,
    { enabled: !!ejercicioActual }
  );

  const periodoSel = useMemo(
    () => periodos?.find((p) => String(p.id) === periodoId),
    [periodos, periodoId]
  );
  const periodoCerrado = periodoSel?.estado === 'CERRADO' || periodoSel?.estado === 'BLOQUEADO';

  const previewMut = useApiMutation<PreviewResp, FormData>(
    (fd) => api.post('/api/erp/libro-iva-ventas/import/preview', fd),
    {
      onSuccess: (r) => {
        setPreview(r);
        if (r.import_existente) {
          toast.error('Archivo duplicado',
            `Este archivo ya se importó (#${r.import_existente.id}, ${r.import_existente.estado})`);
          return;
        }
        const abierto = periodos?.find((p) => p.estado === 'ABIERTO');
        if (abierto) setPeriodoId(String(abierto.id));
        setStep(2);
      },
      onError: (e) => toast.error('Error al previsualizar', errorMessage(e)),
    }
  );

  const confirmMut = useApiMutation<ConfirmResp, FormData>(
    (fd) => api.post('/api/erp/libro-iva-ventas/import/confirmar', fd),
    {
      onSuccess: (r) => {
        setResultado(r);
        setStep(3);
        invalidate();
        if (r.estado === 'ERROR_TOTAL' || r.stats.errores > 0) {
          toast.error('Import rechazado',
            `${r.stats.errores} errores. Ninguna fila se importó (TODO-O-NADA).`);
        } else if (r.stats.warnings && r.stats.warnings > 0) {
          toast.success('Import procesado con warnings',
            `${r.stats.totales} facturas · ${r.stats.warnings} warnings`);
        } else {
          toast.success('Import procesado', `${r.stats.totales} facturas importadas`);
        }
      },
      onError: (e) => toast.error('Error al confirmar', (e as ApiError).message),
    }
  );

  const submitPreview = () => {
    if (!archivo) return;
    const fd = new FormData();
    fd.append('archivo', archivo);
    previewMut.mutate(fd);
  };
  const submitConfirmar = () => {
    if (!archivo || !periodoId) return;
    const fd = new FormData();
    fd.append('archivo', archivo);
    fd.append('periodo_imputacion_id', periodoId);
    confirmMut.mutate(fd);
  };

  const titulo = step === 1 ? 'Subir archivo' : step === 2 ? 'Revisar y confirmar' : 'Resultado';

  return (
    <Modal open onClose={onClose} size="lg"
      title={`Wizard import Libro IVA Ventas — paso ${step}/3 · ${titulo}`}
      footer={
        step === 3 ? (
          <Button variant="primary" onClick={onClose}>Cerrar</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose}>Cancelar</Button>
            {step === 2 && (
              <Button variant="ghost" onClick={() => setStep(1)}>
                <ArrowLeft className="w-3 h-3" /> Atrás
              </Button>
            )}
            {step === 1 && (
              <Button variant="primary" disabled={!archivo || previewMut.isPending}
                onClick={submitPreview}>
                {previewMut.isPending ? 'Analizando…' : <>Continuar <ArrowRight className="w-3 h-3" /></>}
              </Button>
            )}
            {step === 2 && (
              <Button variant="primary"
                disabled={!periodoId || periodoCerrado || confirmMut.isPending}
                onClick={submitConfirmar}>
                {confirmMut.isPending ? 'Importando…' : 'Confirmar e importar'}
              </Button>
            )}
          </>
        )
      }>
      {step === 1 && <Step1 archivo={archivo} setArchivo={setArchivo} error={previewMut.error} />}
      {step === 2 && preview && (
        <Step2 preview={preview} periodos={periodos ?? []}
          periodoId={periodoId} setPeriodoId={setPeriodoId}
          periodoCerrado={periodoCerrado} error={confirmMut.error} />
      )}
      {step === 3 && resultado && (
        <Step3 resultado={resultado} encodingDetectado={preview?.encoding_detectado ?? null} />
      )}
    </Modal>
  );
}

function Step1({ archivo, setArchivo, error }: {
  archivo: File | null;
  setArchivo: (f: File | null) => void;
  error: ApiError | null;
}) {
  return (
    <div className="space-y-3">
      <div className="text-[12px] text-ink-muted">
        Subí el CSV/Excel de AFIP "Mis Comprobantes Emitidos". El contador puede sumar
        3 columnas extras opcionales: <code>COD JURISD</code>, <code>COMENTARIO</code>,{' '}
        <code>PERIODO TRABAJADO</code>. Hasta 50 MB.
      </div>
      <div className="border-2 border-dashed border-line rounded-lg p-6 text-center space-y-2 bg-surface-row">
        <FileSpreadsheet className="w-8 h-8 mx-auto text-ink-muted" />
        <input type="file"
          accept=".csv,.xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          onChange={(e) => setArchivo(e.target.files?.[0] ?? null)}
          className="block mx-auto text-[12px] file:mr-3 file:py-2 file:px-3 file:border-0 file:bg-azure file:text-white file:rounded-md file:cursor-pointer file:text-[12px] file:font-medium hover:file:bg-azure/90" />
        {archivo && (
          <div className="text-[11.5px] text-ink">
            <strong>{archivo.name}</strong> · {(archivo.size / 1024).toFixed(1)} KB
          </div>
        )}
      </div>
      <FormError error={error ? errorMessage(error) : null} />
    </div>
  );
}

function Step2({
  preview, periodos, periodoId, setPeriodoId, periodoCerrado, error,
}: {
  preview: PreviewResp;
  periodos: Periodo[];
  periodoId: string;
  setPeriodoId: (s: string) => void;
  periodoCerrado: boolean;
  error: ApiError | null;
}) {
  const opciones = periodos.map((p) => ({
    value: String(p.id),
    label: `${MESES[p.mes]} ${p.anio} (${p.estado})`,
  }));
  const detectadas = preview.columnas_extras_detectadas;
  const cobertura = (col: string) => detectadas.includes(col);

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-2 text-[12px]">
        <Stat label="Filas totales detectadas" value={preview.filas_totales} />
        <Stat label="Período AFIP (auto)"
          value={preview.periodo_afip ?? <span className="text-ink-muted">—</span>} />
      </div>

      <div className="border border-line rounded-md p-3 space-y-2 bg-surface-row">
        <div className="text-[12px] font-semibold text-navy-800">Columnas extras opcionales</div>
        <div className="flex flex-wrap gap-2">
          {EXTRAS_OBLIGATORIAS.map((c) => (
            <div key={c} className={`text-[11.5px] px-2 py-1 rounded border flex items-center gap-1 ${
              cobertura(c) ? 'border-success/30 bg-success-bg/20 text-success'
                : 'border-line bg-white text-ink-muted'
            }`}>
              {cobertura(c) ? <Check className="w-3 h-3" /> : '○'}
              <code>{c}</code>
            </div>
          ))}
        </div>
        {detectadas.length === 0 && (
          <div className="text-[11px] text-ink-muted italic">
            No se detectaron columnas extras — solo se persistirán los campos AFIP estándar.
          </div>
        )}
      </div>

      <SelectField label="Período de imputación contable" required
        value={periodoId} placeholder="Elegí un período"
        onChange={(e) => setPeriodoId(e.target.value)}
        options={opciones}
        hint="Todas las facturas del archivo se imputarán a este período (con fecha emisión real)."
        containerClassName="w-full" />

      {periodoCerrado && (
        <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-3 space-y-1">
          <div className="flex items-center gap-1 text-[12px] font-semibold text-warning">
            <AlertTriangle className="w-3.5 h-3.5" /> El período está cerrado
          </div>
          <div className="text-[11.5px] text-ink">
            Reabrilo desde <code>Contabilidad → Períodos → Reabrir</code>. Una vez terminado, podés cerrarlo de nuevo.
          </div>
        </div>
      )}

      <FormError error={error ? errorMessage(error) : null} />
    </div>
  );
}

function Step3({ resultado, encodingDetectado }: { resultado: ConfirmResp; encodingDetectado: string | null }) {
  const { stats, errores, clientes_no_mapeados } = resultado;
  const warnings = resultado.warnings ?? [];
  const rollbackTotal = stats.errores > 0;
  const descargarCsvErrores = () => {
    const url = `/api/erp/libro-iva-ventas/imports/${resultado.import_id}/errores.csv`;
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `errores_import_ventas_${resultado.import_id}.csv`;
        a.click();
      });
  };

  return (
    <div className="space-y-4">
      {rollbackTotal ? (
        <div className="border border-danger/40 bg-danger-bg/30 rounded-md p-4 space-y-2">
          <div className="flex items-center gap-2 text-[14px] font-bold text-danger">
            <AlertTriangle className="w-4 h-4" />
            Import rechazado — ninguna fila se importó
          </div>
          <div className="text-[12px] text-ink">
            El archivo tiene <strong>{stats.errores}</strong> errores. Atomicidad TODO-O-NADA:
            o entran todas o ninguna. Corregí el archivo y re-subí.
            {encodingDetectado && encodingDetectado !== 'UTF-8' && encodingDetectado !== 'XLSX' && (
              <> Encoding detectado: <strong>{encodingDetectado}</strong>.</>
            )}
          </div>
        </div>
      ) : warnings.length > 0 ? (
        <div className="border border-warning/40 bg-warning-bg/30 rounded-md p-4 space-y-2">
          <div className="flex items-center gap-2 text-[14px] font-bold text-warning">
            <AlertTriangle className="w-4 h-4" />
            ✓ Import #{resultado.import_id} procesado con {warnings.length} warning{warnings.length === 1 ? '' : 's'}
          </div>
        </div>
      ) : (
        <div className="border border-success/30 bg-success-bg/20 rounded-md p-3 text-center">
          <div className="text-[14px] font-semibold text-success">
            ✓ Import #{resultado.import_id} procesado
          </div>
        </div>
      )}

      <div className="grid grid-cols-3 gap-2 text-[12px]">
        <Stat label="Facturas importadas" value={<Badge variant="success">{stats.totales}</Badge>} />
        <Stat label="Skipped (filas vacías)" value={stats.skipped} />
        <Stat label="Errores" value={
          stats.errores ? <Badge variant="danger">{stats.errores}</Badge> : 0
        } />
        <Stat label="Clientes creados" value={<Badge variant="success">{stats.clientes_creados}</Badge>} />
        <Stat label="Clientes sin mapear" value={
          stats.clientes_no_mapeados
            ? <Badge variant="warning">{stats.clientes_no_mapeados}</Badge>
            : 0
        } />
        <Stat label="Warnings" value={
          stats.warnings ? <Badge variant="warning">{stats.warnings}</Badge> : 0
        } />
      </div>

      {clientes_no_mapeados.length > 0 && (
        <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-3 space-y-1">
          <div className="text-[12px] font-semibold text-warning">
            Clientes no mapeados ({clientes_no_mapeados.length})
          </div>
          <ul className="text-[11.5px] space-y-0.5 max-h-[120px] overflow-y-auto">
            {clientes_no_mapeados.slice(0, 30).map((c, i) => (
              <li key={i}>Fila {c.row ?? '?'}: <code>"{c.valor}"</code></li>
            ))}
            {clientes_no_mapeados.length > 30 && (
              <li className="text-ink-muted">… y {clientes_no_mapeados.length - 30} más</li>
            )}
          </ul>
        </div>
      )}

      {warnings.length > 0 && (
        <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-3 space-y-2">
          <div className="text-[12px] font-semibold text-warning">
            Warnings ({warnings.length})
          </div>
          <ul className="text-[11.5px] space-y-0.5 max-h-[180px] overflow-y-auto">
            {warnings.slice(0, 20).map((w, i) => (
              <li key={i}><strong>Fila {w.row ?? '?'}:</strong> {w.motivo}</li>
            ))}
            {warnings.length > 20 && (
              <li className="text-ink-muted italic">… {warnings.length - 20} más</li>
            )}
          </ul>
        </div>
      )}

      {errores.length > 0 && (
        <div className="border border-danger/30 bg-danger-bg/20 rounded-md p-3 space-y-2">
          <div className="flex items-center justify-between">
            <div className="text-[12px] font-semibold text-danger">
              Errores por fila ({errores.length})
            </div>
            {errores.length > 20 && (
              <Button variant="outline" size="sm" onClick={descargarCsvErrores}>
                Descargar CSV con los {errores.length} errores
              </Button>
            )}
          </div>
          <ul className="text-[11.5px] space-y-0.5 max-h-[240px] overflow-y-auto">
            {errores.slice(0, 20).map((e, i) => {
              const fila = e.row ?? e.fila ?? '?';
              const rawMsg = (e.motivo ?? e.mensaje ?? e.detalle ?? '').trim();
              let codigo = e.codigo ?? '';
              let msg = rawMsg;
              if (!codigo) {
                const m = rawMsg.match(/^([A-Z_][A-Z0-9_]*):\s*(.+)$/);
                if (m) { codigo = m[1]; msg = m[2]; }
              }
              return (
                <li key={i}>
                  <strong>Fila {fila}:</strong> {msg || '—'}
                  {codigo && <code className="ml-2 text-[10.5px] bg-danger/10 px-1 rounded">[{codigo}]</code>}
                </li>
              );
            })}
            {errores.length > 20 && (
              <li className="text-ink-muted italic">… {errores.length - 20} más en el CSV</li>
            )}
          </ul>
        </div>
      )}
    </div>
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
