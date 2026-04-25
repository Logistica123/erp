import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus, Calculator, CheckCircle2, X, GitBranch, FileText, Eye, BookCheck } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { auth } from '@/lib/auth';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Liquidacion = {
  id: number; periodo: string; tipo: 'MENSUAL' | 'SAC' | 'AJUSTE' | 'FINAL';
  estado: 'BORRADOR' | 'CALCULADA' | 'APROBADA' | 'PAGADA' | 'RECTIFICADA' | 'ANULADA';
  fecha_calculo: string | null; fecha_aprobacion: string | null; fecha_pago: string | null;
  total_bruto: number | string; total_descuentos: number | string; total_neto: number | string;
  total_formal: number | string; total_efectivo: number | string | null; total_mt: number | string;
  empleados_count: number; asiento_id: number | null;
  liquidacion_origen_id: number | null;
  observaciones: string | null;
};

type LiquidacionItem = {
  id: number; liquidacion_id: number; empleado_id: number; concepto_id: number;
  componente: 'FORMAL' | 'EFECTIVO' | 'MT';
  cantidad: number | string; importe_unitario: number | string | null;
  importe: number | string; base_calculo: number | string | null;
  observaciones: string | null;
  empleado?: { id: number; legajo: string; apellido: string; nombre: string };
  concepto?: { id: number; codigo: string; nombre: string; signo: 'HABER' | 'DESCUENTO'; tipo: string };
};

const ESTADOS = ['BORRADOR', 'CALCULADA', 'APROBADA', 'PAGADA', 'RECTIFICADA', 'ANULADA'];
const TIPOS = ['MENSUAL', 'SAC', 'AJUSTE', 'FINAL'];

function estadoColor(e: Liquidacion['estado']): 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'default' {
  switch (e) {
    case 'BORRADOR': return 'neutral';
    case 'CALCULADA': return 'info';
    case 'APROBADA': return 'warning';
    case 'PAGADA': return 'success';
    case 'RECTIFICADA': return 'default';
    case 'ANULADA': return 'danger';
  }
}

export function LiquidacionesPage() {
  const [filtros, setFiltros] = useState({ periodo: '', estado: '', tipo: '' });
  const [page, setPage] = useState(1);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [verLiq, setVerLiq] = useState<Liquidacion | null>(null);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (filtros.periodo) p.set('periodo', filtros.periodo);
    if (filtros.estado) p.set('estado', filtros.estado);
    if (filtros.tipo) p.set('tipo', filtros.tipo);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [filtros, page]);

  const { data, isLoading, error } = useApi<Paginator<Liquidacion>>(
    ['sueldos-liquidaciones', qs],
    `/api/erp/sueldos/liquidaciones${qs ? `?${qs}` : ''}`
  );

  const cols: Column<Liquidacion>[] = [
    { key: 'id', header: '#', width: '70px', render: (r) => <code>{r.id}</code> },
    { key: 'periodo', header: 'Período', width: '90px' },
    { key: 'tipo', header: 'Tipo', width: '100px',
      render: (r) => <Badge variant="default">{r.tipo}</Badge> },
    { key: 'estado', header: 'Estado', width: '120px',
      render: (r) => <Badge variant={estadoColor(r.estado)}>{r.estado}</Badge> },
    { key: 'empleados_count', header: 'Emps', align: 'right', width: '70px' },
    { key: 'bruto', header: 'Bruto', align: 'right', width: '130px',
      render: (r) => fmtMoney(Number(r.total_bruto)) },
    { key: 'neto', header: 'Neto', align: 'right', width: '130px',
      render: (r) => fmtMoney(Number(r.total_neto)) },
    { key: 'asiento', header: 'Asiento', width: '90px',
      render: (r) => r.asiento_id ? <Badge variant="success">#{r.asiento_id}</Badge> : <Badge variant="neutral">—</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '70px',
      render: (r) => (
        <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setVerLiq(r); }}>
          <Eye className="w-3 h-3" />
        </Button>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Calculator className="w-4 h-4 text-azure" /> Liquidaciones</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Nueva liquidación
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <Field label="Período" value={filtros.periodo} placeholder="YYYY-MM"
              onChange={(e) => { setFiltros({ ...filtros, periodo: e.target.value }); setPage(1); }}
              containerClassName="w-[140px]" />
            <SelectField label="Tipo" value={filtros.tipo} placeholder="Todos"
              onChange={(e) => { setFiltros({ ...filtros, tipo: e.target.value }); setPage(1); }}
              options={TIPOS.map((t) => ({ value: t, label: t }))}
              containerClassName="w-[140px]" />
            <SelectField label="Estado" value={filtros.estado} placeholder="Todos"
              onChange={(e) => { setFiltros({ ...filtros, estado: e.target.value }); setPage(1); }}
              options={ESTADOS.map((s) => ({ value: s, label: s }))}
              containerClassName="w-[160px]" />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={cols} paginator={data} loading={isLoading}
            onPageChange={setPage} onRowClick={(r) => setVerLiq(r)}
            empty="Sin liquidaciones" />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevaLiquidacionModal onClose={() => setNuevoOpen(false)} />}
      {verLiq && <DetalleDrawer liquidacion={verLiq} onClose={() => setVerLiq(null)} />}
    </div>
  );
}

function NuevaLiquidacionModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-liquidaciones']);
  const [form, setForm] = useState({
    periodo: new Date().toISOString().slice(0, 7),
    tipo: 'MENSUAL', observaciones: '',
  });
  const m = useApiMutation<Liquidacion, Record<string, unknown>>(
    (vars) => api.post('/api/erp/sueldos/liquidaciones', vars),
    {
      onSuccess: () => { toast.success('Liquidación creada (BORRADOR)'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    }
  );
  return (
    <Modal open onClose={onClose} title="Nueva liquidación" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!form.periodo || m.isPending}
            onClick={() => m.mutate({ ...form })}>
            {m.isPending ? 'Creando…' : 'Crear borrador'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <Field label="Período" required value={form.periodo} placeholder="YYYY-MM"
          onChange={(e) => setForm({ ...form, periodo: e.target.value })} />
        <SelectField label="Tipo" required value={form.tipo}
          onChange={(e) => setForm({ ...form, tipo: e.target.value })}
          options={TIPOS.map((t) => ({ value: t, label: t }))} placeholder={null} />
        <TextareaField label="Observaciones" rows={2} value={form.observaciones}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function DetalleDrawer({ liquidacion, onClose }: { liquidacion: Liquidacion; onClose: () => void }) {
  const [tab, setTab] = useState<'resumen' | 'items' | 'pagos'>('resumen');

  return (
    <Modal open onClose={onClose}
      title={`Liquidación #${liquidacion.id} — ${liquidacion.tipo} ${liquidacion.periodo}`}
      size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}
    >
      <ResumenHeader liq={liquidacion} onClose={onClose} />
      <div className="flex gap-2 border-b border-line my-3">
        {(['resumen', 'items', 'pagos'] as const).map((t) => (
          <Button key={t} size="sm" variant="ghost"
            className={tab === t ? 'border-b-2 border-azure rounded-none text-azure' : 'border-b-2 border-transparent rounded-none'}
            onClick={() => setTab(t)}>
            {t === 'resumen' ? 'Resumen' : t === 'items' ? 'Ítems' : 'Pagos'}
          </Button>
        ))}
      </div>
      {tab === 'resumen' && <ResumenTab liqId={liquidacion.id} />}
      {tab === 'items' && <ItemsTab liqId={liquidacion.id} />}
      {tab === 'pagos' && <PagosTab liqId={liquidacion.id} liq={liquidacion} />}
    </Modal>
  );
}

function ResumenHeader({ liq, onClose }: { liq: Liquidacion; onClose: () => void }) {
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-4 gap-2 text-[12px]">
        <Stat label="Estado" value={<Badge variant={estadoColor(liq.estado)}>{liq.estado}</Badge>} />
        <Stat label="Empleados" value={liq.empleados_count} />
        <Stat label="Bruto" value={fmtMoney(Number(liq.total_bruto))} />
        <Stat label="Neto" value={fmtMoney(Number(liq.total_neto))} />
      </div>
      <div className="grid grid-cols-3 gap-2 text-[12px]">
        <Stat label="Formal" value={fmtMoney(Number(liq.total_formal))} />
        <Stat label="Efectivo" value={liq.total_efectivo !== null ? fmtMoney(Number(liq.total_efectivo)) : <span className="text-ink-muted">— oculto —</span>} />
        <Stat label="MT" value={fmtMoney(Number(liq.total_mt))} />
      </div>
      <Acciones liq={liq} onClose={onClose} />
    </div>
  );
}

function useLiqAction(liqId: number, action: string, label: string, onClose: () => void) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-liquidaciones']);
  return useApiMutation(
    () => api.post(`/api/erp/sueldos/liquidaciones/${liqId}/${action}`),
    {
      onSuccess: () => { toast.success(label); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo', errorMessage(e)),
    }
  );
}

function Acciones({ liq, onClose }: { liq: Liquidacion; onClose: () => void }) {
  const calc = useLiqAction(liq.id, 'calcular', 'Liquidación calculada', onClose);
  const aprobar = useLiqAction(liq.id, 'aprobar', 'Liquidación aprobada', onClose);
  const contabilizar = useLiqAction(liq.id, 'contabilizar', 'Devengo contabilizado', onClose);
  const [anularOpen, setAnularOpen] = useState(false);
  const [rectifOpen, setRectifOpen] = useState(false);
  const [reciboOpen, setReciboOpen] = useState(false);

  const acciones: { label: string; icon: React.ReactNode; variant?: 'primary' | 'outline' | 'danger'; visible: boolean; onClick: () => void; pending: boolean }[] = [
    { label: 'Calcular', icon: <Calculator className="w-3 h-3" />, variant: 'primary',
      visible: liq.estado === 'BORRADOR' || liq.estado === 'CALCULADA',
      onClick: () => calc.mutate(undefined as unknown as void), pending: calc.isPending },
    { label: 'Aprobar', icon: <CheckCircle2 className="w-3 h-3" />, variant: 'primary',
      visible: liq.estado === 'CALCULADA',
      onClick: () => aprobar.mutate(undefined as unknown as void), pending: aprobar.isPending },
    { label: 'Contabilizar devengo', icon: <BookCheck className="w-3 h-3" />, variant: 'outline',
      visible: (liq.estado === 'CALCULADA' || liq.estado === 'APROBADA') && !liq.asiento_id,
      onClick: () => contabilizar.mutate(undefined as unknown as void), pending: contabilizar.isPending },
    { label: 'Rectificar', icon: <GitBranch className="w-3 h-3" />, variant: 'outline',
      visible: liq.estado === 'APROBADA' || liq.estado === 'PAGADA',
      onClick: () => setRectifOpen(true), pending: false },
    { label: 'Anular', icon: <X className="w-3 h-3" />, variant: 'danger',
      visible: !['PAGADA', 'RECTIFICADA', 'ANULADA'].includes(liq.estado),
      onClick: () => setAnularOpen(true), pending: false },
    { label: 'Ver recibo (empleado…)', icon: <FileText className="w-3 h-3" />, variant: 'outline',
      visible: liq.empleados_count > 0,
      onClick: () => setReciboOpen(true), pending: false },
  ];

  return (
    <div className="flex flex-wrap gap-2">
      {acciones.filter((a) => a.visible).map((a, i) => (
        <Button key={i} size="sm" variant={a.variant} disabled={a.pending} onClick={a.onClick}>
          {a.icon} {a.label}
        </Button>
      ))}
      {(liq.estado === 'APROBADA' || liq.estado === 'PAGADA') && (
        <Link to={`/erp/sueldos/liber?liq=${liq.id}`} onClick={onClose}>
          <Button size="sm" variant="outline">
            <FileText className="w-3 h-3" /> Export LIBER
          </Button>
        </Link>
      )}

      {anularOpen && <AnularModal liqId={liq.id} onClose={() => setAnularOpen(false)} onDone={onClose} />}
      {rectifOpen && <RectificarModal liqId={liq.id} onClose={() => setRectifOpen(false)} onDone={onClose} />}
      {reciboOpen && <ReciboModal liqId={liq.id} onClose={() => setReciboOpen(false)} />}
    </div>
  );
}

function AnularModal({ liqId, onClose, onDone }: { liqId: number; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-liquidaciones']);
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation(
    () => api.post(`/api/erp/sueldos/liquidaciones/${liqId}/anular`, { motivo }),
    {
      onSuccess: () => { toast.success('Liquidación anulada'); invalidate(); onClose(); onDone(); },
      onError: (e) => toast.error('No se pudo anular', errorMessage(e)),
    }
  );
  return (
    <ConfirmDialog open onClose={onClose} variant="danger"
      title={`Anular liquidación #${liqId}`}
      message={
        <div className="space-y-2">
          <div>Esto borra todos los items y pone la liquidación en ANULADA.</div>
          <Field label="Motivo (mín. 5 chars)" required value={motivo}
            onChange={(e) => setMotivo(e.target.value)} />
        </div>
      }
      confirmLabel="Anular"
      loading={m.isPending}
      onConfirm={() => motivo.length >= 5 && m.mutate(undefined as unknown as void)} />
  );
}

function RectificarModal({ liqId, onClose, onDone }: { liqId: number; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-liquidaciones']);
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<Liquidacion, { motivo: string }>(
    (vars) => api.post(`/api/erp/sueldos/liquidaciones/${liqId}/rectificar`, vars),
    {
      onSuccess: (nueva) => {
        toast.success(`Rectificativa #${nueva.id} creada (BORRADOR)`, 'Editá novedades y recalculá');
        invalidate(); onClose(); onDone();
      },
      onError: (e) => toast.error('No se pudo rectificar', errorMessage(e)),
    }
  );
  return (
    <Modal open onClose={onClose} title={`Rectificar liquidación #${liqId}`} size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={motivo.length < 5 || m.isPending}
            onClick={() => m.mutate({ motivo })}>
            {m.isPending ? 'Procesando…' : 'Crear rectificativa'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <div className="text-[12px] text-ink-2 bg-warning-bg/40 border border-warning/30 rounded-md p-3">
          La original pasa a RECTIFICADA. Se crea una nueva tipo AJUSTE en BORRADOR vinculada por liquidacion_origen_id.
        </div>
        <TextareaField label="Motivo de la rectificativa" required rows={3} value={motivo}
          onChange={(e) => setMotivo(e.target.value)}
          placeholder="Ej: olvido SAC empleado X" />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function ReciboModal({ liqId, onClose }: { liqId: number; onClose: () => void }) {
  const [empleadoId, setEmpleadoId] = useState('');
  const open = () => {
    if (! empleadoId) return;
    const url = `/api/erp/sueldos/liquidaciones/${liqId}/recibo/${empleadoId}`;
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}`, Accept: 'text/html' } })
      .then((r) => r.text())
      .then((html) => {
        const w = window.open('', '_blank');
        if (w) { w.document.write(html); w.document.close(); }
      });
  };
  return (
    <Modal open onClose={onClose} title="Ver recibo" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cerrar</Button>
          <Button variant="primary" disabled={!empleadoId} onClick={open}>
            <FileText className="w-3 h-3" /> Abrir
          </Button>
        </>
      }>
      <div className="space-y-3">
        <Field label="ID empleado" required type="number" value={empleadoId}
          onChange={(e) => setEmpleadoId(e.target.value)} />
        <div className="text-[11.5px] text-ink-muted">El recibo se abre en una nueva pestaña, lista para imprimir.</div>
      </div>
    </Modal>
  );
}

type ResumenEmpleadoRow = { legajo: string; nombre_completo: string; regimen: string; haberes: number; descuentos: number; neto: number; formal: number; efectivo: number; mt: number };

function ResumenTab({ liqId }: { liqId: number }) {
  const { data, isLoading, error } = useApi<{ liquidacion: Record<string, unknown>; empleados: ResumenEmpleadoRow[] }>(
    ['sueldos-rep-liq', liqId],
    `/api/erp/sueldos/reportes/liquidacion/${liqId}`
  );
  const cols: Column<ResumenEmpleadoRow>[] = [
    { key: 'legajo', header: 'Legajo', width: '100px' },
    { key: 'nombre_completo', header: 'Empleado' },
    { key: 'regimen', header: 'Régimen', width: '120px',
      render: (r) => <Badge variant="default">{r.regimen}</Badge> },
    { key: 'haberes', header: 'Haberes', align: 'right', render: (r) => fmtMoney(r.haberes) },
    { key: 'descuentos', header: 'Descuentos', align: 'right', render: (r) => fmtMoney(r.descuentos) },
    { key: 'neto', header: 'Neto', align: 'right', render: (r) => <strong>{fmtMoney(r.neto)}</strong> },
    { key: 'formal', header: 'Formal', align: 'right', render: (r) => fmtMoney(r.formal) },
    { key: 'efectivo', header: 'Efectivo', align: 'right', render: (r) => fmtMoney(r.efectivo) },
    { key: 'mt', header: 'MT', align: 'right', render: (r) => fmtMoney(r.mt) },
  ];
  return (
    <>
      {error && <FormError error={errorMessage(error)} />}
      <DataTable columns={cols} rows={data?.empleados ?? []} loading={isLoading}
        empty="Sin empleados — calculá la liquidación" />
    </>
  );
}

function ItemsTab({ liqId }: { liqId: number }) {
  const [filtros, setFiltros] = useState({ empleado_id: '', componente: '' });
  const qs = (() => {
    const p = new URLSearchParams();
    if (filtros.empleado_id) p.set('empleado_id', filtros.empleado_id);
    if (filtros.componente) p.set('componente', filtros.componente);
    return p.toString();
  })();
  const { data, isLoading } = useApi<Paginator<LiquidacionItem>>(
    ['sueldos-liq-items', liqId, qs],
    `/api/erp/sueldos/liquidaciones/${liqId}/items${qs ? `?${qs}` : ''}`
  );
  const cols: Column<LiquidacionItem>[] = [
    { key: 'empleado', header: 'Empleado',
      render: (r) => r.empleado ? `${r.empleado.legajo} ${r.empleado.apellido}, ${r.empleado.nombre}` : '—' },
    { key: 'concepto', header: 'Concepto',
      render: (r) => r.concepto ? <><code className="text-[11px]">{r.concepto.codigo}</code> {r.concepto.nombre}</> : '—' },
    { key: 'componente', header: 'Comp.', width: '100px',
      render: (r) => <Badge variant={r.componente === 'FORMAL' ? 'success' : r.componente === 'EFECTIVO' ? 'warning' : 'default'}>{r.componente}</Badge> },
    { key: 'signo', header: 'Signo', width: '110px',
      render: (r) => r.concepto && <Badge variant={r.concepto.signo === 'HABER' ? 'success' : 'warning'}>{r.concepto.signo}</Badge> },
    { key: 'cantidad', header: 'Cant.', align: 'right', width: '80px',
      render: (r) => Number(r.cantidad).toFixed(2) },
    { key: 'unitario', header: 'Unit.', align: 'right', width: '110px',
      render: (r) => r.importe_unitario !== null ? fmtMoney(Number(r.importe_unitario)) : '—' },
    { key: 'importe', header: 'Importe', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.importe)) },
  ];
  return (
    <>
      <div className="flex flex-wrap gap-3 items-end mb-3">
        <Field label="ID empleado" type="number" value={filtros.empleado_id}
          onChange={(e) => setFiltros({ ...filtros, empleado_id: e.target.value })}
          containerClassName="w-[150px]" />
        <SelectField label="Componente" value={filtros.componente} placeholder="Todos"
          onChange={(e) => setFiltros({ ...filtros, componente: e.target.value })}
          options={[{ value: 'FORMAL', label: 'FORMAL' }, { value: 'EFECTIVO', label: 'EFECTIVO' }, { value: 'MT', label: 'MT' }]}
          containerClassName="w-[170px]" />
      </div>
      <DataTable columns={cols} rows={data?.data ?? []} loading={isLoading} empty="Sin ítems" />
    </>
  );
}

function PagosTab({ liqId, liq }: { liqId: number; liq: Liquidacion }) {
  const { data, isLoading } = useApi<Array<{ id: number; empleado_id: number; componente: string; medio: string; importe: number | string; fecha: string; orden_pago_id: number | null; recibido_por: string | null; asiento_id: number | null; empleado?: { legajo: string; apellido: string; nombre: string }; ordenPago?: { numero: string; estado: string } | null; asiento?: { numero: string } | null }>>(
    ['sueldos-liq-pagos', liqId],
    `/api/erp/sueldos/liquidaciones/${liqId}/pagos`
  );
  const cols: Column<NonNullable<typeof data>[number]>[] = [
    { key: 'empleado', header: 'Empleado',
      render: (r) => r.empleado ? `${r.empleado.legajo} ${r.empleado.apellido}, ${r.empleado.nombre}` : '—' },
    { key: 'componente', header: 'Comp.', width: '100px',
      render: (r) => <Badge variant={r.componente === 'FORMAL' ? 'success' : r.componente === 'EFECTIVO' ? 'warning' : 'default'}>{r.componente}</Badge> },
    { key: 'medio', header: 'Medio', width: '120px',
      render: (r) => <Badge variant="default">{r.medio}</Badge> },
    { key: 'importe', header: 'Importe', align: 'right', width: '130px',
      render: (r) => fmtMoney(Number(r.importe)) },
    { key: 'fecha', header: 'Fecha', width: '100px', render: (r) => fmtDate(r.fecha) },
    { key: 'op', header: 'OP / Receptor',
      render: (r) => r.ordenPago ? <code>{r.ordenPago.numero}</code> : r.recibido_por ?? '—' },
    { key: 'asiento', header: 'Asiento', width: '90px',
      render: (r) => r.asiento ? <code>{r.asiento.numero}</code> : '—' },
  ];
  return (
    <>
      <div className="text-[12px] text-ink-muted mb-3">
        {liq.estado === 'APROBADA'
          ? 'Para ejecutar pagos andá a "Sueldos → Pagos" (sección dedicada).'
          : `Estado actual: ${liq.estado}. Los pagos sólo se pueden ejecutar cuando la liquidación está APROBADA.`}
      </div>
      <DataTable columns={cols} rows={data ?? []} loading={isLoading} empty="Sin pagos registrados" />
    </>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="border border-line rounded-md p-2 bg-white">
      <div className="text-[10.5px] uppercase text-ink-muted">{label}</div>
      <div className="font-medium tabular-nums">{value}</div>
    </div>
  );
}
