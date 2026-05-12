import { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { ShoppingCart, ShieldCheck, AlertTriangle, ArrowLeft } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Field, SelectField, FormError } from '@/components/ui/Field';
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

  // Catálogos.
  const { data: tipos } = useQuery<{ data: Tipo[] }>({
    queryKey: ['fc-tipos-compra'],
    queryFn: () => api.get('/api/erp/tipos-comprobante?clase=COMPRA'),
  });
  const { data: periodos } = useQuery<{ data: Periodo[] }>({
    queryKey: ['fc-periodos'],
    queryFn: () => api.get('/api/erp/periodos/abierto'),
  });
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
    if (!form.periodo_id && periodos?.data && periodos.data.length > 0) {
      const p = periodos.data[0];
      setForm((f) => ({ ...f, periodo_id: p.id }));
    }
  }, [tipos, periodos, form.tipo_comprobante_id, form.periodo_id]);

  // Auto-calcular total.
  useEffect(() => {
    const total = Number(form.imp_neto_gravado || 0) + Number(form.imp_iva || 0) +
      Number(form.imp_no_gravado || 0) + Number(form.imp_exento || 0);
    if (Math.abs(total - form.imp_total) > 0.005) {
      setForm((f) => ({ ...f, imp_total: +total.toFixed(2) }));
    }
  }, [form.imp_neto_gravado, form.imp_iva, form.imp_no_gravado, form.imp_exento, form.imp_total]);

  // Buscar proveedor por CUIT cuando se escribe.
  const proveedorLookup = useMutation<{ data: { id: number; nombre: string } }, ApiError, string>({
    mutationFn: (cuit) => api.get(`/api/erp/auxiliares/by-cuit/${cuit}?tipo=Proveedor`),
    onSuccess: (r) => {
      setForm((f) => ({ ...f, auxiliar_id: r.data.id, razon_social_emisor: r.data.nombre || f.razon_social_emisor }));
      toast.success('Proveedor encontrado', r.data.nombre);
    },
  });

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
              options={(periodos?.data ?? []).map((p) => ({
                value: String(p.id), label: `${MESES[p.mes]} ${p.anio} (${p.estado})`,
              }))} placeholder="—" />
          </div>

          <div className="border-t border-line pt-3">
            <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide mb-2">Proveedor</h3>
            <div className="grid grid-cols-3 gap-3">
              <div className="flex gap-2 col-span-1">
                <Field label="CUIT proveedor *" value={form.cuit_emisor}
                  onChange={(e) => setForm({ ...form, cuit_emisor: e.target.value })}
                  placeholder="11 dígitos" containerClassName="flex-1" />
                <Button variant="outline" size="sm" className="self-end mb-0.5"
                  disabled={!/^\d{11}$/.test(form.cuit_emisor) || proveedorLookup.isPending}
                  onClick={() => proveedorLookup.mutate(form.cuit_emisor)}>
                  Buscar
                </Button>
              </div>
              <Field label="Razón social *" value={form.razon_social_emisor}
                onChange={(e) => setForm({ ...form, razon_social_emisor: e.target.value })}
                containerClassName="col-span-2" />
            </div>
            {form.auxiliar_id > 0 && (
              <div className="text-[11.5px] text-success mt-1">
                ✓ Proveedor reconocido (auxiliar #{form.auxiliar_id})
              </div>
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
            <div className="grid grid-cols-4 gap-3">
              <Field label="Neto gravado" type="number" step="0.01" value={String(form.imp_neto_gravado)}
                onChange={(e) => setForm({ ...form, imp_neto_gravado: +e.target.value })} />
              <Field label="IVA" type="number" step="0.01" value={String(form.imp_iva)}
                onChange={(e) => setForm({ ...form, imp_iva: +e.target.value })} />
              <Field label="No gravado" type="number" step="0.01" value={String(form.imp_no_gravado)}
                onChange={(e) => setForm({ ...form, imp_no_gravado: +e.target.value })} />
              <Field label="Exento" type="number" step="0.01" value={String(form.imp_exento)}
                onChange={(e) => setForm({ ...form, imp_exento: +e.target.value })} />
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
            <Button variant="primary" disabled={!valid || registrar.isPending}
              onClick={() => registrar.mutate()}>
              {registrar.isPending ? 'Registrando…' : `Registrar (${fmtMoney(form.imp_total)})`}
            </Button>
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
