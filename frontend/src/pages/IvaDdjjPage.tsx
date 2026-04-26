import { useState } from 'react';
import { Receipt, FileText, Download, Calculator, Banknote } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { fmtMoney, fmtDate } from '@/components/ui/DataTable';
import { PeriodosFiscalesCard } from '@/components/impuestos/PeriodosFiscalesCard';
import { auth } from '@/lib/auth';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type DDJJ = {
  id: number; periodo_id: number;
  debito_fiscal: number | string; credito_fiscal: number | string;
  saldo_tecnico: number | string; saldo_libre_disp_anterior: number | string;
  retenciones_sufridas: number | string; percepciones_sufridas: number | string;
  pagos_a_cuenta: number | string;
  saldo_libre_disp_final: number | string; importe_a_pagar: number | string;
  archivo_f2002_path: string | null; archivo_f2002_hash: string | null;
  generado_at: string | null; volante_pago_id: number | null;
};

type Periodo = { id: number; impuesto: string; anio: number; mes: number; estado: string };

type ShowResp = { periodo: Periodo; ddjj: DDJJ | null };

export function IvaDdjjPage() {
  const [periodoId, setPeriodoId] = useState('');
  const [pagosACuenta, setPagosACuenta] = useState('');

  const toast = useToast();
  const invalidate = useInvalidate(['iva-ddjj']);

  const { data, error, refetch } = useApi<ShowResp>(
    ['iva-ddjj', periodoId],
    `/api/erp/impuestos/iva/${periodoId}`,
    { enabled: Boolean(periodoId) }
  );

  const calcular = useApiMutation<DDJJ, { pagos_a_cuenta?: number }>(
    (vars) => api.post(`/api/erp/impuestos/iva/${periodoId}/calcular`, vars),
    {
      onSuccess: () => { toast.success('DDJJ calculada'); invalidate(); refetch(); },
      onError: (e) => toast.error('Error al calcular', errorMessage(e)),
    }
  );

  const generar = useApiMutation<{ path: string; hash: string; importe_a_pagar: number }, { pagos_a_cuenta?: number }>(
    (vars) => api.post(`/api/erp/impuestos/iva/${periodoId}/generar-f2002`, vars),
    {
      onSuccess: (r) => {
        toast.success('F.2002 generado', `A pagar: ${fmtMoney(r.importe_a_pagar)}`);
        invalidate(); refetch();
      },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );

  const [opOpen, setOpOpen] = useState(false);

  const descargar = () => {
    const url = `/api/erp/impuestos/iva/${periodoId}/descargar`;
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `F2002_${data?.periodo.anio}-${String(data?.periodo.mes).padStart(2, '0')}.txt`;
        a.click();
      });
  };

  const ddjj = data?.ddjj;

  return (
    <div className="p-6 space-y-4">
      <PeriodosFiscalesCard
        impuesto="IVA"
        selectedId={periodoId}
        onSelect={(id) => setPeriodoId(String(id))}
        titulo="Períodos IVA F.2002 — click para seleccionar"
      />

      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><Receipt className="w-4 h-4 text-azure" /> DDJJ IVA F.2002</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <Field label="ID Período seleccionado" required type="number" value={periodoId}
              readOnly
              hint="Elegí un período de la tabla de arriba"
              containerClassName="w-[180px]" />
            <Field label="Pagos a cuenta" type="number" step="0.01" value={pagosACuenta}
              onChange={(e) => setPagosACuenta(e.target.value)}
              hint="Anticipos pagados o saldo a favor manual"
              containerClassName="w-[180px]" />
            <Button variant="secondary" disabled={!periodoId || calcular.isPending}
              onClick={() => calcular.mutate({ pagos_a_cuenta: Number(pagosACuenta) || 0 })}>
              <Calculator className="w-3 h-3" /> Calcular
            </Button>
            <Button variant="primary" disabled={!periodoId || generar.isPending}
              onClick={() => generar.mutate({ pagos_a_cuenta: Number(pagosACuenta) || 0 })}>
              <FileText className="w-3 h-3" /> {generar.isPending ? 'Generando…' : 'Generar F.2002'}
            </Button>
            {ddjj?.archivo_f2002_path && (
              <Button variant="outline" onClick={descargar}>
                <Download className="w-3 h-3" /> Descargar
              </Button>
            )}
            {ddjj && Number(ddjj.importe_a_pagar) > 0 && !ddjj.volante_pago_id && (
              <Button variant="outline" onClick={() => setOpOpen(true)}>
                <Banknote className="w-3 h-3" /> Generar OP
              </Button>
            )}
          </div>
          {error && <FormError error={errorMessage(error)} />}

          {data?.periodo && (
            <div className="text-[12.5px] text-ink-2">
              Período: <strong>{data.periodo.anio}/{String(data.periodo.mes).padStart(2, '0')}</strong>{' '}
              · Estado: <Badge variant={data.periodo.estado === 'ABIERTO' ? 'warning' : 'info'}>{data.periodo.estado}</Badge>
            </div>
          )}

          {ddjj && (
            <div className="grid grid-cols-3 gap-3 mt-2">
              <Stat label="Débito fiscal" value={fmtMoney(ddjj.debito_fiscal)} />
              <Stat label="Crédito fiscal" value={fmtMoney(ddjj.credito_fiscal)} />
              <Stat label="Saldo técnico" value={fmtMoney(ddjj.saldo_tecnico)}
                accent={Number(ddjj.saldo_tecnico) > 0 ? 'warning' : 'success'} />

              <Stat label="Saldo libre disp anterior (RN-51)" value={fmtMoney(ddjj.saldo_libre_disp_anterior)} />
              <Stat label="Percepciones sufridas" value={fmtMoney(ddjj.percepciones_sufridas)} />
              <Stat label="Retenciones sufridas" value={fmtMoney(ddjj.retenciones_sufridas)} />

              <Stat label="Pagos a cuenta" value={fmtMoney(ddjj.pagos_a_cuenta)} />
              <Stat label="Saldo libre disp final" value={fmtMoney(ddjj.saldo_libre_disp_final)} accent="success" />
              <Stat label="IMPORTE A PAGAR" value={fmtMoney(ddjj.importe_a_pagar)}
                accent={Number(ddjj.importe_a_pagar) > 0 ? 'danger' : 'success'} />

              {ddjj.generado_at && (
                <div className="col-span-3 text-[11px] text-ink-muted">
                  TXT generado el {fmtDate(ddjj.generado_at)} · hash {ddjj.archivo_f2002_hash?.slice(0, 16)}…
                  {ddjj.volante_pago_id && <> · OP #{ddjj.volante_pago_id}</>}
                </div>
              )}
            </div>
          )}
        </CardBody>
      </Card>

      {opOpen && ddjj && (
        <GenerarOpModal periodoId={Number(periodoId)} ddjj={ddjj} onClose={() => setOpOpen(false)} />
      )}
    </div>
  );
}

