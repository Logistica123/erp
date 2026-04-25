import { useState } from 'react';
import { FileSpreadsheet, RefreshCw, Calculator, Layers } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Field, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type ListadoFila = {
  id: number;
  nro_inventario: string;
  descripcion: string;
  categoria_nombre: string | null;
  fecha_alta: string;
  valor_origen: number | string;
  amort_acum_contable: number | string;
  vnr_contable: number | string;
  estado: string;
};

type AnexoFila = {
  categoria_id: number;
  categoria: string;
  saldo_inicial: number | string;
  altas: number | string;
  bajas: number | string;
  saldo_final: number | string;
  amort_inicial: number | string;
  amort_ejercicio: number | string;
  amort_bajas: number | string;
  amort_final: number | string;
  vnr_final: number | string;
};

type AltasBajasResp = {
  altas: ListadoFila[];
  bajas: ListadoFila[];
  totales: { altas: number; bajas: number; total_altas: number; total_bajas: number };
};

type AmortVsFiscalFila = {
  bien_id: number;
  nro_inventario: string;
  descripcion: string;
  cuota_contable_anual: number | string;
  cuota_fiscal_anual: number | string;
  diferencia: number | string;
};

type Reexpresion = {
  id: number;
  ejercicio_id: number;
  bien_id: number;
  indice_origen: number | string;
  indice_destino: number | string;
  factor: number | string;
  valor_reexpresado: number | string;
  generado_at: string;
  bien?: { nro_inventario: string; descripcion: string };
};

type Tab = 'listado' | 'anexo' | 'altasbajas' | 'amort' | 'reexpresion';

const TABS: { value: Tab; label: string; icon: typeof FileSpreadsheet }[] = [
  { value: 'listado', label: 'Listado al corte', icon: FileSpreadsheet },
  { value: 'anexo', label: 'Anexo BdU (RT 9)', icon: Layers },
  { value: 'altasbajas', label: 'Altas / Bajas', icon: FileSpreadsheet },
  { value: 'amort', label: 'Amort. Cont. vs Fiscal', icon: Calculator },
  { value: 'reexpresion', label: 'Reexpresión RT 6', icon: RefreshCw },
];

export function ReportesAfPage() {
  const [tab, setTab] = useState<Tab>('listado');

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><FileSpreadsheet className="w-4 h-4 text-azure" /> Reportes de Activos Fijos</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-2">
            {TABS.map((t) => {
              const Icon = t.icon;
              return (
                <Button key={t.value} size="sm" variant={tab === t.value ? 'primary' : 'outline'}
                  onClick={() => setTab(t.value)}>
                  <Icon className="w-3 h-3" /> {t.label}
                </Button>
              );
            })}
          </div>

          {tab === 'listado' && <ListadoTab />}
          {tab === 'anexo' && <AnexoTab />}
          {tab === 'altasbajas' && <AltasBajasTab />}
          {tab === 'amort' && <AmortTab />}
          {tab === 'reexpresion' && <ReexpresionTab />}
        </CardBody>
      </Card>
    </div>
  );
}

function ListadoTab() {
  const [fecha, setFecha] = useState('');
  const qs = fecha ? `?fecha=${fecha}` : '';
  const { data, isLoading, error } = useApi<ListadoFila[]>(
    ['af-rep-listado', fecha], `/api/erp/af/reportes/listado${qs}`
  );

  const columns: Column<ListadoFila>[] = [
    { key: 'nro_inventario', header: 'Inventario', width: '130px',
      render: (r) => <code className="text-[12px]">{r.nro_inventario}</code> },
    { key: 'descripcion', header: 'Descripción' },
    { key: 'categoria_nombre', header: 'Categoría', width: '160px',
      render: (r) => r.categoria_nombre ?? '—' },
    { key: 'fecha_alta', header: 'Alta', width: '95px',
      render: (r) => fmtDate(r.fecha_alta) },
    { key: 'valor_origen', header: 'V. Origen', align: 'right', width: '110px',
      render: (r) => fmtMoney(Number(r.valor_origen)) },
    { key: 'amort_acum_contable', header: 'Amort. Acum.', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.amort_acum_contable)) },
    { key: 'vnr_contable', header: 'VNR', align: 'right', width: '110px',
      render: (r) => fmtMoney(Number(r.vnr_contable)) },
    { key: 'estado', header: 'Estado', width: '110px',
      render: (r) => <Badge variant="default">{r.estado}</Badge> },
  ];

  return (
    <>
      <div className="flex flex-wrap gap-3 items-end">
        <Field label="Corte al" type="date" value={fecha}
          onChange={(e) => setFecha(e.target.value)}
          hint="Vacío = hoy" containerClassName="w-[180px]" />
      </div>
      {error && <FormError error={errorMessage(error)} />}
      <DataTable columns={columns} rows={data ?? []} loading={isLoading} empty="Sin bienes" />
    </>
  );
}

