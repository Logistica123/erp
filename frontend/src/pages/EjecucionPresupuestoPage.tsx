import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Activity, BarChart3, ListChecks } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, type Column } from '@/components/ui/DataTable';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { useApi, errorMessage } from '@/hooks/useApi';

type Semaforo = 'verde' | 'amarillo' | 'rojo' | 'sin_dato';

type EjecFila = {
  cuenta: string;
  presupuesto_acum: number;
  real_acum: number;
  ejecucion_pct: number | null;
  semaforo: Semaforo;
};
type EjecResp = {
  presupuesto_id: number;
  hasta_mes: number;
  filas: EjecFila[];
};

type DetalleFila = {
  item_id: number;
  cuenta: string;
  cuenta_tipo: string;
  centro_costo: string | null;
  mes: number;
  presupuesto: number;
  real: number;
  variacion_abs: number;
  variacion_pct: number | null;
};
type VariacionesResp = {
  presupuesto_id: number;
  anio: number;
  filas: DetalleFila[];
  totales: { presupuesto: number; real: number; variacion_abs: number };
};

type ResumenFila = {
  clave: string;
  presupuesto: number;
  real: number;
  variacion_abs: number;
  variacion_pct: number | null;
};
type ResumenResp = {
  presupuesto_id: number;
  agrupado_por: 'cuenta' | 'cc';
  filas: ResumenFila[];
  totales: { presupuesto: number; real: number; variacion_abs: number };
};

const MESES = Array.from({ length: 12 }, (_, i) => ({
  value: String(i + 1), label: String(i + 1).padStart(2, '0'),
}));

const semaforoBadge = (s: Semaforo): { v: 'success' | 'warning' | 'danger' | 'neutral'; label: string } => {
  switch (s) {
    case 'verde':    return { v: 'success', label: 'OK (<95%)' };
    case 'amarillo': return { v: 'warning', label: 'Atención (95-110%)' };
    case 'rojo':     return { v: 'danger',  label: 'Sobreejecución (>110%)' };
    default:         return { v: 'neutral', label: 'Sin dato' };
  }
};

type Tab = 'ejecucion' | 'variaciones' | 'resumen';