function Stat({ label, value, accent }: {
  label: string; value: React.ReactNode; accent?: 'success' | 'warning' | 'danger';
}) {
  const tone = accent === 'success' ? 'border-success/30 bg-success-bg/30 text-success'
    : accent === 'warning' ? 'border-warning/30 bg-warning-bg/30 text-warning'
    : accent === 'danger' ? 'border-danger/30 bg-danger-bg/30 text-danger'
    : 'border-line bg-white text-ink-2';
  return (
    <div className={`border rounded-md p-3 ${tone}`}>
      <div className="text-[10.5px] uppercase tracking-wide mb-1">{label}</div>
      <strong className="text-[14px] tabular-nums">{value}</strong>
    </div>
  );
}

function GenerarOpModal({ periodoId, ddjj, onClose }: { periodoId: number; ddjj: DDJJ; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['iva-ddjj']);

  type Aux = { id: number; codigo: string; nombre: string };
  const { data: auxiliares } = useApi<Aux[]>(['auxiliares','afip'], '/api/erp/auxiliares?tipo=Organismo');

  const [auxiliarId, setAuxiliarId] = useState('');
  const [concepto, setConcepto] = useState('');

  const m = useApiMutation<{ op_id: number; numero: string }, Record<string, unknown>>(
    (vars) => api.post(`/api/erp/impuestos/iva/${periodoId}/generar-op`, vars),
    {
      onSuccess: (r) => {
        toast.success('OP creada', `Nº ${r.numero} en BORRADOR — completar en Tesorería`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo crear OP', errorMessage(e)),
    }
  );

  return (
    <Modal open onClose={onClose} title="Generar Orden de Pago AFIP" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!auxiliarId || m.isPending}
            onClick={() => m.mutate({
              auxiliar_id: Number(auxiliarId), moneda_id: 1, concepto: concepto || undefined,
            })}>
            {m.isPending ? 'Creando…' : `Crear OP por ${fmtMoney(ddjj.importe_a_pagar)}`}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="text-[12.5px] bg-warning-bg/40 border border-warning/30 rounded-md p-3">
          La OP queda en BORRADOR. Completá medios de pago y libéra desde Tesorería.
        </div>
        <SelectField label="Auxiliar AFIP / Organismo" required value={auxiliarId}
          onChange={(e) => setAuxiliarId(e.target.value)}
          options={(auxiliares ?? []).map((a) => ({ value: a.id, label: `${a.codigo} ${a.nombre}` }))}
          placeholder="Elegí…"
          hint="Si no existe, crealo desde Tesorería primero." />
        <TextareaField label="Concepto" rows={2} value={concepto}
          onChange={(e) => setConcepto(e.target.value)}
          placeholder={`DDJJ IVA F.2002 ${new Date().getMonth() + 1}/${new Date().getFullYear()}`} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