function AnexoTab() {
  const [ejercicioId, setEjercicioId] = useState('');
  const qs = ejercicioId ? `?ejercicio_id=${ejercicioId}` : '';
  const { data, isLoading, error } = useApi<AnexoFila[]>(
    ['af-rep-anexo', ejercicioId], `/api/erp/af/reportes/anexo-bienes-uso${qs}`,
    { enabled: Boolean(ejercicioId) }
  );

  const columns: Column<AnexoFila>[] = [
    { key: 'categoria', header: 'Categoría' },
    { key: 'saldo_inicial', header: 'Saldo inic.', align: 'right',
      render: (r) => fmtMoney(Number(r.saldo_inicial)) },
    { key: 'altas', header: 'Altas', align: 'right',
      render: (r) => fmtMoney(Number(r.altas)) },
    { key: 'bajas', header: 'Bajas', align: 'right',
      render: (r) => fmtMoney(Number(r.bajas)) },
    { key: 'saldo_final', header: 'Saldo final', align: 'right',
      render: (r) => fmtMoney(Number(r.saldo_final)) },
    { key: 'amort_inicial', header: 'Amort. inic.', align: 'right',
      render: (r) => fmtMoney(Number(r.amort_inicial)) },
    { key: 'amort_ejercicio', header: 'Amort. ejer.', align: 'right',
      render: (r) => fmtMoney(Number(r.amort_ejercicio)) },
    { key: 'amort_bajas', header: 'Amort. bajas', align: 'right',
      render: (r) => fmtMoney(Number(r.amort_bajas)) },
    { key: 'amort_final', header: 'Amort. final', align: 'right',
      render: (r) => fmtMoney(Number(r.amort_final)) },
    { key: 'vnr_final', header: 'VNR final', align: 'right',
      render: (r) => fmtMoney(Number(r.vnr_final)) },
  ];

  return (
    <>
      <div className="flex flex-wrap gap-3 items-end">
        <Field label="ID ejercicio" required type="number" value={ejercicioId}
          onChange={(e) => setEjercicioId(e.target.value)}
          containerClassName="w-[180px]" />
      </div>
      {error && <FormError error={errorMessage(error)} />}
      <DataTable columns={columns} rows={data ?? []} loading={isLoading}
        empty="Cargá un ejercicio para ver el anexo" />
    </>
  );
}

function AltasBajasTab() {
  const [ejercicioId, setEjercicioId] = useState('');
  const qs = ejercicioId ? `?ejercicio_id=${ejercicioId}` : '';
  const { data, isLoading, error } = useApi<AltasBajasResp>(
    ['af-rep-altasbajas', ejercicioId], `/api/erp/af/reportes/altas-bajas${qs}`,
    { enabled: Boolean(ejercicioId) }
  );

  const cols: Column<ListadoFila>[] = [
    { key: 'nro_inventario', header: 'Inventario', width: '130px',
      render: (r) => <code className="text-[12px]">{r.nro_inventario}</code> },
    { key: 'descripcion', header: 'Descripción' },
    { key: 'fecha_alta', header: 'Fecha', width: '95px',
      render: (r) => fmtDate(r.fecha_alta) },
    { key: 'valor_origen', header: 'Valor', align: 'right', width: '110px',
      render: (r) => fmtMoney(Number(r.valor_origen)) },
  ];

  return (
    <>
      <div className="flex flex-wrap gap-3 items-end">
        <Field label="ID ejercicio" required type="number" value={ejercicioId}
          onChange={(e) => setEjercicioId(e.target.value)}
          containerClassName="w-[180px]" />
      </div>
      {error && <FormError error={errorMessage(error)} />}
      {data && (
        <div className="grid grid-cols-2 gap-3">
          <KpiCard label="Altas" value={`${data.totales.altas} (${fmtMoney(Number(data.totales.total_altas))})`} />
          <KpiCard label="Bajas" value={`${data.totales.bajas} (${fmtMoney(Number(data.totales.total_bajas))})`} />
        </div>
      )}
      {data && (
        <div className="space-y-3">
          <div>
            <div className="text-[11.5px] uppercase font-semibold text-ink-muted mb-1">Altas</div>
            <DataTable columns={cols} rows={data.altas} loading={isLoading} empty="Sin altas" />
          </div>
          <div>
            <div className="text-[11.5px] uppercase font-semibold text-ink-muted mb-1">Bajas</div>
            <DataTable columns={cols} rows={data.bajas} loading={isLoading} empty="Sin bajas" />
          </div>
        </div>
      )}
    </>
  );
}

