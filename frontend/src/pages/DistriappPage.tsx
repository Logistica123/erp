import { useState } from 'react';
import { Box, RefreshCw, Users, Truck, FileText, Receipt, BookCheck } from 'lucide-react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { FormError } from '@/components/ui/Field';
import { api, ApiError } from '@/lib/api';
import { errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Cliente = {
  distriapp_id: number; razon_social: string; cuit: string | null;
  direccion?: string | null; localidad?: string | null; activo: number;
};
type Distribuidor = {
  distriapp_id: number; apellidos: string; nombres: string; cuil?: string | null;
  email?: string | null; telefono?: string | null;
};
type FacturaDA = {
  distriapp_id: number; fecha_emision: string; tipo_cbte: number;
  letra: string | null; pto_vta: number; numero: number;
  cuit_cliente: string | null; razon_social: string;
  imp_total: number | string; cae: string | null;
  importada_erp: number;
};
type LiquidacionDA = {
  distriapp_id: number; periodo_desde: string; periodo_hasta: string;
  distribuidor_nombre: string; total_liquidado: number | string;
  total_comisiones: number | string;
};

type Tab = 'clientes' | 'distribuidores' | 'facturas' | 'liquidaciones';

const TABS: { value: Tab; label: string; icon: typeof Box }[] = [
  { value: 'clientes', label: 'Clientes', icon: Users },
  { value: 'distribuidores', label: 'Distribuidores', icon: Truck },
  { value: 'facturas', label: 'Facturas DistriApp', icon: FileText },
  { value: 'liquidaciones', label: 'Liquidaciones distrib.', icon: Receipt },
];

export function DistriappPage() {
  const [tab, setTab] = useState<Tab>('clientes');

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><Box className="w-4 h-4 text-azure" /> Integración DistriApp</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12.5px] text-ink-2">
            Bridge de solo-lectura sobre el esquema <code className="text-[11.5px] bg-bg-soft px-1 rounded">basepersonal</code>.
            Las acciones de sync upsertean en <code className="text-[11.5px] bg-bg-soft px-1 rounded">erp_auxiliares</code> /
            <code className="text-[11.5px] bg-bg-soft px-1 rounded">erp_facturas_venta</code> respetando idempotencia (natural keys).
          </div>

          <SyncButtons />

          <div className="flex flex-wrap gap-2 border-b border-line">
            {TABS.map((t) => {
              const Icon = t.icon;
              return (
                <Button key={t.value} variant="ghost" size="sm"
                  className={tab === t.value
                    ? 'border-b-2 border-azure rounded-none text-azure'
                    : 'border-b-2 border-transparent rounded-none'}
                  onClick={() => setTab(t.value)}>
                  <Icon className="w-3 h-3" /> {t.label}
                </Button>
              );
            })}
          </div>

          {tab === 'clientes' && <ClientesTab />}
          {tab === 'distribuidores' && <DistribuidoresTab />}
          {tab === 'facturas' && <FacturasTab />}
          {tab === 'liquidaciones' && <LiquidacionesTab />}
        </CardBody>
      </Card>
    </div>
  );
}

type SyncResp = { message: string; creados?: number; actualizados?: number; total?: number; skipped?: number };

function useSync(path: string, label: string, invalidate: string[]) {
  const toast = useToast();
  const qc = useQueryClient();
  return useMutation<SyncResp, ApiError>({
    mutationFn: () => api.post(path),
    onSuccess: (res) => {
      const detalle = [
        res.creados !== undefined ? `creados: ${res.creados}` : null,
        res.actualizados !== undefined ? `actualizados: ${res.actualizados}` : null,
        res.skipped ? `skipped: ${res.skipped}` : null,
        res.total !== undefined ? `total: ${res.total}` : null,
      ].filter(Boolean).join(' · ');
      toast.success(label, detalle || res.message);
      invalidate.forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
    },
    onError: (e) => toast.error(`Error en ${label}`, errorMessage(e)),
  });
}

function SyncButtons() {
  const syncClientes = useSync('/api/erp/integracion/distriapp/sync-clientes', 'Sync Clientes', ['da-clientes']);
  const syncDistribuidores = useSync('/api/erp/integracion/distriapp/sync-distribuidores', 'Sync Distribuidores', ['da-distribuidores']);
  const syncFacturas = useSync('/api/erp/integracion/distriapp/sync-facturas', 'Sync Facturas', ['da-facturas']);
  const contab = useSync('/api/erp/integracion/distriapp/contabilizar-facturas', 'Contabilizar facturas', ['da-facturas']);

  return (
    <div className="flex flex-wrap gap-2">
      <Button variant="outline" size="sm" disabled={syncClientes.isPending}
        onClick={() => syncClientes.mutate()}>
        <RefreshCw className={`w-3 h-3 ${syncClientes.isPending ? 'animate-spin' : ''}`} />
        Sync Clientes
      </Button>
      <Button variant="outline" size="sm" disabled={syncDistribuidores.isPending}
        onClick={() => syncDistribuidores.mutate()}>
        <RefreshCw className={`w-3 h-3 ${syncDistribuidores.isPending ? 'animate-spin' : ''}`} />
        Sync Distribuidores
      </Button>
      <Button variant="outline" size="sm" disabled={syncFacturas.isPending}
        onClick={() => syncFacturas.mutate()}>
        <RefreshCw className={`w-3 h-3 ${syncFacturas.isPending ? 'animate-spin' : ''}`} />
        Sync Facturas
      </Button>
      <Button variant="primary" size="sm" disabled={contab.isPending}
        onClick={() => contab.mutate()}>
        <BookCheck className="w-3 h-3" />
        {contab.isPending ? 'Contabilizando…' : 'Contabilizar pendientes'}
      </Button>
    </div>
  );
}

