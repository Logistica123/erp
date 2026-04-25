import { useState } from 'react';
import { PieChart, Calculator, FileText, Download, Plus } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { auth } from '@/lib/auth';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Liquidacion = {
  id: number; ejercicio_id: number; periodo_id: number;
  resultado_contable: number | string;
  ajustes_fiscales_mas: number | string; ajustes_fiscales_menos: number | string;
  resultado_impositivo: number | string; impuesto_determinado: number | string;
  anticipos_computados: number | string; retenciones_sufridas: number | string;
  percepciones_sufridas: number | string;
  saldo_a_pagar: number | string; saldo_a_favor: number | string;
  ajusta_por_inflacion: boolean;
  alicuota_escalonada: { breakdown_tramos?: any[]; ajustes?: any[] } | null;
  archivo_f713_path: string | null; archivo_f713_hash: string | null;
  generado_at: string | null;
  anticipos?: Anticipo[];
};

type Anticipo = {
  id: number; nro_anticipo: number; fecha_vencimiento: string;
  base_calculo: number | string; porcentaje: number | string; importe: number | string;
  estado: 'PENDIENTE' | 'PAGADO' | 'COMPENSADO' | 'EXIMIDO';
  fecha_pago: string | null; orden_pago_id: number | null;
};

type Ejercicio = { id: number; numero: number; fecha_inicio: string; fecha_cierre: string; estado: string };

type ShowResp = { ejercicio: Ejercicio; liquidacion: Liquidacion | null };

