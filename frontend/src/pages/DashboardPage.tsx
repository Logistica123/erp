import { ArrowDown, ArrowUp, Download } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

type Health = {
  status: string;
  empresa: { razon_social: string } | null;
  counts: {
    monedas: number;
    cuentas_contables: number;
    cuentas_imputables: number;
    permisos: number;
    roles: number;
    asientos: number;
  };
};

function Saldo({ label, value, sub }: { label: string; value: string; sub: string }) {
  return (
    <div className="pl-[18px] border-l border-white/15 first:border-none first:pl-0">
      <div className="text-[10px] opacity-70 uppercase tracking-wider font-semibold">{label}</div>
      <div className="text-xl font-semibold tabular mt-1">{value}</div>
      <div className="text-[11px] opacity-65 mt-[3px]">{sub}</div>
    </div>
  );
}

function Kpi({
  label,
  value,
  trend,
  trendDir,
  accent,
}: {
  label: string;
  value: string;
  trend: string;
  trendDir?: 'up' | 'down' | 'warn';
  accent: 'azure' | 'success' | 'danger' | 'warning';
}) {
  const accentColor = {
    azure: 'bg-azure',
    success: 'bg-success',
    danger: 'bg-danger',
    warning: 'bg-warning',
  }[accent];

  const trendClass =
    trendDir === 'up'
      ? 'text-success'
      : trendDir === 'down'
        ? 'text-danger'
        : trendDir === 'warn'
          ? 'text-warning'
          : 'text-ink-muted';

  return (
    <div className="relative bg-white border border-line rounded-lg p-[14px_16px] overflow-hidden">
      <div className={`absolute left-0 top-0 bottom-0 w-[3px] ${accentColor}`} />
      <div className="text-[10px] font-semibold tracking-wider uppercase text-ink-muted mb-[6px]">
        {label}
      </div>
      <div className="text-[22px] font-semibold text-navy-800 tracking-tight tabular">{value}</div>
      <div className={`text-[11px] mt-1 flex items-center gap-[5px] ${trendClass}`}>
        {trendDir === 'up' && <ArrowUp className="w-3 h-3" />}
        {trendDir === 'down' && <ArrowDown className="w-3 h-3" />}
        {trend}
      </div>
    </div>
  );
}