function ClientesTab() {
  const { data, isLoading, error } = useQuery<{ data: Cliente[] }, ApiError>({
    queryKey: ['da-clientes'],
    queryFn: () => api.get('/api/erp/integracion/distriapp/clientes'),
  });

  const cols: Column<Cliente>[] = [
    { key: 'distriapp_id', header: 'ID DA', width: '90px',
      render: (r) => <code className="text-[11px]">{r.distriapp_id}</code> },
    { key: 'razon_social', header: 'Razón social' },
    { key: 'cuit', header: 'CUIT', width: '140px',
      render: (r) => r.cuit ?? '—' },
    { key: 'localidad', header: 'Localidad', width: '180px',
      render: (r) => r.localidad ?? '—' },
    { key: 'activo', header: 'Activo', width: '80px',
      render: (r) => r.activo
        ? <Badge variant="success">SÍ</Badge>
        : <Badge variant="neutral">NO</Badge> },
  ];

  return <>
    {error && <FormError error={errorMessage(error)} />}
    <DataTable columns={cols} rows={data?.data ?? []} loading={isLoading} empty="Sin clientes en DistriApp" />
  </>;
}

function DistribuidoresTab() {
  const { data, isLoading, error } = useQuery<{ data: Distribuidor[] }, ApiError>({
    queryKey: ['da-distribuidores'],
    queryFn: () => api.get('/api/erp/integracion/distriapp/distribuidores'),
  });

  const cols: Column<Distribuidor>[] = [
    { key: 'distriapp_id', header: 'ID DA', width: '90px',
      render: (r) => <code className="text-[11px]">{r.distriapp_id}</code> },
    { key: 'apellidos', header: 'Apellido y nombre',
      render: (r) => `${r.apellidos}, ${r.nombres}` },
    { key: 'cuil', header: 'CUIL', width: '140px',
      render: (r) => r.cuil ?? '—' },
    { key: 'email', header: 'Email',
      render: (r) => r.email ?? '—' },
    { key: 'telefono', header: 'Teléfono', width: '140px',
      render: (r) => r.telefono ?? '—' },
  ];

  return <>
    {error && <FormError error={errorMessage(error)} />}
    <DataTable columns={cols} rows={data?.data ?? []} loading={isLoading} empty="Sin distribuidores en DistriApp" />
  </>;
}

function FacturasTab() {
  const { data, isLoading, error } = useQuery<{ data: FacturaDA[] }, ApiError>({
    queryKey: ['da-facturas'],
    queryFn: () => api.get('/api/erp/integracion/distriapp/facturas'),
  });

  const cols: Column<FacturaDA>[] = [
    { key: 'fecha_emision', header: 'Fecha', width: '95px',
      render: (r) => fmtDate(r.fecha_emision) },
    { key: 'comprobante', header: 'Comprobante', width: '180px',
      render: (r) => `${r.tipo_cbte}${r.letra ? '-' + r.letra : ''} ${String(r.pto_vta).padStart(4, '0')}-${String(r.numero).padStart(8, '0')}` },
    { key: 'razon_social', header: 'Cliente' },
    { key: 'cuit_cliente', header: 'CUIT', width: '130px',
      render: (r) => r.cuit_cliente ?? '—' },
    { key: 'imp_total', header: 'Total', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.imp_total)) },
    { key: 'cae', header: 'CAE', width: '160px',
      render: (r) => r.cae ? <code className="text-[10.5px]">{r.cae}</code> : <Badge variant="warning">SIN CAE</Badge> },
    { key: 'importada_erp', header: 'En ERP', width: '90px',
      render: (r) => r.importada_erp
        ? <Badge variant="success">SÍ</Badge>
        : <Badge variant="warning">PENDIENTE</Badge> },
  ];

  return <>
    {error && <FormError error={errorMessage(error)} />}
    <DataTable columns={cols} rows={data?.data ?? []} loading={isLoading} empty="Sin facturas DistriApp" />
  </>;
}

function LiquidacionesTab() {
  const { data, isLoading, error } = useQuery<{ data: LiquidacionDA[] }, ApiError>({
    queryKey: ['da-liquidaciones'],
    queryFn: () => api.get('/api/erp/integracion/distriapp/liquidaciones-distrib'),
  });

  const cols: Column<LiquidacionDA>[] = [
    { key: 'distriapp_id', header: 'ID DA', width: '90px',
      render: (r) => <code className="text-[11px]">{r.distriapp_id}</code> },
    { key: 'periodo', header: 'Período', width: '200px',
      render: (r) => `${fmtDate(r.periodo_desde)} → ${fmtDate(r.periodo_hasta)}` },
    { key: 'distribuidor_nombre', header: 'Distribuidor' },
    { key: 'total_liquidado', header: 'Total liquidado', align: 'right', width: '140px',
      render: (r) => fmtMoney(Number(r.total_liquidado)) },
    { key: 'total_comisiones', header: 'Comisiones', align: 'right', width: '130px',
      render: (r) => fmtMoney(Number(r.total_comisiones)) },
  ];

  return <>
    {error && <FormError error={errorMessage(error)} />}
    <DataTable columns={cols} rows={data?.data ?? []} loading={isLoading} empty="Sin liquidaciones" />
  </>;
}
