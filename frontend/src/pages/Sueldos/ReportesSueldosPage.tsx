import { useState } from 'react';
import { PieChart, Calendar, User, Wallet, LayoutDashboard, Download } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { fmtMoney, fmtDate } from '@/components/ui/DataTable';
import { Field, FormError } from '@/components/ui/Field';
import { useApi, errorMessage } from '@/hooks/useApi';
import { auth } from '@/lib/auth';

type Tab = 'dashboard' | 'costo' | 'historico' | 'cc';

export function ReportesSueldosPage() {
  const [tab, setTab] = useState<Tab>('dashboard');
  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><PieChart className="w-4 h-4 text-azure" /> Reportes de Sueldos</div>
        } />
        <CardBody className="p-4">
          <div className="flex gap-2 border-b border-line mb-3">
            <Button size="sm" variant="ghost"
              className={tab === 'dashboard' ? 'border-b-2 border-azure rounded-none text-azure' : 'border-b-2 border-transparent rounded-none'}
              onClick={() => setTab('dashboard')}>
              <LayoutDashboard className="w-3 h-3" /> Dashboard
            </Button>
            <Button size="sm" variant="ghost"
              className={tab === 'costo' ? 'border-b-2 border-azure rounded-none text-azure' : 'border-b-2 border-transparent rounded-none'}
              onClick={() => setTab('costo')}>
              <Calendar className="w-3 h-3" /> Costo laboral anual
            </Button>
            <Button size="sm" variant="ghost"
              className={tab === 'historico' ? 'border-b-2 border-azure rounded-none text-azure' : 'border-b-2 border-transparent rounded-none'}
              onClick={() => setTab('historico')}>
              <User className="w-3 h-3" /> Histórico empleado
            </Button>
            <Button size="sm" variant="ghost"
              className={tab === 'cc' ? 'border-b-2 border-azure rounded-none text-azure' : 'border-b-2 border-transparent rounded-none'}
              onClick={() => setTab('cc')}>
              <Wallet className="w-3 h-3" /> CC empleado
            </Button>
          </div>
          {tab === 'dashboard' && <DashboardTab />}
          {tab === 'costo' && <CostoLaboralTab />}
          {tab === 'historico' && <HistoricoTab />}
          {tab === 'cc' && <CCEmpleadoTab />}
        </CardBody>
      </Card>
    </div>
  );
}

type CostoMes = { formal: number; efectivo: number; mt: number; total: number };
type CostoEmpleado = {
  empleado_id: number; legajo: string; nombre_completo: string;
  meses: Record<string, CostoMes>; total_anual: number;
};
type CostoResp = { anio: number; empleados: CostoEmpleado[]; totales_mes: Record<string, CostoMes> };

function CostoLaboralTab() {
  const [anio, setAnio] = useState(String(new Date().getFullYear()));
  const [submitted, setSubmitted] = useState('');

  const { data, isLoading, error } = useApi<CostoResp>(
    ['sueldos-rep-costo', submitted],
    `/api/erp/sueldos/reportes/costo-laboral?anio=${submitted}`,
    { enabled: !!submitted }
  );

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-3 items-end">
        <Field label="Año" required type="number" min={2020} max={2100} value={anio}
          onChange={(e) => setAnio(e.target.value)}
          containerClassName="w-[140px]" />
        <Button variant="primary" onClick={() => setSubmitted(anio)}>Generar</Button>
      </div>
      {error && <FormError error={errorMessage(error)} />}
      {isLoading && <div className="py-8 text-center text-ink-muted">Cargando…</div>}
      {data && (
        <div className="overflow-x-auto border border-line rounded-md">
          <table className="text-[11px]">
            <thead className="bg-bg-soft">
              <tr>
                <th rowSpan={2} className="text-left p-2 sticky left-0 bg-bg-soft min-w-[180px]">Empleado</th>
                {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                  <th key={m} colSpan={1} className="p-2 text-right border-l border-line">{String(m).padStart(2, '0')}</th>
                ))}
                <th className="p-2 text-right border-l border-line bg-info-bg/30">TOTAL ANUAL</th>
              </tr>
              <tr className="text-[10px] text-ink-muted uppercase"></tr>
            </thead>
            <tbody>
              {data.empleados.map((e) => (
                <tr key={e.empleado_id} className="border-t border-line/60">
                  <td className="p-2 sticky left-0 bg-white">
                    <code className="text-[10.5px]">{e.legajo}</code> {e.nombre_completo}
                  </td>
                  {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                    <td key={m} className="p-2 text-right tabular-nums border-l border-line/60">
                      {e.meses[m]?.total ? fmtMoney(e.meses[m].total) : <span className="text-ink-muted">—</span>}
                    </td>
                  ))}
                  <td className="p-2 text-right tabular-nums font-semibold border-l border-line bg-info-bg/15">
                    {fmtMoney(e.total_anual)}
                  </td>
                </tr>
              ))}
              {data.empleados.length === 0 && (
                <tr><td colSpan={14} className="p-6 text-center text-ink-muted">Sin liquidaciones APROBADAS/PAGADAS en {data.anio}</td></tr>
              )}
            </tbody>
            {data.empleados.length > 0 && (
              <tfoot className="bg-bg-soft font-semibold">
                <tr>
                  <td className="p-2 sticky left-0 bg-bg-soft">Total mes</td>
                  {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                    <td key={m} className="p-2 text-right tabular-nums border-l border-line">
                      {data.totales_mes[m]?.total ? fmtMoney(data.totales_mes[m].total) : '—'}
                    </td>
                  ))}
                  <td className="p-2 text-right tabular-nums border-l border-line bg-info-bg/30">
                    {fmtMoney(Object.values(data.totales_mes).reduce((a, m) => a + (m.total ?? 0), 0))}
                  </td>
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      )}
    </div>
  );
}