function AmortTab() {
  const [ejercicioId, setEjercicioId] = useState('');
  const qs = ejercicioId ? `?ejercicio_id=${ejercicioId}` : '';
  const { data, isLoading, error } = useApi<AmortVsFiscalFila[]>(
    ['af-rep-amort', ejercicioId], `/api/erp/af/reportes/amortizaciones${qs}`,
    { enabled: Boolean(ejercicioId) }
  );

  const totales = (data ?? []).reduce((acc, r) => ({
    cont: acc.cont + Number(r.cuota_contable_anual),
    fis: acc.fis + Number(r.cuota_fiscal_anual),
    dif: acc.dif + Number(r.diferencia),
  }), { cont: 0, fis: 0, dif: 0 });

  const columns: Column<AmortVsFiscalFila>[] = [
    { key: 'nro_inventario', header: 'Inventario', width: '130px',
      render: (r) => <code className="text-[12px]">{r.nro_inventario}</code> },
    { key: 'descripcion', header: 'Descripción' },
    { key: 'cuota_contable_anual', header: 'Contable anual', align: 'right',
      render: (r) => fmtMoney(Number(r.cuota_contable_anual)) },
    { key: 'cuota_fiscal_anual', header: 'Fiscal anual', align: 'right',
      render: (r) => fmtMoney(Number(r.cuota_fiscal_anual)) },
    { key: 'diferencia', header: 'Diferencia', align: 'right',
      render: (r) => {
        const v = Number(r.diferencia);
        return <span className={v > 0 ? 'text-success' : v < 0 ? 'text-danger' : ''}>{fmtMoney(v)}</span>;
      } },
  ];

  return (
    <>
      <div className="flex flex-wrap gap-3 items-end">
        <Field label="ID ejercicio" required type="number" value={ejercicioId}
          onChange={(e) => setEjercicioId(e.target.value)}
          containerClassName="w-[180px]" />
      </div>
      {error && <FormError error={errorMessage(error)} />}
      {data && data.length > 0 && (
        <div className="grid grid-cols-3 gap-3">
          <KpiCard label="Total contable" value={fmtMoney(totales.cont)} />
          <KpiCard label="Total fiscal" value={fmtMoney(totales.fis)} />
          <KpiCard label="Diferencia (DDJJ Gan)" value={fmtMoney(totales.dif)} />
        </div>
      )}
      <DataTable columns={columns} rows={data ?? []} loading={isLoading}
        empty="Cargá un ejercicio para ver la comparación" />
    </>
  );
}

function ReexpresionTab() {
  const [ejercicioId, setEjercicioId] = useState('');
  const [indiceDefault, setIndiceDefault] = useState('');
  const toast = useToast();
  const invalidate = useInvalidate(['af-reexp']);

  const qs = ejercicioId ? `?ejercicio_id=${ejercicioId}` : '';
  const { data, isLoading, error, refetch } = useApi<Reexpresion[]>(
    ['af-reexp', ejercicioId], `/api/erp/af/reexpresiones${qs}`,
    { enabled: Boolean(ejercicioId) }
  );

  const generar = useApiMutation<unknown, Record<string, unknown>>(
    (vars) => api.post('/api/erp/af/reexpresiones/generar', vars),
    {
      onSuccess: () => {
        toast.success('Reexpresión generada');
        invalidate();
        refetch();
      },
      onError: (e) => toast.error('Error al generar', errorMessage(e)),
    }
  );

  const columns: Column<Reexpresion>[] = [
    { key: 'bien', header: 'Bien',
      render: (r) => r.bien
        ? <div><code>{r.bien.nro_inventario}</code> <span className="text-ink-muted text-[11px]">{r.bien.descripcion}</span></div>
        : `#${r.bien_id}` },
    { key: 'indice_origen', header: 'Índice origen', align: 'right',
      render: (r) => Number(r.indice_origen).toFixed(6) },
    { key: 'indice_destino', header: 'Índice destino', align: 'right',
      render: (r) => Number(r.indice_destino).toFixed(6) },
    { key: 'factor', header: 'Factor', align: 'right',
      render: (r) => Number(r.factor).toFixed(4) },
    { key: 'valor_reexpresado', header: 'Valor reexpr.', align: 'right',
      render: (r) => fmtMoney(Number(r.valor_reexpresado)) },
    { key: 'generado_at', header: 'Generado', width: '140px',
      render: (r) => fmtDate(r.generado_at) },
  ];

  return (
    <>
      <div className="flex flex-wrap gap-3 items-end">
        <Field label="ID ejercicio" required type="number" value={ejercicioId}
          onChange={(e) => setEjercicioId(e.target.value)}
          containerClassName="w-[180px]" />
        <Field label="Índice origen default" type="number" step="0.000001" value={indiceDefault}
          onChange={(e) => setIndiceDefault(e.target.value)}
          hint="Para bienes sin índice asignado"
          containerClassName="w-[200px]" />
        <Button variant="primary" disabled={!ejercicioId || generar.isPending}
          onClick={() => generar.mutate({
            ejercicio_id: Number(ejercicioId),
            indice_origen_default: indiceDefault ? Number(indiceDefault) : undefined,
          })}>
          <RefreshCw className="w-3 h-3" /> {generar.isPending ? 'Generando…' : 'Generar reexpresión'}
        </Button>
      </div>
      {error && <FormError error={errorMessage(error)} />}
      <div className="text-[11.5px] text-ink-muted">
        RT 6: aplica el factor IPC al valor de origen para mostrar valores en moneda homogénea.
      </div>
      <DataTable columns={columns} rows={data ?? []} loading={isLoading}
        empty="Aún no se generó reexpresión para el ejercicio" />
    </>
  );
}

function KpiCard({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="border border-line rounded-md p-3 bg-white">
      <div className="text-[11px] uppercase text-ink-muted tracking-wide">{label}</div>
      <div className="text-[16px] font-semibold tabular-nums">{value}</div>
    </div>
  );
}
