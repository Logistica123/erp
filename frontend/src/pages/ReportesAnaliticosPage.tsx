import { useState, useMemo } from 'react';
import { BarChart3, Filter } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { DataTable, fmtMoney, type Column } from '@/components/ui/DataTable';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useApi, errorMessage } from '@/hooks/useApi';

/**
 * ADDENDUM v1.14 — pantalla unificada con 5 reports analíticos:
 * ventas/gastos/margen por cliente, ventas/gastos por jurisdicción.
 * Tabs en lugar de 5 rutas separadas para mantener simple la UX.
 */

type RowCliente = {
  cc_id: number | null;
  cc_codigo: string | null;
  nombre: string;
  facturas: number;
  neto: string | number;
  iva: string | number;
  total: string | number;
};

type RowMargen = {
  cc_id: number;
  cc_codigo: string | null;
  nombre: string;
  ventas: number;
  gastos: number;
  margen: number;
  pct_margen: number | null;
};

type RowJuris = {
  codigo: string | null;
  nombre: string;
  facturas: number;
  neto: string | number;
  iva: string | number;
  total: string | number;
};

type Jurisdiccion = { codigo: string; nombre: string };

type Tab = 'ventas-cli' | 'gastos-cli' | 'margen-cli' | 'ventas-jur' | 'gastos-jur';

const TABS: Array<{ id: Tab; label: string; descripcion: string }> = [
  { id: 'ventas-cli', label: 'Ventas por cliente', descripcion: 'Agrupa facturas de venta por CC.' },
  { id: 'gastos-cli', label: 'Gastos por cliente', descripcion: 'Agrupa facturas de compra por CC del cliente al que se asignaron.' },
  { id: 'margen-cli', label: 'Margen por cliente', descripcion: 'Ventas − Gastos por CC (margen + % sobre ventas).' },
  { id: 'ventas-jur', label: 'Ventas por jurisdicción', descripcion: 'Agrupa por jurisdicción IIBB (901-924). Útil para IIBB.' },
  { id: 'gastos-jur', label: 'Gastos por jurisdicción', descripcion: 'Idem para compras.' },
];

const defaultDesde = () => {
  const d = new Date();
  d.setMonth(d.getMonth() - 1);
  d.setDate(1);
  return d.toISOString().slice(0, 10);
};
const defaultHasta = () => new Date().toISOString().slice(0, 10);

