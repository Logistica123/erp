import { useRef, useState } from 'react';
import { ShieldCheck, Upload, AlertTriangle, Eye } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api, ApiError } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Archivo = { nombre: string; path: string; size: number; hash: string };
type Campos = {
  cuit_emisor: string | null; cuit_receptor: string | null;
  tipo_comprobante: number | null; punto_venta: number | null; numero: number | null;
  fecha_emision: string | null; importe_total: number | null; cae: string | null;
  moneda: string | null; tipo_doc_receptor: number | null;
};
type ExtraccionResp = {
  archivo: Archivo;
  extraccion: { metodo: string; qr_detectado: boolean; ocr_aplicado: boolean; campos: Campos; campos_faltantes: string[]; raw_qr: string | null; raw_texto: string | null };
  previo?: { id: number; resultado_global: string; created_at: string } | null;
};
type Validacion = {
  id: number; archivo_nombre: string; metodo_extraccion: string;
  resultado_global: 'VALIDA' | 'INVALIDA' | 'APOCRIFA' | 'ERROR' | 'NO_PROCESABLE';
  nivel_confianza: 'ALTO' | 'MEDIO' | 'BAJO';
  estado_seguimiento: string;
  wscdc_resultado: string | null; apoc_estado: string | null;
  datos_extraidos: string | Record<string, unknown>;
  created_at: string;
  validado_por_nombre: string | null;
};
type ValidacionDetalle = Validacion & {
  archivo_path: string;
  qr_detectado: boolean; ocr_aplicado: boolean;
  wscdc_obs: string | null; wscdc_response_raw: string | null;
  apoc_motivo: string | null;
  observaciones_operador: string | null;
  fecha_revision: string | null;
  revisada_por_nombre: string | null;
};
type Alerta = {
  id: number; validacion_id: number; tipo_alerta: string;
  severidad: 'BAJA' | 'MEDIA' | 'ALTA' | 'CRITICA';
  mensaje: string; created_at: string;
  archivo_nombre: string | null; resultado_global: string | null;
};

const RESULTADO_VARIANT: Record<string, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  VALIDA: 'success', INVALIDA: 'danger', APOCRIFA: 'danger',
  ERROR: 'warning', NO_PROCESABLE: 'neutral',
};

