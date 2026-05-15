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
  filas_tomadas: number;
  filas_no_tomadas: number;
  filas_skipped: number;
  filas_error: number;
  estado: 'OK' | 'ERROR' | 'PARCIAL';
  importado_at: string;
  // v1.20 — derivados por el backend para el render condicional del 🗑️.
  facturas_count?: number;
  puede_borrar?: boolean;
};

type PreviewResp = {
  hash: string;
  archivo_nombre: string;
  encoding_detectado?: string | null; // v1.19
  filas_totales: number;
  filas_con_tomado_si: number;
  filas_con_tomado_no: number;
  periodo_afip: string | null;
  columnas_extras_detectadas: string[];
  import_existente: { id: number; importado_at: string; estado: string } | null;
};

type ConfirmResp = {
  import_id: number;
  stats: {
    totales: number; tomadas: number; no_tomadas: number; skipped: number;
    errores: number; proveedores_creados: number;
    clientes_mapeados: number; clientes_no_mapeados: number;
  };
  // El backend devuelve { row, motivo }. Mantenemos los aliases por compat.
  errores: Array<{
    row?: number; fila?: number;
    motivo?: string; mensaje?: string; codigo?: string; detalle?: string;
    numero?: number;
  }>;
  clientes_no_mapeados: Array<{ valor: string; filas: number[] }>;
};

const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// v1.13 introdujo 4 columnas extras (tomado/cliente/observaciones/tipo).
// v1.14 sumó 2 más (periodo trabajado/jurisdiccion). Todas opcionales.
const EXTRAS_OBLIGATORIAS = [
  'tomado', 'cliente', 'observaciones', 'tipo',
  'periodo trabajado', 'jurisdiccion',
];

const ESTADO_BADGES: Record<Import['estado'], 'success' | 'danger' | 'warning'> = {
  OK: 'success', ERROR: 'danger', PARCIAL: 'warning',
};

