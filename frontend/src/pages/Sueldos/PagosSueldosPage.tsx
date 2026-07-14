import { useMemo, useState } from 'react';
import { Banknote, Landmark, ReceiptText, BookCheck, AlertTriangle } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { fmtMoney, fmtDate } from '@/components/ui/DataTable';
import { Field, SelectField } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Liq = { id: number; periodo: string; tipo: string; estado: string; total_neto: number | string; asiento_id: number | null; empleados_count: number };
type Pago = { id: number; empleado_id: number; componente: 'FORMAL' | 'EFECTIVO' | 'MT'; medio: string; importe: number | string; fecha: string; orden_pago_id: number | null; recibido_por: string | null; asiento_id: number | null; empleado?: { legajo: string; apellido: string; nombre: string } };
type EfectivoPrep = {
  empleados: { empleado_id: number; legajo: string | null; nombre: string | null; neto_efectivo: number; a_entregar: number; diferencia_redondeo: number; ya_pagado: boolean }[];
  total_a_preparar: number; total_diferencia_redondeo: number;
};
type Cancelado = { id: number; empleado_id: number; mensaje: string };

export function PagosSueldosPage() {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-liq-pagables']);
  const [liqId, setLiqId] = useState<number | ''>('');
  const [fecha, setFecha] = useState(() => new Date().toISOString().slice(0, 10));
  const [cuentaBancariaId, setCuentaBancariaId] = useState('');
  const [cajaId, setCajaId] = useState('');
  const [receptores, setReceptores] = useState<Record<number, { recibido_por: string; dni_recibio: string }>>({});
  const [alertas, setAlertas] = useState<Cancelado[]>([]);

  const { data: liqs } = useApi<{ data: Liq[] } | Liq[]>(['sueldos-liq-pagables'], '/api/erp/sueldos/liquidaciones?estado=APROBADA&per_page=100');
  const liqList: Liq[] = useMemo(() => {
    const raw: any = liqs;
    return (raw?.data?.data ?? raw?.data ?? raw ?? []) as Liq[];
  }, [liqs]);

  const { data: bancos } = useApi<any>(['cat-bancos'], '/api/erp/cuentas-bancarias');
  const { data: cajas } = useApi<any>(['cat-cajas'], '/api/erp/cajas');
  const bancosList = (bancos?.data ?? bancos ?? []) as any[];
  const cajasList = ((cajas?.data ?? cajas ?? []) as any[]).filter((c) => c.activo !== false && c.activo !== 0);

  const liq = liqList.find((l) => l.id === liqId);

  const { data: prep, refetch: refetchPrep } = useApi<EfectivoPrep>(
    ['sueldos-efectivo-prep', liqId],
    `/api/erp/sueldos/liquidaciones/${liqId}/efectivo-a-preparar`,
    { enabled: !!liqId }
  );
  const { data: pagos, refetch: refetchPagos } = useApi<Pago[]>(
    ['sueldos-pagos-liq', liqId],
    `/api/erp/sueldos/liquidaciones/${liqId}/pagos`,
    { enabled: !!liqId }
  );

  const done = (res: any, msj: string) => {
    if (res?.prestamos_cancelados?.length) setAlertas((a) => [...a, ...res.prestamos_cancelados]);
    invalidate(); refetchPagos(); refetchPrep();
    toast.success(msj);
  };

  const contabilizar = useApiMutation<any, void>(
    () => api.post(`/api/erp/sueldos/liquidaciones/${liqId}/contabilizar`),
    { onSuccess: () => done(null, 'Devengo contabilizado.'), onError: (e) => toast.error(errorMessage(e)) }
  );
  const pagarFormal = useApiMutation<any, void>(
    () => api.post(`/api/erp/sueldos/liquidaciones/${liqId}/pagar/formal`, { cuenta_bancaria_id: Number(cuentaBancariaId), fecha }),
    { onSuccess: (r) => done(r, 'FORMAL pagado (OP por empleado).'), onError: (e) => toast.error(errorMessage(e)) }
  );
  const pagarMt = useApiMutation<any, void>(
    () => {
      const facturas = window.prompt('IDs de factura C por empleado (formato: empleadoId:facturaId, separados por coma)') ?? '';
      const parsed = facturas.split(',').map((p) => p.split(':').map((x) => Number(x.trim()))).filter((p) => p.length === 2 && p[0] && p[1])
        .map(([empleado_id, factura_compra_id]) => ({ empleado_id, factura_compra_id }));
      return api.post(`/api/erp/sueldos/liquidaciones/${liqId}/pagar/mt`, { cuenta_bancaria_id: Number(cuentaBancariaId), fecha, facturas: parsed });
    },
    { onSuccess: (r) => done(r, 'MT pagado contra facturas.'), onError: (e) => toast.error(errorMessage(e)) }
  );
  const pagarEfectivo = useApiMutation<any, void>(
    () => {
      const pendientes = (prep?.empleados ?? []).filter((e) => !e.ya_pagado && e.a_entregar > 0);
      const lista = pendientes.map((e) => ({
        empleado_id: e.empleado_id,
        recibido_por: receptores[e.empleado_id]?.recibido_por || '',
        dni_recibio: receptores[e.empleado_id]?.dni_recibio || '',
      }));
      return api.post(`/api/erp/sueldos/liquidaciones/${liqId}/pagar/efectivo`, { caja_id: Number(cajaId), fecha, receptores: lista });
    },
    { onSuccess: (r) => done(r, 'EFECTIVO pagado.'), onError: (e) => toast.error(errorMessage(e)) }
  );

  const pagosList = ((pagos as any)?.data ?? pagos ?? []) as Pago[];
  const pendEf = (prep?.empleados ?? []).filter((e) => !e.ya_pagado && e.a_entregar > 0);
  const receptoresCompletos = pendEf.every((e) => receptores[e.empleado_id]?.recibido_por && receptores[e.empleado_id]?.dni_recibio);

  return (
    <div className="p-4 space-y-4">
      <div className="flex items-center justify-between flex-wrap gap-2">
        <h1 className="text-lg font-semibold">Pagos de sueldos</h1>
        <div className="w-72">
          <SelectField label="Liquidación APROBADA" value={String(liqId)}
            onChange={(e) => { setLiqId(e.target.value ? Number(e.target.value) : ''); setAlertas([]); }}
            placeholder="— elegir —"
            options={liqList.map((l) => ({ value: String(l.id), label: `#${l.id} · ${l.periodo} ${l.tipo} · ${fmtMoney(Number(l.total_neto))}` }))}
          />
        </div>
      </div>

      {alertas.length > 0 && (
        <Card><CardBody className="py-2">
          {alertas.map((a, i) => (
            <div key={i} className="flex items-center gap-2 text-[12.5px] text-amber-700 dark:text-amber-400">
              <AlertTriangle className="w-3.5 h-3.5" /> {a.mensaje} Verificalo en CC + Préstamos.
            </div>
          ))}
        </CardBody></Card>
      )}

      {!liq && <p className="text-sm text-slate-500">Elegí una liquidación aprobada. (Se aprueban desde Liquidaciones; el detalle editable está en su Grilla.)</p>}

      {liq && (
        <>
          <div className="grid md:grid-cols-4 gap-3">
            <Card><CardBody className="py-3">
              <div className="text-[11px] uppercase text-slate-500 flex items-center gap-1"><BookCheck className="w-3.5 h-3.5" /> Devengo</div>
              <div className="mt-1 text-sm">{liq.asiento_id ? <Badge variant="success">Asiento #{liq.asiento_id}</Badge> : <Badge variant="warning">SIN CONTABILIZAR</Badge>}</div>
              <Button size="sm" className="mt-2" disabled={!!liq.asiento_id || contabilizar.isPending} onClick={() => contabilizar.mutate()}>Contabilizar</Button>
            </CardBody></Card>

            <Card><CardBody className="py-3">
              <div className="text-[11px] uppercase text-slate-500 flex items-center gap-1"><Landmark className="w-3.5 h-3.5" /> FORMAL (OP + transferencia)</div>
              <SelectField label="Cuenta bancaria" value={cuentaBancariaId} onChange={(e) => setCuentaBancariaId(e.target.value)}
                options={bancosList.map((b: any) => ({ value: String(b.id), label: b.nombre ?? b.alias ?? `Cuenta #${b.id}` }))} />
              <Button size="sm" className="mt-2" disabled={!cuentaBancariaId || pagarFormal.isPending} onClick={() => pagarFormal.mutate()}>Pagar FORMAL</Button>
            </CardBody></Card>

            <Card><CardBody className="py-3">
              <div className="text-[11px] uppercase text-slate-500 flex items-center gap-1"><ReceiptText className="w-3.5 h-3.5" /> MT (contra factura C)</div>
              <p className="text-[11.5px] text-slate-500 mt-1">Usa la misma cuenta bancaria. Pide factura por empleado al ejecutar.</p>
              <Button size="sm" className="mt-2" disabled={!cuentaBancariaId || pagarMt.isPending} onClick={() => pagarMt.mutate()}>Pagar MT</Button>
            </CardBody></Card>

            <Card><CardBody className="py-3">
              <div className="text-[11px] uppercase text-slate-500 flex items-center gap-1"><Banknote className="w-3.5 h-3.5" /> Fecha de pago</div>
              <Field label="Fecha" type="date" value={fecha} onChange={(e: any) => setFecha(e.target.value)} />
            </CardBody></Card>
          </div>

          <Card>
            <CardHeader title={`Efectivo a preparar ${prep ? `— TOTAL ${fmtMoney(prep.total_a_preparar)} (dif. redondeo ${fmtMoney(prep.total_diferencia_redondeo)})` : ''}`} />
            <CardBody className="p-0 overflow-x-auto">
              <table className="text-[12px] min-w-full">
                <thead><tr className="text-left text-[11px] uppercase text-slate-500">
                  <th className="px-3 py-1.5">Legajo</th><th className="px-3 py-1.5">Empleado</th>
                  <th className="px-3 py-1.5 text-right">Neto efectivo</th><th className="px-3 py-1.5 text-right">A entregar (redond.)</th>
                  <th className="px-3 py-1.5 text-right">Dif.</th>
                  <th className="px-3 py-1.5">Recibido por</th><th className="px-3 py-1.5">DNI</th><th className="px-3 py-1.5"></th>
                </tr></thead>
                <tbody>
                  {(prep?.empleados ?? []).map((e) => (
                    <tr key={e.empleado_id} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="px-3 py-1 font-mono text-[11px]">{e.legajo}</td>
                      <td className="px-3 py-1">{e.nombre}</td>
                      <td className="px-3 py-1 text-right">{fmtMoney(e.neto_efectivo)}</td>
                      <td className="px-3 py-1 text-right font-semibold">{fmtMoney(e.a_entregar)}</td>
                      <td className="px-3 py-1 text-right text-slate-500">{fmtMoney(e.diferencia_redondeo)}</td>
                      <td className="px-3 py-1">
                        <input disabled={e.ya_pagado} className="w-40 text-[12px] px-1.5 py-0.5 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 disabled:opacity-50"
                          placeholder={e.nombre ?? ''} value={receptores[e.empleado_id]?.recibido_por ?? ''}
                          onChange={(ev) => setReceptores((r) => ({ ...r, [e.empleado_id]: { ...(r[e.empleado_id] ?? { dni_recibio: '' }), recibido_por: ev.target.value } }))} />
                      </td>
                      <td className="px-3 py-1">
                        <input disabled={e.ya_pagado} className="w-24 text-[12px] px-1.5 py-0.5 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 disabled:opacity-50"
                          value={receptores[e.empleado_id]?.dni_recibio ?? ''}
                          onChange={(ev) => setReceptores((r) => ({ ...r, [e.empleado_id]: { ...(r[e.empleado_id] ?? { recibido_por: '' }), dni_recibio: ev.target.value } }))} />
                      </td>
                      <td className="px-3 py-1">{e.ya_pagado && <Badge variant="success">PAGADO</Badge>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardBody>
            <CardBody className="pt-0 flex items-center gap-3">
              <SelectField label="Caja" value={cajaId} onChange={(e) => setCajaId(e.target.value)}
                options={cajasList.map((c: any) => ({ value: String(c.id), label: c.nombre ?? c.codigo }))} />
              <Button size="sm" disabled={!cajaId || pendEf.length === 0 || !receptoresCompletos || pagarEfectivo.isPending}
                onClick={() => pagarEfectivo.mutate()}>
                Pagar EFECTIVO ({pendEf.length} empleados)
              </Button>
              {!receptoresCompletos && pendEf.length > 0 && <span className="text-[11px] text-amber-600">Completá receptor + DNI de todos (RN-112).</span>}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title={`Pagos registrados (${pagosList.length})`} />
            <CardBody className="p-0 overflow-x-auto">
              <table className="text-[12px] min-w-full">
                <thead><tr className="text-left text-[11px] uppercase text-slate-500">
                  <th className="px-3 py-1.5">Empleado</th><th className="px-3 py-1.5">Comp.</th><th className="px-3 py-1.5">Medio</th>
                  <th className="px-3 py-1.5 text-right">Importe</th><th className="px-3 py-1.5">Fecha</th>
                  <th className="px-3 py-1.5">OP / Receptor</th><th className="px-3 py-1.5">Asiento</th>
                </tr></thead>
                <tbody>
                  {pagosList.map((p) => (
                    <tr key={p.id} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="px-3 py-1">{p.empleado ? `${p.empleado.apellido}, ${p.empleado.nombre}` : `#${p.empleado_id}`}</td>
                      <td className="px-3 py-1"><Badge variant={p.componente === 'FORMAL' ? 'info' : p.componente === 'MT' ? 'default' : 'warning'}>{p.componente}</Badge></td>
                      <td className="px-3 py-1">{p.medio}</td>
                      <td className="px-3 py-1 text-right">{fmtMoney(Number(p.importe))}</td>
                      <td className="px-3 py-1">{fmtDate(p.fecha)}</td>
                      <td className="px-3 py-1">{p.orden_pago_id ? `OP #${p.orden_pago_id}` : (p.recibido_por ?? '—')}</td>
                      <td className="px-3 py-1">{p.asiento_id ? <code>#{p.asiento_id}</code> : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardBody>
          </Card>
        </>
      )}
    </div>
  );
}
