import { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { ShoppingCart, ShieldCheck, AlertTriangle, ArrowLeft } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { DecimalField } from '@/components/ui/DecimalField';
import { fmtMoney } from '@/lib/cn';
import { useQuery, useMutation } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { useToast } from '@/hooks/useToast';

/**
 * ADDENDUM v1.17 — Carga manual de factura de compra.
 * Mismo modelo que el import del Libro IVA Compras pero individual.
 * Acepta query param `?return_to=libro-iva` para volver al listado del Libro IVA.
 */

type Periodo = { id: number; anio: number; mes: number; estado: string };
type Tipo = { id: number; codigo_interno: string; nombre: string; letra: string | null; clase: string };
type Cliente = { id: number; nombre: string; codigo: string; centro_costo_codigo: string | null };
type CC = { id: number; codigo: string; nombre: string; tipo: string; activo: number | boolean };
type Jurisdiccion = { codigo: string; nombre: string };

const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

export function FacturaCompraManualPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const returnTo = searchParams.get('return_to');
  const toast = useToast();
  const [verificacion, setVerificacion] = useState<{ verificada: boolean; resultado: Record<string, unknown> } | null>(null);
  const [createdId, setCreatedId] = useState<number | null>(null);
  // v1.38 M1 — flag para pintar de rojo el campo razón social si el CUIT no
  // matchea ningún proveedor existente. Se resetea al cambiar el CUIT.
  const [proveedorNoExiste, setProveedorNoExiste] = useState(false);
  // v1.38 M2 — modo "registrar y cargar otro": no navega tras success y
  // preserva CUIT, razón social, fecha emisión, fecha imputación.
  const [cargarOtroMode, setCargarOtroMode] = useState(false);

  // Catálogos.
  const { data: tipos } = useQuery<{ data: Tipo[] }>({
    queryKey: ['fc-tipos-compra'],
    queryFn: () => api.get('/api/erp/tipos-comprobante?clase=COMPRA'),
  });
  // El endpoint devuelve { data: Periodo | null } (un solo período abierto).
  const { data: periodoResp } = useQuery<{ data: Periodo | null }>({
    queryKey: ['fc-periodo-abierto'],
    queryFn: () => api.get('/api/erp/periodos/abierto'),
  });
  const periodoAbierto = periodoResp?.data ?? null;
  // Catálogos del facturas-venta tienen jurisdicciones y clientes con CC.
  const { data: catsVenta } = useQuery<{ clientes: Cliente[]; jurisdicciones?: Jurisdiccion[] }>({
    queryKey: ['fc-catalogos-clientes'],
    queryFn: () => api.get('/api/erp/facturas-venta/catalogos'),
  });
  const { data: ccs } = useQuery<{ ok: boolean; data: CC[] }>({
    queryKey: ['fc-ccs-manual'],
    queryFn: () => api.get('/api/erp/centros-costo/abm?tipo=GENERAL'),
  });

  const [form, setForm] = useState({
    tipo_comprobante_id: 0,
    punto_venta: 1,
    numero: 0,
    fecha_emision: new Date().toISOString().slice(0, 10),
    fecha_imputacion: new Date().toISOString().slice(0, 10),
    periodo_id: 0,
    cuit_emisor: '',
    razon_social_emisor: '',
    auxiliar_id: 0,
    cliente_auxiliar_id: 0,
    centro_costo_id: 0,
    moneda_id: 1,
    imp_neto_gravado: 0,
    imp_no_gravado: 0,
    imp_exento: 0,
    imp_iva: 0,
    // v1.25 — desglose por alícuota (3 visibles + 2 ocultas para alícuotas raras).
    imp_neto_gravado_21: 0,
    imp_neto_gravado_10_5: 0,
    imp_neto_gravado_27: 0,
    imp_iva_21: 0,
    imp_iva_10_5: 0,
    imp_iva_27: 0,
    // v1.34 — percepciones.
    imp_percepciones_iva: 0,
    imp_percepciones_iibb: 0,
    imp_total: 0,
    cae: '',
    tomado: true,
    tipo_gasto: '',
    observaciones: '',
    periodo_trabajado_texto: '',
    jurisdiccion_codigo: '',
  });

  // Auto-default tipo + periodo.
  useEffect(() => {
    if (!form.tipo_comprobante_id && tipos?.data.length) {
      const fa = tipos.data.find((t) => t.codigo_interno === 'FA');
      setForm((f) => ({ ...f, tipo_comprobante_id: fa?.id ?? tipos.data[0].id }));
    }
    if (!form.periodo_id && periodoAbierto) {
      setForm((f) => ({ ...f, periodo_id: periodoAbierto.id }));
    }
  }, [tipos, periodoAbierto, form.tipo_comprobante_id, form.periodo_id]);

  // v1.25 + v1.34 — Auto-calcular agregados y total. Total = netos por alícuota
  // + IVA por alícuota + no gravado + exento + percepciones IVA + percepciones IIBB.
  useEffect(() => {
    const neto = Number(form.imp_neto_gravado_21 || 0)
      + Number(form.imp_neto_gravado_10_5 || 0)
      + Number(form.imp_neto_gravado_27 || 0);
    const iva = Number(form.imp_iva_21 || 0)
      + Number(form.imp_iva_10_5 || 0)
      + Number(form.imp_iva_27 || 0);
    const percep = Number(form.imp_percepciones_iva || 0)
      + Number(form.imp_percepciones_iibb || 0);
    const total = neto + iva + Number(form.imp_no_gravado || 0) + Number(form.imp_exento || 0) + percep;

    setForm((f) => {
      if (Math.abs(neto - f.imp_neto_gravado) < 0.005
        && Math.abs(iva - f.imp_iva) < 0.005
        && Math.abs(total - f.imp_total) < 0.005) {
        return f;
      }
      return {
        ...f,
        imp_neto_gravado: +neto.toFixed(2),
        imp_iva: +iva.toFixed(2),
        imp_total: +total.toFixed(2),
      };
    });
  }, [
    form.imp_neto_gravado_21, form.imp_neto_gravado_10_5, form.imp_neto_gravado_27,
    form.imp_iva_21, form.imp_iva_10_5, form.imp_iva_27,
    form.imp_no_gravado, form.imp_exento,
    form.imp_percepciones_iva, form.imp_percepciones_iibb,
  ]);

  // v1.38 M1 — Buscar proveedor por CUIT al perder foco (onBlur). Si existe,
  // autocompleta razón social; si no, pinta rojo el campo razón social.
  const proveedorLookup = useMutation<{ data: { id: number; nombre: string } }, ApiError, string>({
    mutationFn: (cuit) => api.get(`/api/erp/auxiliares/by-cuit/${cuit}?tipo=Proveedor`),
    onSuccess: (r) => {
      setForm((f) => ({ ...f, auxiliar_id: r.data.id, razon_social_emisor: r.data.nombre || f.razon_social_emisor }));
      setProveedorNoExiste(false);
    },
    onError: () => {
      // 404 esperado cuando el CUIT no está cargado todavía. No mostramos
      // toast — el feedback es el borde rojo del input de razón social.
      setForm((f) => ({ ...f, auxiliar_id: 0 }));
      setProveedorNoExiste(true);
    },
  });

  const handleCuitBlur = () => {
    if (/^\d{11}$/.test(form.cuit_emisor) && !proveedorLookup.isPending) {
      proveedorLookup.mutate(form.cuit_emisor);
    }
  };

  const registrar = useMutation<{ data: { id: number } }, ApiError, void>({
    mutationFn: () => api.post('/api/erp/facturas-compra/manual', {
      tipo_comprobante_id: form.tipo_comprobante_id,
      punto_venta: form.punto_venta,
      numero: form.numero,
      fecha_emision: form.fecha_emision,
      fecha_imputacion: form.fecha_imputacion,
      periodo_id: form.periodo_id,
      cuit_emisor: form.cuit_emisor,
      razon_social_emisor: form.razon_social_emisor,
      auxiliar_id: form.auxiliar_id || undefined,
      cliente_auxiliar_id: form.cliente_auxiliar_id || undefined,
      centro_costo_id: form.centro_costo_id || undefined,
      moneda_id: form.moneda_id,
      imp_neto_gravado: form.imp_neto_gravado,
      imp_no_gravado: form.imp_no_gravado,
      imp_exento: form.imp_exento,
      imp_iva: form.imp_iva,
      // v1.25 — desglose por alícuota
      imp_neto_gravado_21: form.imp_neto_gravado_21,
      imp_neto_gravado_10_5: form.imp_neto_gravado_10_5,
      imp_neto_gravado_27: form.imp_neto_gravado_27,
      imp_iva_21: form.imp_iva_21,
      imp_iva_10_5: form.imp_iva_10_5,
      imp_iva_27: form.imp_iva_27,
      // v1.34 — percepciones
      imp_percepciones_iva: form.imp_percepciones_iva,
      imp_percepciones_iibb: form.imp_percepciones_iibb,
      imp_total: form.imp_total,
      cae: form.cae || undefined,
      tomado: form.tomado,
      tipo_gasto: form.tipo_gasto || undefined,
      observaciones: form.observaciones || undefined,
      periodo_trabajado_texto: form.periodo_trabajado_texto || undefined,
      jurisdiccion_codigo: form.jurisdiccion_codigo || undefined,
    }),
    onSuccess: (r) => {
      toast.success('Factura registrada', `Manual #${r.data.id}`);
      setCreatedId(r.data.id);
      if (cargarOtroMode) {
        // v1.38 M2 — reset preservando proveedor + fechas. El operador típico
        // carga varias facturas seguidas del mismo proveedor o del mismo día.
        setForm((f) => ({
          ...f,
          punto_venta: 1,
          numero: 0,
          cliente_auxiliar_id: 0,
          centro_costo_id: 0,
          imp_neto_gravado: 0,
          imp_no_gravado: 0,
          imp_exento: 0,
          imp_iva: 0,
          imp_neto_gravado_21: 0,
          imp_neto_gravado_10_5: 0,
          imp_neto_gravado_27: 0,
          imp_iva_21: 0,
          imp_iva_10_5: 0,
          imp_iva_27: 0,
          imp_percepciones_iva: 0,
          imp_percepciones_iibb: 0,
          imp_total: 0,
          cae: '',
          tipo_gasto: '',
          observaciones: '',
          periodo_trabajado_texto: '',
          jurisdiccion_codigo: '',
        }));
        setCreatedId(null);
        setVerificacion(null);
        setCargarOtroMode(false);
        return;
      }
      if (returnTo === 'libro-iva') {
        navigate('/erp/libro-iva-compras/import');
      } else {
        navigate('/erp/facturas-compra');
      }
    },
    onError: (e) => toast.error('No se pudo registrar', e.message),
  });

  const verificar = useMutation<{ data: { verificada: boolean; resultado: Record<string, unknown> } }, ApiError, void>({
    mutationFn: () => api.post(`/api/erp/facturas/compra/${createdId}/verificar-arca`),
    onSuccess: (r) => setVerificacion(r.data),
    onError: (e) => toast.error('Error verificando', e.message),
  });

  const necesitaCcManual = !form.cliente_auxiliar_id;
  const valid = form.tipo_comprobante_id && form.punto_venta > 0 && form.numero > 0
    && form.fecha_emision && form.fecha_imputacion && form.periodo_id
    && /^\d{11}$/.test(form.cuit_emisor) && form.razon_social_emisor
    && form.imp_total > 0
    && (!necesitaCcManual || form.centro_costo_id > 0);

  return (
    <div className="p-6 max-w-4xl space-y-4">
      <div className="flex items-center gap-2 text-[13px] text-ink-muted">
        <button onClick={() => navigate(returnTo === 'libro-iva' ? '/erp/libro-iva-compras/import' : '/erp/facturas-compra')}
          className="hover:text-ink-2 flex items-center gap-1">
          <ArrowLeft className="w-3 h-3" /> {returnTo === 'libro-iva' ? 'Libro IVA Compras' : 'Facturas de compra'}
        </button>
        <span>›</span>
        <span className="text-ink-2 font-medium">Nueva manual</span>
      </div>

      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2">
            <ShoppingCart className="w-4 h-4 text-azure" /> Nueva factura de compra (carga manual)
          </div>
        } />
        <CardBody className="p-4 space-y-4">
          <div className="border border-info/30 bg-info-bg/20 rounded-md p-3 text-[12px] flex items-start gap-2">
            <AlertTriangle className="w-3.5 h-3.5 text-info shrink-0 mt-0.5" />
            <div>
              Carga individual de factura recibida que no entró por el import del Libro IVA.
              Mismo modelo que las importadas (<code>origen=MANUAL</code>). Si el contador después
              importa el mismo período, se detecta el match y se reporta como conflicto en el resumen.
            </div>
          </div>

          <div className="grid grid-cols-3 gap-3">
            <SelectField label="Tipo *" value={String(form.tipo_comprobante_id)}
              onChange={(e) => setForm({ ...form, tipo_comprobante_id: +e.target.value })}
              options={(tipos?.data ?? []).map((t) => ({
                value: String(t.id), label: `${t.codigo_interno} ${t.letra ?? ''} — ${t.nombre}`,
              }))} placeholder="—" />
            <Field label="Punto de venta *" type="number" value={String(form.punto_venta)}
              onChange={(e) => setForm({ ...form, punto_venta: +e.target.value })} />
            <Field label="Número *" type="number" value={String(form.numero)}
              onChange={(e) => setForm({ ...form, numero: +e.target.value })} />
          </div>

          <div className="grid grid-cols-3 gap-3">
            <Field label="Fecha emisión *" type="date" value={form.fecha_emision}
              onChange={(e) => setForm({ ...form, fecha_emision: e.target.value })} />
            <Field label="Fecha imputación *" type="date" value={form.fecha_imputacion}
              onChange={(e) => setForm({ ...form, fecha_imputacion: e.target.value })} />
            <SelectField label="Período *" value={String(form.periodo_id)}
              onChange={(e) => setForm({ ...form, periodo_id: +e.target.value })}
              options={periodoAbierto
                ? [{ value: String(periodoAbierto.id),
                     label: `${MESES[periodoAbierto.mes]} ${periodoAbierto.anio} (${periodoAbierto.estado})` }]
                : []}
              placeholder="—" />
          </div>

          <div className="border-t border-line pt-3">
            <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide mb-2">Proveedor</h3>
            <div className="grid grid-cols-3 gap-3">
              <Field label="CUIT proveedor *" value={form.cuit_emisor}
                onChange={(e) => {
                  // Al editar el CUIT, reseteamos el estado de "no existe"
                  // hasta que se vuelva a disparar el lookup en blur.
                  if (proveedorNoExiste) setProveedorNoExiste(false);
                  setForm({ ...form, cuit_emisor: e.target.value, auxiliar_id: 0 });
                }}
                onBlur={handleCuitBlur}
                placeholder="11 dígitos" containerClassName="col-span-1" />
              <div className="col-span-2">
                <label className="block text-[11px] font-medium text-ink-muted mb-1">Razón social *</label>
                <input
                  type="text"
                  value={form.razon_social_emisor}
                  onChange={(e) => setForm({ ...form, razon_social_emisor: e.target.value })}
                  className={`w-full text-[12px] border rounded px-2 py-1 focus:outline-none ${
                    proveedorNoExiste
                      ? 'border-danger focus:border-danger bg-danger-bg/10'
                      : 'border-azure-soft focus:border-azure'
                  }`}
                />
                {proveedorNoExiste && (
                  <div className="mt-0.5 text-[10.5px] text-danger">
                    Proveedor no existe — será cargado al registrar. Ingresá el nombre.
                  </div>
                )}
              </div>
            </div>
            {form.auxiliar_id > 0 && !proveedorNoExiste && (
              <div className="text-[11.5px] text-success mt-1">
                ✓ Proveedor reconocido (auxiliar #{form.auxiliar_id})
              </div>
            )}
            {proveedorLookup.isPending && (
              <div className="text-[11.5px] text-ink-muted mt-1">Buscando proveedor…</div>
            )}
          </div>

          <div className="border-t border-line pt-3">
            <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide mb-2">Asignación a Centro de Costos</h3>
            <div className="grid grid-cols-2 gap-3">
              <SelectField label="Cliente asociado (opcional)"
                value={String(form.cliente_auxiliar_id)}
                onChange={(e) => setForm({ ...form, cliente_auxiliar_id: +e.target.value, centro_costo_id: 0 })}
                options={[{ value: '0', label: '— sin cliente (gasto no atribuible) —' },
                  ...(catsVenta?.clientes ?? []).map((c) => ({
                    value: String(c.id), label: `${c.codigo} ${c.nombre}`,
                  }))]} />
              {necesitaCcManual ? (
                <SelectField label="CC manual *"
                  value={String(form.centro_costo_id)}
                  onChange={(e) => setForm({ ...form, centro_costo_id: +e.target.value })}
                  options={[{ value: '0', label: 'Elegí CC manual…' },
                    ...((ccs?.data ?? []).filter((c) => c.activo).map((c) => ({
                      value: String(c.id), label: `${c.codigo} — ${c.nombre}`,
                    })))]}
                  hint="Como no hay cliente, elegí un CC manual (MANT-FLOTA, ALQUILER-OFI, etc)." />
              ) : (
                <div className="text-[11.5px] text-ink-muted bg-surface-row border border-line rounded-md px-3 py-2 self-end">
                  CC derivado del cliente (auto)
                </div>
              )}
            </div>
          </div>

          <div className="grid grid-cols-3 gap-3 border-t border-line pt-3">
            <Field label="Período trabajado" value={form.periodo_trabajado_texto}
              onChange={(e) => setForm({ ...form, periodo_trabajado_texto: e.target.value })}
              placeholder="2026-04 o 2026-04-Q1" />
            <SelectField label="Jurisdicción IIBB" value={form.jurisdiccion_codigo}
              onChange={(e) => setForm({ ...form, jurisdiccion_codigo: e.target.value })}
              options={[{ value: '', label: '—' },
                ...(catsVenta?.jurisdicciones ?? []).map((j) => ({ value: j.codigo, label: `${j.codigo} ${j.nombre}` }))]} />
            <Field label="Tipo gasto" value={form.tipo_gasto}
              onChange={(e) => setForm({ ...form, tipo_gasto: e.target.value })}
              placeholder="Combustible, Peajes, …" />
          </div>

          <div className="border-t border-line pt-3">
            <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide mb-2">Importes</h3>
            <div className="text-[11px] text-ink-muted mb-2">
              Cargá el neto y el IVA por alícuota. El total se calcula solo.
            </div>

            {/*
              v1.25 — desglose por alícuota (10,5% / 21% / 27%).
              v1.38 M3 — al cambiar el neto, autocalculamos IVA = neto * tasa
              SOLO si el IVA actual está "sincronizado" con el neto anterior
              (es decir, no fue editado manualmente). Esto permite override
              manual: si el usuario edita el IVA, futuros cambios al neto NO
              lo van a pisar.
              v1.38 M4 — DecimalField acepta '.' y ',' sin perder valor.
            */}
            <div className="space-y-2">
              <div className="grid grid-cols-[80px_1fr_1fr] gap-3 items-end">
                <div className="text-[11px] text-ink-muted font-medium pb-2">IVA 21%</div>
                <DecimalField label="Neto gravado"
                  value={form.imp_neto_gravado_21}
                  onChange={(n) => setForm((f) => {
                    const ivaPrevExpected = +(f.imp_neto_gravado_21 * 0.21).toFixed(2);
                    const ivaSync = Math.abs(f.imp_iva_21 - ivaPrevExpected) < 0.01;
                    return {
                      ...f,
                      imp_neto_gravado_21: n,
                      imp_iva_21: ivaSync ? +(n * 0.21).toFixed(2) : f.imp_iva_21,
                    };
                  })} />
                <DecimalField label="IVA"
                  value={form.imp_iva_21}
                  onChange={(n) => setForm({ ...form, imp_iva_21: n })} />
              </div>
              <div className="grid grid-cols-[80px_1fr_1fr] gap-3 items-end">
                <div className="text-[11px] text-ink-muted font-medium pb-2">IVA 10,5%</div>
                <DecimalField label="Neto gravado"
                  value={form.imp_neto_gravado_10_5}
                  onChange={(n) => setForm((f) => {
                    const ivaPrevExpected = +(f.imp_neto_gravado_10_5 * 0.105).toFixed(2);
                    const ivaSync = Math.abs(f.imp_iva_10_5 - ivaPrevExpected) < 0.01;
                    return {
                      ...f,
                      imp_neto_gravado_10_5: n,
                      imp_iva_10_5: ivaSync ? +(n * 0.105).toFixed(2) : f.imp_iva_10_5,
                    };
                  })} />
                <DecimalField label="IVA"
                  value={form.imp_iva_10_5}
                  onChange={(n) => setForm({ ...form, imp_iva_10_5: n })} />
              </div>
              <div className="grid grid-cols-[80px_1fr_1fr] gap-3 items-end">
                <div className="text-[11px] text-ink-muted font-medium pb-2">IVA 27%</div>
                <DecimalField label="Neto gravado"
                  value={form.imp_neto_gravado_27}
                  onChange={(n) => setForm((f) => {
                    const ivaPrevExpected = +(f.imp_neto_gravado_27 * 0.27).toFixed(2);
                    const ivaSync = Math.abs(f.imp_iva_27 - ivaPrevExpected) < 0.01;
                    return {
                      ...f,
                      imp_neto_gravado_27: n,
                      imp_iva_27: ivaSync ? +(n * 0.27).toFixed(2) : f.imp_iva_27,
                    };
                  })} />
                <DecimalField label="IVA"
                  value={form.imp_iva_27}
                  onChange={(n) => setForm({ ...form, imp_iva_27: n })} />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3 mt-3">
              <DecimalField label="No gravado" value={form.imp_no_gravado}
                onChange={(n) => setForm({ ...form, imp_no_gravado: n })} />
              <DecimalField label="Exento" value={form.imp_exento}
                onChange={(n) => setForm({ ...form, imp_exento: n })} />
            </div>

            {/* v1.34 — percepciones IVA + IIBB. Suman al total. */}
            <div className="grid grid-cols-2 gap-3 mt-3">
              <DecimalField label="Percepción IVA"
                value={form.imp_percepciones_iva}
                onChange={(n) => setForm({ ...form, imp_percepciones_iva: n })} />
              <DecimalField label="Percepción Ingresos Brutos"
                value={form.imp_percepciones_iibb}
                onChange={(n) => setForm({ ...form, imp_percepciones_iibb: n })} />
            </div>

            <div className="mt-3 flex justify-between items-center bg-surface-row border border-line rounded-md px-3 py-2">
              <span className="text-[12px] font-semibold">Total</span>
              <span className="text-[15px] font-bold tabular text-navy-800">{fmtMoney(form.imp_total)}</span>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3 border-t border-line pt-3">
            <Field label="CAE (opcional)" value={form.cae}
              onChange={(e) => setForm({ ...form, cae: e.target.value })} />
            <label className="flex items-center gap-2 text-[12px] cursor-pointer self-end pb-1">
              <input type="checkbox" checked={form.tomado}
                onChange={(e) => setForm({ ...form, tomado: e.target.checked })} />
              Tomado para Libro IVA Compras (impacta DDJJ)
            </label>
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

          <div className="flex justify-end gap-2 border-t border-line pt-3">
            <Button variant="secondary"
              onClick={() => navigate(returnTo === 'libro-iva' ? '/erp/libro-iva-compras/import' : '/erp/facturas-compra')}>
              Cancelar
            </Button>
            {createdId && (
              <Button variant="outline" size="sm" disabled={verificar.isPending}
                onClick={() => verificar.mutate()}>
                <ShieldCheck className="w-3 h-3" />
                {verificar.isPending ? 'Verificando…' : 'Verificar ARCA'}
              </Button>
            )}
            <Button variant="outline" disabled={!valid || registrar.isPending}
              onClick={() => { setCargarOtroMode(true); registrar.mutate(); }}
              title="Guarda esta factura y deja la ventana abierta con proveedor y fechas para cargar la siguiente.">
              {registrar.isPending && cargarOtroMode ? 'Registrando…' : 'Registrar y cargar otro'}
            </Button>
            <Button variant="primary" disabled={!valid || registrar.isPending}
              onClick={() => { setCargarOtroMode(false); registrar.mutate(); }}>
              {registrar.isPending && !cargarOtroMode ? 'Registrando…' : `Registrar (${fmtMoney(form.imp_total)})`}
            </Button>
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
