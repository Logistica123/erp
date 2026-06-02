import { useMemo, useState } from 'react';
import { Loader2, RefreshCw, TrendingUp, TrendingDown, Activity, X, Calendar, FileSpreadsheet, FileText } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { fmtMoney } from '@/lib/cn';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useApi } from '@/hooks/useApi';
import { auth } from '@/lib/auth';

// v1.37 — Pantalla del reporte de saldos consolidados (Deudores ventas + Deuda
// compras + aging + top deudores/acreedores + drill-down).
//
// Convención D-37-4: FACTURA es el default y nunca se rotula. Solo EFECTIVO
// aparece como subtotal y badge. Sin permiso `ver_efectivo`, el desglose se
// oculta completamente.

type Widget = {
  total: number;
  efectivo?: number;
  pct_efectivo?: number;
  cantidad_operaciones: number;
};

type Bucket = { total: number; efectivo?: number; pct: number; cantidad: number };
type AgingMap = Record<'corriente' | '1_30' | '31_60' | '61_90' | 'mas_90', Bucket>;

type TopRow = {
  auxiliar_id: number;
  codigo: string;
  nombre: string;
  cuit: string | null;
  saldo_total: number;
  saldo_efectivo?: number;
  saldo_vencido: number;
  cantidad: number;
};

type ReporteData = {
  fecha_corte: string;
  moneda: string;
  incluir_efectivo: boolean;
  widgets: {
    deudores_ventas: Widget;
    deuda_compras: Widget;
    posicion_neta: number;
  };
  aging_deudores: AgingMap;
  aging_acreedores: AgingMap;
  top_deudores: TopRow[];
  top_acreedores: TopRow[];
  calculado_at: string;
  permisos: { ver_efectivo: boolean };
};

const BUCKET_LABEL: Record<keyof AgingMap, string> = {
  corriente: 'Corriente (no vencidas)',
  '1_30': '1-30 días',
  '31_60': '31-60 días',
  '61_90': '61-90 días',
  mas_90: 'Más de 90 días',
};

const BUCKET_COLOR: Record<keyof AgingMap, string> = {
  corriente: 'text-success',
  '1_30': 'text-ink',
  '31_60': 'text-warning',
  '61_90': 'text-warning',
  mas_90: 'text-danger',
};

