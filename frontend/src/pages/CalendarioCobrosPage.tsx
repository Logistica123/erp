import { useMemo, useState } from 'react';
import { CalendarDays, ChevronLeft, ChevronRight, Users, AlertTriangle, Save } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { fmtMoney } from '@/components/ui/DataTable';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Item = {
  tipo: 'FACTURA' | 'CHEQUE'; id: number; referencia: string; cliente: string | null;
  auxiliar_id: number | null; fecha_origen: string | null; plazo_dias: number | null;
  fecha: string | null; fecha_bucket?: string; importe: number; vencido?: boolean; estado_cheque?: string;
};
type Dia = { fecha: string; total: number; facturas: number; cheques: number; items: number };
type Calendario = {
  desde: string; hasta: string; items: Item[]; por_dia: Dia[]; sin_plazo: Item[];
  totales: { total: number; facturas: number; cheques: number; vencido: number; sin_plazo: number };
};
type Plazo = { auxiliar_id: number; codigo: string; nombre: string; cuit: string | null; plazo_cobro_dias: number | null };

const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

export function CalendarioCobrosPage() {
  const [tab, setTab] = useState<'calendario' | 'plazos'>('calendario');
  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={<div className="flex items-center gap-2"><CalendarDays className="w-4 h-4 text-azure" /> Calendario de cobros proyectados</div>} />
        <CardBody className="p-4">
          <div className="flex gap-2 border-b border-line mb-3">
            <Button size="sm" variant="ghost"
              className={tab === 'calendario' ? 'border-b-2 border-azure rounded-none text-azure' : 'border-b-2 border-transparent rounded-none'}
              onClick={() => setTab('calendario')}><CalendarDays className="w-3 h-3" /> Calendario</Button>
            <Button size="sm" variant="ghost"
              className={tab === 'plazos' ? 'border-b-2 border-azure rounded-none text-azure' : 'border-b-2 border-transparent rounded-none'}
              onClick={() => setTab('plazos')}><Users className="w-3 h-3" /> Plazos por cliente</Button>
          </div>
          {tab === 'calendario' ? <CalendarioTab /> : <PlazosTab />}
        </CardBody>
      </Card>
    </div>
  );
}