export function ControlFacturasPage() {
  const [validarOpen, setValidarOpen] = useState(false);
  const [detalleId, setDetalleId] = useState<number | null>(null);
  const [filtros, setFiltros] = useState({ desde: '', hasta: '', resultado: '', seguimiento: '', cuit: '' });
  const [page, setPage] = useState(1);

  const qs = new URLSearchParams();
  Object.entries(filtros).forEach(([k, v]) => { if (v) qs.set(k, v); });
  if (page > 1) qs.set('page', String(page));

  const { data, isLoading, error } = useApi<Paginator<Validacion>>(
    ['ctlf-list', qs.toString()],
    `/api/erp/control-facturas?${qs}`,
  );

  const { data: alertas } = useApi<Alerta[]>(['ctlf-alertas'], '/api/erp/control-facturas/alertas');

  const columns: Column<Validacion>[] = [
    { key: 'created_at', header: 'Fecha', width: '110px', render: (r) => fmtDate(r.created_at) },
    { key: 'archivo_nombre', header: 'Archivo', render: (r) => <span title={r.archivo_nombre}>{r.archivo_nombre}</span> },
    { key: 'metodo_extraccion', header: 'Extracción', width: '90px',
      render: (r) => <Badge variant={r.metodo_extraccion === 'QR' ? 'success' : r.metodo_extraccion === 'OCR' ? 'info' : 'neutral'}>{r.metodo_extraccion}</Badge> },
    { key: 'resultado_global', header: 'Resultado', width: '120px',
      render: (r) => <Badge variant={RESULTADO_VARIANT[r.resultado_global] ?? 'neutral'}>{r.resultado_global}</Badge> },
    { key: 'nivel_confianza', header: 'Confianza', width: '100px',
      render: (r) => <Badge variant={r.nivel_confianza === 'ALTO' ? 'success' : r.nivel_confianza === 'MEDIO' ? 'info' : 'neutral'}>{r.nivel_confianza}</Badge> },
    { key: 'estado_seguimiento', header: 'Seguimiento', width: '160px',
      render: (r) => <span className="text-[11.5px]">{r.estado_seguimiento.replaceAll('_', ' ')}</span> },
    { key: 'validado_por_nombre', header: 'Por', render: (r) => r.validado_por_nombre ?? '—' },
    { key: 'acciones', header: '', width: '90px',
      render: (r) => <Button variant="secondary" onClick={() => setDetalleId(r.id)}><Eye className="w-3 h-3" /> Ver</Button> },
  ];

  return (
    <div className="p-6 space-y-4">
      {(alertas ?? []).length > 0 && (
        <Card>
          <CardBody className="p-3">
            <div className="flex items-center gap-2 text-warning">
              <AlertTriangle className="w-4 h-4" />
              <strong>{(alertas ?? []).length} alerta(s) no leída(s)</strong>
              <span className="text-ink-3 text-[12px]">— las más recientes:</span>
            </div>
            <div className="mt-2 space-y-1">
              {(alertas ?? []).slice(0, 5).map((a) => (
                <div key={a.id} className="text-[12px] flex items-center gap-2">
                  <Badge variant={a.severidad === 'CRITICA' ? 'danger' : a.severidad === 'ALTA' ? 'warning' : 'neutral'}>{a.severidad}</Badge>
                  <span className="text-ink-2">{a.mensaje}</span>
                  <button className="text-ink-3 text-[11px] hover:text-ink-2 underline"
                    onClick={() => setDetalleId(a.validacion_id)}>ver #{a.validacion_id}</button>
                </div>
              ))}
            </div>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><ShieldCheck className="w-4 h-4 text-azure" /> Control de facturas</div>}
          actions={
            <Button variant="primary" onClick={() => setValidarOpen(true)}>
              <Upload className="w-3 h-3" /> Validar factura nueva
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <Field label="Desde" type="date" value={filtros.desde}
              onChange={(e) => { setFiltros({ ...filtros, desde: e.target.value }); setPage(1); }}
              containerClassName="w-[150px]" />
            <Field label="Hasta" type="date" value={filtros.hasta}
              onChange={(e) => { setFiltros({ ...filtros, hasta: e.target.value }); setPage(1); }}
              containerClassName="w-[150px]" />
            <SelectField label="Resultado" value={filtros.resultado}
              onChange={(e) => { setFiltros({ ...filtros, resultado: e.target.value }); setPage(1); }}
              containerClassName="w-[180px]" placeholder="Todos"
              options={[
                { value: 'VALIDA', label: 'Válida' }, { value: 'INVALIDA', label: 'Inválida' },
                { value: 'APOCRIFA', label: 'Apócrifa' }, { value: 'ERROR', label: 'Error' },
                { value: 'NO_PROCESABLE', label: 'No procesable' },
              ]} />
            <SelectField label="Seguimiento" value={filtros.seguimiento}
              onChange={(e) => { setFiltros({ ...filtros, seguimiento: e.target.value }); setPage(1); }}
              containerClassName="w-[200px]" placeholder="Todos"
              options={[
                { value: 'PENDIENTE_REVISION', label: 'Pendiente' },
                { value: 'REVISADA_OK', label: 'Revisada OK' },
                { value: 'REVISADA_DESCARTADA', label: 'Descartada' },
                { value: 'ESCALADA', label: 'Escalada' },
              ]} />
            <Field label="CUIT emisor" value={filtros.cuit}
              onChange={(e) => { setFiltros({ ...filtros, cuit: e.target.value }); setPage(1); }}
              containerClassName="w-[160px]" placeholder="11 dígitos" />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={columns} paginator={data} loading={isLoading} onPageChange={setPage}
            empty="Sin validaciones todavía. Subí un PDF para empezar." />
        </CardBody>
      </Card>

      {validarOpen && <ValidarModal onClose={() => setValidarOpen(false)} />}
      {detalleId && <DetalleModal vid={detalleId} onClose={() => setDetalleId(null)} />}
    </div>
  );
}

function ValidarModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['ctlf-list'], ['ctlf-alertas']);
  const fileRef = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<ExtraccionResp | null>(null);
  const [campos, setCampos] = useState<Campos | null>(null);
  const [uploading, setUploading] = useState(false);

  async function onUpload(file: File) {
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('pdf', file);
      // Usa el cliente api (manda Authorization: Bearer + maneja FormData).
      const body = await api.post<{ ok: boolean; data: ExtraccionResp; error?: { message: string } }>(
        '/api/erp/control-facturas/extraer', fd,
      );
      if (body.ok === false) throw new ApiError(409, body, body.error?.message ?? 'Error extracción');
      setPreview(body.data);
      setCampos(body.data.extraccion.campos);
    } catch (e) {
      toast.error('No se pudo procesar el PDF', errorMessage(e));
    } finally {
      setUploading(false);
    }
  }

  const validar = useApiMutation<Validacion, Record<string, unknown>>(
    (v) => api.post('/api/erp/control-facturas/validar', v),
    {
      onSuccess: (data) => {
        const res = data.resultado_global;
        if (res === 'APOCRIFA') toast.error('CUIT en padrón APOC', `Validación #${data.id} — CRITICA.`);
        else if (res === 'INVALIDA') toast.error('Factura inválida', `Validación #${data.id}: WSCDC rechazó.`);
        else if (res === 'VALIDA') toast.success('Factura válida', `Validación #${data.id} confirmada.`);
        else toast.info('Resultado: ' + res, `Validación #${data.id}.`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo validar', errorMessage(e)),
    },
  );

  const canValidate = preview && campos && !preview.extraccion.campos_faltantes.length;

  return (
    <Modal open onClose={onClose} title="Validar factura nueva" size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          {preview && (
            <Button variant="primary" disabled={!canValidate || validar.isPending}
              onClick={() => validar.mutate({
                archivo: preview.archivo,
                metodo_extraccion: preview.extraccion.metodo,
                qr_detectado: preview.extraccion.qr_detectado,
                ocr_aplicado: preview.extraccion.ocr_aplicado,
                campos,
              })}>
              {validar.isPending ? 'Consultando AFIP…' : 'Validar contra AFIP'}
            </Button>
          )}
        </>
      }>
      {!preview && (
        <div className="space-y-3">
          <div className="border-2 border-dashed border-line rounded-md p-6 text-center"
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => { e.preventDefault(); const f = e.dataTransfer.files?.[0]; if (f) onUpload(f); }}>
            <Upload className="w-8 h-8 mx-auto text-ink-3" />
            <div className="mt-2 text-[12.5px] text-ink-2">
              Arrastrá un PDF o <button className="text-azure underline"
                onClick={() => fileRef.current?.click()}>elegí archivo</button>
            </div>
            <input ref={fileRef} type="file" accept="application/pdf" className="hidden"
              onChange={(e) => { const f = e.target.files?.[0]; if (f) onUpload(f); }} />
            {uploading && <div className="mt-3 text-ink-3 text-[12px]">Procesando PDF…</div>}
          </div>
          <div className="text-[11.5px] text-ink-3">
            Se extraen automáticamente CUIT emisor, tipo, PV, número, CAE, fecha y total
            (QR primero, OCR de respaldo). Después podés editar antes de validar.
          </div>
        </div>
      )}

      {preview && campos && (
        <div className="space-y-3 text-[12.5px]">
          <div className="bg-surface-row border border-line rounded-md p-2 flex items-center gap-3">
            <Badge variant={preview.extraccion.metodo === 'QR' ? 'success' : preview.extraccion.metodo === 'OCR' ? 'info' : 'neutral'}>
              {preview.extraccion.metodo}
            </Badge>
            <span className="text-ink-2">{preview.archivo.nombre}</span>
            {preview.extraccion.campos_faltantes.length > 0 && (
              <Badge variant="warning">faltan: {preview.extraccion.campos_faltantes.join(', ')}</Badge>
            )}
          </div>
          {preview.previo && (
            <div className="bg-warning-bg/30 border border-warning/40 rounded-md p-2 text-[11.5px]">
              ⚠ Este PDF ya fue validado el {fmtDate(preview.previo.created_at)} (#{preview.previo.id}, resultado={preview.previo.resultado_global}).
            </div>
          )}
          <div className="grid grid-cols-3 gap-3">
            <Field label="CUIT emisor *" value={campos.cuit_emisor ?? ''}
              onChange={(e) => setCampos({ ...campos, cuit_emisor: e.target.value })} />
            <SelectField label="Tipo comprobante *" value={String(campos.tipo_comprobante ?? '')}
              onChange={(e) => setCampos({ ...campos, tipo_comprobante: e.target.value ? Number(e.target.value) : null })}
              options={[
                { value: '1', label: '1 - FA A' }, { value: '6', label: '6 - FA B' },
                { value: '11', label: '11 - FA C' }, { value: '3', label: '3 - NC A' },
                { value: '8', label: '8 - NC B' }, { value: '13', label: '13 - NC C' },
                { value: '2', label: '2 - ND A' }, { value: '7', label: '7 - ND B' },
                { value: '12', label: '12 - ND C' },
              ]} placeholder="—" />
            <Field label="Fecha emisión *" type="date" value={campos.fecha_emision ?? ''}
              onChange={(e) => setCampos({ ...campos, fecha_emision: e.target.value })} />
            <Field label="Punto venta *" type="number" value={String(campos.punto_venta ?? '')}
              onChange={(e) => setCampos({ ...campos, punto_venta: e.target.value ? Number(e.target.value) : null })} />
            <Field label="Número *" type="number" value={String(campos.numero ?? '')}
              onChange={(e) => setCampos({ ...campos, numero: e.target.value ? Number(e.target.value) : null })} />
            <Field label="Importe total *" type="number" step="0.01" value={String(campos.importe_total ?? '')}
              onChange={(e) => setCampos({ ...campos, importe_total: e.target.value ? Number(e.target.value) : null })} />
            <Field label="CAE *" value={campos.cae ?? ''}
              onChange={(e) => setCampos({ ...campos, cae: e.target.value })} />
            <Field label="CUIT receptor" value={campos.cuit_receptor ?? ''}
              onChange={(e) => setCampos({ ...campos, cuit_receptor: e.target.value })} />
            <Field label="Moneda" value={campos.moneda ?? 'PES'}
              onChange={(e) => setCampos({ ...campos, moneda: e.target.value })} />
          </div>
          {validar.error && <FormError error={errorMessage(validar.error)} />}
        </div>
      )}
    </Modal>
  );
}

