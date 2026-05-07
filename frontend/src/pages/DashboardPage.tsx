import { Plus, Loader2 } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { Link } from 'react-router-dom';

type EvolMes = { label: string; anio: number; mes: number; cant: number; total: number; actual: boolean };
type UltFactura = {
  id: number; fecha_emision: string; numero: number; imp_total: string;
  estado: string; origen: string; tipo_codigo: string; letra: string | null;
  pto_vta: number; cliente_nombre: string;
};
type UltAsiento = {
  id: number; numero: number; fecha: string; glosa: string | null;
  total_debe: string; diario: string;
};

type Stats = {
  fecha: string;
  periodo_actual: { id: number; anio: number; mes: number; estado: string } | null;
  mes: { inicio: string; fin: string; facturas: number; facturado_total: number; facturado_neto: number; iva_df: number };
  por_cobrar: { cant: number; total: number };
  contadores: { clientes: number; distribuidores: number; asientos_contabilizados: number };
  evolucion_6m: EvolMes[];
  ultimas_facturas: UltFactura[];
  ultimos_asientos: UltAsiento[];
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
  label, value, sub, accent,
}: {
  label: string; value: string; sub: string;
  accent: 'azure' | 'success' | 'danger' | 'warning';
}) {
  const bar = {
    azure: 'bg-azure', success: 'bg-success',
    danger: 'bg-danger', warning: 'bg-warning',
  }[accent];
  return (
    <div className="relative bg-white border border-line rounded-lg p-[14px_16px] overflow-hidden">
      <div className={`absolute left-0 top-0 bottom-0 w-[3px] ${bar}`} />
      <div className="text-[10px] font-semibold tracking-wider uppercase text-ink-muted mb-[6px]">{label}</div>
      <div className="text-[22px] font-semibold text-navy-800 tracking-tight tabular">{value}</div>
      <div className="text-[11px] mt-1 text-ink-muted">{sub}</div>
    </div>
  );
}

function formatNro(t: string, l: string | null, pv: number, nro: number) {
  const lbl = l ? `${t}-${l}` : t;
  return `${lbl} ${String(pv).padStart(4, '0')}-${String(nro).padStart(8, '0')}`;
}

type PendientesResp = {
  data: { resumen: { total: number; pendientes: number; etiquetados: number } };
};

const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

