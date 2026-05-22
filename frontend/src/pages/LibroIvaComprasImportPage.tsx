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
  warnings_count?: number; // v1.22
  // v1.22 sumó OK_CON_WARNINGS y ERROR_TOTAL; PARCIAL queda legacy en uploads viejos.
  estado: 'OK' | 'ERROR' | 'PARCIAL' | 'COMPLETO' | 'PROCESANDO' | 'OK_CON_WARNINGS' | 'ERROR_TOTAL';
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
  columnas_obligatorias_faltantes?: string[]; // v1.30
  apoc?: { // v1.28
    total_consultados: number;
    activos_count: number;
    inactivos: Array<{ cuit: string; estado: string; razon_social: string | null; filas_afectadas: number[] }>;
    errores: Array<{ cuit: string; motivo: string }>;
    ws_caido: boolean;
  };
  import_existente: { id: number; importado_at: string; estado: string } | null;
};

type ConfirmResp = {
  import_id: number;
  estado?: string; // v1.22 — OK/COMPLETO/OK_CON_WARNINGS/ERROR_TOTAL
  stats: {
    totales: number; tomadas: number; no_tomadas: number; skipped: number;
    errores: number; warnings?: number; // v1.22
    proveedores_creados: number;
    clientes_mapeados: number; clientes_no_mapeados: number;
  };
  // El backend devuelve { row, motivo }. Mantenemos los aliases por compat.
  errores: Array<{
    row?: number; fila?: number;
    motivo?: string; mensaje?: string; codigo?: string; detalle?: string;
    numero?: number;
  }>;
  warnings?: Array<{ row?: number; motivo?: string }>; // v1.22
  // El backend envía un elemento por ocurrencia con { row, valor }. El
  // frontend agrupa por valor antes de mostrar.
  clientes_no_mapeados: Array<{ row?: number; valor: string }>;
};

const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// v1.13 introdujo 4 columnas extras (tomado/cliente/observaciones/tipo).
// v1.14 sumó 2 más (periodo trabajado/jurisdiccion).
// v1.40 sumó 2 más (op + fecha de pago).
// v1.30 — separamos obligatorias vs opcionales (jurisdiccion + op + fecha de pago
// siguen opcionales). El backend valida y bloquea si falta alguna obligatoria.
const EXTRAS_TODAS = [
  'tomado', 'cliente', 'observaciones', 'tipo',
  'periodo trabajado', 'jurisdiccion',
  'op', 'fecha de pago',
];
const EXTRAS_OBLIGATORIAS_SET = new Set([
  'tomado', 'cliente', 'observaciones', 'tipo', 'periodo trabajado',
]);