function CalendarioTab() {
  const hoy = new Date();
  const [anio, setAnio] = useState(hoy.getFullYear());
  const [mes, setMes] = useState(hoy.getMonth()); // 0-11
  const [diaSel, setDiaSel] = useState<string | null>(null);

  const desde = `${anio}-${String(mes + 1).padStart(2, '0')}-01`;
  const ultimoDia = new Date(anio, mes + 1, 0).getDate();
  const hasta = `${anio}-${String(mes + 1).padStart(2, '0')}-${String(ultimoDia).padStart(2, '0')}`;

  const { data, isLoading, error } = useApi<Calendario>(
    ['calendario-cobros', desde],
    `/api/erp/tesoreria/calendario-cobros?desde=${desde}&hasta=${hasta}`
  );

  const porDia = useMemo(() => {
    const m = new Map<string, Dia>();
    (data?.por_dia ?? []).forEach((d) => m.set(d.fecha, d));
    return m;
  }, [data]);

  const celdas = useMemo(() => {
    // Lunes = 0.
    const primero = new Date(anio, mes, 1);
    const offset = (primero.getDay() + 6) % 7;
    const cells: (string | null)[] = Array(offset).fill(null);
    for (let d = 1; d <= ultimoDia; d++) {
      cells.push(`${anio}-${String(mes + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`);
    }
    return cells;
  }, [anio, mes, ultimoDia]);

  const mover = (delta: number) => {
    const m = mes + delta;
    setMes(((m % 12) + 12) % 12);
    setAnio(anio + Math.floor(m / 12));
    setDiaSel(null);
  };

  const itemsDelDia = (data?.items ?? []).filter((i) => i.fecha_bucket === diaSel);
  const hoyStr = new Date().toISOString().slice(0, 10);

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between flex-wrap gap-2">
        <div className="flex items-center gap-2">
          <Button size="sm" variant="outline" onClick={() => mover(-1)}><ChevronLeft className="w-3.5 h-3.5" /></Button>
          <span className="font-semibold w-44 text-center">{MESES[mes]} {anio}</span>
          <Button size="sm" variant="outline" onClick={() => mover(1)}><ChevronRight className="w-3.5 h-3.5" /></Button>
        </div>
        {data && (
          <div className="flex gap-3 text-[12px]">
            <span>Mes: <b>{fmtMoney(data.totales.total)}</b></span>
            <span className="text-sky-600">Facturas: {fmtMoney(data.totales.facturas)}</span>
            <span className="text-emerald-600">Cheques: {fmtMoney(data.totales.cheques)}</span>
            {data.totales.vencido > 0 && <span className="text-red-600">Atrasado (cae hoy): {fmtMoney(data.totales.vencido)}</span>}
          </div>
        )}
      </div>

      {data && data.sin_plazo.length > 0 && (
        <div className="flex items-center gap-2 text-[12.5px] text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/10 rounded px-3 py-1.5">
          <AlertTriangle className="w-3.5 h-3.5 shrink-0" />
          {data.sin_plazo.length} factura(s) por {fmtMoney(data.totales.sin_plazo)} de clientes SIN plazo cargado —
          completalo en la pestaña "Plazos por cliente" para proyectarlas.
        </div>
      )}

      {isLoading && <p className="text-sm text-slate-500">Cargando…</p>}
      {error && <p className="text-sm text-red-600">{errorMessage(error)}</p>}

      <div className="grid grid-cols-7 gap-1 text-[11px]">
        {['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map((d) => (
          <div key={d} className="text-center uppercase text-slate-400 py-1">{d}</div>
        ))}
        {celdas.map((fecha, i) => {
          if (!fecha) return <div key={`x${i}`} />;
          const d = porDia.get(fecha);
          const esHoy = fecha === hoyStr;
          return (
            <button key={fecha} onClick={() => setDiaSel(diaSel === fecha ? null : fecha)}
              className={`min-h-[72px] rounded border p-1.5 text-left align-top transition
                ${diaSel === fecha ? 'border-azure ring-1 ring-azure/40' : 'border-slate-200 dark:border-slate-800'}
                ${esHoy ? 'bg-sky-50/70 dark:bg-sky-900/20' : d ? 'bg-emerald-50/50 dark:bg-emerald-900/10' : 'bg-white dark:bg-slate-950'}`}>
              <div className={`text-[11px] ${esHoy ? 'font-bold text-sky-700' : 'text-slate-400'}`}>{Number(fecha.slice(8))}</div>
              {d && (
                <div className="mt-1 space-y-0.5">
                  <div className="font-semibold text-[11.5px]">{fmtMoney(d.total)}</div>
                  <div className="text-[10px] text-slate-500">{d.items} ítem(s)</div>
                </div>
              )}
            </button>
          );
        })}
      </div>

      {diaSel && (
        <Card>
          <CardHeader title={`Detalle del ${diaSel.split('-').reverse().join('/')}`} />
          <CardBody className="p-0 overflow-x-auto">
            <table className="text-[12px] min-w-full">
              <thead><tr className="text-left text-[11px] uppercase text-slate-500">
                <th className="px-3 py-1.5">Tipo</th><th className="px-3 py-1.5">Referencia</th>
                <th className="px-3 py-1.5">Cliente</th><th className="px-3 py-1.5">Origen</th>
                <th className="px-3 py-1.5 text-right">Importe</th><th className="px-3 py-1.5"></th>
              </tr></thead>
              <tbody>
                {itemsDelDia.map((it) => (
                  <tr key={`${it.tipo}${it.id}`} className="border-t border-slate-100 dark:border-slate-800">
                    <td className="px-3 py-1"><Badge variant={it.tipo === 'CHEQUE' ? 'success' : 'info'}>{it.tipo}</Badge></td>
                    <td className="px-3 py-1">{it.referencia}</td>
                    <td className="px-3 py-1">{it.cliente ?? '—'}</td>
                    <td className="px-3 py-1 text-slate-500">
                      {it.tipo === 'FACTURA'
                        ? `fact. ${it.fecha_origen} + ${it.plazo_dias}d`
                        : `vto recibo ${it.fecha_origen}${it.estado_cheque === 'DEPOSITADO' ? ' (depositado)' : ''}`}
                    </td>
                    <td className="px-3 py-1 text-right font-medium">{fmtMoney(it.importe)}</td>
                    <td className="px-3 py-1">{it.vencido && <Badge variant="danger">atrasado</Badge>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardBody>
        </Card>
      )}
    </div>
  );
}

function PlazosTab() {
  const toast = useToast();
  const invalidate = useInvalidate(['plazos-cobro'], ['calendario-cobros']);
  const { data, isLoading } = useApi<Plazo[]>(['plazos-cobro'], '/api/erp/tesoreria/plazos-cobro');
  const [ediciones, setEdiciones] = useState<Record<number, string>>({});
  const [q, setQ] = useState('');

  const guardar = useApiMutation<void, { auxiliarId: number; dias: number | null }>(
    ({ auxiliarId, dias }) => api.put(`/api/erp/tesoreria/plazos-cobro/${auxiliarId}`, { dias }),
    {
      onSuccess: () => { invalidate(); toast.success('Plazo guardado.'); },
      onError: (e) => toast.error(errorMessage(e)),
    }
  );

  const lista = (data ?? []).filter((p) =>
    !q || p.nombre.toLowerCase().includes(q.toLowerCase()) || (p.cuit ?? '').includes(q));

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-2 flex-wrap">
        <input className="w-64 text-sm px-2 py-1.5 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900"
          placeholder="Buscar cliente / CUIT…" value={q} onChange={(e) => setQ(e.target.value)} />
        <p className="text-[11.5px] text-slate-500">
          Días estimados entre la <b>fecha de factura</b> y el cobro real. Vacío = el cliente no se proyecta (queda en la alerta "sin plazo").
        </p>
      </div>
      {isLoading && <p className="text-sm text-slate-500">Cargando…</p>}
      <table className="text-[12.5px] min-w-full">
        <thead><tr className="text-left text-[11px] uppercase text-slate-500">
          <th className="px-3 py-1.5">Código</th><th className="px-3 py-1.5">Cliente</th>
          <th className="px-3 py-1.5">CUIT</th><th className="px-3 py-1.5 text-right">Plazo (días)</th><th className="px-3 py-1.5"></th>
        </tr></thead>
        <tbody>
          {lista.map((p) => {
            const editado = ediciones[p.auxiliar_id];
            const valor = editado !== undefined ? editado : (p.plazo_cobro_dias !== null ? String(p.plazo_cobro_dias) : '');
            const dirty = editado !== undefined && editado !== (p.plazo_cobro_dias !== null ? String(p.plazo_cobro_dias) : '');
            return (
              <tr key={p.auxiliar_id} className="border-t border-slate-100 dark:border-slate-800">
                <td className="px-3 py-1 font-mono text-[11px]">{p.codigo}</td>
                <td className="px-3 py-1">{p.nombre}</td>
                <td className="px-3 py-1 text-slate-500">{p.cuit ?? '—'}</td>
                <td className="px-3 py-1 text-right">
                  <input type="text" inputMode="numeric"
                    className="w-20 text-right text-[12.5px] px-1.5 py-0.5 rounded border bg-amber-50/60 dark:bg-amber-900/10 border-transparent focus:border-blue-400 focus:bg-white dark:focus:bg-slate-900"
                    value={valor} placeholder="—"
                    onChange={(e) => setEdiciones((prev) => ({ ...prev, [p.auxiliar_id]: e.target.value.replace(/[^0-9]/g, '') }))} />
                </td>
                <td className="px-3 py-1">
                  {dirty && (
                    <Button size="sm" disabled={guardar.isPending}
                      onClick={() => guardar.mutate({ auxiliarId: p.auxiliar_id, dias: valor === '' ? null : Number(valor) })}>
                      <Save className="w-3 h-3" /> Guardar
                    </Button>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