export function GananciasPage() {
  const [ejercicioId, setEjercicioId] = useState('');
  const toast = useToast();
  const invalidate = useInvalidate(['ganancias']);

  const { data, error, refetch } = useApi<ShowResp>(
    ['ganancias', ejercicioId],
    `/api/erp/impuestos/ganancias/${ejercicioId}`,
    { enabled: Boolean(ejercicioId) }
  );

  const calcular = useApiMutation<Liquidacion, Record<string, unknown>>(
    (vars) => api.post(`/api/erp/impuestos/ganancias/${ejercicioId}/calcular`, vars),
    {
      onSuccess: () => { toast.success('Liquidación calculada'); invalidate(); refetch(); },
      onError: (e) => toast.error('Error al calcular', errorMessage(e)),
    }
  );
  const generar = useApiMutation(
    () => api.post(`/api/erp/impuestos/ganancias/${ejercicioId}/generar-f713`),
    {
      onSuccess: () => { toast.success('F.713 generado'); invalidate(); refetch(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );
  const generarAnticipos = useApiMutation(
    () => api.post(`/api/erp/impuestos/ganancias/${ejercicioId}/generar-anticipos`),
    {
      onSuccess: () => { toast.success('10 anticipos generados'); invalidate(); refetch(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );

  const [ajusteOpen, setAjusteOpen] = useState(false);

  const descargar = () => {
    const url = `/api/erp/impuestos/ganancias/${ejercicioId}/descargar`;
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((blob) => {
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = `F713_${data?.ejercicio.fecha_cierre.slice(0, 4)}.txt`; a.click();
      });
  };

  const liq = data?.liquidacion;
  const ajustes = liq?.alicuota_escalonada?.ajustes ?? [];
  const tramos = liq?.alicuota_escalonada?.breakdown_tramos ?? [];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><PieChart className="w-4 h-4 text-azure" /> Ganancias F.713</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <Field label="ID Ejercicio" required type="number" value={ejercicioId}
              onChange={(e) => setEjercicioId(e.target.value)} containerClassName="w-[160px]" />
            <Button variant="secondary" onClick={() => calcular.mutate({})}
              disabled={!ejercicioId || calcular.isPending}>
              <Calculator className="w-3 h-3" /> Calcular
            </Button>
            <Button variant="secondary" onClick={() => setAjusteOpen(true)}
              disabled={!liq}>
              <Plus className="w-3 h-3" /> Ajuste fiscal
            </Button>
            <Button variant="primary" onClick={() => generar.mutate(undefined as unknown as void)}
              disabled={!liq || generar.isPending}>
              <FileText className="w-3 h-3" /> Generar F.713
            </Button>
            {liq?.archivo_f713_path && (
              <Button variant="outline" onClick={descargar}>
                <Download className="w-3 h-3" /> Descargar
              </Button>
            )}
            <Button variant="outline" onClick={() => generarAnticipos.mutate(undefined as unknown as void)}
              disabled={!liq || Number(liq.impuesto_determinado) <= 0 || generarAnticipos.isPending}>
              Generar 10 anticipos
            </Button>
          </div>
          {error && <FormError error={errorMessage(error)} />}

          {data?.ejercicio && (
            <div className="text-[12.5px] text-ink-2">
              Ejercicio Nº <strong>{data.ejercicio.numero}</strong>{' '}
              {fmtDate(data.ejercicio.fecha_inicio)} → {fmtDate(data.ejercicio.fecha_cierre)}{' '}
              · <Badge variant={data.ejercicio.estado === 'CERRADO' ? 'success' : 'warning'}>{data.ejercicio.estado}</Badge>
            </div>
          )}

          {liq && (
            <div className="grid grid-cols-3 gap-3">
              <Stat label="Resultado contable" value={fmtMoney(liq.resultado_contable)} />
              <Stat label="Ajustes (+)" value={fmtMoney(liq.ajustes_fiscales_mas)} />
              <Stat label="Ajustes (−)" value={fmtMoney(liq.ajustes_fiscales_menos)} />
              <Stat label="Resultado impositivo" value={fmtMoney(liq.resultado_impositivo)} accent="info" />
              <Stat label="Impuesto determinado" value={fmtMoney(liq.impuesto_determinado)} accent="warning" />
              <Stat label="Saldo a pagar" value={fmtMoney(liq.saldo_a_pagar)} accent="danger" />
              <Stat label="Anticipos computados" value={fmtMoney(liq.anticipos_computados)} />
              <Stat label="Retenciones sufridas" value={fmtMoney(liq.retenciones_sufridas)} />
              <Stat label="Saldo a favor" value={fmtMoney(liq.saldo_a_favor)} accent="success" />
            </div>
          )}

          {tramos.length > 0 && (
            <div>
              <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide mb-2">Memoria de cálculo (escala art 73)</h3>
              <table className="w-full text-[12px]">
                <thead className="text-[11px] text-ink-muted">
                  <tr>
                    <th className="text-left">Tramo</th>
                    <th className="text-right">Lím inf</th>
                    <th className="text-right">Lím sup</th>
                    <th className="text-right">Cuota fija</th>
                    <th className="text-right">Alic marginal</th>
                    <th className="text-right">Impuesto</th>
                  </tr>
                </thead>
                <tbody>
                  {tramos.map((t: any, i: number) => (
                    <tr key={i} className="border-t border-line/60">
                      <td className="py-1.5">{t.tramo}</td>
                      <td className="py-1.5 text-right">{fmtMoney(t.limite_inferior)}</td>
                      <td className="py-1.5 text-right">{t.limite_superior !== null ? fmtMoney(t.limite_superior) : '∞'}</td>
                      <td className="py-1.5 text-right">{fmtMoney(t.cuota_fija)}</td>
                      <td className="py-1.5 text-right">{(Number(t.alicuota_marginal) * 100).toFixed(2)}%</td>
                      <td className="py-1.5 text-right"><strong>{fmtMoney(t.impuesto)}</strong></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {ajustes.length > 0 && (
            <div>
              <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide mb-2">Ajustes fiscales aplicados</h3>
              <ul className="space-y-1">
                {ajustes.map((a: any, i: number) => (
                  <li key={i} className="text-[12px] flex justify-between gap-3 border-b border-line/60 py-1">
                    <span>
                      <Badge variant={a.tipo === 'MAS' ? 'warning' : 'info'}>{a.tipo}</Badge>{' '}
                      <span className="ml-2 text-ink-2">{a.concepto}</span>
                      {a.descripcion && <span className="text-ink-muted ml-2">— {a.descripcion}</span>}
                    </span>
                    <strong>{fmtMoney(a.importe)}</strong>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {liq?.anticipos && liq.anticipos.length > 0 && (
            <AnticiposTable anticipos={liq.anticipos} />
          )}
        </CardBody>
      </Card>

      {ajusteOpen && (
        <AgregarAjusteModal ejercicioId={Number(ejercicioId)} onClose={() => setAjusteOpen(false)} />
      )}
    </div>
  );
}

function AnticiposTable({ anticipos }: { anticipos: Anticipo[] }) {
  const columns: Column<Anticipo>[] = [
    { key: 'nro_anticipo', header: '#', width: '50px' },
    { key: 'fecha_vencimiento', header: 'Vence', width: '110px',
      render: (r) => fmtDate(r.fecha_vencimiento) },
    { key: 'porcentaje', header: '%', align: 'right', width: '70px',
      render: (r) => `${Number(r.porcentaje).toFixed(2)}%` },
    { key: 'importe', header: 'Importe', align: 'right',
      render: (r) => <strong>{fmtMoney(r.importe)}</strong> },
    { key: 'estado', header: 'Estado',
      render: (r) => <Badge variant={r.estado === 'PAGADO' ? 'success' : r.estado === 'PENDIENTE' ? 'warning' : 'info'}>
        {r.estado}
      </Badge> },
    { key: 'fecha_pago', header: 'Pagado', width: '110px',
      render: (r) => r.fecha_pago ? fmtDate(r.fecha_pago) : '—' },
  ];
  return (
    <div>
      <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide mb-2">Anticipos del ejercicio siguiente (RG 5211)</h3>
      <DataTable columns={columns} rows={anticipos} />
    </div>
  );
}

function Stat({ label, value, accent }: {
  label: string; value: React.ReactNode; accent?: 'success' | 'warning' | 'danger' | 'info';
}) {
  const tone = accent === 'success' ? 'border-success/30 bg-success-bg/30 text-success'
    : accent === 'warning' ? 'border-warning/30 bg-warning-bg/30 text-warning'
    : accent === 'danger' ? 'border-danger/30 bg-danger-bg/30 text-danger'
    : accent === 'info' ? 'border-azure/30 bg-blue-50 text-azure'
    : 'border-line bg-white text-ink-2';
  return (
    <div className={`border rounded-md p-3 ${tone}`}>
      <div className="text-[10.5px] uppercase tracking-wide mb-1">{label}</div>
      <strong className="text-[14px] tabular-nums">{value}</strong>
    </div>
  );
}

function AgregarAjusteModal({ ejercicioId, onClose }: { ejercicioId: number; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['ganancias']);
  const [form, setForm] = useState({ tipo: 'MAS', concepto: '', importe: '', descripcion: '' });

  const m = useApiMutation(
    () => api.post(`/api/erp/impuestos/ganancias/${ejercicioId}/agregar-ajuste`, {
      tipo: form.tipo, concepto: form.concepto, importe: Number(form.importe),
      descripcion: form.descripcion || undefined,
    }),
    {
      onSuccess: () => { toast.success('Ajuste agregado'); invalidate(); onClose(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );
  const valid = form.concepto.trim().length >= 3 && Number(form.importe) > 0;

  return (
    <Modal open onClose={onClose} title="Agregar ajuste fiscal" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending} onClick={() => m.mutate(undefined as unknown as void)}>
            {m.isPending ? 'Guardando…' : 'Agregar'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="grid grid-cols-3 gap-3">
          <SelectField label="Tipo" required value={form.tipo}
            onChange={(e) => setForm({ ...form, tipo: e.target.value })}
            options={[
              { value: 'MAS', label: 'MAS — suma al impositivo' },
              { value: 'MENOS', label: 'MENOS — resta del impositivo' },
            ]} placeholder={null} />
          <Field label="Concepto (código)" required value={form.concepto}
            onChange={(e) => setForm({ ...form, concepto: e.target.value })}
            placeholder="MULTAS_SANCIONES" />
          <Field label="Importe" required type="number" step="0.01" value={form.importe}
            onChange={(e) => setForm({ ...form, importe: e.target.value })} />
        </div>
        <TextareaField label="Descripción (opcional)" rows={2} value={form.descripcion}
          onChange={(e) => setForm({ ...form, descripcion: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