export function ReportesAnaliticosPage() {
  const [tab, setTab] = useState<Tab>('ventas-cli');
  const [filtros, setFiltros] = useState({
    desde: defaultDesde(),
    hasta: defaultHasta(),
    periodo_trabajado: '',
    jurisdiccion: '',
  });

  // El endpoint /facturas-venta/catalogos no usa el wrap {ok, data} estándar
  // — usamos useQuery raw para no chocar con useApi (que unwrappea data).
  const { data: catalogos } = useQuery<{ jurisdicciones?: Jurisdiccion[] }>({
    queryKey: ['v14-catalogos'],
    queryFn: () => api.get('/api/erp/facturas-venta/catalogos'),
  });
  const jurisdicciones = catalogos?.jurisdicciones ?? [];

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (filtros.desde) p.set('desde', filtros.desde);
    if (filtros.hasta) p.set('hasta', filtros.hasta);
    if (filtros.periodo_trabajado) p.set('periodo_trabajado', filtros.periodo_trabajado);
    if (filtros.jurisdiccion) p.set('jurisdiccion', filtros.jurisdiccion);
    return p.toString();
  }, [filtros]);

  const endpoint = ({
    'ventas-cli': '/api/erp/reportes/ventas-por-cliente',
    'gastos-cli': '/api/erp/reportes/gastos-por-cliente',
    'margen-cli': '/api/erp/reportes/margen-por-cliente',
    'ventas-jur': '/api/erp/reportes/ventas-por-jurisdiccion',
    'gastos-jur': '/api/erp/reportes/gastos-por-jurisdiccion',
  } as const)[tab];

  const { data, isLoading, error } = useApi<RowCliente[] | RowMargen[] | RowJuris[]>(
    ['reporte-v14', tab, qs],
    `${endpoint}${qs ? `?${qs}` : ''}`
  );

  const tabActual = TABS.find((t) => t.id === tab)!;

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2">
            <BarChart3 className="w-4 h-4 text-azure" /> Reportes analíticos
          </div>
        } />
        <CardBody className="p-0">
          <div className="border-b border-line flex flex-wrap gap-0">
            {TABS.map((t) => (
              <button key={t.id}
                onClick={() => setTab(t.id)}
                className={`px-4 py-2 text-[12.5px] font-medium border-b-2 transition-colors ${
                  tab === t.id
                    ? 'border-azure text-navy-800 bg-surface-row'
                    : 'border-transparent text-ink-muted hover:text-ink hover:bg-surface-hover'
                }`}>
                {t.label}
              </button>
            ))}
          </div>

          <div className="p-4 space-y-3">
            <div className="text-[11.5px] text-ink-muted">{tabActual.descripcion}</div>

            <div className="flex flex-wrap gap-3 items-end">
              <Field label="Desde" type="date" value={filtros.desde}
                onChange={(e) => setFiltros({ ...filtros, desde: e.target.value })}
                containerClassName="w-[150px]" />
              <Field label="Hasta" type="date" value={filtros.hasta}
                onChange={(e) => setFiltros({ ...filtros, hasta: e.target.value })}
                containerClassName="w-[150px]" />
              <Field label="Período trabajado" value={filtros.periodo_trabajado}
                onChange={(e) => setFiltros({ ...filtros, periodo_trabajado: e.target.value })}
                placeholder="2026-03 (opt)"
                containerClassName="w-[180px]" />
              <SelectField label="Jurisdicción" value={filtros.jurisdiccion}
                placeholder="Todas"
                onChange={(e) => setFiltros({ ...filtros, jurisdiccion: e.target.value })}
                options={jurisdicciones.map((j) => ({
                  value: j.codigo, label: `${j.codigo} ${j.nombre}`,
                }))}
                containerClassName="w-[220px]" />
              <Button variant="ghost" size="sm" onClick={() => setFiltros({
                desde: defaultDesde(), hasta: defaultHasta(),
                periodo_trabajado: '', jurisdiccion: '',
              })}>
                <Filter className="w-3 h-3" /> Reset
              </Button>
            </div>

            {error && <FormError error={errorMessage(error)} />}

            {tab === 'ventas-cli' || tab === 'gastos-cli' ? (
              <TablaCliente rows={(data as RowCliente[]) ?? []} loading={isLoading} />
            ) : tab === 'margen-cli' ? (
              <TablaMargen rows={(data as RowMargen[]) ?? []} loading={isLoading} />
            ) : (
              <TablaJurisdiccion rows={(data as RowJuris[]) ?? []} loading={isLoading} />
            )}
          </div>
        </CardBody>
      </Card>
    </div>
  );
}

function TablaCliente({ rows, loading }: { rows: RowCliente[]; loading: boolean }) {
  const totalNeto = rows.reduce((a, r) => a + Number(r.neto), 0);
  const totalTotal = rows.reduce((a, r) => a + Number(r.total), 0);
  const totalFact = rows.reduce((a, r) => a + Number(r.facturas), 0);

  const cols: Column<RowCliente>[] = [
    { key: 'cc_codigo', header: 'CC', width: '90px',
      render: (r) => r.cc_codigo
        ? <code className="text-[11px]">{r.cc_codigo}</code>
        : <span className="text-ink-muted italic text-[11px]">sin CC</span> },
    { key: 'nombre', header: 'Cliente / Auxiliar' },
    { key: 'facturas', header: 'Facturas', align: 'right', width: '90px' },
    { key: 'neto', header: 'Neto', align: 'right', width: '140px',
      render: (r) => fmtMoney(Number(r.neto)) },
    { key: 'iva', header: 'IVA', align: 'right', width: '130px',
      render: (r) => fmtMoney(Number(r.iva)) },
    { key: 'total', header: 'Total', align: 'right', width: '150px',
      render: (r) => <strong>{fmtMoney(Number(r.total))}</strong> },
  ];

  return (
    <div className="space-y-2">
      <DataTable columns={cols} rows={rows} loading={loading}
        empty="Sin resultados para los filtros aplicados"
        clientPageSize={null} />
      {rows.length > 0 && (
        <div className="grid grid-cols-4 gap-2 text-[11.5px] border-t border-line pt-2">
          <Stat label="Filas" value={rows.length.toLocaleString('es-AR')} />
          <Stat label="Facturas" value={totalFact.toLocaleString('es-AR')} />
          <Stat label="Neto total" value={fmtMoney(totalNeto)} />
          <Stat label="Total bruto" value={fmtMoney(totalTotal)} />
        </div>
      )}
    </div>
  );
}