export function SaldosConsolidadosPage() {
  const [fechaCorte, setFechaCorte] = useState(() => new Date().toISOString().slice(0, 10));
  const [moneda, setMoneda] = useState('ARS');
  const [incluirEfectivo, setIncluirEfectivo] = useState(true);
  const [drillAuxiliarId, setDrillAuxiliarId] = useState<number | null>(null);
  const qc = useQueryClient();

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    p.set('fecha_corte', fechaCorte);
    p.set('moneda_codigo', moneda);
    p.set('incluir_efectivo', incluirEfectivo ? '1' : '0');
    return p.toString();
  }, [fechaCorte, moneda, incluirEfectivo]);

  const { data, isLoading, isFetching, refetch } = useApi<ReporteData>(
    ['saldos-consolidados', qs],
    `/api/erp/reportes/saldos-consolidados?${qs}`,
    { staleTime: 5 * 60 * 1000 },
  );

  const verEfectivo = data?.permisos.ver_efectivo ?? false;

  const handleRefresh = async () => {
    // Bypass del cache del backend Y de React Query.
    await api.get(`/api/erp/reportes/saldos-consolidados?${qs}&nocache=1`);
    qc.invalidateQueries({ queryKey: ['saldos-consolidados'] });
    refetch();
  };

  const descargar = async (formato: 'xlsx' | 'pdf') => {
    const url = `/api/erp/reportes/saldos-consolidados/export/${formato}?${qs}`;
    const token = auth.getToken();
    const resp = await fetch(url, { headers: { Authorization: `Bearer ${token}` } });
    if (!resp.ok) {
      alert(`No se pudo descargar (${resp.status})`);
      return;
    }
    const blob = await resp.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `saldos_consolidados_${data?.fecha_corte ?? fechaCorte}.${formato}`;
    document.body.appendChild(a);
    a.click();
    a.remove();
  };

  return (
    <div className="p-3 space-y-3">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-[16px] font-semibold text-navy-800 flex items-center gap-2">
            <Activity className="w-5 h-5" />
            Saldos consolidados
          </h1>
          <div className="text-[11px] text-ink-muted">
            Deudores por ventas + Deuda con proveedores. Al corte de {data?.fecha_corte ?? '—'}.
          </div>
        </div>
        <div className="flex gap-1.5">
          <Button variant="secondary" size="sm" onClick={() => descargar('xlsx')} disabled={!data}>
            <FileSpreadsheet className="w-3 h-3" /> Excel
          </Button>
          <Button variant="secondary" size="sm" onClick={() => descargar('pdf')} disabled={!data}>
            <FileText className="w-3 h-3" /> PDF
          </Button>
          <Button variant="secondary" size="sm" onClick={handleRefresh} disabled={isFetching}>
            {isFetching ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />}
            Actualizar
          </Button>
        </div>
      </div>

      {/* Filtros */}
      <Card>
        <CardBody className="flex flex-wrap items-end gap-3 text-[12px]">
          <div>
            <label className="block text-[11px] text-ink-muted mb-0.5">Fecha de corte</label>
            <div className="relative">
              <Calendar className="absolute left-2 top-1/2 -translate-y-1/2 w-3 h-3 text-ink-muted pointer-events-none" />
              <input
                type="date" value={fechaCorte}
                onChange={(e) => setFechaCorte(e.target.value)}
                className="pl-6 pr-2 py-1 border border-azure-soft rounded text-[12px] focus:outline-none focus:border-azure" />
            </div>
          </div>
          <div>
            <label className="block text-[11px] text-ink-muted mb-0.5">Moneda</label>
            <select value={moneda} onChange={(e) => setMoneda(e.target.value)}
              className="px-2 py-1 border border-azure-soft rounded text-[12px] focus:outline-none focus:border-azure">
              <option value="ARS">ARS</option>
              <option value="USD">USD</option>
            </select>
          </div>
          {verEfectivo && (
            <label className="inline-flex items-center gap-1.5 text-[12px] cursor-pointer pb-1">
              <input type="checkbox" checked={incluirEfectivo}
                onChange={(e) => setIncluirEfectivo(e.target.checked)} />
              Incluir operaciones EFECTIVO
            </label>
          )}
          {data?.calculado_at && (
            <div className="ml-auto text-[10.5px] text-ink-muted">
              Calculado: {new Date(data.calculado_at).toLocaleString('es-AR')} (cache 5 min)
            </div>
          )}
        </CardBody>
      </Card>

      {isLoading && (
        <div className="flex items-center gap-2 text-ink-muted text-[12px] py-8 justify-center">
          <Loader2 className="w-4 h-4 animate-spin" /> Calculando saldos…
        </div>
      )}

      {data && (
        <>
          {/* Widgets principales */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <WidgetCard
              titulo="Deudores por ventas"
              icon={<TrendingUp className="w-4 h-4 text-success" />}
              widget={data.widgets.deudores_ventas}
              moneda={moneda}
              verEfectivo={verEfectivo}
              color="text-success"
            />
            <WidgetCard
              titulo="Deuda con proveedores"
              icon={<TrendingDown className="w-4 h-4 text-danger" />}
              widget={data.widgets.deuda_compras}
              moneda={moneda}
              verEfectivo={verEfectivo}
              color="text-danger"
            />
            <Card>
              <CardBody className="text-center space-y-1">
                <div className="text-[11px] text-ink-muted uppercase">Posición neta</div>
                <div className={`text-[22px] font-bold tabular ${
                  data.widgets.posicion_neta >= 0 ? 'text-success' : 'text-danger'
                }`}>
                  {data.widgets.posicion_neta >= 0 ? '+' : ''}{moneda} ${fmtMoney(data.widgets.posicion_neta)}
                </div>
                <div className="text-[11px] text-ink-muted">
                  {data.widgets.posicion_neta >= 0 ? 'A favor (cobramos más de lo que debemos)' : 'En contra (debemos más de lo que cobramos)'}
                </div>
              </CardBody>
            </Card>
          </div>

          {/* Aging x 2 */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <AgingCard titulo="Aging deudores (ventas)" data={data.aging_deudores} verEfectivo={verEfectivo} moneda={moneda} />
            <AgingCard titulo="Aging acreedores (compras)" data={data.aging_acreedores} verEfectivo={verEfectivo} moneda={moneda} />
          </div>

          {/* Top 10 */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <TopCard titulo="Top deudores" rows={data.top_deudores} verEfectivo={verEfectivo} moneda={moneda} onClick={setDrillAuxiliarId} />
            <TopCard titulo="Top acreedores" rows={data.top_acreedores} verEfectivo={verEfectivo} moneda={moneda} onClick={setDrillAuxiliarId} />
          </div>
        </>
      )}

      {drillAuxiliarId && (
        <DrillDownModal
          auxiliarId={drillAuxiliarId}
          qs={qs}
          verEfectivo={verEfectivo}
          moneda={moneda}
          onClose={() => setDrillAuxiliarId(null)}
        />
      )}
    </div>
  );
}

// ----------------------------------------------------------------------------

function WidgetCard({ titulo, icon, widget, moneda, verEfectivo, color }: {
  titulo: string;
  icon: React.ReactNode;
  widget: Widget;
  moneda: string;
  verEfectivo: boolean;
  color: string;
}) {
  return (
    <Card>
      <CardBody className="space-y-1.5">
        <div className="flex items-center gap-1 text-[11px] text-ink-muted uppercase">
          {icon} {titulo}
        </div>
        <div className={`text-[22px] font-bold tabular ${color}`}>
          {moneda} ${fmtMoney(widget.total)}
        </div>
        <div className="text-[11px] text-ink-muted">
          {widget.cantidad_operaciones} operación{widget.cantidad_operaciones === 1 ? '' : 'es'} con saldo abierto
        </div>
        {verEfectivo && (widget.efectivo ?? 0) > 0 && (
          <div className="text-[11.5px] text-warning pt-1 border-t border-line mt-1">
            De los cuales, en efectivo:
            <span className="font-semibold tabular ml-1">${fmtMoney(widget.efectivo ?? 0)}</span>
            <span className="text-ink-muted ml-1">({widget.pct_efectivo ?? 0}%)</span>
          </div>
        )}
      </CardBody>
    </Card>
  );
}

function AgingCard({ titulo, data, verEfectivo, moneda }: {
  titulo: string;
  data: AgingMap;
  verEfectivo: boolean;
  moneda: string;
}) {
  const total = Object.values(data).reduce((s, b) => s + b.total, 0);
  return (
    <Card>
      <CardHeader title={<div className="text-[13px] font-semibold">{titulo}</div>} />
      <CardBody>
        <table className="w-full text-[11.5px]">
          <thead>
            <tr className="text-ink-muted border-b border-line">
              <th className="text-left py-1">Bucket</th>
              <th className="text-right py-1">Total</th>
              {verEfectivo && <th className="text-right py-1">Efectivo</th>}
              <th className="text-right py-1">%</th>
              <th className="text-right py-1">Qty</th>
            </tr>
          </thead>
          <tbody>
            {(Object.entries(data) as [keyof AgingMap, Bucket][]).map(([k, b]) => (
              <tr key={k} className="border-b border-line/60">
                <td className={`py-1 ${BUCKET_COLOR[k]}`}>{BUCKET_LABEL[k]}</td>
                <td className="text-right tabular">{moneda} ${fmtMoney(b.total)}</td>
                {verEfectivo && <td className="text-right tabular text-warning">${fmtMoney(b.efectivo ?? 0)}</td>}
                <td className="text-right text-ink-muted">{b.pct}%</td>
                <td className="text-right text-ink-muted">{b.cantidad}</td>
              </tr>
            ))}
            <tr className="font-semibold bg-azure-soft/20">
              <td className="py-1">TOTAL</td>
              <td className="text-right tabular">{moneda} ${fmtMoney(total)}</td>
              {verEfectivo && <td></td>}
              <td className="text-right">100%</td>
              <td></td>
            </tr>
          </tbody>
        </table>
      </CardBody>
    </Card>
  );
}

function TopCard({ titulo, rows, verEfectivo, moneda, onClick }: {
  titulo: string;
  rows: TopRow[];
  verEfectivo: boolean;
  moneda: string;
  onClick: (id: number) => void;
}) {
  return (
    <Card>
      <CardHeader title={<div className="text-[13px] font-semibold">{titulo}</div>} />
      <CardBody>
        {rows.length === 0 ? (
          <div className="text-[11.5px] text-ink-muted italic">Sin saldos abiertos.</div>
        ) : (
          <table className="w-full text-[11.5px]">
            <thead>
              <tr className="text-ink-muted border-b border-line">
                <th className="text-left py-1">Auxiliar</th>
                <th className="text-right py-1">Saldo total</th>
                {verEfectivo && <th className="text-right py-1">Efectivo</th>}
                <th className="text-right py-1">Vencido</th>
                <th className="text-right py-1">Ops</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.auxiliar_id}
                    className="border-b border-line/60 hover:bg-azure-soft/20 cursor-pointer"
                    onClick={() => onClick(r.auxiliar_id)}>
                  <td className="py-1">
                    <div className="font-medium">{r.nombre}</div>
                    {r.cuit && <div className="text-[10.5px] text-ink-muted font-mono">{r.cuit}</div>}
                  </td>
                  <td className="text-right tabular font-semibold">{moneda} ${fmtMoney(r.saldo_total)}</td>
                  {verEfectivo && <td className="text-right tabular text-warning">${fmtMoney(r.saldo_efectivo ?? 0)}</td>}
                  <td className={`text-right tabular ${r.saldo_vencido > 0 ? 'text-danger' : 'text-ink-muted'}`}>${fmtMoney(r.saldo_vencido)}</td>
                  <td className="text-right text-ink-muted">{r.cantidad}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </CardBody>
    </Card>
  );
}

// ----------------------------------------------------------------------------

type DrillData = {
  auxiliar: { id: number; codigo: string; nombre: string; cuit: string | null; tipo: string } | null;
  es_cliente: boolean;
  fecha_corte: string;
  moneda: string;
  operaciones: Array<{
    id: number;
    fecha_emision: string;
    fecha_vencimiento: string | null;
    imp_total: string;
    saldo: string;
    categoria: 'FACTURA' | 'EFECTIVO';
    estado: string;
    origen?: string;
    tipo_comprobante: string;
    letra: string | null;
    pv_numero?: number;
    punto_venta?: number;
    numero: number;
    dias_vencido: number;
  }>;
  totales: { total: number; efectivo: number; vencido: number };
  permisos?: { ver_efectivo: boolean };
};

function DrillDownModal({ auxiliarId, qs, verEfectivo, moneda, onClose }: {
  auxiliarId: number;
  qs: string;
  verEfectivo: boolean;
  moneda: string;
  onClose: () => void;
}) {
  const { data, isLoading } = useQuery<{ data: DrillData }>({
    queryKey: ['saldos-cons-aux', auxiliarId, qs],
    queryFn: () => api.get(`/api/erp/reportes/saldos-consolidados/auxiliar/${auxiliarId}?${qs}`),
    staleTime: 5 * 60 * 1000,
  });
  const d = data?.data;

  return (
    <Modal open onClose={onClose}
      title={d?.auxiliar
        ? `${d.es_cliente ? 'Deudor' : 'Acreedor'}: ${d.auxiliar.nombre}`
        : 'Detalle de auxiliar'}
      size="lg">
      {isLoading || !d ? (
        <div className="flex items-center gap-2 text-ink-muted text-[12px] py-6">
          <Loader2 className="w-4 h-4 animate-spin" /> Cargando…
        </div>
      ) : !d.auxiliar ? (
        <div className="text-[12px] text-danger">Auxiliar no encontrado.</div>
      ) : (
        <div className="space-y-3 text-[12px]">
          <div className="flex items-center justify-between text-[11.5px] bg-azure-soft/20 rounded p-2">
            <div>
              <span className="text-ink-muted">CUIT:</span> <span className="font-mono">{d.auxiliar.cuit ?? '—'}</span>
              <span className="ml-3 text-ink-muted">Tipo:</span> <span>{d.auxiliar.tipo}</span>
              <span className="ml-3 text-ink-muted">Al corte de:</span> <span>{d.fecha_corte}</span>
            </div>
            <div>
              <span className="text-ink-muted">Saldo total:</span>
              <span className="font-bold tabular ml-1">{moneda} ${fmtMoney(d.totales.total)}</span>
              {verEfectivo && d.totales.efectivo > 0 && (
                <span className="text-warning ml-3">de ello efectivo: ${fmtMoney(d.totales.efectivo)}</span>
              )}
              {d.totales.vencido > 0 && (
                <span className="text-danger ml-3">vencido: ${fmtMoney(d.totales.vencido)}</span>
              )}
            </div>
          </div>

          {d.operaciones.length === 0 ? (
            <div className="text-[12px] text-ink-muted italic py-4 text-center">
              Sin operaciones pendientes.
            </div>
          ) : (
            <div className="max-h-[60vh] overflow-auto border border-line rounded">
              <table className="w-full text-[11.5px]">
                <thead className="bg-surface-row sticky top-0">
                  <tr className="text-ink-muted">
                    <th className="text-left p-1">Fecha</th>
                    <th className="text-left p-1">Comprobante</th>
                    <th className="text-right p-1">Total</th>
                    <th className="text-right p-1">Saldo</th>
                    <th className="text-left p-1">Vencimiento</th>
                    <th className="text-left p-1">Estado</th>
                    <th className="text-left p-1">Cat.</th>
                  </tr>
                </thead>
                <tbody>
                  {d.operaciones.map((op) => {
                    const pv = op.pv_numero ?? op.punto_venta ?? 0;
                    const nro = `${op.tipo_comprobante.slice(0, 3).toUpperCase()}-${op.letra ?? ''} ${String(pv).padStart(4, '0')}-${String(op.numero).padStart(8, '0')}`;
                    return (
                      <tr key={op.id} className="border-t border-line/60">
                        <td className="p-1">{op.fecha_emision}</td>
                        <td className="p-1 font-mono text-[11px]">{nro}</td>
                        <td className="p-1 text-right tabular">${fmtMoney(Number(op.imp_total))}</td>
                        <td className="p-1 text-right tabular font-semibold">${fmtMoney(Number(op.saldo))}</td>
                        <td className="p-1">
                          {op.fecha_vencimiento ?? '—'}
                          {op.dias_vencido > 0 && (
                            <span className="ml-1 text-danger text-[10.5px]">({op.dias_vencido}d)</span>
                          )}
                        </td>
                        <td className="p-1"><Badge variant="default">{op.estado}</Badge></td>
                        <td className="p-1">
                          {op.categoria === 'EFECTIVO'
                            ? <Badge variant="warning">EFECTIVO</Badge>
                            : <span className="text-ink-muted text-[10.5px]">—</span>}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          <div className="flex justify-end pt-2 border-t border-line">
            <Button variant="secondary" size="sm" onClick={onClose}>
              <X className="w-3 h-3" /> Cerrar
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
