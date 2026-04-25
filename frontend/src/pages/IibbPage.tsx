import { useState } from 'react';
import { Receipt, Calculator, FileText, Download, Save } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, FormError } from '@/components/ui/Field';
import { auth } from '@/lib/auth';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Detalle = {
  id: number;
  tipo: 'CM03' | 'CM05';
  jurisdiccion: string;
  base_imponible: number | string;
  coeficiente: number | string;
  base_atribuida: number | string;
  alicuota: number | string;
  impuesto_determinado: number | string;
  importe_a_pagar: number | string;
};

type Coef = {
  id: number;
  anio_vigencia: number;
  jurisdiccion: string;
  coeficiente: number | string;
  estado: 'DRAFT' | 'VIGENTE';
  origen: 'CM05' | 'MANUAL';
};

const KIND_CONFIG = {
  cm: { label: 'IIBB Convenio Multilateral', endpoint: 'iibb/cm' },
  caba: { label: 'IIBB CABA (ARCiBA)', endpoint: 'iibb/caba' },
  pba: { label: 'IIBB PBA (ARBA)', endpoint: 'iibb/pba' },
};

export function IibbPage({ kind }: { kind: 'cm' | 'caba' | 'pba' }) {
  const cfg = KIND_CONFIG[kind];
  const [periodoId, setPeriodoId] = useState('');
  const toast = useToast();
  const invalidate = useInvalidate(['iibb', kind]);

  const { data, error, refetch } = useApi<{ periodo: Record<string, unknown>; detalle: Detalle[] | Detalle }>(
    ['iibb', kind, periodoId],
    `/api/erp/impuestos/${cfg.endpoint}/${periodoId}`,
    { enabled: Boolean(periodoId) }
  );

  const calcular = useApiMutation(
    () => api.post(`/api/erp/impuestos/${cfg.endpoint}/${periodoId}/calcular`),
    {
      onSuccess: () => { toast.success('IIBB calculado'); invalidate(); refetch(); },
      onError: (e) => toast.error('Error al calcular', errorMessage(e)),
    }
  );

  const generar = useApiMutation(
    () => api.post(`/api/erp/impuestos/${cfg.endpoint}/${periodoId}/${kind === 'cm' ? 'generar-sifere' : 'generar'}`),
    {
      onSuccess: () => { toast.success('TXT IIBB generado'); invalidate(); refetch(); },
      onError: (e) => toast.error('Error generando', errorMessage(e)),
    }
  );

  const descargar = () => {
    const url = `/api/erp/impuestos/iibb/${periodoId}/descargar`;
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `IIBB_${kind}_${periodoId}.txt`;
        a.click();
      });
  };

  const filas = Array.isArray(data?.detalle) ? data!.detalle : data?.detalle ? [data.detalle] : [];
  const totalDeterminado = filas.reduce((s, f) => s + Number(f.impuesto_determinado || 0), 0);
  const totalAPagar = filas.reduce((s, f) => s + Number(f.importe_a_pagar || 0), 0);

  const columns: Column<Detalle>[] = [
    { key: 'jurisdiccion', header: 'Jur.', width: '70px' },
    { key: 'base_imponible', header: 'Base imponible', align: 'right',
      render: (r) => fmtMoney(r.base_imponible) },
    { key: 'coeficiente', header: 'Coef.', align: 'right', width: '100px',
      render: (r) => Number(r.coeficiente).toFixed(8) },
    { key: 'base_atribuida', header: 'Base atribuida', align: 'right',
      render: (r) => fmtMoney(r.base_atribuida) },
    { key: 'alicuota', header: 'Alic.', align: 'right', width: '70px',
      render: (r) => `${(Number(r.alicuota) * 100).toFixed(2)}%` },
    { key: 'impuesto_determinado', header: 'Impuesto', align: 'right',
      render: (r) => fmtMoney(r.impuesto_determinado) },
    { key: 'importe_a_pagar', header: 'A pagar', align: 'right',
      render: (r) => <strong>{fmtMoney(r.importe_a_pagar)}</strong> },
  ];

  const [coefOpen, setCoefOpen] = useState(false);

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Receipt className="w-4 h-4 text-azure" /> {cfg.label}</div>}
          actions={kind === 'cm' ? (
            <Button variant="outline" onClick={() => setCoefOpen(true)}>
              Coeficientes CM05
            </Button>
          ) : undefined}
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <Field label="ID Período" required type="number" value={periodoId}
              onChange={(e) => setPeriodoId(e.target.value)}
              containerClassName="w-[160px]" />
            <Button variant="secondary" disabled={!periodoId || calcular.isPending}
              onClick={() => calcular.mutate(undefined as unknown as void)}>
              <Calculator className="w-3 h-3" /> Calcular
            </Button>
            <Button variant="primary" disabled={!periodoId || generar.isPending}
              onClick={() => generar.mutate(undefined as unknown as void)}>
              <FileText className="w-3 h-3" /> Generar TXT
            </Button>
            {filas.length > 0 && (
              <Button variant="outline" onClick={descargar}>
                <Download className="w-3 h-3" /> Descargar
              </Button>
            )}
          </div>
          {error && <FormError error={errorMessage(error)} />}

          {filas.length > 0 && (
            <>
              <div className="grid grid-cols-2 gap-3">
                <Stat label="Impuesto determinado total" value={fmtMoney(totalDeterminado)} />
                <Stat label="Importe total a pagar" value={fmtMoney(totalAPagar)}
                  accent={totalAPagar > 0 ? 'danger' : 'success'} />
              </div>
              <DataTable columns={columns} rows={filas} empty="Sin filas" />
            </>
          )}
        </CardBody>
      </Card>

      {coefOpen && <CoeficientesModal onClose={() => setCoefOpen(false)} />}
    </div>
  );
}

