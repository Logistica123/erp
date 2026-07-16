import { useEffect, useMemo, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { Save, RefreshCw, ArrowLeft, Lock } from 'lucide-react';
import { Card, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { fmtMoney } from '@/components/ui/DataTable';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type ConceptoCol = { codigo: string; nombre: string; signo: 'HABER' | 'DESCUENTO'; por_cantidad: boolean };
type Valor = { cantidad?: number | null; importe?: number | null; importe_calculado?: number };
type Fila = {
  empleado_id: number; legajo: string; nombre: string; regimen: string;
  basico_vigente: number | null;
  dias_trabajados: number; dias_override: number | null;
  valores: Record<string, Valor>;
  prestamo_cuota: number;
  neto: number; formal: number; efectivo: number; mt: number;
  reparto: { porc_formal: number; porc_efectivo: number; porc_mt: number; override: boolean };
  reparto_importes: { formal: number | null; mt: number | null };
  efectivo_redondeado: number; diferencia_redondeo: number;
};
type Grilla = {
  liquidacion: { id: number; periodo: string; tipo: string; estado: string; editable: boolean };
  conceptos: ConceptoCol[];
  filas: Fila[];
};

/** Edición local por empleado: solo lo tocado viaja en el PUT. */
type Edicion = {
  dias_trabajados?: number | null;
  reparto_importes?: { formal: number | null; mt: number | null };
  valores?: Record<string, { cantidad?: number | null; importe?: number | null }>;
};

const num = (v: string): number | null => {
  if (v.trim() === '') return null;
  const n = Number(v.replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};

export function GrillaLiquidacionPage() {
  const { id } = useParams();
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-grilla'], ['sueldos-liquidaciones']);
  const { data, isLoading, error, refetch } = useApi<Grilla>(
    ['sueldos-grilla', id],
    `/api/erp/sueldos/liquidaciones/${id}/grilla`
  );
  const [ediciones, setEdiciones] = useState<Record<number, Edicion>>({});

  useEffect(() => { setEdiciones({}); }, [data?.liquidacion?.estado]);

  const editar = (empId: number, patch: (e: Edicion) => Edicion) =>
    setEdiciones((prev) => ({ ...prev, [empId]: patch(prev[empId] ?? {}) }));

  const guardar = useApiMutation<Grilla, void>(
    () => {
      const filas = Object.entries(ediciones).map(([empId, e]) => ({
        empleado_id: Number(empId),
        ...(e.dias_trabajados !== undefined ? { dias_trabajados: e.dias_trabajados } : {}),
        ...(e.reparto_importes ? { reparto_importes: e.reparto_importes } : {}),
        ...(e.valores ? { valores: e.valores } : {}),
      }));
      return api.put(`/api/erp/sueldos/liquidaciones/${id}/grilla`, { filas });
    },
    {
      onSuccess: () => {
        setEdiciones({});
        invalidate();
        toast.success('Grilla guardada y liquidación recalculada.');
      },
      onError: (e) => toast.error(errorMessage(e)),
    }
  );

  const dirty = Object.keys(ediciones).length > 0;
  const g = data;
  const totales = useMemo(() => {
    if (!g) return null;
    const t = { neto: 0, formal: 0, efectivo: 0, mt: 0, redondeado: 0, prestamos: 0 };
    for (const f of g.filas) {
      t.neto += f.neto; t.formal += f.formal; t.efectivo += f.efectivo; t.mt += f.mt;
      t.redondeado += f.efectivo_redondeado; t.prestamos += f.prestamo_cuota;
    }
    return t;
  }, [g]);

  if (isLoading) return <div className="p-6 text-sm text-slate-500">Cargando grilla…</div>;
  if (error || !g) return <div className="p-6 text-sm text-red-600">{errorMessage(error)}</div>;

  const editable = g.liquidacion.editable;
  const haberes = g.conceptos.filter((c) => c.signo === 'HABER');
  const descuentos = g.conceptos.filter((c) => c.signo === 'DESCUENTO');

  const celdaValor = (f: Fila, c: ConceptoCol) => {
    const ed = ediciones[f.empleado_id]?.valores?.[c.codigo];
    const base = f.valores[c.codigo];
    const campo = c.por_cantidad ? 'cantidad' : 'importe';
    const actual = ed !== undefined
      ? (ed[campo] ?? '')
      : (base?.[campo] ?? '');
    return (
      <td key={c.codigo} className="px-1 py-0.5 border-b border-slate-100 dark:border-slate-800">
        <input
          type="text" inputMode="decimal"
          disabled={!editable}
          className="w-20 text-right text-[12px] px-1 py-0.5 rounded border border-transparent bg-amber-50/60 dark:bg-amber-900/10 focus:border-blue-400 focus:bg-white dark:focus:bg-slate-900 disabled:bg-transparent"
          value={actual === null ? '' : String(actual)}
          title={c.nombre + (c.por_cantidad ? ' (cantidad)' : ' ($)') + (base?.importe_calculado !== undefined ? ` — calculado: ${fmtMoney(base.importe_calculado)}` : '')}
          onChange={(ev) => editar(f.empleado_id, (e) => ({
            ...e,
            valores: { ...(e.valores ?? {}), [c.codigo]: { [campo]: num(ev.target.value) } },
          }))}
        />
      </td>
    );
  };

  return (
    <div className="p-4 space-y-3">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-3">
          <Link to="/erp/sueldos/liquidaciones"><Button size="sm" variant="outline"><ArrowLeft className="w-3.5 h-3.5" /> Liquidaciones</Button></Link>
          <h1 className="text-lg font-semibold">Grilla {g.liquidacion.periodo} ({g.liquidacion.tipo})</h1>
          <Badge variant={editable ? 'info' : 'default'}>{g.liquidacion.estado}</Badge>
          {!editable && <span className="text-xs text-slate-500 flex items-center gap-1"><Lock className="w-3 h-3" /> cerrada — solo lectura</span>}
        </div>
        <div className="flex items-center gap-2">
          <Button size="sm" variant="outline" onClick={() => { setEdiciones({}); refetch(); }}>
            <RefreshCw className="w-3.5 h-3.5" /> Recargar
          </Button>
          <Button size="sm" disabled={!dirty || !editable || guardar.isPending} onClick={() => guardar.mutate()}>
            <Save className="w-3.5 h-3.5" /> {guardar.isPending ? 'Guardando…' : `Guardar y recalcular${dirty ? ` (${Object.keys(ediciones).length})` : ''}`}
          </Button>
        </div>
      </div>

      <Card>
        <CardBody className="p-0 overflow-x-auto">
          <table className="text-[12px] whitespace-nowrap min-w-full">
            <thead className="sticky top-0 bg-slate-50 dark:bg-slate-900 z-10">
              <tr className="text-[10.5px] uppercase text-slate-500">
                <th colSpan={4} className="px-2 py-1 text-left border-b">Datos</th>
                <th colSpan={haberes.length + 1} className="px-2 py-1 text-center border-b bg-emerald-50/50 dark:bg-emerald-900/10">Haberes</th>
                <th colSpan={descuentos.length + 1} className="px-2 py-1 text-center border-b bg-rose-50/50 dark:bg-rose-900/10">Descuentos</th>
                <th colSpan={1} className="px-2 py-1 text-center border-b bg-sky-50/60 dark:bg-sky-900/10">Liquidación</th>
                <th colSpan={4} className="px-2 py-1 text-center border-b">Forma de pago</th>
              </tr>
              <tr className="text-left text-[11px]">
                <th className="px-2 py-1 border-b sticky left-0 bg-slate-50 dark:bg-slate-900">Legajo</th>
                <th className="px-2 py-1 border-b">Empleado</th>
                <th className="px-2 py-1 border-b text-right">Básico</th>
                <th className="px-2 py-1 border-b text-right" title="Días trabajados (editable, base 30)">Días</th>
                {haberes.map((c) => <th key={c.codigo} className="px-1 py-1 border-b text-right" title={c.nombre}>{c.codigo}{c.por_cantidad ? ' (hs)' : ''}</th>)}
                <th className="px-2 py-1 border-b text-right font-semibold">Tot.Hab.</th>
                {descuentos.map((c) => <th key={c.codigo} className="px-1 py-1 border-b text-right" title={c.nombre}>{c.codigo}{c.por_cantidad ? ' (hs/d)' : ''}</th>)}
                <th className="px-2 py-1 border-b text-right" title="Cuota de préstamos (automática)">Préstamo</th>
                <th className="px-2 py-1 border-b text-right font-semibold bg-sky-50/60 dark:bg-sky-900/10">NETO</th>
                <th className="px-2 py-1 border-b text-right" title="Importe FORMAL exacto por empleado (vacío = default % del maestro)">Formal ($)</th>
                <th className="px-2 py-1 border-b text-right" title="Importe MT exacto por empleado (vacío = default % del maestro)">MT ($)</th>
                <th className="px-2 py-1 border-b text-right" title="Residual automático: neto − formal − mt (no editable)">Efectivo</th>
                <th className="px-2 py-1 border-b text-right" title="Efectivo redondeado a 500 (+ diferencia)">Efvo. red.</th>
              </tr>
            </thead>
            <tbody>
              {g.filas.map((f) => {
                const ed = ediciones[f.empleado_id];
                const dias = ed?.dias_trabajados !== undefined ? ed.dias_trabajados : f.dias_trabajados;
                const totHab = (f.basico_vigente ?? 0) * (Number(dias ?? 30) / 30);
                return (
                  <tr key={f.empleado_id} className="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                    <td className="px-2 py-0.5 border-b border-slate-100 dark:border-slate-800 sticky left-0 bg-white dark:bg-slate-950 font-mono text-[11px]">{f.legajo}</td>
                    <td className="px-2 py-0.5 border-b border-slate-100 dark:border-slate-800 max-w-[170px] truncate" title={`${f.nombre} · ${f.regimen}${f.basico_vigente === null ? ' · SIN BÁSICO' : ''}`}>
                      {f.nombre} {f.basico_vigente === null && <Badge variant="warning">sin básico</Badge>}
                    </td>
                    <td className="px-2 py-0.5 border-b border-slate-100 dark:border-slate-800 text-right text-slate-500">{f.basico_vigente !== null ? fmtMoney(f.basico_vigente) : '—'}</td>
                    <td className="px-1 py-0.5 border-b border-slate-100 dark:border-slate-800">
                      <input
                        type="text" inputMode="numeric" disabled={!editable}
                        className="w-11 text-right text-[12px] px-1 py-0.5 rounded border border-transparent bg-amber-50/60 dark:bg-amber-900/10 focus:border-blue-400 focus:bg-white dark:focus:bg-slate-900 disabled:bg-transparent"
                        value={dias ?? ''}
                        onChange={(ev) => {
                          const v = ev.target.value.trim();
                          editar(f.empleado_id, (e) => ({ ...e, dias_trabajados: v === '' ? null : Math.max(0, Math.min(30, Number(v) || 0)) }));
                        }}
                      />
                    </td>
                    {haberes.map((c) => celdaValor(f, c))}
                    <td className="px-2 py-0.5 border-b border-slate-100 dark:border-slate-800 text-right text-slate-500" title="Aproximado hasta recalcular">{fmtMoney(totHab)}</td>
                    {descuentos.map((c) => celdaValor(f, c))}
                    <td className="px-2 py-0.5 border-b border-slate-100 dark:border-slate-800 text-right text-slate-500">{f.prestamo_cuota > 0 ? fmtMoney(f.prestamo_cuota) : '—'}</td>
                    <td className="px-2 py-0.5 border-b border-slate-100 dark:border-slate-800 text-right font-semibold bg-sky-50/40 dark:bg-sky-900/10">{fmtMoney(f.neto)}</td>
                    {(['formal', 'mt'] as const).map((k) => {
                      const ed = ediciones[f.empleado_id]?.reparto_importes;
                      const base = f.reparto_importes[k];
                      const actual = ed !== undefined ? (ed[k] ?? '') : (base ?? '');
                      const computado = k === 'formal' ? f.formal : f.mt;
                      return (
                        <td key={k} className="px-1 py-0.5 border-b border-slate-100 dark:border-slate-800">
                          <input type="text" inputMode="decimal" disabled={!editable}
                            className="w-24 text-right text-[12px] px-1 py-0.5 rounded border border-transparent bg-amber-50/60 dark:bg-amber-900/10 focus:border-blue-400 focus:bg-white dark:focus:bg-slate-900 disabled:bg-transparent"
                            value={actual === null ? '' : String(actual)}
                            placeholder={computado > 0 ? String(computado) : ''}
                            title={`Actual: ${fmtMoney(computado)}${base !== null ? ' (importe fijado)' : ' (por % default)'} — vaciar vuelve al default`}
                            onChange={(ev) => {
                              const v = num(ev.target.value);
                              editar(f.empleado_id, (e) => ({
                                ...e,
                                reparto_importes: {
                                  formal: k === 'formal' ? v : (e.reparto_importes?.formal ?? f.reparto_importes.formal),
                                  mt: k === 'mt' ? v : (e.reparto_importes?.mt ?? f.reparto_importes.mt),
                                },
                              }));
                            }}
                          />
                        </td>
                      );
                    })}
                    <td className="px-2 py-0.5 border-b border-slate-100 dark:border-slate-800 text-right text-slate-500"
                        title="Residual automático (neto − formal − mt)">{fmtMoney(f.efectivo)}</td>
                    <td className="px-2 py-0.5 border-b border-slate-100 dark:border-slate-800 text-right" title={f.diferencia_redondeo > 0 ? `Diferencia de redondeo: ${fmtMoney(f.diferencia_redondeo)}` : ''}>
                      {f.efectivo > 0 ? fmtMoney(f.efectivo_redondeado) : '—'}
                    </td>
                  </tr>
                );
              })}
            </tbody>
            {totales && (
              <tfoot>
                <tr className="font-semibold bg-emerald-50/60 dark:bg-emerald-900/10 text-[12px]">
                  <td className="px-2 py-1 sticky left-0 bg-emerald-50/60 dark:bg-emerald-900/10" colSpan={4}>TOTALES ({g.filas.length} empleados)</td>
                  <td colSpan={haberes.length + 1} />
                  <td colSpan={descuentos.length} />
                  <td className="px-2 py-1 text-right">{fmtMoney(totales.prestamos)}</td>
                  <td className="px-2 py-1 text-right">{fmtMoney(totales.neto)}</td>
                  <td className="px-2 py-1 text-right">{fmtMoney(totales.formal)}</td>
                  <td className="px-2 py-1 text-right">{fmtMoney(totales.mt)}</td>
                  <td className="px-2 py-1 text-right">{fmtMoney(totales.efectivo)}</td>
                  <td className="px-2 py-1 text-right">{fmtMoney(totales.redondeado)}</td>
                </tr>
              </tfoot>
            )}
          </table>
        </CardBody>
      </Card>

      <p className="text-[11px] text-slate-500">
        Celdas amarillas = editables (como el Excel). Las columnas por horas (HE_50/HE_100/HORAS_DESC) cargan cantidad y el importe
        se calcula a básico/240; el resto carga importe directo. Vaciar una celda borra la novedad. Formal($)/MT($) fijan importes EXACTOS por empleado solo en esta
        liquidación (vacío = % default del maestro); el Efectivo siempre es el residual. «Guardar y recalcular» aplica todo junto.
      </p>
    </div>
  );
}