function TablaMargen({ rows, loading }: { rows: RowMargen[]; loading: boolean }) {
  const totalVentas = rows.reduce((a, r) => a + Number(r.ventas), 0);
  const totalGastos = rows.reduce((a, r) => a + Number(r.gastos), 0);
  const totalMargen = rows.reduce((a, r) => a + Number(r.margen), 0);

  const cols: Column<RowMargen>[] = [
    { key: 'cc_codigo', header: 'CC', width: '90px',
      render: (r) => <code className="text-[11px]">{r.cc_codigo ?? '—'}</code> },
    { key: 'nombre', header: 'Cliente' },
    { key: 'ventas', header: 'Ventas', align: 'right', width: '140px',
      render: (r) => fmtMoney(Number(r.ventas)) },
    { key: 'gastos', header: 'Gastos', align: 'right', width: '140px',
      render: (r) => fmtMoney(Number(r.gastos)) },
    { key: 'margen', header: 'Margen', align: 'right', width: '140px',
      render: (r) => (
        <strong className={Number(r.margen) >= 0 ? 'text-success' : 'text-danger'}>
          {fmtMoney(Number(r.margen))}
        </strong>
      ) },
    { key: 'pct_margen', header: '% margen', align: 'right', width: '90px',
      render: (r) => r.pct_margen != null
        ? <span className={Number(r.pct_margen) >= 0 ? 'text-success' : 'text-danger'}>
            {Number(r.pct_margen).toFixed(1)}%
          </span>
        : <span className="text-ink-muted">—</span> },
  ];

  return (
    <div className="space-y-2">
      <DataTable columns={cols} rows={rows} loading={loading}
        empty="Sin datos para calcular margen en este rango"
        clientPageSize={null} />
      {rows.length > 0 && (
        <div className="grid grid-cols-4 gap-2 text-[11.5px] border-t border-line pt-2">
          <Stat label="Clientes" value={rows.length.toLocaleString('es-AR')} />
          <Stat label="Ventas total" value={fmtMoney(totalVentas)} />
          <Stat label="Gastos total" value={fmtMoney(totalGastos)} />
          <Stat label="Margen total" value={
            <strong className={totalMargen >= 0 ? 'text-success' : 'text-danger'}>
              {fmtMoney(totalMargen)}
            </strong>
          } />
        </div>
      )}
    </div>
  );
}

function TablaJurisdiccion({ rows, loading }: { rows: RowJuris[]; loading: boolean }) {
  const totalNeto = rows.reduce((a, r) => a + Number(r.neto), 0);
  const totalIva = rows.reduce((a, r) => a + Number(r.iva), 0);
  const totalTotal = rows.reduce((a, r) => a + Number(r.total), 0);

  const cols: Column<RowJuris>[] = [
    { key: 'codigo', header: 'Código', width: '90px',
      render: (r) => r.codigo
        ? <code className="text-[11px]">{r.codigo}</code>
        : <span className="text-ink-muted italic text-[11px]">— sin —</span> },
    { key: 'nombre', header: 'Jurisdicción' },
    { key: 'facturas', header: 'Facturas', align: 'right', width: '90px' },
    { key: 'neto', header: 'Neto', align: 'right', width: '140px',
      render: (r) => fmtMoney(Number(r.neto)) },
    { key: 'iva', header: 'IVA', align: 'right', width: '130px',
      render: (r) => fmtMoney(Number(r.iva)) },
    { key: 'total', header: 'Total', align: 'right', width: '150px',
      render: (r) => <strong>{fmtMoney(Number(r.total))}</strong> },
  ];

  return (
    <div className="space-y-2">
      <DataTable columns={cols} rows={rows} loading={loading}
        empty="Sin facturas con jurisdicción asignada en este rango"
        clientPageSize={null} />
      {rows.length > 0 && (
        <div className="grid grid-cols-4 gap-2 text-[11.5px] border-t border-line pt-2">
          <Stat label="Jurisdicciones" value={rows.length} />
          <Stat label="IVA total" value={fmtMoney(totalIva)} />
          <Stat label="Neto total" value={fmtMoney(totalNeto)} />
          <Stat label="Total bruto" value={fmtMoney(totalTotal)} />
        </div>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="border border-line rounded-md p-2 bg-white">
      <div className="text-[10.5px] uppercase text-ink-muted">{label}</div>
      <div className="font-semibold tabular-nums text-[12px]">{value}</div>
    </div>
  );
}
