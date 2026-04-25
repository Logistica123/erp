import { useState } from 'react';
import { PieChart, Calculator, FileText, Download, Plus, Users, Trash2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { auth } from '@/lib/auth';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Socio = {
  id: number; cuit: string; nombre: string; tipo: 'PERSONA_FISICA' | 'PERSONA_JURIDICA';
  porcentaje_participacion: number | string;
  fecha_alta: string; fecha_baja: string | null; activo: boolean;
};

type Bp = {
  id: number; ejercicio_id: number; periodo_id: number;
  patrimonio_neto_ajustado: number | string; alicuota: number | string;
  impuesto_total: number | string;
  socios_detalle: Array<{ cuit: string; nombre: string; tipo: string; porcentaje_participacion: number; vpp: number; impuesto: number }>;
  archivo_f2000_path: string | null;
};

type ShowResp = {
  ejercicio: { id: number; numero: number; fecha_cierre: string };
  liquidacion: Bp | null;
  pn_contable: number;
  alicuota_vigente: number | null;
};

export function BpPage() {
  const [ejercicioId, setEjercicioId] = useState('');
  const [pnOverride, setPnOverride] = useState('');
  const toast = useToast();
  const invalidate = useInvalidate(['bp']);

  const { data, error, refetch } = useApi<ShowResp>(
    ['bp', ejercicioId],
    `/api/erp/impuestos/bp/${ejercicioId}`,
    { enabled: Boolean(ejercicioId) }
  );

  const calcular = useApiMutation(
    () => api.post(`/api/erp/impuestos/bp/${ejercicioId}/calcular`,
      pnOverride ? { pn_ajustado_override: Number(pnOverride) } : {}),
    {
      onSuccess: () => { toast.success('BP calculado'); invalidate(); refetch(); },
      onError: (e) => toast.error('Error al calcular', errorMessage(e)),
    }
  );
  const generar = useApiMutation(
    () => api.post(`/api/erp/impuestos/bp/${ejercicioId}/generar-f2000`),
    {
      onSuccess: () => { toast.success('F.2000 generado'); invalidate(); refetch(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );

  const descargar = () => {
    const url = `/api/erp/impuestos/bp/${ejercicioId}/descargar`;
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((blob) => {
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = `F2000_${data?.ejercicio.fecha_cierre.slice(0, 4)}.txt`; a.click();
      });
  };

  const liq = data?.liquidacion;
  const [sociosOpen, setSociosOpen] = useState(false);

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><PieChart className="w-4 h-4 text-azure" /> Bienes Personales F.2000</div>}
          actions={
            <Button variant="outline" onClick={() => setSociosOpen(true)}>
              <Users className="w-3 h-3" /> Gestionar socios
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <Field label="ID Ejercicio" required type="number" value={ejercicioId}
              onChange={(e) => setEjercicioId(e.target.value)} containerClassName="w-[160px]" />
            <Field label="PN ajustado override (RT 6)" type="number" step="0.01" value={pnOverride}
              onChange={(e) => setPnOverride(e.target.value)}
              hint="Sólo si aplica reexpresión RT 6"
              containerClassName="w-[220px]" />
            <Button variant="secondary" disabled={!ejercicioId || calcular.isPending}
              onClick={() => calcular.mutate(undefined as unknown as void)}>
              <Calculator className="w-3 h-3" /> Calcular VPP
            </Button>
            <Button variant="primary" disabled={!liq || generar.isPending}
              onClick={() => generar.mutate(undefined as unknown as void)}>
              <FileText className="w-3 h-3" /> Generar F.2000
            </Button>
            {liq?.archivo_f2000_path && (
              <Button variant="outline" onClick={descargar}>
                <Download className="w-3 h-3" /> Descargar
              </Button>
            )}
          </div>
          {error && <FormError error={errorMessage(error)} />}

          {data && (
            <div className="grid grid-cols-3 gap-3">
              <Stat label="PN contable" value={fmtMoney(data.pn_contable)} />
              <Stat label="Alícuota vigente"
                value={data.alicuota_vigente !== null ? `${(Number(data.alicuota_vigente) * 100).toFixed(2)}%` : '—'} />
              {liq && <Stat label="Impuesto total" value={fmtMoney(liq.impuesto_total)} accent="warning" />}
            </div>
          )}

          {liq?.socios_detalle && liq.socios_detalle.length > 0 && (
            <div>
              <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide mb-2">VPP por socio</h3>
              <table className="w-full text-[12px]">
                <thead className="text-[11px] text-ink-muted">
                  <tr>
                    <th className="text-left">CUIT</th>
                    <th className="text-left">Socio</th>
                    <th className="text-right">% Part</th>
                    <th className="text-right">VPP</th>
                    <th className="text-right">Impuesto</th>
                  </tr>
                </thead>
                <tbody>
                  {liq.socios_detalle.map((s, i) => (
                    <tr key={i} className="border-t border-line/60">
                      <td className="py-1.5"><code className="text-[11px]">{s.cuit}</code></td>
                      <td className="py-1.5">{s.nombre}</td>
                      <td className="py-1.5 text-right tabular-nums">{Number(s.porcentaje_participacion).toFixed(2)}%</td>
                      <td className="py-1.5 text-right tabular-nums">{fmtMoney(s.vpp)}</td>
                      <td className="py-1.5 text-right"><strong>{fmtMoney(s.impuesto)}</strong></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      {sociosOpen && <SociosModal onClose={() => setSociosOpen(false)} />}
    </div>
  );
}

function Stat({ label, value, accent }: {
  label: string; value: React.ReactNode; accent?: 'success' | 'warning' | 'danger';
}) {
  const tone = accent === 'warning' ? 'border-warning/30 bg-warning-bg/30 text-warning'
    : accent === 'danger' ? 'border-danger/30 bg-danger-bg/30 text-danger'
    : accent === 'success' ? 'border-success/30 bg-success-bg/30 text-success'
    : 'border-line bg-white text-ink-2';
  return (
    <div className={`border rounded-md p-3 ${tone}`}>
      <div className="text-[10.5px] uppercase tracking-wide mb-1">{label}</div>
      <strong className="text-[14px] tabular-nums">{value}</strong>
    </div>
  );
}

function SociosModal({ onClose }: { onClose: () => void }) {
  const { data, refetch } = useApi<{ data: Socio[]; meta: { suma_porcentaje: number; suma_correcta: boolean } }>(
    ['bp-socios'], '/api/erp/impuestos/bp/socios?solo_activos=1'
  );
  const socios = data?.data ?? [];
  const meta = data?.meta;

  const [altaOpen, setAltaOpen] = useState(false);
  const [bajaOpen, setBajaOpen] = useState<Socio | null>(null);

  const columns: Column<Socio>[] = [
    { key: 'cuit', header: 'CUIT', render: (r) => <code className="text-[11px]">{r.cuit}</code> },
    { key: 'nombre', header: 'Socio' },
    { key: 'tipo', header: 'Tipo', width: '120px',
      render: (r) => <Badge variant={r.tipo === 'PERSONA_JURIDICA' ? 'info' : 'neutral'}>
        {r.tipo === 'PERSONA_JURIDICA' ? 'PJ' : 'PF'}
      </Badge> },
    { key: 'porcentaje_participacion', header: '% Part', align: 'right', width: '90px',
      render: (r) => `${Number(r.porcentaje_participacion).toFixed(2)}%` },
    { key: 'fecha_alta', header: 'Alta', width: '90px',
      render: (r) => fmtDate(r.fecha_alta) },
    { key: 'acciones', header: '', align: 'right', width: '80px',
      render: (r) => (
        <Button size="sm" variant="ghost" onClick={() => setBajaOpen(r)}>
          <Trash2 className="w-3 h-3" />
        </Button>
      ) },
  ];

  return (
    <Modal open onClose={onClose} title="Gestionar socios" size="lg"
      footer={
        <>
          <Button variant="outline" onClick={() => setAltaOpen(true)}>
            <Plus className="w-3 h-3" /> Alta socio
          </Button>
          <Button variant="primary" onClick={onClose}>Cerrar</Button>
        </>
      }
    >
      <div className="space-y-3">
        {meta && (
          <div className={`border rounded-md p-3 text-[12.5px] ${
            meta.suma_correcta ? 'border-success/40 bg-success-bg/30' : 'border-warning/40 bg-warning-bg/30'
          }`}>
            Suma de % participación de socios activos:{' '}
            <strong>{Number(meta.suma_porcentaje).toFixed(4)}%</strong>{' '}
            {meta.suma_correcta
              ? <Badge variant="success">OK</Badge>
              : <Badge variant="warning">Debe sumar 100%</Badge>}
          </div>
        )}
        <DataTable columns={columns} rows={socios} empty="Sin socios activos" />
      </div>

      {altaOpen && <AltaSocioModal onClose={() => { setAltaOpen(false); refetch(); }} />}
      {bajaOpen && <BajaSocioConfirm socio={bajaOpen} onClose={() => { setBajaOpen(null); refetch(); }} />}
    </Modal>
  );
}

function AltaSocioModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const [form, setForm] = useState({
    cuit: '', nombre: '', tipo: 'PERSONA_FISICA',
    porcentaje_participacion: '', fecha_alta: new Date().toISOString().slice(0, 10),
  });
  const m = useApiMutation(
    () => api.post('/api/erp/impuestos/bp/socios', {
      cuit: form.cuit, nombre: form.nombre, tipo: form.tipo,
      porcentaje_participacion: Number(form.porcentaje_participacion),
      fecha_alta: form.fecha_alta,
    }),
    {
      onSuccess: () => { toast.success('Socio dado de alta'); onClose(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );
  const valid = /^\d{11}$/.test(form.cuit) && form.nombre.trim().length > 2 &&
    Number(form.porcentaje_participacion) > 0 && form.fecha_alta;
  return (
    <Modal open onClose={onClose} title="Alta socio" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate(undefined as unknown as void)}>
            {m.isPending ? 'Guardando…' : 'Crear'}
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-3">
        <Field label="CUIT (11 dígitos)" required value={form.cuit} maxLength={11}
          onChange={(e) => setForm({ ...form, cuit: e.target.value.replace(/\D/g, '') })} />
        <SelectField label="Tipo" required value={form.tipo}
          onChange={(e) => setForm({ ...form, tipo: e.target.value })}
          options={[
            { value: 'PERSONA_FISICA', label: 'Persona física' },
            { value: 'PERSONA_JURIDICA', label: 'Persona jurídica' },
          ]} placeholder={null} />
        <Field containerClassName="col-span-2" label="Nombre / Razón social" required
          value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} />
        <Field label="% Participación" required type="number" step="0.0001"
          value={form.porcentaje_participacion}
          onChange={(e) => setForm({ ...form, porcentaje_participacion: e.target.value })} />
        <Field label="Fecha alta" required type="date" value={form.fecha_alta}
          onChange={(e) => setForm({ ...form, fecha_alta: e.target.value })} />
      </div>
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}

function BajaSocioConfirm({ socio, onClose }: { socio: Socio; onClose: () => void }) {
  const toast = useToast();
  const m = useApiMutation(
    () => api.delete(`/api/erp/impuestos/bp/socios/${socio.id}`),
    {
      onSuccess: () => { toast.success('Socio dado de baja'); onClose(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );
  return (
    <ConfirmDialog
      open onClose={onClose}
      onConfirm={() => m.mutate(undefined as unknown as void)}
      title={`Baja de ${socio.nombre}`}
      message={
        <>Marcar al socio <strong>{socio.nombre}</strong> como inactivo (fecha_baja=hoy).
          La suma de %participación de los socios activos quedará en{' '}
          <strong>(actual − {Number(socio.porcentaje_participacion).toFixed(2)}%)</strong>.
        </>
      }
      variant="danger"
      loading={m.isPending}
    />
  );
}