type HistEmp = { id: number; legajo: string; nombre_completo: string; cuil: string | null; regimen: string; categoria: string | null; convenio: string | null };
type HistLiq = { liquidacion_id: number; periodo: string; tipo: string; estado: string; haberes: number; descuentos: number; neto: number; formal: number; efectivo: number; mt: number };
type HistResp = { empleado: HistEmp; rango: { desde: string; hasta: string }; liquidaciones: HistLiq[] };

function HistoricoTab() {
  const [form, setForm] = useState({ id: '', desde: new Date().toISOString().slice(0, 7).replace(/.$/, '1'), hasta: new Date().toISOString().slice(0, 7) });
  const [submitted, setSubmitted] = useState<typeof form | null>(null);

  const qs = submitted ? `?desde=${submitted.desde}&hasta=${submitted.hasta}` : '';
  const { data, isLoading, error } = useApi<HistResp>(
    ['sueldos-rep-historico', submitted?.id, submitted?.desde, submitted?.hasta],
    submitted ? `/api/erp/sueldos/reportes/empleado/${submitted.id}/historico${qs}` : '',
    { enabled: !!submitted }
  );

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-3 items-end">
        <Field label="ID empleado" required type="number" value={form.id}
          onChange={(e) => setForm({ ...form, id: e.target.value })}
          containerClassName="w-[150px]" />
        <Field label="Desde" required value={form.desde} placeholder="YYYY-MM"
          onChange={(e) => setForm({ ...form, desde: e.target.value })}
          containerClassName="w-[140px]" />
        <Field label="Hasta" required value={form.hasta} placeholder="YYYY-MM"
          onChange={(e) => setForm({ ...form, hasta: e.target.value })}
          containerClassName="w-[140px]" />
        <Button variant="primary" disabled={!form.id || !form.desde || !form.hasta}
          onClick={() => setSubmitted({ ...form })}>
          Generar
        </Button>
      </div>
      {error && <FormError error={errorMessage(error)} />}
      {isLoading && <div className="py-8 text-center text-ink-muted">Cargando…</div>}
      {data && (
        <>
          <div className="border border-line rounded-md p-3 bg-white">
            <div className="text-[12.5px] font-semibold">{data.empleado.nombre_completo}</div>
            <div className="text-[11px] text-ink-muted">
              Legajo {data.empleado.legajo} · CUIL {data.empleado.cuil ?? '—'} ·
              Régimen <Badge variant="default">{data.empleado.regimen}</Badge> ·
              {data.empleado.categoria ?? 'Sin categoría'} {data.empleado.convenio ? `(${data.empleado.convenio})` : ''}
            </div>
          </div>
          <table className="w-full text-[12px] border border-line rounded-md">
            <thead className="bg-bg-soft text-[11px] uppercase text-ink-muted">
              <tr>
                <th className="p-2 text-left">Período</th>
                <th className="p-2 text-left">Tipo</th>
                <th className="p-2 text-left">Estado</th>
                <th className="p-2 text-right">Haberes</th>
                <th className="p-2 text-right">Descuentos</th>
                <th className="p-2 text-right">Neto</th>
                <th className="p-2 text-right">Formal</th>
                <th className="p-2 text-right">Efectivo</th>
                <th className="p-2 text-right">MT</th>
              </tr>
            </thead>
            <tbody>
              {data.liquidaciones.length === 0 ? (
                <tr><td colSpan={9} className="p-6 text-center text-ink-muted">Sin liquidaciones en el rango</td></tr>
              ) : data.liquidaciones.map((l) => (
                <tr key={l.liquidacion_id} className="border-t border-line/60">
                  <td className="p-2">{l.periodo}</td>
                  <td className="p-2"><Badge variant="default">{l.tipo}</Badge></td>
                  <td className="p-2"><Badge variant={l.estado === 'PAGADA' ? 'success' : l.estado === 'APROBADA' ? 'info' : 'neutral'}>{l.estado}</Badge></td>
                  <td className="p-2 text-right tabular-nums">{fmtMoney(l.haberes)}</td>
                  <td className="p-2 text-right tabular-nums">{fmtMoney(l.descuentos)}</td>
                  <td className="p-2 text-right tabular-nums font-semibold">{fmtMoney(l.neto)}</td>
                  <td className="p-2 text-right tabular-nums">{fmtMoney(l.formal)}</td>
                  <td className="p-2 text-right tabular-nums">{fmtMoney(l.efectivo)}</td>
                  <td className="p-2 text-right tabular-nums">{fmtMoney(l.mt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </div>
  );
}

type CCResp = {
  empleado: { id: number; legajo: string; nombre_completo: string };
  cuentas: Array<{
    id: number; tipo: string; saldo_actual: number | string; limite_credito: number | string | null;
    activa: boolean; cuenta_codigo: string | null; cuenta_nombre: string | null; ultima_fecha: string | null;
  }>;
};

function CCEmpleadoTab() {
  const [empId, setEmpId] = useState('');
  const [submitted, setSubmitted] = useState('');

  const { data, isLoading, error } = useApi<CCResp>(
    ['sueldos-rep-cc', submitted],
    submitted ? `/api/erp/sueldos/reportes/empleado/${submitted}/cc` : '',
    { enabled: !!submitted }
  );

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-3 items-end">
        <Field label="ID empleado" required type="number" value={empId}
          onChange={(e) => setEmpId(e.target.value)} containerClassName="w-[150px]" />
        <Button variant="primary" disabled={!empId} onClick={() => setSubmitted(empId)}>Generar</Button>
      </div>
      {error && <FormError error={errorMessage(error)} />}
      {isLoading && <div className="py-8 text-center text-ink-muted">Cargando…</div>}
      {data && (
        <>
          <div className="border border-line rounded-md p-3 bg-white">
            <div className="text-[12.5px] font-semibold">{data.empleado.nombre_completo}</div>
            <div className="text-[11px] text-ink-muted">Legajo {data.empleado.legajo}</div>
          </div>
          <table className="w-full text-[12px] border border-line rounded-md">
            <thead className="bg-bg-soft text-[11px] uppercase text-ink-muted">
              <tr>
                <th className="p-2 text-left">Tipo</th>
                <th className="p-2 text-left">Cuenta contable</th>
                <th className="p-2 text-right">Saldo</th>
                <th className="p-2 text-right">Límite</th>
                <th className="p-2 text-left">Último mov.</th>
                <th className="p-2">Activa</th>
              </tr>
            </thead>
            <tbody>
              {data.cuentas.length === 0
                ? <tr><td colSpan={6} className="p-6 text-center text-ink-muted">Sin CCs</td></tr>
                : data.cuentas.map((cc) => (
                  <tr key={cc.id} className="border-t border-line/60">
                    <td className="p-2"><Badge variant="default">{cc.tipo}</Badge></td>
                    <td className="p-2">
                      {cc.cuenta_codigo ? <><code className="text-[11px]">{cc.cuenta_codigo}</code> {cc.cuenta_nombre}</> : '—'}
                    </td>
                    <td className="p-2 text-right tabular-nums">{fmtMoney(Number(cc.saldo_actual))}</td>
                    <td className="p-2 text-right tabular-nums">{cc.limite_credito !== null ? fmtMoney(Number(cc.limite_credito)) : '—'}</td>
                    <td className="p-2">{cc.ultima_fecha ? fmtDate(cc.ultima_fecha) : <span className="text-ink-muted">—</span>}</td>
                    <td className="p-2 text-center">{cc.activa ? <Badge variant="success">SÍ</Badge> : <Badge variant="neutral">NO</Badge>}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        </>
      )}
    </div>
  );
}


// ── G-06: Dashboard totales-por-mes (Excel "TOTALES POR MES") ──────────
type DashMes = { periodo: string; tipo: string; haberes: number; descuentos: number; neto: number; formal: number; efectivo: number | null; mt: number; variacion_neto_pct: number | null };
type DashResp = { anio: number; meses: DashMes[]; acumulado: { haberes: number; descuentos: number; neto: number; formal: number; efectivo: number | null; mt: number } };

function descargarXlsx(tipo: 'dashboard' | 'costo-laboral', anio: string) {
  const token = auth.getToken();
  fetch(`/api/erp/sueldos/reportes/export-xlsx?anio=${anio}&tipo=${tipo}`, { headers: { Authorization: `Bearer ${token}` } })
    .then((r) => r.blob())
    .then((blob) => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `sueldos_${tipo}_${anio}.xlsx`;
      a.click();
      URL.revokeObjectURL(a.href);
    });
}

function DashboardTab() {
  const [anio, setAnio] = useState(String(new Date().getFullYear()));
  const { data, isLoading, error } = useApi<DashResp>(['sueldos-dashboard', anio], `/api/erp/sueldos/reportes/dashboard?anio=${anio}`);

  const ultimo = data?.meses.filter((m) => m.tipo === 'MENSUAL').at(-1);

  return (
    <div className="space-y-3">
      <div className="flex items-end gap-2">
        <Field label="Año" value={anio} onChange={(e) => setAnio(e.target.value)} className="w-24" />
        <Button size="sm" variant="outline" onClick={() => descargarXlsx('dashboard', anio)}><Download className="w-3 h-3" /> XLSX</Button>
        <Button size="sm" variant="outline" onClick={() => descargarXlsx('costo-laboral', anio)}><Download className="w-3 h-3" /> Costo laboral XLSX</Button>
      </div>
      {error && <FormError error={errorMessage(error)} />}
      {isLoading && <p className="text-sm text-slate-500">Cargando…</p>}

      {ultimo && (
        <div className="grid md:grid-cols-4 gap-3">
          <Card><CardBody className="py-3">
            <div className="text-[11px] uppercase text-slate-500">Neto último mes ({ultimo.periodo})</div>
            <div className="text-lg font-semibold">{fmtMoney(ultimo.neto)}</div>
            {ultimo.variacion_neto_pct !== null && (
              <Badge variant={ultimo.variacion_neto_pct > 0 ? 'warning' : 'success'}>
                {ultimo.variacion_neto_pct > 0 ? '+' : ''}{ultimo.variacion_neto_pct}% vs mes anterior
              </Badge>
            )}
          </CardBody></Card>
          <Card><CardBody className="py-3">
            <div className="text-[11px] uppercase text-slate-500">Acumulado {anio} — Neto</div>
            <div className="text-lg font-semibold">{data ? fmtMoney(data.acumulado.neto) : '—'}</div>
          </CardBody></Card>
          <Card><CardBody className="py-3">
            <div className="text-[11px] uppercase text-slate-500">Acumulado — Formal / MT</div>
            <div className="text-sm">{data ? `${fmtMoney(data.acumulado.formal)} / ${fmtMoney(data.acumulado.mt)}` : '—'}</div>
          </CardBody></Card>
          <Card><CardBody className="py-3">
            <div className="text-[11px] uppercase text-slate-500">Acumulado — Efectivo</div>
            <div className="text-sm">{data?.acumulado.efectivo !== null && data ? fmtMoney(data.acumulado.efectivo) : '— oculto —'}</div>
          </CardBody></Card>
        </div>
      )}

      <table className="text-[12px] min-w-full">
        <thead><tr className="text-left text-[11px] uppercase text-slate-500">
          <th className="px-2 py-1.5">Período</th><th className="px-2 py-1.5">Tipo</th>
          <th className="px-2 py-1.5 text-right">Haberes</th><th className="px-2 py-1.5 text-right">Descuentos</th>
          <th className="px-2 py-1.5 text-right">Neto</th><th className="px-2 py-1.5 text-right">Formal</th>
          <th className="px-2 py-1.5 text-right">Efectivo</th><th className="px-2 py-1.5 text-right">MT</th>
          <th className="px-2 py-1.5 text-right">Var. %</th>
        </tr></thead>
        <tbody>
          {(data?.meses ?? []).map((m) => (
            <tr key={m.periodo + m.tipo} className="border-t border-slate-100 dark:border-slate-800">
              <td className="px-2 py-1">{m.periodo}</td>
              <td className="px-2 py-1"><Badge variant={m.tipo === 'SAC' ? 'info' : 'default'}>{m.tipo}</Badge></td>
              <td className="px-2 py-1 text-right">{fmtMoney(m.haberes)}</td>
              <td className="px-2 py-1 text-right">{fmtMoney(m.descuentos)}</td>
              <td className="px-2 py-1 text-right font-semibold">{fmtMoney(m.neto)}</td>
              <td className="px-2 py-1 text-right">{fmtMoney(m.formal)}</td>
              <td className="px-2 py-1 text-right">{m.efectivo !== null ? fmtMoney(m.efectivo) : '— oculto —'}</td>
              <td className="px-2 py-1 text-right">{fmtMoney(m.mt)}</td>
              <td className="px-2 py-1 text-right">{m.variacion_neto_pct !== null ? `${m.variacion_neto_pct}%` : '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {data && data.meses.length === 0 && <p className="text-sm text-slate-500">Sin liquidaciones aprobadas en {anio}.</p>}
    </div>
  );
}
