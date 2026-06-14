import { useState } from 'react';
import { Scale } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { fmtMoney } from '@/components/ui/DataTable';
import { Field, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApiMutation, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type SaldoResp = { cuenta_gasto: string; desde: string; hasta: string; saldo: number };

const HOY = new Date();
const Y = HOY.getFullYear();

export function ReclasificarIiddyccPage() {
  const toast = useToast();
  const [form, setForm] = useState({
    desde: `${Y}-01-01`,
    hasta: `${Y}-${String(HOY.getMonth() + 1).padStart(2, '0')}-${String(HOY.getDate()).padStart(2, '0')}`,
    porcentaje: '33',
    fecha: '',
    observaciones: '',
  });
  const [saldo, setSaldo] = useState<SaldoResp | null>(null);

  const consultar = useApiMutation<SaldoResp, void>(
    () => api.get(`/api/erp/contabilidad/iiddycc/saldo-acumulado?desde=${form.desde}&hasta=${form.hasta}`),
    { onSuccess: (d) => setSaldo(d), onError: (e) => toast.error('No se pudo consultar', errorMessage(e)) },
  );

  const generar = useApiMutation<{ asiento_id: number; numero: number }, Record<string, unknown>>(
    (v) => api.post('/api/erp/contabilidad/iiddycc/reclasificar', v),
    {
      onSuccess: (d) => { toast.success('Asiento generado', `Reclasificación contabilizada (asiento #${d.numero}).`); setSaldo(null); },
      onError: (e) => toast.error('No se pudo generar', errorMessage(e)),
    },
  );

  const pct = Number(form.porcentaje) || 0;
  const reclasificar = saldo ? Math.round(saldo.saldo * pct) / 100 : 0;

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={<div className="flex items-center gap-2"><Scale className="w-4 h-4 text-azure" /> Reclasificar Imp. Débitos y Créditos (Ley 25413)</div>} />
        <CardBody className="p-4 space-y-4 max-w-2xl">
          <div className="text-[12px] text-ink-muted">
            Día a día el impuesto se registra 100% al gasto <code>5.4.04</code>. Acá el contador
            reclasifica el % computable como crédito fiscal <code>1.1.6.12</code> al cierre
            (trimestral / anual). Genera el asiento <strong>Db 1.1.6.12 / Cr 5.4.04</strong>.
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Período desde" type="date" value={form.desde}
              onChange={(e) => { setForm({ ...form, desde: e.target.value }); setSaldo(null); }} />
            <Field label="Período hasta" type="date" value={form.hasta}
              onChange={(e) => { setForm({ ...form, hasta: e.target.value }); setSaldo(null); }} />
          </div>
          <Button variant="secondary" disabled={consultar.isPending} onClick={() => consultar.mutate()}>
            {consultar.isPending ? 'Consultando…' : 'Consultar saldo acumulado'}
          </Button>

          {saldo && (
            <div className="border border-line rounded-md p-3 space-y-3 bg-surface-row">
              <div className="flex justify-between text-[13px]">
                <span>Saldo cuenta {saldo.cuenta_gasto} (gasto) en el período:</span>
                <strong className="tabular-nums">{fmtMoney(saldo.saldo)}</strong>
              </div>
              <Field label="% a reclasificar como crédito fiscal" type="number" step="0.01" value={form.porcentaje}
                onChange={(e) => setForm({ ...form, porcentaje: e.target.value })} containerClassName="w-[220px]" />
              <div className="border border-line rounded p-2 text-[12.5px] bg-surface-base">
                <div className="font-semibold mb-1">Asiento a generar:</div>
                <div className="flex justify-between tabular-nums"><span>Db 1.1.6.12 Impuesto Débitos y Créditos a Computar</span><span>{fmtMoney(reclasificar)}</span></div>
                <div className="flex justify-between tabular-nums"><span>Cr 5.4.04 Impuesto sobre Débitos y Créditos Bancarios</span><span>{fmtMoney(reclasificar)}</span></div>
              </div>
              <Field label="Fecha del asiento (vacío = último día del 'hasta')" type="date" value={form.fecha}
                onChange={(e) => setForm({ ...form, fecha: e.target.value })} containerClassName="w-[260px]" />
              <TextareaField label="Observaciones" value={form.observaciones} rows={2}
                onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
              <FormError error={generar.error ? errorMessage(generar.error) : null} />
              <div className="text-right">
                <Button variant="primary" disabled={reclasificar <= 0 || generar.isPending}
                  onClick={() => generar.mutate({
                    desde: form.desde, hasta: form.hasta, porcentaje: pct,
                    fecha: form.fecha || undefined, observaciones: form.observaciones || undefined,
                  })}>
                  {generar.isPending ? 'Generando…' : 'Generar asiento'}
                </Button>
              </div>
            </div>
          )}
          <FormError error={consultar.error ? errorMessage(consultar.error) : null} />
        </CardBody>
      </Card>
    </div>
  );
}