export function DashboardPage() {
  const { data, isLoading } = useQuery<Stats>({
    queryKey: ['dashboard-stats'],
    queryFn: () => api.get<Stats>('/api/erp/dashboard/stats'),
  });
  const { data: pend } = useQuery<PendientesResp>({
    queryKey: ['pendientes-conciliar'],
    queryFn: () => api.get('/api/erp/reportes/pendientes-conciliar'),
    staleTime: 60_000,
  });

  if (isLoading || !data) {
    return (
      <div className="p-8 flex items-center justify-center gap-2 text-gray-500">
        <Loader2 className="w-4 h-4 animate-spin" /> Cargando dashboard...
      </div>
    );
  }

  const maxTotal = Math.max(1, ...data.evolucion_6m.map((m) => m.total));
  const periodoTxt = data.periodo_actual
    ? `${MESES[data.periodo_actual.mes - 1]} ${data.periodo_actual.anio}`
    : 'Sin período abierto';
  const pendientes = pend?.data.resumen;

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Dashboard contable</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            Situación al {data.fecha} · Período abierto: {periodoTxt}
          </p>
        </div>
        <div className="flex gap-2">
          <Link to="/erp/facturacion/nueva">
            <Button variant="primary"><Plus className="w-3 h-3" /> Nueva factura</Button>
          </Link>
        </div>
      </div>

      {/* Banner con contadores operativos */}
      <div className="bg-gradient-to-br from-navy-800 to-navy-600 text-white rounded-lg p-[18px_22px] mb-[18px] grid grid-cols-4 gap-5">
        <Saldo
          label="Clientes activos"
          value={String(data.contadores.clientes)}
          sub="Sincronizados con DistriApp"
        />
        <Saldo
          label="Distribuidores"
          value={String(data.contadores.distribuidores)}
          sub="Base auxiliares"
        />
        <Saldo
          label="Asientos contabilizados"
          value={String(data.contadores.asientos_contabilizados)}
          sub="Vigentes"
        />
        <Saldo
          label="Facturas del mes"
          value={String(data.mes.facturas)}
          sub={data.mes.inicio.slice(0, 7)}
        />
      </div>

      {/* KPIs principales */}
      <div className="grid grid-cols-4 gap-[14px] mb-[18px]">
        <Kpi
          label="Facturado del mes"
          value={fmtMoney(data.mes.facturado_total)}
          sub={`Neto ${fmtMoney(data.mes.facturado_neto)}`}
          accent="azure"
        />
        <Kpi
          label="IVA Débito Fiscal"
          value={fmtMoney(data.mes.iva_df)}
          sub="A declarar en F.2002"
          accent="success"
        />
        <Kpi
          label="Saldo a cobrar (con IVA, hoy)"
          value={fmtMoney(data.por_cobrar.total)}
          sub={`Facturas pendientes − NC · ${data.por_cobrar.cant} facturas`}
          accent="warning"
        />
        <Kpi
          label="Mov. pendientes de conciliar"
          value={pendientes ? String(pendientes.pendientes + pendientes.etiquetados) : '—'}
          sub={pendientes
            ? `${pendientes.pendientes} pendientes · ${pendientes.etiquetados} etiquetados`
            : 'Cargando…'}
          accent="danger"
        />
      </div>

      {/* Evolución + últimas facturas */}
      <div className="grid grid-cols-[2fr_1fr] gap-4 mb-4">
        <Card>
          <CardHeader title="Facturado últimos 6 meses" />
          <CardBody className="h-[200px] p-[14px] bg-gradient-to-b from-surface-row to-white">
            <div className="flex items-end gap-[4%] h-full pb-6">
              {data.evolucion_6m.map((m) => {
                const vacio = m.cant === 0;
                const h = vacio
                  ? 8                                                  // barra "fantasma" mínima
                  : Math.max(15, (m.total / maxTotal) * 100);          // mínimo 15% si hay datos
                const tooltip = vacio
                  ? `${m.label} ${m.anio}: sin facturas`
                  : `${m.label} ${m.anio}: ${fmtMoney(m.total)} (${m.cant} cbte${m.cant === 1 ? '' : 's'})`;
                return (
                  <div key={`${m.anio}-${m.mes}`} className="flex-1 relative group">
                    <div
                      className={
                        vacio
                          ? 'bg-line border border-dashed border-line-strong rounded-t-[3px]'
                          : m.actual
                            ? 'bg-gradient-to-t from-[#0A5A0A] to-success rounded-t-[3px] transition-all'
                            : 'bg-gradient-to-t from-navy-600 to-azure rounded-t-[3px] transition-all'
                      }
                      style={{ height: `${h}%` }}
                      title={tooltip}
                    />
                    <div className="absolute -bottom-[18px] left-1/2 -translate-x-1/2 text-[10px] text-ink-muted whitespace-nowrap">
                      {m.label}
                    </div>
                    {vacio && (
                      <div className="absolute top-[35%] left-1/2 -translate-x-1/2 text-[9px] text-ink-muted opacity-70 italic whitespace-nowrap">
                        sin fact.
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Últimas facturas"
            actions={
              <Link to="/erp/facturacion">
                <Button variant="secondary" size="sm">Ver todas</Button>
              </Link>
            }
          />
          <CardBody>
            {data.ultimas_facturas.length === 0 ? (
              <div className="py-6 text-center text-[12px] text-ink-muted">Sin facturas todavía.</div>
            ) : (
              <div className="flex flex-col">
                {data.ultimas_facturas.map((f) => (
                  <div
                    key={f.id}
                    className="flex items-center justify-between py-[8px] border-b border-line last:border-b-0"
                  >
                    <div className="min-w-0 flex-1">
                      <div className="font-mono text-[11px] text-ink-2">
                        {formatNro(f.tipo_codigo, f.letra, f.pto_vta, f.numero)}
                      </div>
                      <div className="text-[12px] text-navy-800 truncate">{f.cliente_nombre}</div>
                    </div>
                    <div className="text-right font-mono text-[12px] font-semibold ml-2">
                      {fmtMoney(parseFloat(f.imp_total))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>
      </div>

      {/* Últimos asientos */}
      <Card>
        <CardHeader
          title="Últimos asientos contables"
          actions={
            <Link to="/erp/libro-diario">
              <Button variant="secondary" size="sm">Ver libro diario</Button>
            </Link>
          }
        />
        <CardBody className="p-0">
          {data.ultimos_asientos.length === 0 ? (
            <div className="p-6 text-center text-[12px] text-ink-muted">Sin asientos todavía.</div>
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-surface-hover border-b border-line-strong text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                <tr>
                  <th className="px-4 py-2 text-left">Fecha</th>
                  <th className="px-4 py-2 text-left">Diario</th>
                  <th className="px-4 py-2 text-left">N°</th>
                  <th className="px-4 py-2 text-left">Glosa</th>
                  <th className="px-4 py-2 text-right">Total</th>
                </tr>
              </thead>
              <tbody>
                {data.ultimos_asientos.map((a, i) => (
                  <tr key={a.id} className={i % 2 ? 'bg-surface-row' : ''}>
                    <td className="px-4 py-2 text-ink-2 whitespace-nowrap">{a.fecha?.slice(0, 10)}</td>
                    <td className="px-4 py-2"><Badge variant="neutral">{a.diario}</Badge></td>
                    <td className="px-4 py-2 font-mono text-ink-2">#{a.numero}</td>
                    <td className="px-4 py-2 text-ink-2 truncate max-w-[400px]">{a.glosa ?? '—'}</td>
                    <td className="px-4 py-2 text-right font-mono font-semibold">
                      {fmtMoney(parseFloat(a.total_debe))}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardBody>
      </Card>
    </>
  );
}
