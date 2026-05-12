import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Receipt, ShieldCheck, AlertTriangle, ArrowLeft } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { fmtMoney } from '@/lib/cn';
import { useQuery, useMutation } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { useToast } from '@/hooks/useToast';

/**
 * ADDENDUM v1.17 — Carga manual de factura de venta.
 * NO emite contra ARCA. origen=MANUAL, estado=EMITIDA.
 * Verificación opcional con WSCDC + padrón A13.
 */

type Catalogos = {
  clientes: { id: number; nombre: string; cuit: string | null; codigo: string;
    centro_costo_id: number | null; centro_costo_codigo: string | null }[];
  tipos_comprobante: { id: number; codigo_interno: string; nombre: string; letra: string | null; clase: string }[];
  alicuotas_iva: { id: number; codigo_interno: string; nombre: string; tasa: string }[];
  monedas: { id: number; codigo: string; nombre: string }[];
  jurisdicciones?: { codigo: string; nombre: string }[];
};

const today = () => new Date().toISOString().slice(0, 10);

export function FacturaVentaManualPage() {
  const navigate = useNavigate();
  const toast = useToast();
  const [verificacion, setVerificacion] = useState<{ verificada: boolean; resultado: Record<string, unknown> } | null>(null);

  const { data: cats } = useQuery<Catalogos>({
    queryKey: ['fv-catalogos-manual'],
    queryFn: () => api.get('/api/erp/facturas-venta/catalogos'),
  });

  const [form, setForm] = useState({
    tipo_comprobante_id: 0,
    punto_venta: 1,
    numero: 0,
    fecha_emision: today(),
    cliente_auxiliar_id: 0,
    cuit_cliente: '',
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
  });

  // Auto-fill cliente al elegir.
  useEffect(() => {
    if (!form.cliente_auxiliar_id || !cats) return;
    const c = cats.clientes.find((x) => x.id === form.cliente_auxiliar_id);
    if (c?.cuit && !form.cuit_cliente) {
      setForm((f) => ({ ...f, cuit_cliente: c.cuit ?? '' }));
    }
  }, [form.cliente_auxiliar_id, cats, form.cuit_cliente]);

  // Auto-default tipo cbte.
  useEffect(() => {
    if (form.tipo_comprobante_id || !cats?.tipos_comprobante.length) return;
    const fb = cats.tipos_comprobante.find((t) => t.codigo_interno === 'FB');
    setForm((f) => ({ ...f, tipo_comprobante_id: fb?.id ?? cats.tipos_comprobante[0].id }));
  }, [form.tipo_comprobante_id, cats]);

  // Auto-calcular total = neto + iva + no_gravado + exento.
  useEffect(() => {
    const total = Number(form.imp_neto_gravado || 0) + Number(form.imp_iva || 0) +
      Number(form.imp_no_gravado || 0) + Number(form.imp_exento || 0);
    if (Math.abs(total - form.imp_total) > 0.005) {
      setForm((f) => ({ ...f, imp_total: +total.toFixed(2) }));
    }
  }, [form.imp_neto_gravado, form.imp_iva, form.imp_no_gravado, form.imp_exento, form.imp_total]);

  const registrar = useMutation<{ data: { id: number } }, ApiError, void>({
    mutationFn: () => api.post('/api/erp/facturas-venta/manual', {
      tipo_comprobante_id: form.tipo_comprobante_id,
      punto_venta: form.punto_venta,
      numero: form.numero,
      fecha_emision: form.fecha_emision,
      cliente_auxiliar_id: form.cliente_auxiliar_id || undefined,
      cuit_cliente: form.cuit_cliente || undefined,
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
    }),
    onSuccess: (r) => {
      toast.success('Factura registrada', `Manual #${r.data.id} · origen=MANUAL`);
      navigate('/erp/facturas-venta');
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

  const valid = form.tipo_comprobante_id && form.punto_venta > 0 && form.numero > 0
    && form.fecha_emision && form.cliente_auxiliar_id && form.imp_total > 0;

  const clienteSel = cats?.clientes.find((c) => c.id === form.cliente_auxiliar_id);

  return (
    <div className="p-6 max-w-4xl space-y-4">
      <div className="flex items-center gap-2 text-[13px] text-ink-muted">
        <button onClick={() => navigate('/erp/facturas-venta')} className="hover:text-ink-2 flex items-center gap-1">
          <ArrowLeft className="w-3 h-3" /> Facturación
        </button>
        <span>›</span>
        <span className="text-ink-2 font-medium">Nueva manual</span>
      </div>

      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2">
            <Receipt className="w-4 h-4 text-azure" /> Nueva factura de venta (carga manual)
          </div>
        } />
        <CardBody className="p-4 space-y-4">
          <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-3 text-[12px] flex items-start gap-2">
            <AlertTriangle className="w-3.5 h-3.5 text-warning shrink-0 mt-0.5" />
            <div>
              <strong>Esta factura NO se emite a ARCA.</strong> Solo se registra en el ERP con
              <code> origen=MANUAL</code>. Si la factura viene de un sistema externo (Loginter, etc.)
              y necesitás validarla, cargá CAE y usá "Verificar contra ARCA".
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

          <div className="grid grid-cols-3 gap-3">
            <Field label="Fecha emisión *" type="date" value={form.fecha_emision}
              onChange={(e) => setForm({ ...form, fecha_emision: e.target.value })} />
            <SelectField label="Cliente *" value={String(form.cliente_auxiliar_id)}
              onChange={(e) => setForm({ ...form, cliente_auxiliar_id: +e.target.value })}
              options={(cats?.clientes ?? []).map((c) => ({
                value: String(c.id), label: `${c.codigo} ${c.nombre}`,
              }))} placeholder="Elegí cliente…" />
            <Field label="CUIT cliente" value={form.cuit_cliente}
              onChange={(e) => setForm({ ...form, cuit_cliente: e.target.value })}
              placeholder="11 dígitos" />
          </div>

          {clienteSel && (
            <div className="text-[11.5px] text-ink-muted bg-surface-row border border-line rounded-md px-3 py-2">
              CC asociado: <span className="font-mono font-semibold text-ink-2">
                {clienteSel.centro_costo_codigo ?? '— sin CC —'} — {clienteSel.nombre}
              </span> (auto)
            </div>
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
              onChange={(e) => setForm({ ...form, cae: e.target.value })}
              hint="Si la factura externa trae CAE, cargalo para poder verificar contra ARCA." />
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

          <div className="flex justify-end gap-2 border-t border-line pt-3">
            <Button variant="secondary" onClick={() => navigate('/erp/facturas-venta')}>Cancelar</Button>
            <Button variant="primary" disabled={!valid || registrar.isPending}
              onClick={() => registrar.mutate()}>
              {registrar.isPending ? 'Registrando…' : `Registrar (${fmtMoney(form.imp_total)})`}
            </Button>
          </div>

          {/* Verificación ARCA solo disponible post-guardado */}
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
        </CardBody>
      </Card>
    </div>
  );
}