export function EjecucionPresupuestoPage() {
  const [search, setSearch] = useSearchParams();
  const [presupuestoId, setPresupuestoId] = useState(search.get('id') ?? '');
  const [hastaMes, setHastaMes] = useState(String(new Date().getMonth() + 1));
  const [tab, setTab] = useState<Tab>('ejecucion');
  const [resumenPor, setResumenPor] = useState<'cuenta' | 'cc'>('cuenta');

  useEffect(() => {
    const id = search.get('id');
    if (id && id !== presupuestoId) setPresupuestoId(id);
  }, [search]); // eslint-disable-line react-hooks/exhaustive-deps

  const enabled = Boolean(presupuestoId);

  const { data: ejec, isLoading: lEjec, error: eEjec } = useApi<EjecResp>(
    ['presup-ejecucion', presupuestoId, hastaMes],
    `/api/erp/presupuestos/${presupuestoId}/ejecucion?hasta_mes=${hastaMes}`,
    { enabled: enabled && tab === 'ejecucion' }
  );

  const { data: vars, isLoading: lVars, error: eVars } = useApi<VariacionesResp>(
    ['presup-variaciones', presupuestoId],
    `/api/erp/presupuestos/${presupuestoId}/variaciones`,
    { enabled: enabled && tab === 'variaciones' }
  );

  const { data: resumen, isLoading: lRes, error: eRes } = useApi<ResumenResp>(
    ['presup-resumen', presupuestoId, resumenPor],
    `/api/erp/presupuestos/${presupuestoId}/variaciones/resumen?por=${resumenPor}`,
    { enabled: enabled && tab === 'resumen' }
  );

  const setId = (id: string) => {
    setPresupuestoId(id);
    if (id) setSearch({ id }); else setSearch({});
  };

  const ejecCols: Column<EjecFila>[] = [
    { key: 'cuenta', header: 'Cuenta' },
    { key: 'presupuesto_acum', header: 'Presup. acum.', align: 'right', width: '140px',
      render: (r) => fmtMoney(r.presupuesto_acum) },
    { key: 'real_acum', header: 'Real acum.', align: 'right', width: '140px',
      render: (r) => fmtMoney(r.real_acum) },
    { key: 'pct', header: 'Ejecución', align: 'right', width: '120px',
      render: (r) => r.ejecucion_pct !== null ? `${r.ejecucion_pct.toFixed(1)}%` : '—' },
    { key: 'semaforo', header: 'Estado', width: '210px',
      render: (r) => {
        const b = semaforoBadge(r.semaforo);
        return <Badge variant={b.v}>{b.label}</Badge>;
      } },
  ];

  const totalesEjec = useMemo(() => {
    if (!ejec) return null;
    const presup = ejec.filas.reduce((a, f) => a + f.presupuesto_acum, 0);
    const real = ejec.filas.reduce((a, f) => a + f.real_acum, 0);
    const pct = presup !== 0 ? (real / presup) * 100 : null;
    return { presup, real, pct };
  }, [ejec]);

  const detalleCols: Column<DetalleFila>[] = [
    { key: 'cuenta', header: 'Cuenta' },
    { key: 'cc', header: 'CC', width: '90px',
      render: (r) => r.centro_costo ?? '—' },
    { key: 'mes', header: 'Mes', width: '70px',
      render: (r) => String(r.mes).padStart(2, '0') },
    { key: 'tipo', header: 'Tipo', width: '70px',
      render: (r) => <Badge variant="default">{r.cuenta_tipo}</Badge> },
    { key: 'presupuesto', header: 'Presup.', align: 'right', width: '120px',
      render: (r) => fmtMoney(r.presupuesto) },
    { key: 'real', header: 'Real', align: 'right', width: '120px',
      render: (r) => fmtMoney(r.real) },
    { key: 'variacion_abs', header: 'Var. abs.', align: 'right', width: '120px',
      render: (r) => (
        <span className={r.variacion_abs > 0 ? 'text-danger' : r.variacion_abs < 0 ? 'text-success' : ''}>
          {r.variacion_abs > 0 ? '+' : ''}{fmtMoney(r.variacion_abs)}
        </span>
      ) },
    { key: 'variacion_pct', header: 'Var. %', align: 'right', width: '90px',
      render: (r) => r.variacion_pct !== null ? `${r.variacion_pct > 0 ? '+' : ''}${r.variacion_pct.toFixed(1)}%` : '—' },
  ];

  const resumenCols: Column<ResumenFila>[] = [
    { key: 'clave', header: resumenPor === 'cc' ? 'Centro de costo' : 'Cuenta' },
    { key: 'presupuesto', header: 'Presupuesto', align: 'right', width: '140px',
      render: (r) => fmtMoney(r.presupuesto) },
    { key: 'real', header: 'Real', align: 'right', width: '140px',
      render: (r) => fmtMoney(r.real) },
    { key: 'variacion_abs', header: 'Var. abs.', align: 'right', width: '140px',
      render: (r) => (
        <span className={r.variacion_abs > 0 ? 'text-danger' : r.variacion_abs < 0 ? 'text-success' : ''}>
          {r.variacion_abs > 0 ? '+' : ''}{fmtMoney(r.variacion_abs)}
        </span>
      ) },
    { key: 'variacion_pct', header: 'Var. %', align: 'right', width: '100px',
      render: (r) => r.variacion_pct !== null ? `${r.variacion_pct > 0 ? '+' : ''}${r.variacion_pct.toFixed(1)}%` : '—' },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><Activity className="w-4 h-4 text-azure" /> Ejecución presupuestaria</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <Field label="ID presupuesto" required type="number" value={presupuestoId}
              onChange={(e) => setId(e.target.value)}
              containerClassName="w-[180px]" />
            {tab === 'ejecucion' && (
              <SelectField label="Hasta mes" value={hastaMes}
                onChange={(e) => setHastaMes(e.target.value)}
                options={MESES} placeholder={null}
                containerClassName="w-[110px]" />
            )}
            {tab === 'resumen' && (
              <SelectField label="Agrupar por" value={resumenPor}
                onChange={(e) => setResumenPor(e.target.value as 'cuenta' | 'cc')}
                options={[{ value: 'cuenta', label: 'Cuenta' }, { value: 'cc', label: 'Centro de costo' }]}
                placeholder={null} containerClassName="w-[180px]" />
            )}
          </div>

          <div className="flex gap-2 border-b border-line">
            <TabButton active={tab === 'ejecucion'} onClick={() => setTab('ejecucion')}
              icon={<Activity className="w-3 h-3" />} label="Ejecución acumulada" />
            <TabButton active={tab === 'variaciones'} onClick={() => setTab('variaciones')}
              icon={<ListChecks className="w-3 h-3" />} label="Variaciones (detalle)" />
            <TabButton active={tab === 'resumen'} onClick={() => setTab('resumen')}
              icon={<BarChart3 className="w-3 h-3" />} label="Variaciones (resumen)" />
          </div>

          {!enabled && (
            <div className="py-12 text-center text-ink-muted">
              Ingresá el ID del presupuesto para ver su ejecución.
            </div>
          )}

          {enabled && tab === 'ejecucion' && (
            <>
              {eEjec && <FormError error={errorMessage(eEjec)} />}
              {totalesEjec && (
                <div className="grid grid-cols-3 gap-3">
                  <Kpi label="Presupuesto acum." value={fmtMoney(totalesEjec.presup)} />
                  <Kpi label="Real acum." value={fmtMoney(totalesEjec.real)} />
                  <Kpi label="Ejecución global"
                    value={totalesEjec.pct !== null ? `${totalesEjec.pct.toFixed(1)}%` : '—'} />
                </div>
              )}
              <DataTable columns={ejecCols} rows={ejec?.filas ?? []} loading={lEjec}
                empty="Sin filas para mostrar" />
            </>
          )}

          {enabled && tab === 'variaciones' && (
            <>
              {eVars && <FormError error={errorMessage(eVars)} />}
              {vars && (
                <div className="grid grid-cols-3 gap-3">
                  <Kpi label="Presupuesto" value={fmtMoney(vars.totales.presupuesto)} />
                  <Kpi label="Real" value={fmtMoney(vars.totales.real)} />
                  <Kpi label="Variación abs." value={fmtMoney(vars.totales.variacion_abs)} />
                </div>
              )}
              <DataTable columns={detalleCols} rows={vars?.filas ?? []} loading={lVars}
                empty="Sin variaciones" />
            </>
          )}

          {enabled && tab === 'resumen' && (
            <>
              {eRes && <FormError error={errorMessage(eRes)} />}
              {resumen && (
                <div className="grid grid-cols-3 gap-3">
                  <Kpi label="Presupuesto" value={fmtMoney(resumen.totales.presupuesto)} />
                  <Kpi label="Real" value={fmtMoney(resumen.totales.real)} />
                  <Kpi label="Variación abs." value={fmtMoney(resumen.totales.variacion_abs)} />
                </div>
              )}
              <DataTable columns={resumenCols} rows={resumen?.filas ?? []} loading={lRes}
                empty="Sin datos" />
            </>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

function TabButton({ active, onClick, icon, label }: { active: boolean; onClick: () => void; icon: React.ReactNode; label: string }) {
  return (
    <Button variant="ghost" size="sm" onClick={onClick}
      className={active
        ? 'border-b-2 border-azure rounded-none text-azure'
        : 'border-b-2 border-transparent rounded-none'}>
      {icon} {label}
    </Button>
  );
}

function Kpi({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="border border-line rounded-md p-3 bg-white">
      <div className="text-[11px] uppercase text-ink-muted tracking-wide">{label}</div>
      <div className="text-[16px] font-semibold tabular-nums">{value}</div>
    </div>
  );
}