export function DashboardPage() {
  const { data: health } = useQuery<Health>({
    queryKey: ['health'],
    queryFn: () => api.get<Health>('/api/erp/health'),
  });

  const bankBalances = [
    { label: 'ICBC Cta Cte $', value: '$ 6.420.318' },
    { label: 'Galicia Cta Cte $', value: '$ 3.114.070' },
    { label: 'Brubank CC', value: '$ 1.850.000' },
    { label: 'Brubank Remunerada', value: '$ 5.204.815', pos: true },
    { label: 'Mercado Pago', value: '$ 1.244.210' },
    { label: 'ICBC USD · US$ 820', value: '$ 820.000' },
  ];

  const aging = [
    { cliente: 'OCA', fact: 8140500, cobr: 5200000, saldo: 2940500 },
    { cliente: 'URBANO', fact: 6820100, cobr: 6820100, saldo: 0 },
    { cliente: 'OCASA', fact: 4315780, cobr: 2115780, saldo: 2200000 },
    { cliente: 'Loginter', fact: 2910420, cobr: 2910420, saldo: 0 },
    { cliente: 'Newsan', fact: 1740000, cobr: 870000, saldo: 870000 },
  ];

  const fiscal = [
    { obl: 'IVA F.2002', per: 'Mar 2026', vence: '20/04', estado: 'Próximo', variant: 'warning' as const },
    { obl: 'IIBB CM03', per: 'Mar 2026', vence: '22/04', estado: 'Pendiente', variant: 'neutral' as const },
    { obl: 'SICORE', per: 'Mar 2026', vence: '15/04', estado: 'Presentado', variant: 'success' as const },
    { obl: 'Ant. Ganancias', per: 'N° 4/2026', vence: '28/04', estado: 'Pendiente', variant: 'neutral' as const },
  ];

  const months = [
    { label: 'Nov', h: 40 },
    { label: 'Dic', h: 55 },
    { label: 'Ene', h: 45 },
    { label: 'Feb', h: 60 },
    { label: 'Mar', h: 70 },
    { label: 'Abr', h: 85, hl: true },
  ];

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Dashboard contable</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            Situación financiera al cierre del día · 17/04/2026
            {health?.empresa && ` · ${health.empresa.razon_social}`}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary">
            <Download className="w-3 h-3" /> Exportar
          </Button>
          <Button variant="primary">Nuevo asiento</Button>
        </div>
      </div>

      <div className="bg-gradient-to-br from-navy-800 to-navy-600 text-white rounded-lg p-[18px_22px] mb-[18px] grid grid-cols-4 gap-5">
        <Saldo label="Activo Total" value={fmtMoney(142587340.2)} sub="+3,4% vs mes anterior" />
        <Saldo label="Pasivo Total" value={fmtMoney(58214105.77)} sub="41% del activo" />
        <Saldo label="Patrimonio Neto" value={fmtMoney(84373234.43)} sub="Ratio de solvencia 2,45" />
        <Saldo label="Resultado del Ejercicio" value={fmtMoney(12104220.18)} sub="Acumulado Ene-Abr" />
      </div>

      <div className="grid grid-cols-4 gap-[14px] mb-[18px]">
        <Kpi label="Disponibilidades" value="$ 18.452.117" trend="8,2% semana" trendDir="up" accent="azure" />
        <Kpi label="Ingresos del mes" value="$ 24.870.540" trend="12,1% vs Mar" trendDir="up" accent="success" />
        <Kpi label="Egresos del mes" value="$ 16.104.220" trend="2,8% vs Mar" trendDir="down" accent="danger" />
        <Kpi
          label="Asientos sin contabilizar"
          value={String(health?.counts.asientos ?? 0)}
          trend="Requieren revisión"
          trendDir="warn"
          accent="warning"
        />
      </div>

      <div className="grid grid-cols-[2fr_1fr] gap-4 mb-4">
        <Card>
          <CardHeader
            title="Evolución mensual · Ingresos vs Egresos"
            actions={
              <>
                <Button variant="secondary" size="sm">
                  12 meses
                </Button>
                <Button variant="secondary" size="sm">
                  YTD
                </Button>
              </>
            }
          />
          <CardBody className="h-[180px] p-[14px] bg-gradient-to-b from-surface-row to-white">
            <div className="flex items-end gap-[4%] h-full pb-6">
              {months.map((m) => (
                <div key={m.label} className="flex-1 relative">
                  <div
                    className={`${
                      m.hl
                        ? 'bg-gradient-to-t from-[#0A5A0A] to-success'
                        : 'bg-gradient-to-t from-navy-600 to-azure'
                    } rounded-t-[3px]`}
                    style={{ height: `${m.h}%` }}
                  />
                  <div className="absolute -bottom-[18px] left-1/2 -translate-x-1/2 text-[10px] text-ink-muted">
                    {m.label}
                  </div>
                </div>
              ))}
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Saldos bancarios" />
          <CardBody>
            <div className="flex flex-col">
              {bankBalances.map((b) => (
                <div
                  key={b.label}
                  className="flex items-center justify-between px-4 py-[9px] border-b border-line last:border-b-0 text-[12px]"
                >
                  <span className="text-ink-2">{b.label}</span>
                  <span className={`font-semibold tabular ${b.pos ? 'text-success' : 'text-navy-800'}`}>
                    {b.value}
                  </span>
                </div>
              ))}
            </div>
          </CardBody>
        </Card>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <Card>
          <CardHeader
            title="Cuentas por cobrar · Top 5 clientes"
            actions={
              <Button variant="secondary" size="sm">
                Ver todos
              </Button>
            }
          />
          <CardBody>
            <table className="w-full border-collapse text-[12px]">
              <thead>
                <tr className="bg-surface-hover border-b border-line-strong">
                  <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                    Cliente
                  </th>
                  <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                    Facturado
                  </th>
                  <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                    Cobrado
                  </th>
                  <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                    Saldo
                  </th>
                </tr>
              </thead>
              <tbody>
                {aging.map((r, i) => (
                  <tr key={r.cliente} className={i % 2 ? 'bg-surface-row' : ''}>
                    <td className="px-[10px] py-[7px] text-ink-2">{r.cliente}</td>
                    <td className="px-[10px] py-[7px] tabular text-ink-2">{fmtMoney(r.fact)}</td>
                    <td className="px-[10px] py-[7px] tabular text-ink-2">{fmtMoney(r.cobr)}</td>
                    <td
                      className={`px-[10px] py-[7px] tabular text-right font-medium ${
                        r.saldo > 0 ? 'text-danger' : 'text-ink-2'
                      }`}
                    >
                      {fmtMoney(r.saldo)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Vencimientos fiscales próximos" />
          <CardBody>
            <table className="w-full border-collapse text-[12px]">
              <thead>
                <tr className="bg-surface-hover border-b border-line-strong">
                  <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                    Obligación
                  </th>
                  <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                    Período
                  </th>
                  <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                    Vence
                  </th>
                  <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                    Estado
                  </th>
                </tr>
              </thead>
              <tbody>
                {fiscal.map((r, i) => (
                  <tr key={r.obl} className={i % 2 ? 'bg-surface-row' : ''}>
                    <td className="px-[10px] py-[7px] text-ink-2">{r.obl}</td>
                    <td className="px-[10px] py-[7px] text-ink-2">{r.per}</td>
                    <td className="px-[10px] py-[7px] text-ink-2">{r.vence}</td>
                    <td className="px-[10px] py-[7px]">
                      <Badge variant={r.variant}>{r.estado}</Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardBody>
        </Card>
      </div>
    </>
  );
}
