import { useState } from 'react';
import { ShieldCheck, ShieldAlert, ShieldOff } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { fmtMoney } from '@/components/ui/DataTable';
import { api } from '@/lib/api';
import { useApiMutation, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Resultado = 'VALIDO' | 'INVALIDO' | 'NO_ENCONTRADO';

type ConstatacionResp = {
  resultado: Resultado;
  cae?: string;
  cae_validez?: string;
  fecha_cbte?: string;
  imp_total?: number | string;
  observaciones?: string | null;
  obs?: Array<{ codigo: string; mensaje: string }> | null;
  raw?: unknown;
};

const TIPOS_CBTE = [
  { value: '1', label: '01 — Factura A' },
  { value: '6', label: '06 — Factura B' },
  { value: '11', label: '11 — Factura C' },
  { value: '51', label: '51 — Factura M' },
  { value: '2', label: '02 — Nota de Débito A' },
  { value: '3', label: '03 — Nota de Crédito A' },
  { value: '7', label: '07 — Nota de Débito B' },
  { value: '8', label: '08 — Nota de Crédito B' },
  { value: '201', label: '201 — FCE MiPyME A' },
  { value: '206', label: '206 — FCE MiPyME B' },
];

export function ConstatacionPage() {
  const [form, setForm] = useState({
    tipo: '1',
    pto_vta: '',
    numero: '',
    cuit_emisor: '',
    cae: '',
    fecha_cbte: '',
    imp_total: '',
  });
  const [resp, setResp] = useState<ConstatacionResp | null>(null);
  const toast = useToast();

  const m = useApiMutation<ConstatacionResp, Record<string, unknown>>(
    (vars) => api.post('/api/erp/comprobantes/constatar', vars),
    {
      onSuccess: (d) => {
        setResp(d);
        if (d.resultado === 'VALIDO') toast.success('Comprobante válido');
        else if (d.resultado === 'INVALIDO') toast.error('Comprobante inválido');
        else toast.error('No encontrado en AFIP');
      },
      onError: (e) => {
        setResp(null);
        toast.error('Error al constatar', errorMessage(e));
      },
    }
  );

  const submit = () => {
    const payload: Record<string, unknown> = {
      tipo: Number(form.tipo),
      pto_vta: Number(form.pto_vta),
      numero: Number(form.numero),
      cuit_emisor: form.cuit_emisor.replace(/[^0-9]/g, ''),
      cae: form.cae.replace(/\s/g, ''),
    };
    if (form.fecha_cbte) payload.fecha_cbte = form.fecha_cbte;
    if (form.imp_total) payload.imp_total = Number(form.imp_total);
    m.mutate(payload);
  };

  const valid = form.tipo && form.pto_vta && form.numero && form.cuit_emisor && form.cae;

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><ShieldCheck className="w-4 h-4 text-azure" /> Constatación de CAE</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            Valida el CAE de un comprobante recibido contra AFIP (servicio COMP_CONSULT — RN-42).
            Útil para auditar facturas de proveedores antes de contabilizar.
          </div>

          <div className="grid grid-cols-3 gap-3">
            <SelectField label="Tipo de comprobante" required value={form.tipo}
              onChange={(e) => setForm({ ...form, tipo: e.target.value })}
              options={TIPOS_CBTE} placeholder={null} />
            <Field label="Punto de venta" required type="number" value={form.pto_vta}
              onChange={(e) => setForm({ ...form, pto_vta: e.target.value })} placeholder="ej: 5" />
            <Field label="Número" required type="number" value={form.numero}
              onChange={(e) => setForm({ ...form, numero: e.target.value })} placeholder="ej: 123" />
            <Field label="CUIT emisor" required value={form.cuit_emisor}
              onChange={(e) => setForm({ ...form, cuit_emisor: e.target.value })} placeholder="20-12345678-9" />
            <Field label="CAE" required value={form.cae}
              onChange={(e) => setForm({ ...form, cae: e.target.value })} placeholder="14 dígitos"
              containerClassName="col-span-2" />
            <Field label="Fecha del comprobante" type="date" value={form.fecha_cbte}
              onChange={(e) => setForm({ ...form, fecha_cbte: e.target.value })}
              hint="Opcional pero recomendado" />
            <Field label="Importe total" type="number" step="0.01" value={form.imp_total}
              onChange={(e) => setForm({ ...form, imp_total: e.target.value })}
              hint="Opcional — verifica match" />
            <div className="flex items-end">
              <Button variant="primary" onClick={submit} disabled={!valid || m.isPending} className="w-full">
                {m.isPending ? 'Consultando AFIP…' : 'Constatar'}
              </Button>
            </div>
          </div>

          {m.error && <FormError error={errorMessage(m.error)} />}

          {resp && <ResultadoPanel r={resp} />}
        </CardBody>
      </Card>
    </div>
  );
}

function ResultadoPanel({ r }: { r: ConstatacionResp }) {
  const cfg = {
    VALIDO: { Icon: ShieldCheck, cls: 'border-success/40 bg-success-bg/30', titulo: 'Comprobante VÁLIDO' },
    INVALIDO: { Icon: ShieldAlert, cls: 'border-danger/40 bg-danger-bg/30', titulo: 'Comprobante INVÁLIDO' },
    NO_ENCONTRADO: { Icon: ShieldOff, cls: 'border-warning/40 bg-warning-bg/30', titulo: 'No encontrado en AFIP' },
  }[r.resultado];

  const Icon = cfg.Icon;

  return (
    <div className={`border rounded-md p-4 ${cfg.cls}`}>
      <div className="flex items-center gap-2 font-semibold mb-3">
        <Icon className="w-5 h-5" /> {cfg.titulo}
      </div>

      {r.resultado === 'VALIDO' && (
        <div className="grid grid-cols-2 gap-3 text-[12.5px]">
          {r.cae && <Stat label="CAE" value={<code>{r.cae}</code>} />}
          {r.cae_validez && <Stat label="Válido hasta" value={r.cae_validez} />}
          {r.fecha_cbte && <Stat label="Fecha" value={r.fecha_cbte} />}
          {r.imp_total !== undefined && <Stat label="Importe total" value={fmtMoney(Number(r.imp_total))} />}
        </div>
      )}

      {r.observaciones && <div className="mt-2 text-[12px]">{r.observaciones}</div>}

      {r.obs && r.obs.length > 0 && (
        <ul className="mt-3 space-y-1 text-[11.5px]">
          {r.obs.map((o, i) => (
            <li key={i}>
              <Badge variant="warning">{o.codigo}</Badge> <span className="ml-1">{o.mensaje}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="text-[10.5px] uppercase text-ink-muted">{label}</div>
      <div className="font-medium tabular-nums">{value}</div>
    </div>
  );
}