function DetalleModal({ vid, onClose }: { vid: number; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['ctlf-list'], ['ctlf-alertas']);
  const { data, isLoading } = useApi<{ validacion: ValidacionDetalle; alertas: Alerta[] }>(
    ['ctlf-detalle', vid],
    `/api/erp/control-facturas/${vid}`,
  );
  const [seg, setSeg] = useState({ estado: '', obs: '' });
  const segMut = useApiMutation<{ ok: true }, Record<string, unknown>>(
    (v) => api.patch(`/api/erp/control-facturas/${vid}/seguimiento`, v),
    {
      onSuccess: () => { toast.success('Seguimiento actualizado'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo guardar', errorMessage(e)),
    },
  );

  const v = data?.validacion;
  const datos = v && typeof v.datos_extraidos === 'string'
    ? (JSON.parse(v.datos_extraidos) as Campos)
    : (v?.datos_extraidos as Campos | undefined);

  return (
    <Modal open onClose={onClose} title={`Validación #${vid}`} size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}>
      {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
      {v && (
        <div className="space-y-3 text-[12.5px]">
          <div className="grid grid-cols-3 gap-3">
            <KpiBox label="Resultado" value={v.resultado_global}
              variant={RESULTADO_VARIANT[v.resultado_global] ?? 'neutral'} />
            <KpiBox label="Confianza" value={v.nivel_confianza}
              variant={v.nivel_confianza === 'ALTO' ? 'success' : v.nivel_confianza === 'MEDIO' ? 'info' : 'neutral'} />
            <KpiBox label="Método" value={v.metodo_extraccion} variant="neutral" />
          </div>

          {datos && (
            <div className="border border-line rounded-md p-3 grid grid-cols-2 gap-2">
              <div><span className="text-ink-3 text-[11px]">CUIT emisor:</span> <strong>{datos.cuit_emisor}</strong></div>
              <div><span className="text-ink-3 text-[11px]">CUIT receptor:</span> {datos.cuit_receptor ?? '—'}</div>
              <div><span className="text-ink-3 text-[11px]">Comprobante:</span> tipo {datos.tipo_comprobante} · PV {datos.punto_venta} · Nro {datos.numero}</div>
              <div><span className="text-ink-3 text-[11px]">Fecha:</span> {datos.fecha_emision ? fmtDate(datos.fecha_emision) : '—'}</div>
              <div><span className="text-ink-3 text-[11px]">Total:</span> <strong>{fmtMoney(datos.importe_total ?? 0)}</strong></div>
              <div><span className="text-ink-3 text-[11px]">CAE:</span> <code className="text-[11px]">{datos.cae}</code></div>
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div className="border border-line rounded-md p-3">
              <div className="text-ink-3 text-[11px] uppercase">WSCDC</div>
              <div className="mt-1">Resultado: <Badge variant={v.wscdc_resultado === 'A' ? 'success' : v.wscdc_resultado === 'R' ? 'danger' : 'neutral'}>{v.wscdc_resultado ?? '—'}</Badge></div>
              {v.wscdc_obs && <div className="text-ink-3 text-[11.5px] mt-1">{v.wscdc_obs}</div>}
            </div>
            <div className="border border-line rounded-md p-3">
              <div className="text-ink-3 text-[11px] uppercase">APOC (padrón)</div>
              <div className="mt-1">Estado: <Badge variant={v.apoc_estado === 'NO_APOC' ? 'success' : v.apoc_estado === 'EN_APOC' ? 'danger' : 'neutral'}>{v.apoc_estado ?? '—'}</Badge></div>
              {v.apoc_motivo && <div className="text-ink-3 text-[11.5px] mt-1">{v.apoc_motivo}</div>}
            </div>
          </div>

          {(data?.alertas ?? []).length > 0 && (
            <div>
              <div className="text-ink-3 text-[11px] uppercase font-semibold mb-1">Alertas</div>
              <div className="space-y-1">
                {data!.alertas.map((a) => (
                  <div key={a.id} className="border border-line rounded-md p-2 flex items-center gap-2">
                    <Badge variant={a.severidad === 'CRITICA' ? 'danger' : a.severidad === 'ALTA' ? 'warning' : 'neutral'}>{a.severidad}</Badge>
                    <span>{a.mensaje}</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="border-t border-line pt-3">
            <div className="text-ink-3 text-[11px] uppercase font-semibold mb-1">Seguimiento</div>
            <div className="text-[11.5px] mb-2">
              Actual: <strong>{v.estado_seguimiento.replaceAll('_', ' ')}</strong>
              {v.revisada_por_nombre && <> · por {v.revisada_por_nombre} {v.fecha_revision ? fmtDate(v.fecha_revision) : ''}</>}
              {v.observaciones_operador && <div className="text-ink-3 mt-1">{v.observaciones_operador}</div>}
            </div>
            <div className="grid grid-cols-2 gap-2">
              <SelectField label="Nuevo estado" value={seg.estado}
                onChange={(e) => setSeg({ ...seg, estado: e.target.value })}
                options={[
                  { value: 'PENDIENTE_REVISION', label: 'Pendiente revisión' },
                  { value: 'REVISADA_OK', label: 'Revisada OK' },
                  { value: 'REVISADA_DESCARTADA', label: 'Revisada y descartada' },
                  { value: 'ESCALADA', label: 'Escalada' },
                ]} placeholder="Elegí…" />
              <TextareaField label="Observaciones" value={seg.obs} rows={2}
                onChange={(e) => setSeg({ ...seg, obs: e.target.value })} />
            </div>
            <div className="mt-2 text-right">
              <Button variant="primary" disabled={!seg.estado || segMut.isPending}
                onClick={() => segMut.mutate({ estado_seguimiento: seg.estado, observaciones_operador: seg.obs || undefined })}>
                {segMut.isPending ? 'Guardando…' : 'Actualizar seguimiento'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}

function KpiBox({ label, value, variant }: { label: string; value: string; variant: 'success' | 'danger' | 'warning' | 'neutral' | 'info' }) {
  return (
    <div className="border border-line rounded-md p-3">
      <div className="text-ink-3 text-[11px] uppercase">{label}</div>
      <div className="mt-1"><Badge variant={variant}>{value}</Badge></div>
    </div>
  );
}