export function LibroIvaComprasImportPage() {
  const [wizardOpen, setWizardOpen] = useState(false);
  // v1.20 — modal de borrado: target del 🗑️ (null = cerrado).
  const [borrarTarget, setBorrarTarget] = useState<Import | null>(null);

  const { data: imports, isLoading, error } = useApi<Import[]>(
    ['libro-iva-compras-imports'],
    '/api/erp/libro-iva-compras/imports'
  );

  // El backend marca puede_borrar=true solo si el user tiene
  // `compras.libro_iva.borrar_import` (super_admin). Si nadie en el listado
  // lo tiene, escondemos la columna entera.
  const mostrarAcciones = (imports ?? []).some(
    (r) => r.puede_borrar !== undefined,
  );

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
    { key: 'filas_tomadas', header: 'Tomadas', align: 'right', width: '90px',
      render: (r) => r.filas_tomadas
        ? <Badge variant="success">{r.filas_tomadas}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'filas_no_tomadas', header: 'No tomadas', align: 'right', width: '110px',
      render: (r) => r.filas_no_tomadas
        ? <Badge variant="warning">{r.filas_no_tomadas}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'filas_error', header: 'Errores', align: 'right', width: '90px',
      render: (r) => r.filas_error
        ? <Badge variant="danger">{r.filas_error}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'estado', header: 'Estado', width: '100px',
      render: (r) => <Badge variant={ESTADO_BADGES[r.estado]}>{r.estado}</Badge> },
    { key: 'importado_at', header: 'Importado', width: '120px',
      render: (r) => fmtDate(r.importado_at) },
  ];

  if (mostrarAcciones) {
    cols.push({
      key: 'acciones', header: '', width: '60px', align: 'center',
      render: (r) => {
        const tieneFacturas = (r.facturas_count ?? 0) > 0;
        if (r.puede_borrar) {
          return (
            <button
              type="button"
              onClick={() => setBorrarTarget(r)}
              className="text-red-600 hover:text-red-800 p-1 rounded transition"
              title="Borrar upload"
            >
              <Trash2 className="w-3.5 h-3.5" />
            </button>
          );
        }
        if (tieneFacturas) {
          return (
            <button
              type="button"
              disabled
              className="text-ink-muted/40 p-1 cursor-not-allowed"
              title={`Este import generó ${r.facturas_count} facturas. Anulalas o desvinculalas primero.`}
            >
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
            <ScrollText className="w-4 h-4 text-azure" /> Libro IVA Compras — import enriquecido
          </div>}
          actions={
            <div className="flex gap-2">
              {/* v1.17 — Botón de carga manual al lado del import. */}
              <Link to="/erp/facturas-compra/nueva?return_to=libro-iva">
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
            Importa el CSV/Excel del Libro IVA Compras enriquecido por el contador.
            Las 4 columnas extras (<code>Tomado</code>, <code>Cliente</code>, <code>Observaciones</code>, <code>Tipo</code>)
            son opcionales. Las facturas con <code>Tomado=NO</code> se importan sin generar asiento ni
            impactar el Libro IVA del ERP — pueden tomarse después en otro período.
          </div>

          {error && <FormError error={errorMessage(error)} />}

          <DataTable columns={cols} rows={imports ?? []} loading={isLoading}
            empty="Aún no se importó ningún archivo del Libro IVA Compras" />
        </CardBody>
      </Card>

      {wizardOpen && <ImportWizardModal onClose={() => setWizardOpen(false)} />}
      {borrarTarget && (
        <BorrarImportModal
          imp={borrarTarget}
          onClose={() => setBorrarTarget(null)}
        />
      )}
    </div>
  );
}

function BorrarImportModal({ imp, onClose }: { imp: Import; onClose: () => void }) {
  const [motivo, setMotivo] = useState('');
  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-compras-imports']);

  const deleteMut = useApiMutation<unknown, { motivo?: string }>(
    (body) => api.delete(`/api/erp/libro-iva-compras/imports/${imp.id}`, body),
    {
      onSuccess: () => {
        toast.success('Upload borrado', `#${imp.id} (${imp.archivo_nombre})`);
        invalidate();
        onClose();
      },
      onError: (e) => {
        if (e instanceof ApiError && e.status === 409) {
          toast.error('No se puede borrar',
            'El import tiene facturas vinculadas. Anulalas primero desde el Libro Diario.');
          return;
        }
        toast.error('Error al borrar', errorMessage(e));
      },
    },
  );

  return (
    <Modal open onClose={onClose} title="Borrar import del Libro IVA Compras">
      <div className="space-y-3 text-[12px]">
        <div className="text-ink-muted">
          Vas a borrar definitivamente el upload <code>#{imp.id}</code>.
        </div>

        <dl className="grid grid-cols-[120px_1fr] gap-y-1 gap-x-2 text-[11px] bg-azure-soft/30 rounded p-2">
          <dt className="text-ink-muted">Archivo</dt>
          <dd>{imp.archivo_nombre}</dd>
          <dt className="text-ink-muted">Hash</dt>
          <dd className="font-mono">{imp.archivo_hash.slice(0, 16)}…</dd>
          <dt className="text-ink-muted">Período AFIP</dt>
          <dd>{imp.periodo_afip ?? <span className="text-ink-muted">—</span>}</dd>
          <dt className="text-ink-muted">Filas totales</dt>
          <dd>{imp.filas_totales}</dd>
          <dt className="text-ink-muted">Errores</dt>
          <dd>{imp.filas_error}</dd>
          <dt className="text-ink-muted">Facturas vinculadas</dt>
          <dd>{imp.facturas_count ?? 0}</dd>
          <dt className="text-ink-muted">Estado</dt>
          <dd><Badge variant={ESTADO_BADGES[imp.estado]}>{imp.estado}</Badge></dd>
          <dt className="text-ink-muted">Importado el</dt>
          <dd>{fmtDate(imp.importado_at)}</dd>
        </dl>

        <div className="bg-red-50 border border-red-200 rounded p-2 text-[11px] space-y-1">
          <div className="flex items-start gap-1.5">
            <AlertTriangle className="w-3 h-3 text-red-700 mt-[2px] flex-shrink-0" />
            <div>
              <strong>Esta acción es irreversible.</strong> Se borra el upload junto con su
              detalle de errores. El audit log registra el evento de manera inmutable.
              El hash queda liberado y podrás re-subir el mismo archivo.
            </div>
          </div>
        </div>

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">
            Motivo del borrado (opcional)
          </label>
          <textarea
            rows={2}
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
            maxLength={500}
            placeholder="Ej: Re-import post-fix encoding v1.19"
            className="w-full text-[12px] border border-azure-soft rounded px-2 py-1 focus:outline-none focus:border-azure"
          />
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <Button variant="outline" onClick={onClose} disabled={deleteMut.isPending}>
            Cancelar
          </Button>
          <Button
            variant="danger"
            onClick={() => deleteMut.mutate(motivo.trim() ? { motivo: motivo.trim() } : {})}
            disabled={deleteMut.isPending}
          >
            <Trash2 className="w-3 h-3" /> Borrar definitivamente
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
  // v1.19 D-19-3: checkbox de bypass eliminado. Si el período es cerrado,
  // el botón Confirmar queda disabled + mensaje claro.
  const [resultado, setResultado] = useState<ConfirmResp | null>(null);

  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-compras-imports']);

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
    (fd) => api.post('/api/erp/libro-iva-compras/import/preview', fd),
    {
      onSuccess: (r) => {
        setPreview(r);
        if (r.import_existente) {
          toast.error('Archivo duplicado',
            `Este archivo ya se importó (#${r.import_existente.id}, ${r.import_existente.estado})`);
          return;
        }
        // Auto-seleccionar período abierto del ejercicio actual.
        const abierto = periodos?.find((p) => p.estado === 'ABIERTO');
        if (abierto) setPeriodoId(String(abierto.id));
        setStep(2);
      },
      onError: (e) => toast.error('Error al previsualizar', errorMessage(e)),
    }
  );

  const confirmMut = useApiMutation<ConfirmResp, FormData>(
    (fd) => api.post('/api/erp/libro-iva-compras/import/confirmar', fd),
    {
      onSuccess: (r) => {
        setResultado(r);
        setStep(3);
        invalidate();
        toast.success('Import procesado',
          `${r.stats.tomadas} tomadas / ${r.stats.no_tomadas} no_tomadas / ${r.stats.errores} errores`);
      },
      onError: (e) => {
        const apiErr = e as ApiError;
        toast.error('Error al confirmar', apiErr.message);
      },
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
    // v1.19: no se envía confirmar_periodo_cerrado — el bypass se eliminó.
    confirmMut.mutate(fd);
  };

  const titulo = step === 1 ? 'Subir archivo'
    : step === 2 ? 'Revisar y confirmar'
    : 'Resultado del import';

  return (
    <Modal open onClose={onClose} size="lg"
      title={`Wizard import Libro IVA Compras — paso ${step}/3 · ${titulo}`}
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
      }
    >
      {step === 1 && <Step1 archivo={archivo} setArchivo={setArchivo} error={previewMut.error} />}
      {step === 2 && preview && (
        <Step2
          preview={preview}
          periodos={periodos ?? []}
          periodoId={periodoId}
          setPeriodoId={setPeriodoId}
          periodoCerrado={periodoCerrado}
          error={confirmMut.error}
        />
      )}
      {step === 3 && resultado && <Step3 resultado={resultado} encodingDetectado={preview?.encoding_detectado ?? null} />}
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
        Subí el CSV crudo de AFIP "Mis Comprobantes" o el Excel enriquecido por el contador
        (con las 4 columnas extra). Hasta 50 MB. Encoding ISO-8859-1 / UTF-8.
      </div>

      <div className="border-2 border-dashed border-line rounded-lg p-6 text-center space-y-2 bg-surface-row">
        <FileSpreadsheet className="w-8 h-8 mx-auto text-ink-muted" />
        <input
          type="file"
          accept=".csv,.xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          onChange={(e) => setArchivo(e.target.files?.[0] ?? null)}
          className="block mx-auto text-[12px] file:mr-3 file:py-2 file:px-3 file:border-0 file:bg-azure file:text-white file:rounded-md file:cursor-pointer file:text-[12px] file:font-medium hover:file:bg-azure/90"
        />
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
  preview, periodos, periodoId, setPeriodoId,
  periodoCerrado, error,
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
      <div className="grid grid-cols-3 gap-2 text-[12px]">
        <Stat label="Filas totales" value={preview.filas_totales} />
        <Stat label="Tomadas (SI)" value={
          <Badge variant="success">{preview.filas_con_tomado_si}</Badge>
        } />
        <Stat label="No tomadas (NO)" value={
          <Badge variant="warning">{preview.filas_con_tomado_no}</Badge>
        } />
      </div>

      <div className="border border-line rounded-md p-3 space-y-2 bg-surface-row">
        <div className="text-[12px] font-semibold text-navy-800">Columnas extras detectadas en el archivo</div>
        <div className="flex flex-wrap gap-2">
          {EXTRAS_OBLIGATORIAS.map((c) => (
            <div key={c} className={`text-[11.5px] px-2 py-1 rounded border flex items-center gap-1 ${
              cobertura(c)
                ? 'border-success/30 bg-success-bg/20 text-success'
                : 'border-line bg-white text-ink-muted'
            }`}>
              {cobertura(c) ? <Check className="w-3 h-3" /> : '○'}
              <code>{c}</code>
            </div>
          ))}
        </div>
        {detectadas.length === 0 && (
          <div className="text-[11px] text-ink-muted italic">
            No se detectaron columnas extras — todas las facturas se procesarán como <code>Tomado=SI</code> (default).
          </div>
        )}
      </div>

      {preview.periodo_afip && (
        <div className="text-[11.5px] text-ink-muted">
          Período AFIP detectado del nombre del archivo: <strong>{preview.periodo_afip}</strong>
        </div>
      )}

      <SelectField label="Período de imputación contable" required
        value={periodoId} placeholder="Elegí un período"
        onChange={(e) => setPeriodoId(e.target.value)}
        options={opciones}
        hint="Todas las facturas con Tomado=SI se imputarán a este período."
        containerClassName="w-full" />

      {/* v1.19 D-19-3: el bypass se eliminó. Si el período está cerrado, el
          botón Confirmar queda disabled y se muestra el camino correcto. */}
      {periodoCerrado && (
        <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-3 space-y-1">
          <div className="flex items-center gap-1 text-[12px] font-semibold text-warning">
            <AlertTriangle className="w-3.5 h-3.5" /> El período está cerrado
          </div>
          <div className="text-[11.5px] text-ink">
            Para importar, primero <strong>reabrilo</strong> desde{' '}
            <code>Contabilidad → Períodos → Reabrir</code>. Una vez terminado el import,
            podés cerrarlo de nuevo. Cada paso queda en audit log.
          </div>
        </div>
      )}

      <FormError error={error ? errorMessage(error) : null} />
    </div>
  );
}

function Step3({ resultado, encodingDetectado }: { resultado: ConfirmResp; encodingDetectado: string | null }) {
  const { stats, errores, clientes_no_mapeados } = resultado;
  // v1.19 D-19-6: si 100% filas fallan, banner rojo + diagnóstico específico.
  const totalProcesado = stats.tomadas + stats.no_tomadas;
  const todoFallido = stats.errores > 0 && totalProcesado === 0;
  const descargarCsvErrores = () => {
    const url = `/api/erp/libro-iva-compras/imports/${resultado.import_id}/errores.csv`;
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `errores_import_${resultado.import_id}.csv`;
        a.click();
      });
  };
  return (
    <div className="space-y-4">
      {todoFallido ? (
        // v1.19 D-19-6: banner rojo prominente cuando 100% filas fallan.
        <div className="border border-danger/40 bg-danger-bg/30 rounded-md p-4 space-y-2">
          <div className="flex items-center gap-2 text-[14px] font-bold text-danger">
            <AlertTriangle className="w-4 h-4" />
            Ninguna fila pudo procesarse
          </div>
          <div className="text-[12px] text-ink">
            Causa probable: encoding del archivo o headers incompatibles. Verificá
            que las columnas obligatorias estén presentes y el archivo sea CSV/Excel
            válido.
            {encodingDetectado && encodingDetectado !== 'UTF-8' && encodingDetectado !== 'XLSX' && (
              <> El archivo fue interpretado como <strong>{encodingDetectado}</strong>.
                Si los errores siguen, revisá las columnas obligatorias contra la
                plantilla AFIP.</>
            )}
          </div>
        </div>
      ) : (
        <div className="border border-success/30 bg-success-bg/20 rounded-md p-3 text-center">
          <div className="text-[14px] font-semibold text-success">
            ✓ Import #{resultado.import_id} procesado
          </div>
          {encodingDetectado && encodingDetectado !== 'UTF-8' && encodingDetectado !== 'XLSX' && (
            <div className="text-[11px] text-ink-muted mt-1">
              Archivo leído como <code>{encodingDetectado}</code>, convertido a UTF-8 para procesar.
            </div>
          )}
        </div>
      )}

      <div className="grid grid-cols-3 gap-2 text-[12px]">
        <Stat label="Totales" value={stats.totales} />
        <Stat label="Tomadas" value={<Badge variant="success">{stats.tomadas}</Badge>} />
        <Stat label="No tomadas" value={<Badge variant="warning">{stats.no_tomadas}</Badge>} />
        <Stat label="Skipped" value={stats.skipped} />
        <Stat label="Errores" value={
          stats.errores ? <Badge variant="danger">{stats.errores}</Badge> : 0
        } />
        <Stat label="Proveedores nuevos" value={stats.proveedores_creados} />
        <Stat label="Clientes mapeados" value={
          <Badge variant="success">{stats.clientes_mapeados}</Badge>
        } />
        <Stat label="Clientes sin mapear" value={
          stats.clientes_no_mapeados
            ? <Badge variant="warning">{stats.clientes_no_mapeados}</Badge>
            : 0
        } />
      </div>

      {clientes_no_mapeados.length > 0 && (
        <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-3 space-y-1">
          <div className="text-[12px] font-semibold text-warning">
            Clientes no mapeados ({clientes_no_mapeados.length})
          </div>
          <div className="text-[11px] text-ink-muted">
            Estos valores del Excel del contador no coincidieron con ningún cliente de DistriApp.
            Las facturas se importaron con <code>cliente_id=NULL</code>; podés mapearlas manualmente
            desde el detalle de la factura.
          </div>
          <ul className="text-[11.5px] space-y-0.5 max-h-[120px] overflow-y-auto">
            {clientes_no_mapeados.slice(0, 30).map((c, i) => (
              <li key={i}>
                <code>"{c.valor}"</code> — {c.filas.length} fila{c.filas.length === 1 ? '' : 's'}
              </li>
            ))}
            {clientes_no_mapeados.length > 30 && (
              <li className="text-ink-muted">… y {clientes_no_mapeados.length - 30} más</li>
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
              // v1.19 D-19-5: render robusto que soporta los aliases del backend
              // ({row, motivo}) y los del frontend antiguo ({fila, codigo, detalle}).
              const fila = e.row ?? e.fila ?? '?';
              const rawMsg = (e.motivo ?? e.mensaje ?? e.detalle ?? '').trim();
              // Extraer código prefijado tipo "CUENTA_X: mensaje".
              let codigo = e.codigo ?? '';
              let msg = rawMsg;
              if (! codigo) {
                const m = rawMsg.match(/^([A-Z_][A-Z0-9_]*):\s*(.+)$/);
                if (m) { codigo = m[1]; msg = m[2]; }
              }
              return (
                <li key={i}>
                  <strong>Fila {fila}:</strong>
                  {e.numero ? ` (nro ${e.numero})` : ''} {msg || '—'}
                  {codigo && <code className="ml-2 text-[10.5px] bg-danger/10 px-1 rounded">[{codigo}]</code>}
                </li>
              );
            })}
            {errores.length > 20 && (
              <li className="text-ink-muted italic">… {errores.length - 20} más en el CSV de descarga</li>
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
