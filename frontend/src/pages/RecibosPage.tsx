import { useMemo, useState } from 'react';
import { Receipt, Plus, Eye, Ban, AlertTriangle, Trash2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { api, ApiError } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * v1.31 — Pantalla de Recibos. Cobranza unificada: factura + NC aplicadas +
 * retenciones + cobro neto en un solo documento. Reemplaza el flow de cobros
 * sueltos del v1.15 (que sigue operativo en /erp/cobros para back-compat).
 */

type Recibo = {
  id: number;
  numero_correlativo: string;
  fecha_emision: string;
  cliente_auxiliar_id: number;
  factura_venta_id: number;
  total_factura: number;
  total_nc_aplicadas: number;
  total_retenciones: number;
  monto_cobrable: number;
  monto_cobrado: number;
  saldo_factura_post: number;
  medio_cobro_id: number | null;
  estado: 'BORRADOR' | 'EMITIDO' | 'CONCILIADO' | 'ANULADO';
  cliente?: { id: number; nombre: string; cuit: string | null };
  factura?: { id: number; numero: number; imp_total: number };
  observaciones?: string | null;
};

type Cliente = { id: number; codigo: string; nombre: string };
type FacturaVenta = {
  id: number;
  tipo_codigo: string;
  letra: string | null;
  punto_venta_numero: number;
  numero: number;
  imp_total: number;
  estado: string;
  fecha_emision: string;
};
type CuentaBancaria = { id: number; nombre: string };
type NcLibre = {
  id: number; tipo: string; numero: number; fecha_emision: string;
  imp_total: number; saldo_imputable: number;
};
type CuentaContable = { id: number; codigo: string; nombre: string };

const ESTADO_BADGE: Record<Recibo['estado'], 'success' | 'warning' | 'info' | 'danger'> = {
  BORRADOR: 'warning',
  EMITIDO: 'success',
  CONCILIADO: 'info',
  ANULADO: 'danger',
};

export function RecibosPage() {
  const [estado, setEstado] = useState('');
  const [clienteId] = useState(''); // reservado para filtro futuro por cliente
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [verId, setVerId] = useState<number | null>(null);
  const [anularTarget, setAnularTarget] = useState<Recibo | null>(null);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (estado) p.set('estado', estado);
    if (clienteId) p.set('cliente_id', clienteId);
    return p.toString();
  }, [estado, clienteId]);

  const { data, isLoading, error } = useApi<Recibo[]>(
    ['recibos', qs],
    `/api/erp/tesoreria/recibos${qs ? `?${qs}` : ''}`,
  );

  const columns: Column<Recibo>[] = [
    { key: 'numero_correlativo', header: 'Nº Recibo', width: '160px',
      render: (r) => <code className="text-[11px]">{r.numero_correlativo}</code> },
    { key: 'fecha_emision', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha_emision) },
    { key: 'cliente', header: 'Cliente', render: (r) => r.cliente?.nombre ?? `#${r.cliente_auxiliar_id}` },
    { key: 'factura', header: 'Factura', width: '110px',
      render: (r) => <span className="text-[11.5px]">FV #{r.factura?.numero ?? r.factura_venta_id}</span> },
    { key: 'total_factura', header: 'Total', align: 'right', width: '100px',
      render: (r) => `$${fmtMoney(r.total_factura)}` },
    { key: 'total_nc_aplicadas', header: 'NC', align: 'right', width: '90px',
      render: (r) => r.total_nc_aplicadas > 0 ? `$${fmtMoney(r.total_nc_aplicadas)}` : '—' },
    { key: 'total_retenciones', header: 'Ret', align: 'right', width: '90px',
      render: (r) => r.total_retenciones > 0 ? `$${fmtMoney(r.total_retenciones)}` : '—' },
    { key: 'monto_cobrado', header: 'Cobrado', align: 'right', width: '110px',
      render: (r) => <strong>${fmtMoney(r.monto_cobrado)}</strong> },
    { key: 'estado', header: 'Estado', width: '110px',
      render: (r) => <Badge variant={ESTADO_BADGE[r.estado]}>{r.estado}</Badge> },
    { key: 'acciones', header: '', width: '80px',
      render: (r) => (
        <div className="flex gap-1">
          <button onClick={() => setVerId(r.id)} title="Ver"><Eye className="w-3.5 h-3.5 text-azure" /></button>
          {r.estado === 'EMITIDO' && (
            <button onClick={() => setAnularTarget(r)} title="Anular">
              <Ban className="w-3.5 h-3.5 text-danger" />
            </button>
          )}
        </div>
      ) },
  ];

  return (
    <div className="p-4 space-y-3">
      <Card>
        <CardHeader title={
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Receipt className="w-4 h-4 text-azure" /> Recibos (v1.31)
            </div>
            <Button variant="primary" size="sm" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Nuevo recibo
            </Button>
          </div>
        } />
        <CardBody className="space-y-2">
          <div className="text-[11.5px] text-ink-muted bg-info-bg/20 border border-info/20 rounded p-2">
            Cobranza unificada: junta factura + NC aplicadas + retenciones + cobro neto en un solo documento.
            Es la fuente única para conciliar contra el extracto bancario. La pantalla de Cobros (v1.15)
            sigue disponible para back-compat pero el flujo recomendado es Recibos.
          </div>

          <div className="flex gap-2 items-end">
            <SelectField label="Estado" value={estado} onChange={(e) => setEstado(e.target.value)}
              options={[
                { value: '', label: 'Todos' },
                { value: 'BORRADOR', label: 'BORRADOR' },
                { value: 'EMITIDO', label: 'EMITIDO' },
                { value: 'CONCILIADO', label: 'CONCILIADO' },
                { value: 'ANULADO', label: 'ANULADO' },
              ]}
              containerClassName="w-40" />
          </div>

          {error ? (
            <FormError error={errorMessage(error)} />
          ) : (
            <DataTable<Recibo>
              rows={data ?? []}
              columns={columns}
              loading={isLoading}
              empty="Sin recibos."
            />
          )}
        </CardBody>
      </Card>

      {nuevoOpen && <NuevoReciboModal onClose={() => setNuevoOpen(false)} />}
      {verId !== null && <DetalleReciboModal id={verId} onClose={() => setVerId(null)} />}
      {anularTarget && <AnularReciboModal recibo={anularTarget} onClose={() => setAnularTarget(null)} />}
    </div>
  );
}

function NuevoReciboModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['recibos']);
  const [clienteId, setClienteId] = useState('');
  const [facturaId, setFacturaId] = useState('');
  const [montoCobrado, setMontoCobrado] = useState('');
  const [medioCobroId, setMedioCobroId] = useState('');
  const [observaciones, setObservaciones] = useState('');
  const [autoImputarNc, setAutoImputarNc] = useState(true);
  const [ncAplicadas, setNcAplicadas] = useState<Array<{ nc_factura_id: number; monto_aplicado: number; nc?: NcLibre }>>([]);
  const [retenciones, setRetenciones] = useState<Array<{
    tipo: string; jurisdiccion_codigo?: string; numero_certificado?: string;
    alicuota?: number; base_imponible?: number; monto: number; cuenta_contable_id: number;
  }>>([]);
  const [emitirAlConfirmar, setEmitirAlConfirmar] = useState(false);

  const { data: clientesData } = useApi<{ clientes: Cliente[] }>(['clientes-cat'],
    '/api/erp/facturas-venta/catalogos');
  const { data: bancos } = useApi<{ ok: boolean; data: CuentaBancaria[] }>(['cuentas-bancarias'],
    '/api/erp/cuentas-bancarias');
  // Para retenciones: cuentas contables
  const { data: cuentas } = useApi<{ data: CuentaContable[] }>(['cuentas-contables-list'],
    '/api/erp/cuentas-contables?limit=500');

  // Cargar facturas del cliente cuando se selecciona.
  const { data: facturasData } = useApi<{ data: FacturaVenta[] }>(
    ['facturas-cliente', clienteId],
    `/api/erp/facturas-venta?auxiliar_id=${clienteId}&estado=EMITIDA,COBRO_PARCIAL`,
    { enabled: !!clienteId },
  );
  const facturasDisponibles = facturasData?.data ?? [];
  const facturaSel = facturasDisponibles.find((f) => String(f.id) === facturaId);

  // NC libres del cliente.
  const { data: ncLibresResp } = useApi<{ data: NcLibre[] }>(
    ['nc-libres', clienteId],
    `/api/erp/clientes/${clienteId}/notas-credito-libres`,
    { enabled: !!clienteId },
  );
  const ncLibres = ncLibresResp?.data ?? [];

  // Auto-imputación al cambiar factura (sólo si autoImputarNc activo).
  const autoImputarMut = useApiMutation<{ data: { nc_aplicadas: Array<{ nc_factura_id: number; monto_aplicado: number; nc: NcLibre }>; total_nc: number } }, { factura_venta_id: number }>(
    (body) => api.post('/api/erp/tesoreria/recibos/auto-imputar-nc', body),
    {
      onSuccess: (r) => {
        setNcAplicadas(r.data.nc_aplicadas);
      },
      onError: () => {/* silent */},
    },
  );

  const onPickFactura = (id: string) => {
    setFacturaId(id);
    setNcAplicadas([]);
    if (autoImputarNc && id) {
      autoImputarMut.mutate({ factura_venta_id: +id });
    }
  };

  const totalNc = ncAplicadas.reduce((a, n) => a + Number(n.monto_aplicado), 0);
  const totalRet = retenciones.reduce((a, r) => a + Number(r.monto), 0);
  const totalFactura = Number(facturaSel?.imp_total ?? 0);
  const montoCobrable = Math.max(0, totalFactura - totalNc - totalRet);

  const crearMut = useApiMutation<{ data: Recibo }, Record<string, unknown>>(
    (body) => api.post('/api/erp/tesoreria/recibos', body),
    {
      onSuccess: async (r) => {
        toast.success('Recibo creado', r.data.numero_correlativo);
        if (emitirAlConfirmar) {
          try {
            await api.post(`/api/erp/tesoreria/recibos/${r.data.id}/emitir`, {});
            toast.success('Recibo emitido', `Asiento generado`);
          } catch (e) {
            toast.error('Recibo creado pero no se pudo emitir', (e as ApiError).message);
          }
        }
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('Error', (e as ApiError).message),
    },
  );

  const submit = () => {
    if (!facturaId) return;
    crearMut.mutate({
      factura_venta_id: +facturaId,
      monto_cobrado: montoCobrado === '' ? undefined : Number(montoCobrado),
      medio_cobro_id: medioCobroId ? +medioCobroId : null,
      observaciones: observaciones || null,
      auto_imputar_nc: autoImputarNc,
      nc_aplicadas: ncAplicadas.map((n) => ({ nc_factura_id: n.nc_factura_id, monto_aplicado: n.monto_aplicado })),
      retenciones,
    });
  };

  const valid = !!facturaId && (Number(montoCobrado || montoCobrable) === 0 || !!medioCobroId);

  return (
    <Modal open onClose={onClose} title="Nuevo recibo" size="lg" footer={
      <>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" onClick={() => { setEmitirAlConfirmar(false); submit(); }}
          disabled={!valid || crearMut.isPending}>
          Guardar borrador
        </Button>
        <Button variant="primary" onClick={() => { setEmitirAlConfirmar(true); submit(); }}
          disabled={!valid || crearMut.isPending}>
          Emitir recibo
        </Button>
      </>
    }>
      <div className="space-y-3 text-[12px]">
        <div className="grid grid-cols-2 gap-2">
          <SelectField label="Cliente *" value={clienteId}
            onChange={(e) => { setClienteId(e.target.value); setFacturaId(''); setNcAplicadas([]); }}
            options={[{ value: '', label: 'Elegí cliente…' },
              ...(clientesData?.clientes ?? []).map((c) => ({
                value: String(c.id), label: `${c.codigo} ${c.nombre}`,
              }))]} />
          <SelectField label="Factura *" value={facturaId}
            onChange={(e) => onPickFactura(e.target.value)}
            options={[{ value: '', label: clienteId ? 'Elegí factura…' : '(elegí cliente primero)' },
              ...facturasDisponibles.map((f) => ({
                value: String(f.id),
                label: `${f.tipo_codigo} ${f.letra ?? ''} ${String(f.punto_venta_numero).padStart(4, '0')}-${String(f.numero).padStart(8, '0')} · $${fmtMoney(f.imp_total)}`,
              }))]} />
        </div>

        {facturaSel && (
          <div className="bg-azure-soft/20 border border-azure-soft rounded p-2 text-[11.5px]">
            Factura {facturaSel.tipo_codigo} {facturaSel.letra ?? ''}{' '}
            <code>{String(facturaSel.punto_venta_numero).padStart(4, '0')}-{String(facturaSel.numero).padStart(8, '0')}</code>{' '}
            · Total <strong>${fmtMoney(facturaSel.imp_total)}</strong> · Estado {facturaSel.estado}
          </div>
        )}

        {/* NC aplicadas */}
        {facturaId && (
          <div className="border border-line rounded p-2 space-y-1.5 bg-surface-row">
            <div className="flex items-center justify-between">
              <div className="text-[11.5px] font-semibold text-navy-800">NC aplicadas (auto-FIFO para WSFE)</div>
              <label className="flex items-center gap-1 text-[11px]">
                <input type="checkbox" checked={autoImputarNc}
                  onChange={(e) => setAutoImputarNc(e.target.checked)} />
                Auto-imputar
              </label>
            </div>
            {ncAplicadas.length === 0 && (
              <div className="text-[11px] text-ink-muted italic">
                {ncLibres.length === 0 ? 'El cliente no tiene NC libres con saldo.'
                  : `${ncLibres.length} NC libre${ncLibres.length === 1 ? '' : 's'} disponible${ncLibres.length === 1 ? '' : 's'}. ${autoImputarNc ? 'Auto-imputación activa.' : 'Activá auto-imputar o agregalas manualmente.'}`}
              </div>
            )}
            {ncAplicadas.length > 0 && (
              <table className="w-full text-[11px]">
                <thead><tr className="text-ink-muted"><th className="text-left px-1">NC</th><th className="text-right">Saldo NC</th><th className="text-right">A aplicar</th><th></th></tr></thead>
                <tbody>
                  {ncAplicadas.map((n, i) => {
                    const nc = ncLibres.find((x) => x.id === n.nc_factura_id) ?? n.nc;
                    return (
                      <tr key={i} className="border-t border-line">
                        <td className="px-1">{nc ? `${nc.tipo} ${nc.numero} (${fmtDate(nc.fecha_emision)})` : `NC #${n.nc_factura_id}`}</td>
                        <td className="text-right tabular">${nc ? fmtMoney(nc.saldo_imputable ?? nc.imp_total) : '—'}</td>
                        <td className="text-right tabular font-semibold">${fmtMoney(n.monto_aplicado)}</td>
                        <td className="text-right">
                          <button onClick={() => setNcAplicadas(ncAplicadas.filter((_, idx) => idx !== i))}>
                            <Trash2 className="w-3 h-3 text-danger" />
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                  <tr className="font-semibold border-t border-line">
                    <td colSpan={2} className="text-right px-1">Total NC</td>
                    <td className="text-right tabular">${fmtMoney(totalNc)}</td>
                    <td></td>
                  </tr>
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* Retenciones */}
        {facturaId && (
          <div className="border border-line rounded p-2 space-y-1.5 bg-surface-row">
            <div className="flex items-center justify-between">
              <div className="text-[11.5px] font-semibold text-navy-800">Retenciones recibidas</div>
              <Button variant="ghost" size="sm" onClick={() => setRetenciones([...retenciones,
                { tipo: 'GANANCIAS', monto: 0, cuenta_contable_id: 0 }])}>
                <Plus className="w-3 h-3" /> Sumar
              </Button>
            </div>
            {retenciones.length === 0 ? (
              <div className="text-[11px] text-ink-muted italic">Sin retenciones.</div>
            ) : (
              <div className="space-y-1">
                {retenciones.map((r, i) => (
                  <div key={i} className="grid grid-cols-12 gap-1 items-end border-b border-line pb-1">
                    <SelectField label="Tipo" value={r.tipo}
                      onChange={(e) => {
                        const next = [...retenciones]; next[i].tipo = e.target.value; setRetenciones(next);
                      }}
                      options={['GANANCIAS', 'IVA', 'IIBB', 'SUSS', 'OTRO'].map((v) => ({ value: v, label: v }))}
                      containerClassName="col-span-2" />
                    <Field label="Cert" value={r.numero_certificado ?? ''}
                      onChange={(e) => { const n = [...retenciones]; n[i].numero_certificado = e.target.value; setRetenciones(n); }}
                      containerClassName="col-span-2" />
                    <Field label="Alíc %" type="number" value={String(r.alicuota ?? '')}
                      onChange={(e) => { const n = [...retenciones]; n[i].alicuota = +e.target.value || undefined; setRetenciones(n); }}
                      containerClassName="col-span-1" />
                    <Field label="Base" type="number" value={String(r.base_imponible ?? '')}
                      onChange={(e) => { const n = [...retenciones]; n[i].base_imponible = +e.target.value || undefined; setRetenciones(n); }}
                      containerClassName="col-span-2" />
                    <Field label="Monto *" type="number" value={String(r.monto)}
                      onChange={(e) => { const n = [...retenciones]; n[i].monto = +e.target.value; setRetenciones(n); }}
                      containerClassName="col-span-2" />
                    <SelectField label="Cuenta dest *" value={String(r.cuenta_contable_id)}
                      onChange={(e) => { const n = [...retenciones]; n[i].cuenta_contable_id = +e.target.value; setRetenciones(n); }}
                      options={[{ value: '0', label: '—' },
                        ...(cuentas?.data ?? []).map((c) => ({ value: String(c.id), label: `${c.codigo} ${c.nombre}` }))]}
                      containerClassName="col-span-2" />
                    <div className="col-span-1 text-right">
                      <button onClick={() => setRetenciones(retenciones.filter((_, idx) => idx !== i))}>
                        <Trash2 className="w-3 h-3 text-danger" />
                      </button>
                    </div>
                  </div>
                ))}
                <div className="text-right text-[11.5px] font-semibold">Total ret ${fmtMoney(totalRet)}</div>
              </div>
            )}
          </div>
        )}

        {/* Cobro */}
        {facturaId && (
          <div className="border border-azure-soft rounded p-2 space-y-1.5 bg-azure-soft/10">
            <div className="grid grid-cols-3 gap-2 text-[11.5px]">
              <div><strong>Total factura:</strong> ${fmtMoney(totalFactura)}</div>
              <div>NC: ${fmtMoney(totalNc)}</div>
              <div>Retenciones: ${fmtMoney(totalRet)}</div>
            </div>
            <div className="border-t border-azure-soft pt-1.5 grid grid-cols-2 gap-2">
              <div>
                <strong>Monto cobrable:</strong> ${fmtMoney(montoCobrable)}
                <div className="text-[10px] text-ink-muted">total − NC − retenciones</div>
              </div>
              <Field label="Monto a cobrar (default = cobrable)" type="number"
                value={montoCobrado} onChange={(e) => setMontoCobrado(e.target.value)}
                placeholder={String(montoCobrable.toFixed(2))} />
            </div>
            {(Number(montoCobrado || montoCobrable) > 0) && (
              <SelectField label="Medio de cobro *" value={medioCobroId}
                onChange={(e) => setMedioCobroId(e.target.value)}
                options={[{ value: '', label: 'Elegí medio…' },
                  ...(bancos?.data ?? []).map((b) => ({ value: String(b.id), label: b.nombre }))]} />
            )}
          </div>
        )}

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">Observaciones</label>
          <textarea rows={2} value={observaciones} onChange={(e) => setObservaciones(e.target.value)}
            maxLength={1000}
            className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
        </div>

        {crearMut.error && (
          <FormError error={errorMessage(crearMut.error)} />
        )}
      </div>
    </Modal>
  );
}

function DetalleReciboModal({ id, onClose }: { id: number; onClose: () => void }) {
  const { data, isLoading } = useApi<Recibo & {
    nc_aplicadas?: Array<{ id: number; nc_factura_id: number; monto_aplicado: number; automatica: boolean; nc?: { numero: number; imp_total: number } }>;
    retenciones?: Array<{ id: number; tipo: string; monto: number; numero_certificado: string | null }>;
    medio_cobro?: CuentaBancaria;
    asiento?: { id: number; numero: number };
  }>(['recibo', id], `/api/erp/tesoreria/recibos/${id}`);

  return (
    <Modal open onClose={onClose} title={data ? `Recibo ${data.numero_correlativo}` : 'Cargando…'} size="lg" footer={
      <Button variant="primary" onClick={onClose}>Cerrar</Button>
    }>
      {isLoading || !data ? <div className="text-[12px] text-ink-muted">Cargando…</div> : (
        <div className="space-y-3 text-[12px]">
          <div className="grid grid-cols-2 gap-2 bg-surface-row border border-line rounded p-2">
            <div><strong>Fecha:</strong> {fmtDate(data.fecha_emision)}</div>
            <div><strong>Estado:</strong> <Badge variant={ESTADO_BADGE[data.estado]}>{data.estado}</Badge></div>
            <div><strong>Cliente:</strong> {data.cliente?.nombre}</div>
            <div><strong>Factura:</strong> FV #{data.factura?.numero}</div>
            <div><strong>Total factura:</strong> ${fmtMoney(data.total_factura)}</div>
            <div><strong>Saldo post:</strong> ${fmtMoney(data.saldo_factura_post)}</div>
            <div><strong>NC aplicadas:</strong> ${fmtMoney(data.total_nc_aplicadas)}</div>
            <div><strong>Retenciones:</strong> ${fmtMoney(data.total_retenciones)}</div>
            <div><strong>Monto cobrado:</strong> ${fmtMoney(data.monto_cobrado)}</div>
            {data.medio_cobro && <div><strong>Medio:</strong> {data.medio_cobro.nombre}</div>}
            {data.asiento && <div><strong>Asiento:</strong> #{data.asiento.numero}</div>}
          </div>
          {data.nc_aplicadas && data.nc_aplicadas.length > 0 && (
            <div>
              <div className="font-semibold mb-1">NC aplicadas</div>
              <ul className="space-y-0.5 text-[11.5px]">
                {data.nc_aplicadas.map((n) => (
                  <li key={n.id}>NC #{n.nc_factura_id} · ${fmtMoney(n.monto_aplicado)} {n.automatica && <span className="text-info">(auto)</span>}</li>
                ))}
              </ul>
            </div>
          )}
          {data.retenciones && data.retenciones.length > 0 && (
            <div>
              <div className="font-semibold mb-1">Retenciones</div>
              <ul className="space-y-0.5 text-[11.5px]">
                {data.retenciones.map((r) => (
                  <li key={r.id}>{r.tipo}{r.numero_certificado && ` (Cert#${r.numero_certificado})`}: ${fmtMoney(r.monto)}</li>
                ))}
              </ul>
            </div>
          )}
          {data.observaciones && (
            <div className="bg-azure-soft/10 border border-azure-soft rounded p-2 text-[11.5px]">
              <strong>Observaciones:</strong> {data.observaciones}
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}

function AnularReciboModal({ recibo, onClose }: { recibo: Recibo; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['recibos']);
  const [motivo, setMotivo] = useState('');

  const mut = useApiMutation<unknown, { motivo: string }>(
    (body) => api.post(`/api/erp/tesoreria/recibos/${recibo.id}/anular`, body),
    {
      onSuccess: () => {
        toast.success('Recibo anulado', `Reversa generada para ${recibo.numero_correlativo}`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo anular', (e as ApiError).message),
    },
  );

  return (
    <Modal open onClose={onClose} title={`Anular recibo ${recibo.numero_correlativo}`} size="md" footer={
      <>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="danger" disabled={motivo.trim().length < 5 || mut.isPending}
          onClick={() => mut.mutate({ motivo: motivo.trim() })}>
          {mut.isPending ? 'Anulando…' : 'Anular'}
        </Button>
      </>
    }>
      <div className="space-y-2 text-[12px]">
        <div className="border border-warning/40 bg-warning-bg/20 rounded p-2 flex items-start gap-1.5">
          <AlertTriangle className="w-4 h-4 text-warning shrink-0" />
          <div>Esta acción genera un asiento reversa, libera el saldo de la factura y des-imputa las NC asociadas.
            Queda registrada en audit log.</div>
        </div>
        <label className="block text-[11px] text-ink-muted">Motivo *</label>
        <textarea rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)}
          maxLength={500}
          placeholder="Ej: registrado por error; cliente solicitó anulación; doble registro…"
          className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
        <div className="text-[10px] text-ink-muted">{motivo.length} / 500 (mín 5)</div>
      </div>
    </Modal>
  );
}
