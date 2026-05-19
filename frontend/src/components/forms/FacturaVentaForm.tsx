import { useEffect, useState } from 'react';
import { ShieldCheck, AlertTriangle, Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { DecimalField } from '@/components/ui/DecimalField';
import { fmtMoney } from '@/lib/cn';
import { useQuery, useMutation } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { useToast } from '@/hooks/useToast';

/**
 * v1.39 — Formulario reusable de carga manual de factura de VENTA.
 *
 * Se separó de la página standalone (`FacturaVentaManualPage`) para poder
 * embeberlo en el wizard de importación batch de PDFs AFIP. Aplica las
 * mismas mejoras de UX que el v1.38 hizo en COMPRAS:
 *   - DecimalField (acepta '.' y ',')
 *   - Auto-IVA al cambiar el neto (21% por default)
 *   - Auto-detect cliente onBlur del CUIT (upsert si no existe)
 *   - Botón "Registrar y cargar otro" (modo standalone)
 *
 * Props:
 *   pdfFile        — si se pasa, se incluye en el POST (multipart) como adjunto.
 *   initialValues  — valores precargados (usado por el wizard al pasar de un
 *                    PDF al siguiente preservando ciertos campos).
 *   onSuccess      — callback con el id creado. Override del nav default.
 *   showCargarOtro — si false oculta el botón "Registrar y cargar otro".
 *                    En el wizard no aplica porque "siguiente" reemplaza eso.
 *   submitLabel    — texto del botón primary (default "Registrar").
 *   extraActions   — botones adicionales (ej: "Saltar" en el wizard).
 */

export type Catalogos = {
  clientes: { id: number; nombre: string; cuit: string | null; codigo: string;
    centro_costo_id: number | null; centro_costo_codigo: string | null }[];
  tipos_comprobante: { id: number; codigo_interno: string; nombre: string; letra: string | null; clase: string }[];
  alicuotas_iva: { id: number; codigo_interno: string; nombre: string; tasa: string }[];
  monedas: { id: number; codigo: string; nombre: string }[];
  jurisdicciones?: { codigo: string; nombre: string }[];
};

export type FacturaVentaFormValues = {
  tipo_comprobante_id: number;
  punto_venta: number;
  numero: number;
  fecha_emision: string;
  cliente_auxiliar_id: number;
  cuit_cliente: string;
  razon_social_cliente: string;
  moneda_id: number;
  imp_neto_gravado: number;
  imp_no_gravado: number;
  imp_exento: number;
  imp_iva: number;
  imp_total: number;
  cae: string;
  fecha_vto_cae: string;
  periodo_trabajado_texto: string;
  jurisdiccion_codigo: string;
  concepto_afip: number;
};

const today = () => new Date().toISOString().slice(0, 10);

export const defaultFormValues: FacturaVentaFormValues = {
  tipo_comprobante_id: 0,
  punto_venta: 1,
  numero: 0,
  fecha_emision: today(),
  cliente_auxiliar_id: 0,
  cuit_cliente: '',
  razon_social_cliente: '',
  moneda_id: 1,
  imp_neto_gravado: 0,
  imp_no_gravado: 0,
  imp_exento: 0,
  imp_iva: 0,
  imp_total: 0,
  cae: '',
  fecha_vto_cae: '',
  periodo_trabajado_texto: '',
  jurisdiccion_codigo: '',
  concepto_afip: 2,
};

type PdfExtractResp = {
  ok: boolean;
  campos: {
    codigo_afip: number | null;
    letra: string | null;
    punto_venta: number | null;
    numero: number | null;
    fecha_emision: string | null;
    cuit_cliente: string | null;
    razon_social_cliente: string | null;
    imp_neto_gravado: number | null;
    imp_iva: number | null;
    imp_total: number | null;
    alicuota: number | null;
    cae: string | null;
    fecha_vto_cae: string | null;
    periodo_trabajado_texto: string | null;
  };
  tipo_comprobante_id: number | null;
  raw_excerpt: string;
  warning: string | null;
};

type Props = {
  pdfFile?: File | null;
  initialValues?: Partial<FacturaVentaFormValues>;
  onSuccess?: (id: number, values: FacturaVentaFormValues) => void;
  showCargarOtro?: boolean;
  submitLabel?: string;
  extraActions?: React.ReactNode;
};

export function FacturaVentaForm({
  pdfFile,
  initialValues,
  onSuccess,
  showCargarOtro = true,
  submitLabel,
  extraActions,
}: Props) {
  const toast = useToast();
  const [verificacion, setVerificacion] = useState<{ verificada: boolean; resultado: Record<string, unknown> } | null>(null);
  // v1.39 — flag pintar rojo el campo razón social si CUIT no matchea cliente.
  const [clienteNoExiste, setClienteNoExiste] = useState(false);
  // v1.39 — "registrar y cargar otro": preserva cliente/fecha al guardar.
  const [cargarOtroMode, setCargarOtroMode] = useState(false);
  // v1.41 — estado de la extracción automática desde el PDF.
  const [extractando, setExtractando] = useState(false);
  const [extractInfo, setExtractInfo] = useState<{
    detectados: string[]; warning: string | null;
  } | null>(null);

  const { data: cats } = useQuery<Catalogos>({
    queryKey: ['fv-catalogos-manual'],
    queryFn: () => api.get('/api/erp/facturas-venta/catalogos'),
  });

  const [form, setForm] = useState<FacturaVentaFormValues>({
    ...defaultFormValues,
    ...(initialValues ?? {}),
  });

  // Re-aplicar initialValues si cambian (caso wizard cambiando de PDF).
  useEffect(() => {
    if (initialValues) {
      setForm((f) => ({ ...f, ...initialValues }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(initialValues ?? {})]);

  // v1.41 — Si llega un PDF, intentar extraer datos del backend (pdftotext +
  // regex). Los valores extraídos pisan los initialValues (más específicos).
  // El operador puede corregir cualquier campo si la extracción falló o sacó
  // un dato mal.
  useEffect(() => {
    if (!pdfFile) {
      setExtractInfo(null);
      return;
    }
    let cancelado = false;
    setExtractando(true);
    setExtractInfo(null);
    const fd = new FormData();
    fd.append('pdf', pdfFile);
    api.post<{ ok: boolean; data: PdfExtractResp }>('/api/erp/facturas-venta/pdf-extract', fd)
      .then((r) => {
        if (cancelado) return;
        const det = aplicarCamposExtraidos(r.data);
        setExtractInfo({ detectados: det, warning: r.data.warning });
      })
      .catch((e: ApiError) => {
        if (cancelado) return;
        toast.error('Error extrayendo PDF', e.message);
        setExtractInfo({ detectados: [], warning: e.message });
      })
      .finally(() => { if (!cancelado) setExtractando(false); });
    return () => { cancelado = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pdfFile]);

  // Aplica al form los campos no-null extraídos. Devuelve la lista de keys
  // que se completaron para mostrar feedback al usuario.
  const aplicarCamposExtraidos = (data: PdfExtractResp): string[] => {
    const c = data.campos;
    const cambios: Partial<FacturaVentaFormValues> = {};
    if (data.tipo_comprobante_id) cambios.tipo_comprobante_id = data.tipo_comprobante_id;
    if (c.punto_venta != null) cambios.punto_venta = c.punto_venta;
    if (c.numero != null) cambios.numero = c.numero;
    if (c.fecha_emision) cambios.fecha_emision = c.fecha_emision;
    if (c.cuit_cliente) cambios.cuit_cliente = c.cuit_cliente;
    if (c.razon_social_cliente) cambios.razon_social_cliente = c.razon_social_cliente;
    if (c.imp_neto_gravado != null) cambios.imp_neto_gravado = c.imp_neto_gravado;
    if (c.imp_iva != null) cambios.imp_iva = c.imp_iva;
    if (c.imp_total != null) cambios.imp_total = c.imp_total;
    if (c.cae) cambios.cae = c.cae;
    if (c.fecha_vto_cae) cambios.fecha_vto_cae = c.fecha_vto_cae;
    if (c.periodo_trabajado_texto) cambios.periodo_trabajado_texto = c.periodo_trabajado_texto;
    if (Object.keys(cambios).length > 0) {
      setForm((f) => ({ ...f, ...cambios }));
    }
    // Si vino CUIT, lanzamos el lookup para resolver el cliente_auxiliar_id.
    if (cambios.cuit_cliente && /^\d{11}$/.test(cambios.cuit_cliente)) {
      clienteLookup.mutate(cambios.cuit_cliente);
    }
    return Object.keys(cambios);
  };

  // Auto-fill cliente al elegir desde el dropdown.
  useEffect(() => {
    if (!form.cliente_auxiliar_id || !cats) return;
    const c = cats.clientes.find((x) => x.id === form.cliente_auxiliar_id);
    if (c?.cuit && form.cuit_cliente !== c.cuit) {
      setForm((f) => ({ ...f, cuit_cliente: c.cuit ?? '', razon_social_cliente: c.nombre }));
      setClienteNoExiste(false);
    }
  }, [form.cliente_auxiliar_id, cats, form.cuit_cliente]);

  // Auto-default tipo cbte.
  useEffect(() => {
    if (form.tipo_comprobante_id || !cats?.tipos_comprobante.length) return;
    const fb = cats.tipos_comprobante.find((t) => t.codigo_interno === 'FB');
    setForm((f) => ({ ...f, tipo_comprobante_id: fb?.id ?? cats.tipos_comprobante[0].id }));
  }, [form.tipo_comprobante_id, cats]);

  // Total = neto + iva + no_gravado + exento.
  useEffect(() => {
    const total = Number(form.imp_neto_gravado || 0) + Number(form.imp_iva || 0) +
      Number(form.imp_no_gravado || 0) + Number(form.imp_exento || 0);
    if (Math.abs(total - form.imp_total) > 0.005) {
      setForm((f) => ({ ...f, imp_total: +total.toFixed(2) }));
    }
  }, [form.imp_neto_gravado, form.imp_iva, form.imp_no_gravado, form.imp_exento, form.imp_total]);

  // v1.39 — CUIT lookup onBlur. Mismo patrón que en compras (v1.38 M1).
  // Acá usamos el endpoint específico de clientes; si no existe, queda en
  // estado "clienteNoExiste=true" → razón social en rojo. El backend lo
  // upserta al guardar (no hace falta crear el auxiliar manualmente).
  const clienteLookup = useMutation<{ data: { id: number; nombre: string } }, ApiError, string>({
    mutationFn: (cuit) => api.get(`/api/erp/auxiliares/by-cuit/${cuit}?tipo=Cliente`),
    onSuccess: (r) => {
      setForm((f) => ({
        ...f,
        cliente_auxiliar_id: r.data.id,
        razon_social_cliente: r.data.nombre || f.razon_social_cliente,
      }));
      setClienteNoExiste(false);
    },
    onError: () => {
      setForm((f) => ({ ...f, cliente_auxiliar_id: 0 }));
      setClienteNoExiste(true);
    },
  });

  const handleCuitBlur = () => {
    if (/^\d{11}$/.test(form.cuit_cliente) && !clienteLookup.isPending) {
      clienteLookup.mutate(form.cuit_cliente);
    }
  };

  const registrar = useMutation<{ data: { id: number } }, ApiError, void>({
    mutationFn: () => {
      // Si hay PDF, mandamos multipart. Sino JSON.
      if (pdfFile) {
        const fd = new FormData();
        const append = (k: string, v: unknown) => {
          if (v !== undefined && v !== null && v !== '') fd.append(k, String(v));
        };
        append('tipo_comprobante_id', form.tipo_comprobante_id);
        append('punto_venta', form.punto_venta);
        append('numero', form.numero);
        append('fecha_emision', form.fecha_emision);
        append('cliente_auxiliar_id', form.cliente_auxiliar_id || '');
        append('cuit_cliente', form.cuit_cliente);
        append('razon_social_cliente', form.razon_social_cliente);
        append('moneda_id', form.moneda_id);
        append('imp_neto_gravado', form.imp_neto_gravado);
        append('imp_no_gravado', form.imp_no_gravado);
        append('imp_exento', form.imp_exento);
        append('imp_iva', form.imp_iva);
        append('imp_total', form.imp_total);
        append('cae', form.cae);
        append('fecha_vto_cae', form.fecha_vto_cae);
        append('periodo_trabajado_texto', form.periodo_trabajado_texto);
        append('jurisdiccion_codigo', form.jurisdiccion_codigo);
        append('concepto_afip', form.concepto_afip);
        fd.append('pdf', pdfFile);
        return api.post('/api/erp/facturas-venta/manual', fd);
      }
      return api.post('/api/erp/facturas-venta/manual', {
        tipo_comprobante_id: form.tipo_comprobante_id,
        punto_venta: form.punto_venta,
        numero: form.numero,
        fecha_emision: form.fecha_emision,
        cliente_auxiliar_id: form.cliente_auxiliar_id || undefined,
        cuit_cliente: form.cuit_cliente || undefined,
        razon_social_cliente: form.razon_social_cliente || undefined,
        moneda_id: form.moneda_id,
        imp_neto_gravado: form.imp_neto_gravado,
        imp_no_gravado: form.imp_no_gravado,
        imp_exento: form.imp_exento,
        imp_iva: form.imp_iva,
        imp_total: form.imp_total,
        cae: form.cae || undefined,
        fecha_vto_cae: form.fecha_vto_cae || undefined,
        periodo_trabajado_texto: form.periodo_trabajado_texto || undefined,
        jurisdiccion_codigo: form.jurisdiccion_codigo || undefined,
        concepto_afip: form.concepto_afip,
      });
    },
    onSuccess: (r) => {
      toast.success('Factura registrada', `Manual #${r.data.id} · origen=MANUAL`);
      if (cargarOtroMode) {
        // Preservar cliente + fecha, resetear el resto.
        setForm((f) => ({
          ...defaultFormValues,
          tipo_comprobante_id: f.tipo_comprobante_id,
          cliente_auxiliar_id: f.cliente_auxiliar_id,
          cuit_cliente: f.cuit_cliente,
          razon_social_cliente: f.razon_social_cliente,
          fecha_emision: f.fecha_emision,
        }));
        setCargarOtroMode(false);
        setVerificacion(null);
        return;
      }
      onSuccess?.(r.data.id, form);
    },
    onError: (e) => toast.error('No se pudo registrar', e.message),
  });

  const verificarArca = useMutation<{ data: { verificada: boolean; resultado: Record<string, unknown> } }, ApiError, number>({
    mutationFn: (id) => api.post(`/api/erp/facturas/venta/${id}/verificar-arca`),
    onSuccess: (r) => {
      setVerificacion(r.data);
      if (r.data.verificada) toast.success('Verificada contra ARCA ✓');
      else toast.error('No verificada', JSON.stringify(r.data.resultado));
    },
    onError: (e) => toast.error('Error verificando', e.message),
  });

  // Con CUIT manual + razón social, el cliente_auxiliar_id puede ser 0 — el
  // backend lo upserta. Solo exigimos CUIT + razón.
  const valid = form.tipo_comprobante_id && form.punto_venta > 0 && form.numero > 0
    && form.fecha_emision && /^\d{11}$/.test(form.cuit_cliente)
    && form.razon_social_cliente && form.imp_total > 0;

  const clienteSel = cats?.clientes.find((c) => c.id === form.cliente_auxiliar_id);

  return (
    <div className="space-y-4">
      {/* v1.41 — Feedback de extracción automática desde PDF. */}
      {extractando && (
        <div className="border border-azure-soft bg-azure-soft/30 rounded-md p-2 text-[12px] flex items-center gap-2">
          <Sparkles className="w-3.5 h-3.5 text-azure animate-pulse" />
          Extrayendo datos del PDF…
        </div>
      )}
      {!extractando && extractInfo && extractInfo.detectados.length > 0 && (
        <div className="border border-success/30 bg-success-bg/20 rounded-md p-2 text-[12px] flex items-start gap-2">
          <Sparkles className="w-3.5 h-3.5 text-success shrink-0 mt-0.5" />
          <div>
            <strong>{extractInfo.detectados.length} campos autocompletados del PDF.</strong>{' '}
            Revisalos y corregí si hace falta antes de guardar.
          </div>
        </div>
      )}
      {!extractando && extractInfo && extractInfo.detectados.length === 0 && extractInfo.warning && (
        <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-2 text-[12px] flex items-start gap-2">
          <AlertTriangle className="w-3.5 h-3.5 text-warning shrink-0 mt-0.5" />
          <div>
            No se pudo autocompletar nada del PDF. Cargá los datos a mano.
            <div className="text-[10.5px] text-ink-muted mt-0.5">{extractInfo.warning}</div>
          </div>
        </div>
      )}

      <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-3 text-[12px] flex items-start gap-2">
        <AlertTriangle className="w-3.5 h-3.5 text-warning shrink-0 mt-0.5" />
        <div>
          <strong>Esta factura NO se emite a ARCA.</strong> Solo se registra en el ERP con
          <code> origen=MANUAL</code>. Si la factura externa trae CAE, cargalo para poder
          "Verificar contra ARCA".
        </div>
      </div>

      <div className="grid grid-cols-3 gap-3">
        <SelectField label="Tipo *" value={String(form.tipo_comprobante_id)}
          onChange={(e) => setForm({ ...form, tipo_comprobante_id: +e.target.value })}
          options={(cats?.tipos_comprobante ?? []).map((t) => ({
            value: String(t.id), label: `${t.codigo_interno} ${t.letra ?? ''} — ${t.nombre}`,
          }))} placeholder="—" />
        <Field label="Punto de venta *" type="number" value={String(form.punto_venta)}
          onChange={(e) => setForm({ ...form, punto_venta: +e.target.value })} />
        <Field label="Número *" type="number" value={String(form.numero)}
          onChange={(e) => setForm({ ...form, numero: +e.target.value })} />
      </div>

      <div className="grid grid-cols-2 gap-3">
        <Field label="Fecha emisión *" type="date" value={form.fecha_emision}
          onChange={(e) => setForm({ ...form, fecha_emision: e.target.value })} />
        <SelectField label="Cliente (existente)" value={String(form.cliente_auxiliar_id)}
          onChange={(e) => setForm({ ...form, cliente_auxiliar_id: +e.target.value })}
          options={[{ value: '0', label: '— escribí el CUIT abajo —' },
            ...((cats?.clientes ?? []).map((c) => ({
              value: String(c.id), label: `${c.codigo} ${c.nombre}`,
            })))]} />
      </div>

      <div className="grid grid-cols-3 gap-3">
        <Field label="CUIT cliente *" value={form.cuit_cliente}
          onChange={(e) => {
            if (clienteNoExiste) setClienteNoExiste(false);
            setForm({ ...form, cuit_cliente: e.target.value, cliente_auxiliar_id: 0 });
          }}
          onBlur={handleCuitBlur}
          placeholder="11 dígitos" containerClassName="col-span-1" />
        <div className="col-span-2">
          <label className="block text-[11px] font-medium text-ink-muted mb-1">Razón social *</label>
          <input
            type="text"
            value={form.razon_social_cliente}
            onChange={(e) => setForm({ ...form, razon_social_cliente: e.target.value })}
            className={`w-full text-[12px] border rounded px-2 py-1 focus:outline-none ${
              clienteNoExiste
                ? 'border-danger focus:border-danger bg-danger-bg/10'
                : 'border-azure-soft focus:border-azure'
            }`}
          />
          {clienteNoExiste && (
            <div className="mt-0.5 text-[10.5px] text-danger">
              Cliente no existe — será cargado al registrar. Ingresá el nombre.
            </div>
          )}
        </div>
      </div>

      {form.cliente_auxiliar_id > 0 && !clienteNoExiste && clienteSel && (
        <div className="text-[11.5px] text-success">
          ✓ Cliente reconocido — CC asociado:{' '}
          <span className="font-mono font-semibold">{clienteSel.centro_costo_codigo ?? '— sin CC —'}</span>
        </div>
      )}
      {clienteLookup.isPending && (
        <div className="text-[11.5px] text-ink-muted">Buscando cliente…</div>
      )}

      <div className="grid grid-cols-3 gap-3">
        <Field label="Período trabajado" value={form.periodo_trabajado_texto}
          onChange={(e) => setForm({ ...form, periodo_trabajado_texto: e.target.value })}
          placeholder="2026-04 o 2026-04-Q1" />
        <SelectField label="Jurisdicción IIBB" value={form.jurisdiccion_codigo}
          onChange={(e) => setForm({ ...form, jurisdiccion_codigo: e.target.value })}
          options={[{ value: '', label: '— sin —' },
            ...(cats?.jurisdicciones ?? []).map((j) => ({ value: j.codigo, label: `${j.codigo} ${j.nombre}` }))]} />
        <SelectField label="Concepto AFIP" value={String(form.concepto_afip)}
          onChange={(e) => setForm({ ...form, concepto_afip: +e.target.value })}
          options={[
            { value: '1', label: 'Productos' },
            { value: '2', label: 'Servicios' },
            { value: '3', label: 'Prod+Serv' },
          ]} />
      </div>

      <div className="border-t border-line pt-3">
        <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide mb-2">Importes</h3>
        <div className="grid grid-cols-4 gap-3">
          <DecimalField label="Neto gravado"
            value={form.imp_neto_gravado}
            onChange={(n) => setForm((f) => {
              // v1.39 (mismo patrón v1.38 M3) — auto-IVA 21% si no fue editado manual.
              const ivaPrevExpected = +(f.imp_neto_gravado * 0.21).toFixed(2);
              const ivaSync = Math.abs(f.imp_iva - ivaPrevExpected) < 0.01;
              return {
                ...f,
                imp_neto_gravado: n,
                imp_iva: ivaSync ? +(n * 0.21).toFixed(2) : f.imp_iva,
              };
            })} />
          <DecimalField label="IVA"
            value={form.imp_iva}
            onChange={(n) => setForm({ ...form, imp_iva: n })} />
          <DecimalField label="No gravado"
            value={form.imp_no_gravado}
            onChange={(n) => setForm({ ...form, imp_no_gravado: n })} />
          <DecimalField label="Exento"
            value={form.imp_exento}
            onChange={(n) => setForm({ ...form, imp_exento: n })} />
        </div>
        <div className="mt-3 flex justify-between items-center bg-surface-row border border-line rounded-md px-3 py-2">
          <span className="text-[12px] font-semibold">Total</span>
          <span className="text-[15px] font-bold tabular text-navy-800">{fmtMoney(form.imp_total)}</span>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 border-t border-line pt-3">
        <Field label="CAE (opcional)" value={form.cae}
          onChange={(e) => setForm({ ...form, cae: e.target.value })}
          hint="Si la factura externa trae CAE, cargalo para verificar contra ARCA." />
        <Field label="Vto CAE (opcional)" type="date" value={form.fecha_vto_cae}
          onChange={(e) => setForm({ ...form, fecha_vto_cae: e.target.value })} />
      </div>

      {verificacion && (
        <div className={`border rounded-md p-3 text-[12px] ${verificacion.verificada
          ? 'border-success/30 bg-success-bg/20 text-success'
          : 'border-danger/30 bg-danger-bg/20 text-danger'}`}>
          <strong>{verificacion.verificada ? '✓ Verificada contra ARCA' : '✗ No verificada'}</strong>
          <pre className="text-[10.5px] mt-1 whitespace-pre-wrap">{JSON.stringify(verificacion.resultado, null, 2)}</pre>
        </div>
      )}

      <FormError error={registrar.error ? registrar.error.message : null} />

      <div className="flex justify-end gap-2 border-t border-line pt-3 flex-wrap">
        {extraActions}
        {showCargarOtro && (
          <Button variant="outline" disabled={!valid || registrar.isPending}
            onClick={() => { setCargarOtroMode(true); registrar.mutate(); }}
            title="Guarda y deja la ventana abierta con cliente + fecha para cargar otra factura.">
            {registrar.isPending && cargarOtroMode ? 'Registrando…' : 'Registrar y cargar otro'}
          </Button>
        )}
        <Button variant="primary" disabled={!valid || registrar.isPending}
          onClick={() => { setCargarOtroMode(false); registrar.mutate(); }}>
          {registrar.isPending && !cargarOtroMode
            ? 'Registrando…'
            : (submitLabel ?? `Registrar (${fmtMoney(form.imp_total)})`)}
        </Button>
      </div>

      {registrar.data?.data?.id && (
        <div className="border-t border-line pt-3">
          <Button variant="outline" size="sm"
            disabled={verificarArca.isPending}
            onClick={() => verificarArca.mutate(registrar.data!.data.id)}>
            <ShieldCheck className="w-3 h-3" />
            {verificarArca.isPending ? 'Verificando…' : 'Verificar contra ARCA'}
          </Button>
        </div>
      )}
    </div>
  );
}