const ESTADO_BADGES: Record<Import['estado'], 'success' | 'danger' | 'warning'> = {
  OK: 'success',
  COMPLETO: 'success', // v1.22 — equivalente a OK
  PROCESANDO: 'warning',
  ERROR: 'danger',
  PARCIAL: 'warning', // legacy
  OK_CON_WARNINGS: 'warning', // v1.22
  ERROR_TOTAL: 'danger',      // v1.22
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
        // v1.22 §13: si tiene facturas + permiso, el botón sigue clickeable y
        // el modal ofrece la opción de cascada.
        if (r.puede_borrar || tieneFacturas) {
          return (
            <button
              type="button"
              onClick={() => setBorrarTarget(r)}
              className="text-red-600 hover:text-red-800 p-1 rounded transition"
              title={tieneFacturas
                ? `Este import generó ${r.facturas_count} facturas. El modal te ofrece borrarlas en cascada.`
                : 'Borrar upload'}
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
            Las 8 columnas extras (<code>Tomado</code>, <code>Cliente</code>, <code>Observaciones</code>,{' '}
            <code>Tipo</code>, <code>Período trabajado</code>, <code>Jurisdicción</code>,{' '}
            <code>OP</code>, <code>Fecha de pago</code>) son opcionales.
            Las facturas con <code>Tomado=NO</code> se importan sin generar asiento ni
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
  // v1.22 §13 — si el import tiene facturas, requerir checkbox para cascada.
  const tieneFacturas = (imp.facturas_count ?? 0) > 0;
  const [cascada, setCascada] = useState(false);
  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-compras-imports']);
  const facturasInvalidate = useInvalidate(['facturas-compra']);

  const deleteMut = useApiMutation<unknown, { motivo?: string }>(
    (body) => {
      const qs = tieneFacturas && cascada ? '?cascada=true' : '';
      return api.delete(`/api/erp/libro-iva-compras/imports/${imp.id}${qs}`, body);
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
            'El import tiene facturas vinculadas. Marcá "cascada" para borrar todo, o desvinculalas primero.');
          return;
        }
        if (e instanceof ApiError && e.status === 422) {
          const payload = e.payload as { error?: { code?: string } };
          if (payload.error?.code === 'PERIODO_CERRADO_EN_SELECCION') {
            toast.error('Período cerrado',
              'Hay facturas en período cerrado. Reabrí el período antes de borrar.');
            return;
          }
          if (payload.error?.code === 'FACTURA_CONCILIADA') {
            toast.error('Factura conciliada',
              'Hay facturas con pagos asociados. Desconciliá primero.');
            return;
          }
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

        {tieneFacturas && (
          // v1.22 §13 — checkbox de cascada cuando hay facturas vinculadas.
          <div className="border border-warning/40 bg-warning-bg/30 rounded p-2 text-[11px] space-y-2">
            <div className="flex items-start gap-1.5">
              <AlertTriangle className="w-3 h-3 text-warning mt-[2px] flex-shrink-0" />
              <div>
                Este import tiene <strong>{imp.facturas_count}</strong> facturas con asientos.
                Para borrar todo en cascada (facturas + asientos + upload), marcá la opción de abajo.
                Solo aplica en período <strong>ABIERTO</strong>.
              </div>
            </div>
            <label className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                checked={cascada}
                onChange={(e) => setCascada(e.target.checked)}
              />
              <span>Borrar en cascada (anular facturas + asientos)</span>
            </label>
          </div>
        )}

        <div className="bg-red-50 border border-red-200 rounded p-2 text-[11px] space-y-1">
          <div className="flex items-start gap-1.5">
            <AlertTriangle className="w-3 h-3 text-red-700 mt-[2px] flex-shrink-0" />
            <div>
              <strong>Esta acción es irreversible.</strong> El audit log registra el evento
              de manera inmutable. {tieneFacturas && cascada
                ? <>Los asientos se borran <strong>físicamente</strong> (no se generan reversas).</>
                : <>El hash queda liberado y podrás re-subir el mismo archivo.</>}
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
            placeholder="Ej: Limpieza de tests del import"
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
            disabled={deleteMut.isPending || (tieneFacturas && !cascada)}
          >
            <Trash2 className="w-3 h-3" />
            {tieneFacturas && cascada
              ? `Borrar todo (cascada)`
              : 'Borrar definitivamente'}
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
  // v1.28 — override APOC con motivo (sólo se envía si hay inactivos).
  const [apocOverrideMotivo, setApocOverrideMotivo] = useState('');

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
        // v1.22 — distinguir OK / OK_CON_WARNINGS / ERROR_TOTAL en el toast.
        if (r.estado === 'ERROR_TOTAL' || r.stats.errores > 0) {
          toast.error('Import rechazado',
            `${r.stats.errores} errores. Ninguna fila fue importada (atomicidad TODO-O-NADA).`);
        } else if (r.stats.warnings && r.stats.warnings > 0) {
          toast.success('Import procesado con warnings',
            `${r.stats.tomadas} tomadas / ${r.stats.no_tomadas} no_tomadas · ${r.stats.warnings} warnings`);
        } else {
          toast.success('Import procesado',
            `${r.stats.tomadas} tomadas / ${r.stats.no_tomadas} no_tomadas`);
        }
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
    // v1.28 — Si hay APOC inactivos y el operador escribió motivo, lo enviamos.
    if (preview?.apoc && preview.apoc.inactivos.length > 0 && apocOverrideMotivo.trim().length >= 10) {
      fd.append('apoc_override_motivo', apocOverrideMotivo.trim());
    }
    confirmMut.mutate(fd);
  };

  // v1.28 — bloqueo extra del botón si hay inactivos sin motivo válido.
  const apocBloquea = (preview?.apoc?.inactivos?.length ?? 0) > 0
    && apocOverrideMotivo.trim().length < 10;

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
                disabled={!periodoId || periodoCerrado || confirmMut.isPending
                  || (preview?.columnas_obligatorias_faltantes?.length ?? 0) > 0
                  || apocBloquea}
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
          apocOverrideMotivo={apocOverrideMotivo}
          setApocOverrideMotivo={setApocOverrideMotivo}
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
        (con las 6 columnas extra). Hasta 50 MB. Encoding ISO-8859-1 / UTF-8.
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
  periodoCerrado, apocOverrideMotivo, setApocOverrideMotivo, error,
}: {
  preview: PreviewResp;
  periodos: Periodo[];
  periodoId: string;
  setPeriodoId: (s: string) => void;
  periodoCerrado: boolean;
  apocOverrideMotivo: string;
  setApocOverrideMotivo: (s: string) => void;
  error: ApiError | null;
}) {
  const opciones = periodos.map((p) => ({
    value: String(p.id),
    label: `${MESES[p.mes]} ${p.anio} (${p.estado})`,
  }));

  const detectadas = preview.columnas_extras_detectadas;
  const cobertura = (col: string) => detectadas.includes(col);
  // v1.30 — el backend ya calcula faltantes; fallback si no vino.
  const obligatoriasFaltantes = preview.columnas_obligatorias_faltantes
    ?? Array.from(EXTRAS_OBLIGATORIAS_SET).filter((c) => !cobertura(c));

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
          {EXTRAS_TODAS.map((c) => {
            const obligatoria = EXTRAS_OBLIGATORIAS_SET.has(c);
            const ok = cobertura(c);
            const falta = obligatoria && !ok;
            return (
              <div key={c} className={`text-[11.5px] px-2 py-1 rounded border flex items-center gap-1 ${
                ok
                  ? 'border-success/30 bg-success-bg/20 text-success'
                  : falta
                    ? 'border-danger/40 bg-danger-bg/30 text-danger'
                    : 'border-line bg-white text-ink-muted'
              }`}>
                {ok ? <Check className="w-3 h-3" /> : falta ? <AlertTriangle className="w-3 h-3" /> : '○'}
                <code>{c}</code>
                {obligatoria && <span className="text-[9.5px] opacity-70">*</span>}
              </div>
            );
          })}
        </div>
        <div className="text-[10.5px] text-ink-muted">
          <span className="text-danger font-semibold">*</span> obligatorias (v1.30) — sin ellas el import se bloquea.
          {' '}<code>jurisdiccion</code>, <code>op</code> y <code>fecha de pago</code> son opcionales.
        </div>
        {obligatoriasFaltantes.length > 0 && (
          <div className="text-[11.5px] text-danger flex items-start gap-1.5 mt-1">
            <AlertTriangle className="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
            <div>
              Faltan columnas obligatorias: <strong>{obligatoriasFaltantes.join(', ')}</strong>.
              <br />Completalas con bulk-edit del v1.26 o re-subí el CSV con las columnas faltantes.
            </div>
          </div>
        )}
      </div>

      {preview.periodo_afip && (
        <div className="text-[11.5px] text-ink-muted">
          Período AFIP detectado del nombre del archivo: <strong>{preview.periodo_afip}</strong>
        </div>
      )}

      {/* v1.28 — Validación APOC AFIP */}
      {preview.apoc && (
        <div className="border border-line rounded-md p-3 space-y-2 bg-surface-row">
          <div className="text-[12px] font-semibold text-navy-800">
            Validación APOC AFIP
            <span className="ml-2 text-[10.5px] text-ink-muted">
              ({preview.apoc.total_consultados} CUITs únicos · cache 30d)
            </span>
          </div>

          {preview.apoc.ws_caido && (
            <div className="border border-danger/40 bg-danger-bg/20 rounded p-2 text-[11.5px] text-danger flex items-start gap-1.5">
              <AlertTriangle className="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
              <div>
                <strong>WS APOC AFIP no responde.</strong> Reintentá más tarde o contactá soporte.
                Mientras el WS esté caído, se requiere override + motivo para importar.
              </div>
            </div>
          )}

          {preview.apoc.inactivos.length === 0 && !preview.apoc.ws_caido && (
            <div className="text-[11.5px] text-success flex items-center gap-1">
              <Check className="w-3.5 h-3.5" />
              Todos los CUITs están <strong>ACTIVOS</strong> en padrón AFIP.
            </div>
          )}

          {preview.apoc.inactivos.length > 0 && (
            <div className="border border-warning/40 bg-warning-bg/20 rounded p-2 space-y-1.5">
              <div className="text-[11.5px] font-semibold text-warning flex items-center gap-1">
                <AlertTriangle className="w-3.5 h-3.5" />
                {preview.apoc.inactivos.length} CUIT{preview.apoc.inactivos.length === 1 ? '' : 's'} no activo{preview.apoc.inactivos.length === 1 ? '' : 's'} en padrón
              </div>
              <div className="max-h-40 overflow-auto border border-warning/20 rounded bg-white">
                <table className="w-full text-[11px]">
                  <thead className="bg-warning-bg/30 sticky top-0"><tr>
                    <th className="px-2 py-1 text-left">CUIT</th>
                    <th className="text-left">Estado AFIP</th>
                    <th className="text-left">Razón social</th>
                    <th className="text-right">Filas afectadas</th>
                  </tr></thead>
                  <tbody>
                    {preview.apoc.inactivos.map((i) => (
                      <tr key={i.cuit} className="border-t border-warning/10">
                        <td className="px-2 py-0.5 font-mono">{i.cuit}</td>
                        <td className="font-semibold text-warning">{i.estado}</td>
                        <td>{i.razon_social ?? '—'}</td>
                        <td className="text-right tabular">{i.filas_afectadas.length}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="pt-1">
                <label className="block text-[11px] text-ink-muted mb-1">
                  Motivo del override (obligatorio · mín 10 chars) <span className="text-danger">*</span>
                </label>
                <textarea rows={2} value={apocOverrideMotivo} onChange={(e) => setApocOverrideMotivo(e.target.value)}
                  maxLength={500}
                  placeholder="Ej: el proveedor regularizó posteriormente; comprobante histórico, etc."
                  className="w-full px-2 py-1 text-[11.5px] border border-warning/40 rounded focus:outline-none focus:border-warning" />
                <div className="text-[10px] text-ink-muted mt-0.5">
                  {apocOverrideMotivo.length} / 500 — {apocOverrideMotivo.trim().length < 10 ? `faltan ${10 - apocOverrideMotivo.trim().length}` : '✓'}
                  {' · '}requiere permiso <code>compras.libro_iva.import_override_apoc</code>
                </div>
              </div>
            </div>
          )}
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
  const warnings = resultado.warnings ?? [];
  // v1.22 — con atomicidad TODO-O-NADA, si hay errores > 0 → nada se importó.
  const rollbackTotal = stats.errores > 0;
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
      {rollbackTotal ? (
        // v1.22 D-22-2: atomicidad TODO-O-NADA — si hay errores, nada se importó.
        <div className="border border-danger/40 bg-danger-bg/30 rounded-md p-4 space-y-2">
          <div className="flex items-center gap-2 text-[14px] font-bold text-danger">
            <AlertTriangle className="w-4 h-4" />
            Import rechazado — ninguna fila fue importada
          </div>
          <div className="text-[12px] text-ink">
            El archivo tiene <strong>{stats.errores}</strong> errores. Por seguridad contable, el
            sistema NO importa parcialmente: o entran todas las filas o ninguna. Revisá los
            errores abajo, corregí el archivo y re-subí.
            {encodingDetectado && encodingDetectado !== 'UTF-8' && encodingDetectado !== 'XLSX' && (
              <> El archivo fue interpretado como <strong>{encodingDetectado}</strong>.</>
            )}
          </div>
        </div>
      ) : warnings.length > 0 ? (
        // v1.22 — OK_CON_WARNINGS: facturas importadas + advertencias no bloqueantes.
        <div className="border border-warning/40 bg-warning-bg/30 rounded-md p-4 space-y-2">
          <div className="flex items-center gap-2 text-[14px] font-bold text-warning">
            <AlertTriangle className="w-4 h-4" />
            ✓ Import #{resultado.import_id} procesado con {warnings.length} warning{warnings.length === 1 ? '' : 's'}
          </div>
          <div className="text-[12px] text-ink">
            Todas las filas se importaron correctamente. Revisá los warnings abajo —
            son advertencias no bloqueantes (ej: fechas en el borde del período).
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

      {clientes_no_mapeados.length > 0 && (() => {
        // Fix bug "Cannot read properties of undefined (reading 'length')":
        // el backend manda { row, valor } por ocurrencia, NO { valor, filas[] }.
        // Agrupamos en el frontend por valor → array de filas.
        const agrupados = (() => {
          const mapa = new Map<string, number[]>();
          for (const c of clientes_no_mapeados) {
            const filas = mapa.get(c.valor) ?? [];
            if (c.row !== undefined) filas.push(c.row);
            mapa.set(c.valor, filas);
          }
          return Array.from(mapa.entries()).map(([valor, filas]) => ({ valor, filas }));
        })();
        return (
          <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-3 space-y-1">
            <div className="text-[12px] font-semibold text-warning">
              Clientes no mapeados ({agrupados.length})
            </div>
            <div className="text-[11px] text-ink-muted">
              Estos valores del Excel del contador no coincidieron con ningún cliente de DistriApp.
              Las facturas se importaron con <code>cliente_id=NULL</code>; podés mapearlas manualmente
              desde el detalle de la factura.
            </div>
            <ul className="text-[11.5px] space-y-0.5 max-h-[120px] overflow-y-auto">
              {agrupados.slice(0, 30).map((c, i) => (
                <li key={i}>
                  <code>"{c.valor}"</code> — {c.filas.length} fila{c.filas.length === 1 ? '' : 's'}
                  {c.filas.length > 0 && c.filas.length <= 5 && (
                    <span className="text-ink-muted"> (fila{c.filas.length === 1 ? '' : 's'} {c.filas.join(', ')})</span>
                  )}
                </li>
              ))}
              {agrupados.length > 30 && (
                <li className="text-ink-muted">… y {agrupados.length - 30} más</li>
              )}
            </ul>
          </div>
        );
      })()}

      {warnings.length > 0 && (
        <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-3 space-y-2">
          <div className="text-[12px] font-semibold text-warning">
            Warnings ({warnings.length})
          </div>
          <ul className="text-[11.5px] space-y-0.5 max-h-[180px] overflow-y-auto">
            {warnings.slice(0, 20).map((w, i) => {
              const rawMsg = (w.motivo ?? '').trim();
              const m = rawMsg.match(/^([A-Z_][A-Z0-9_]*):\s*(.+)$/);
              const codigo = m?.[1] ?? '';
              const msg = m?.[2] ?? rawMsg;
              return (
                <li key={i}>
                  <strong>Fila {w.row ?? '?'}:</strong> {msg || '—'}
                  {codigo && <code className="ml-2 text-[10.5px] bg-warning/10 px-1 rounded">[{codigo}]</code>}
                </li>
              );
            })}
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