function Stat({ label, value, accent }: {
  label: string; value: React.ReactNode; accent?: 'success' | 'danger';
}) {
  const tone = accent === 'success' ? 'border-success/30 bg-success-bg/30 text-success'
    : accent === 'danger' ? 'border-danger/30 bg-danger-bg/30 text-danger'
    : 'border-line bg-white text-ink-2';
  return (
    <div className={`border rounded-md p-3 ${tone}`}>
      <div className="text-[10.5px] uppercase tracking-wide mb-1">{label}</div>
      <strong className="text-[16px] tabular-nums">{value}</strong>
    </div>
  );
}

function CoeficientesModal({ onClose }: { onClose: () => void }) {
  const [anio, setAnio] = useState(new Date().getFullYear());
  const { data: coefs, refetch } = useApi<Coef[]>(
    ['iibb-coefs', anio],
    `/api/erp/impuestos/iibb/cm05/${anio}/coeficientes`
  );
  const toast = useToast();

  const aprobar = useApiMutation(
    () => api.post(`/api/erp/impuestos/iibb/cm05/${anio}/aprobar`),
    {
      onSuccess: () => { toast.success('Coeficientes aprobados'); refetch(); },
      onError: (e) => toast.error('Error al aprobar', errorMessage(e)),
    }
  );

  return (
    <Modal open onClose={onClose} title="Coeficientes IIBB CM" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cerrar</Button>
          <Button variant="primary" onClick={() => aprobar.mutate(undefined as unknown as void)}
            disabled={aprobar.isPending || !(coefs ?? []).some((c) => c.estado === 'DRAFT')}>
            <Save className="w-3 h-3" /> Aprobar DRAFTs → VIGENTE
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Field label="Año vigencia" type="number" value={String(anio)}
          onChange={(e) => setAnio(Number(e.target.value))}
          containerClassName="w-[140px]" />
        <table className="w-full text-[12px]">
          <thead className="text-[11px] text-ink-muted">
            <tr>
              <th className="text-left">Jur</th>
              <th className="text-right">Coef</th>
              <th className="text-left">Origen</th>
              <th className="text-left">Estado</th>
            </tr>
          </thead>
          <tbody>
            {(coefs ?? []).map((c) => (
              <tr key={c.id} className="border-t border-line/60">
                <td className="py-1.5">{c.jurisdiccion}</td>
                <td className="py-1.5 text-right tabular-nums">{Number(c.coeficiente).toFixed(8)}</td>
                <td className="py-1.5 text-[10.5px]">{c.origen}</td>
                <td className="py-1.5">
                  <Badge variant={c.estado === 'VIGENTE' ? 'success' : 'warning'}>{c.estado}</Badge>
                </td>
              </tr>
            ))}
            {(coefs ?? []).length === 0 && (
              <tr><td colSpan={4} className="py-4 text-center text-ink-muted">
                Sin coeficientes para {anio}. Calculá CM05 desde el período fiscal correspondiente.
              </td></tr>
            )}
          </tbody>
        </table>
      </div>
    </Modal>
  );
}
