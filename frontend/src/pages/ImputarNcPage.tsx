import { useMemo, useState } from 'react';
import { Wand2, Receipt, FileMinus, Trash2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { fmtMoney, fmtDate } from '@/components/ui/DataTable';
import { api, ApiError } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * ADDENDUM v1.15 Sprint O — pantalla de imputación de Notas de Crédito a
 * facturas/ND. Selector de cliente + lista de NC libres + lista de
 * facturas pendientes + form de imputaciones con valida saldos.
 */

type Auxiliar = { id: number; codigo: string; nombre: string };

type ItemCobrable = {
  tipo: 'factura' | 'nd' | 'nc';
  id: number;
  label: string;
  fecha: string;
  total: number;
  saldo: number;
  saldo_imputable?: number;
};

type ImputacionPendiente = {
  nc_id: number;
  factura_id: number;
  importe: number;
  ncLabel: string;
  facturaLabel: string;
};

export function ImputarNcPage() {
  const [clienteId, setClienteId] = useState('');
  const [pendientes, setPendientes] = useState<ImputacionPendiente[]>([]);
  const [ncSel, setNcSel] = useState('');
  const [facturaSel, setFacturaSel] = useState('');
  const [importe, setImporte] = useState('');
  const [error, setError] = useState<string | null>(null);

  const toast = useToast();
  const invalidate = useInvalidate(['imputaciones-nc'], ['items-cobrables']);

  const { data: auxiliares } = useApi<Auxiliar[]>(['auxiliares', 'clientes-imp'],
    '/api/erp/auxiliares?tipo=Cliente');

  const { data: items, isLoading } = useApi<ItemCobrable[]>(
    ['items-cobrables', clienteId],
    `/api/erp/cobros/items-cobrables?cliente_id=${clienteId}`,
    { enabled: !!clienteId }
  );

  const ncs = useMemo(() => (items ?? []).filter((i) => i.tipo === 'nc'), [items]);
  const facturas = useMemo(
    () => (items ?? []).filter((i) => i.tipo === 'factura' || i.tipo === 'nd'),
    [items]
  );

  // Saldos efectivos restando lo ya cargado en pendientes (no confirmado).
  const saldoNcEfectivo = (id: number): number => {
    const nc = ncs.find((x) => x.id === id);
    if (!nc) return 0;
    const yaPend = pendientes.filter((p) => p.nc_id === id).reduce((s, p) => s + p.importe, 0);
    return Math.max(0, (nc.saldo_imputable ?? Math.abs(nc.saldo)) - yaPend);
  };
  const saldoFactEfectivo = (id: number): number => {
    const f = facturas.find((x) => x.id === id);
    if (!f) return 0;
    const yaPend = pendientes.filter((p) => p.factura_id === id).reduce((s, p) => s + p.importe, 0);
    return Math.max(0, f.saldo - yaPend);
  };

  const agregar = () => {
    setError(null);
    if (!ncSel || !facturaSel || !importe) return;
    const nc = ncs.find((x) => x.id === Number(ncSel));
    const fa = facturas.find((x) => x.id === Number(facturaSel));
    if (!nc || !fa) return;
    const imp = Number(importe);
    if (imp <= 0) { setError('El importe debe ser mayor a 0.'); return; }
    if (imp > saldoNcEfectivo(nc.id) + 0.005) {
      setError(`Excede el saldo de la NC (disponible ${fmtMoney(saldoNcEfectivo(nc.id))}).`);
      return;
    }
    if (imp > saldoFactEfectivo(fa.id) + 0.005) {
      setError(`Excede el saldo de la factura (disponible ${fmtMoney(saldoFactEfectivo(fa.id))}).`);
      return;
    }
    setPendientes([...pendientes, {
      nc_id: nc.id, factura_id: fa.id, importe: imp,
      ncLabel: nc.label, facturaLabel: fa.label,
    }]);
    setImporte('');
  };

  const remover = (idx: number) => setPendientes(pendientes.filter((_, i) => i !== idx));

  const m = useApiMutation<{ ids: number[] }, void>(
    () => api.post('/api/erp/imputaciones-nc', {
      cliente_id: Number(clienteId),
      imputaciones: pendientes.map((p) => ({
        nc_id: p.nc_id, factura_id: p.factura_id, importe: p.importe,
      })),
    }),
    {
      onSuccess: (r) => {
        toast.success(`${r.ids.length} imputaciones confirmadas`);
        setPendientes([]);
        invalidate();
      },
      onError: (e) => toast.error('Error al confirmar', errorMessage(e as ApiError)),
    }
  );

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2">
            <Wand2 className="w-4 h-4 text-azure" /> Imputar Notas de Crédito a Facturas
          </div>
        } />
        <CardBody className="p-4 space-y-4">
          <div className="text-[12px] text-ink-muted">
            Elegí un cliente para ver sus NC libres + facturas pendientes. Armá pares
            "NC → Factura" con el importe a imputar (puede partirse entre varias facturas).
            Confirmá todas las imputaciones juntas al final.
          </div>

          <SelectField label="Cliente" value={clienteId} placeholder="Elegí cliente"
            onChange={(e) => { setClienteId(e.target.value); setPendientes([]); setError(null); }}
            options={(auxiliares ?? []).map((a) => ({
              value: String(a.id), label: `${a.codigo} ${a.nombre}`,
            }))}
            containerClassName="w-[400px]" />

          {clienteId && !isLoading && (
            <div className="grid grid-cols-2 gap-4">
              <ListaItems titulo="Notas de Crédito libres" icon={<FileMinus className="w-3 h-3" />}
                rows={ncs.map((nc) => ({
                  id: nc.id, label: nc.label, fecha: nc.fecha,
                  total: Math.abs(nc.total),
                  saldo: saldoNcEfectivo(nc.id),
                }))}
                emptyText="Sin NC libres para este cliente." />
              <ListaItems titulo="Facturas / ND con saldo" icon={<Receipt className="w-3 h-3" />}
                rows={facturas.map((f) => ({
                  id: f.id, label: f.label, fecha: f.fecha,
                  total: f.total,
                  saldo: saldoFactEfectivo(f.id),
                }))}
                emptyText="Sin facturas pendientes para este cliente." />
            </div>
          )}

          {clienteId && (ncs.length > 0 && facturas.length > 0) && (
            <div className="border-t border-line pt-3 space-y-3">
              <div className="text-[12px] font-semibold text-navy-800">Armar imputación</div>
              <div className="grid grid-cols-12 gap-3 items-end">
                <SelectField label="NC" value={ncSel} placeholder="Elegí NC"
                  onChange={(e) => setNcSel(e.target.value)}
                  options={ncs.filter((nc) => saldoNcEfectivo(nc.id) > 0.005).map((nc) => ({
                    value: String(nc.id),
                    label: `${nc.label} · disp ${fmtMoney(saldoNcEfectivo(nc.id))}`,
                  }))}
                  containerClassName="col-span-4" />
                <SelectField label="Factura" value={facturaSel} placeholder="Elegí factura"
                  onChange={(e) => setFacturaSel(e.target.value)}
                  options={facturas.filter((f) => saldoFactEfectivo(f.id) > 0.005).map((f) => ({
                    value: String(f.id),
                    label: `${f.label} · disp ${fmtMoney(saldoFactEfectivo(f.id))}`,
                  }))}
                  containerClassName="col-span-5" />
                <Field label="Importe" type="number" step="0.01"
                  value={importe} onChange={(e) => setImporte(e.target.value)}
                  containerClassName="col-span-2" />
                <Button variant="primary" disabled={!ncSel || !facturaSel || !importe}
                  onClick={agregar} className="col-span-1">
                  Agregar
                </Button>
              </div>
              <FormError error={error} />
            </div>
          )}

          {pendientes.length > 0 && (
            <div className="border-t border-line pt-3 space-y-2">
              <div className="text-[12px] font-semibold text-navy-800">
                Imputaciones pendientes de confirmar ({pendientes.length})
              </div>
              <div className="border border-line rounded-md overflow-hidden">
                <table className="w-full text-[12px]">
                  <thead className="bg-surface-row text-[11px] uppercase tracking-wider text-ink-muted">
                    <tr>
                      <th className="px-2 py-2 text-left">NC</th>
                      <th className="px-2 py-2 text-left">→ Factura</th>
                      <th className="px-2 py-2 text-right w-[150px]">Importe</th>
                      <th className="px-2 py-2 w-[40px]"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line/60">
                    {pendientes.map((p, i) => (
                      <tr key={i}>
                        <td className="px-2 py-1.5"><code>{p.ncLabel}</code></td>
                        <td className="px-2 py-1.5"><code>{p.facturaLabel}</code></td>
                        <td className="px-2 py-1.5 text-right font-semibold">{fmtMoney(p.importe)}</td>
                        <td className="px-2 py-1.5 text-right">
                          <button onClick={() => remover(i)}
                            className="opacity-50 hover:opacity-100 hover:text-danger">
                            <Trash2 className="w-3 h-3" />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="flex justify-between items-center">
                <div className="text-[11.5px] text-ink-muted">
                  Total a imputar: <strong>{fmtMoney(pendientes.reduce((s, p) => s + p.importe, 0))}</strong>
                </div>
                <Button variant="primary" disabled={m.isPending}
                  onClick={() => m.mutate(undefined as unknown as void)}>
                  {m.isPending ? 'Confirmando…' : `Confirmar ${pendientes.length} imputaciones`}
                </Button>
              </div>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

function ListaItems({
  titulo, icon, rows, emptyText,
}: {
  titulo: string;
  icon: React.ReactNode;
  rows: Array<{ id: number; label: string; fecha: string; total: number; saldo: number }>;
  emptyText: string;
}) {
  return (
    <div className="border border-line rounded-md overflow-hidden">
      <div className="bg-surface-row px-3 py-2 text-[11.5px] font-semibold text-navy-800 flex items-center gap-1">
        {icon} {titulo} <Badge variant="default">{rows.length}</Badge>
      </div>
      {rows.length === 0 ? (
        <div className="px-3 py-6 text-center text-[11.5px] text-ink-muted">{emptyText}</div>
      ) : (
        <table className="w-full text-[11.5px]">
          <thead className="text-[10.5px] uppercase tracking-wider text-ink-muted bg-white border-b border-line">
            <tr>
              <th className="px-2 py-1 text-left">Comprobante</th>
              <th className="px-2 py-1 text-left">Fecha</th>
              <th className="px-2 py-1 text-right">Total</th>
              <th className="px-2 py-1 text-right">Saldo</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line/60">
            {rows.map((r) => (
              <tr key={r.id} className="hover:bg-surface-hover">
                <td className="px-2 py-1.5"><code>{r.label}</code></td>
                <td className="px-2 py-1.5">{fmtDate(r.fecha)}</td>
                <td className="px-2 py-1.5 text-right">{fmtMoney(r.total)}</td>
                <td className="px-2 py-1.5 text-right font-semibold">{fmtMoney(r.saldo)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
