import { useEffect, useMemo, useState } from 'react';
import { Wallet, Plus, Trash2, Eye, ListChecks } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Auxiliar = { id: number; codigo: string; nombre: string; tipo?: string };
type Moneda = { id: number; codigo: string };
type MedioPago = { id: number; codigo: string; nombre: string; afecta_caja?: number; afecta_banco?: number; genera_echeq?: number };
type Caja = { id: number; codigo: string; nombre: string; moneda_id?: number; moneda?: Moneda };
type CuentaBanco = { id: number; codigo: string; nombre: string; moneda_id?: number; moneda?: Moneda };

// v1.15 Sprint O+ — items cobrables del backend (/api/erp/cobros/items-cobrables)
type ItemCobrable = {
  tipo: 'factura' | 'nd' | 'nc';
  id: number;
  label: string;
  fecha: string;
  total: number;
  saldo: number;
};

type Cobro = {
  id: number;
  numero: string;
  fecha: string;
  auxiliar: Auxiliar;
  moneda: Moneda;
  cotizacion: number | string;
  importe_total: number | string;
  total_retenciones: number | string;
  estado: string;
  concepto?: string;
};

const ESTADOS = ['REGISTRADO', 'PARCIAL_ACREDITADO', 'ACREDITADO', 'RECHAZADO_PARCIAL', 'RECHAZADO', 'ANULADO'];

function badgeFor(estado: string) {
  switch (estado) {
    case 'ACREDITADO':         return 'success' as const;
    case 'PARCIAL_ACREDITADO': return 'info' as const;
    case 'RECHAZADO':
    case 'RECHAZADO_PARCIAL':
    case 'ANULADO':            return 'danger' as const;
    default:                   return 'warning' as const;
  }
}

export function CobrosPage() {
  const [estado, setEstado] = useState('');
  const [page, setPage] = useState(1);
  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (estado) p.set('estado', estado);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [estado, page]);

  const { data, isLoading, error } = useApi<Paginator<Cobro>>(
    ['cobros', qs],
    `/api/erp/cobros${qs ? `?${qs}` : ''}`
  );

  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [verId, setVerId] = useState<number | null>(null);
  const [anularOpen, setAnularOpen] = useState<Cobro | null>(null);

  const columns: Column<Cobro>[] = [
    { key: 'fecha', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha) },
    { key: 'numero', header: 'Nº', width: '110px' },
    { key: 'auxiliar', header: 'Cliente', render: (r) => r.auxiliar?.nombre },
    { key: 'importe_total', header: 'Importe', align: 'right', width: '120px',
      render: (r) => `${r.moneda?.codigo} ${fmtMoney(r.importe_total)}` },
    { key: 'estado', header: 'Estado', width: '160px',
      render: (r) => <Badge variant={badgeFor(r.estado)}>{r.estado}</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '160px',
      render: (r) => (
        <div className="flex justify-end gap-1.5">
          <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setVerId(r.id); }}>
            <Eye className="w-3 h-3" />
          </Button>
          {r.estado !== 'ANULADO' && (
            <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setAnularOpen(r); }}>
              <Trash2 className="w-3 h-3" />
            </Button>
          )}
        </div>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Wallet className="w-4 h-4 text-azure" /> Cobros</div>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Nuevo cobro
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <SelectField label="Estado" value={estado} placeholder="Todos"
            onChange={(e) => { setEstado(e.target.value); setPage(1); }}
            containerClassName="w-[200px]"
            options={ESTADOS.map((s) => ({ value: s, label: s }))} />
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={columns} paginator={data} loading={isLoading} onPageChange={setPage} empty="Sin cobros" />
        </CardBody>
      </Card>

      {nuevoOpen && <NuevoCobroModal onClose={() => setNuevoOpen(false)} />}
      {verId && <DetalleCobroModal id={verId} onClose={() => setVerId(null)} />}
      {anularOpen && <AnularCobroModal cobro={anularOpen} onClose={() => setAnularOpen(null)} />}
    </div>
  );
}

type ItemBorrador = { tipo_item: string; concepto: string; importe: string; factura_id?: number };
type MedioBorrador = {
  medio_pago_id: string;
  caja_id?: string;
  cuenta_bancaria_id?: string;
  importe: string;
  referencia?: string;
  echeq?: { numero: string; cuit_librador: string; razon_social_librador: string; fecha_pago: string };
};

function NuevoCobroModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['cobros'], ['echeq']);

  const { data: auxiliares } = useApi<Auxiliar[]>(['auxiliares','clientes'], '/api/erp/auxiliares?tipo=Cliente');
  const { data: monedas }    = useApi<Moneda[]>(['monedas'], '/api/erp/monedas');
  const { data: medios }     = useApi<MedioPago[]>(['medios-pago'], '/api/erp/medios-pago');
  const { data: cajas }      = useApi<Caja[]>(['cajas'], '/api/erp/cajas');
  const { data: ctasBanco }  = useApi<CuentaBanco[]>(['cuentas-bancarias'], '/api/erp/cuentas-bancarias');

  const [form, setForm] = useState({
    fecha: new Date().toISOString().slice(0, 10),
    auxiliar_id: '', moneda_id: '', cotizacion: '1',
    concepto: '', observaciones: '',
  });
  const [items, setItems]   = useState<ItemBorrador[]>([{ tipo_item: 'OTRO', concepto: '', importe: '' }]);
  const [mediosLista, setMedios] = useState<MedioBorrador[]>([{ medio_pago_id: '', importe: '' }]);

  const totalItems  = items.reduce((s, it) => s + (Number(it.importe) || 0), 0);
  const totalMedios = mediosLista.reduce((s, m) => s + (Number(m.importe) || 0), 0);
  const balanceado = Math.abs(totalItems - totalMedios) < 0.01 && totalItems > 0;

  const m = useApiMutation<Cobro, Record<string, unknown>>(
    (vars) => api.post('/api/erp/cobros', vars),
    {
      onSuccess: () => {
        toast.success('Cobro registrado');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo registrar', errorMessage(e)),
    }
  );

  // v1.16 — modal de selección de items pendientes.
  const [pendientesModal, setPendientesModal] = useState<{ items: ItemCobrable[]; abierto: boolean }>({
    items: [],
    abierto: false,
  });
  const [seleccionPend, setSeleccionPend] = useState<Set<number>>(new Set());

  const cargarPendientes = useApiMutation<{ data: ItemCobrable[] } | ItemCobrable[], number>(
    (clienteId) => api.get(`/api/erp/cobros/items-cobrables?cliente_id=${clienteId}`),
    {
      onSuccess: (resp) => {
        const lista: ItemCobrable[] = Array.isArray(resp) ? resp : (resp.data ?? []);
        if (lista.length === 0) {
          toast.info('Cliente sin pendientes', 'No hay comprobantes cobrables.');
          return;
        }
        // Pre-seleccionar facturas/ND. NC quedan deseleccionadas (van por Imputar NC).
        const preSel = new Set<number>(lista.filter((i) => i.tipo !== 'nc').map((i) => i.id));
        setPendientesModal({ items: lista, abierto: true });
        setSeleccionPend(preSel);
      },
      onError: (e) => toast.error('No se pudo cargar', errorMessage(e)),
    }
  );

  const confirmarSeleccionPendientes = () => {
    const seleccionados = pendientesModal.items.filter(
      (it) => seleccionPend.has(it.id) && it.tipo !== 'nc',
    );
    if (seleccionados.length === 0) {
      toast.info('Sin selección', 'Elegí al menos una factura/ND para cargar.');
      return;
    }
    const nuevos: ItemBorrador[] = seleccionados.map((it) => ({
      tipo_item: it.tipo === 'nd' ? 'NOTA_DEBITO' : 'FACTURA_VENTA',
      concepto: `${it.label} (saldo ${fmtMoney(it.saldo)})`,
      importe: String(it.saldo.toFixed(2)),
      factura_id: it.id,
    }));
    const itemsBase = items.length === 1 && !items[0].concepto && !items[0].importe ? [] : items;
    setItems([...itemsBase, ...nuevos]);
    toast.success(`${nuevos.length} items cargados`,
      `Total pendiente: ${fmtMoney(seleccionados.reduce((s, i) => s + i.saldo, 0))}`);
    setPendientesModal({ items: [], abierto: false });
    setSeleccionPend(new Set());
  };

  // v1.15 ampliación (D-TS-3) — auto-derivar moneda según el medio elegido.
  // Si la cuenta bancaria o caja del primer medio tiene moneda definida y
  // la moneda del header está vacía, autocompletarla.
  useEffect(() => {
    if (form.moneda_id) return; // ya elegida — no pisar
    const primerMedio = mediosLista[0];
    if (!primerMedio?.medio_pago_id) return;
    const medio = (medios ?? []).find((mp) => String(mp.id) === primerMedio.medio_pago_id);
    if (!medio) return;
    let monedaId: number | null = null;
    if (medio.afecta_banco && primerMedio.cuenta_bancaria_id) {
      const cta = (ctasBanco ?? []).find((c) => String(c.id) === primerMedio.cuenta_bancaria_id);
      monedaId = cta?.moneda?.id ?? cta?.moneda_id ?? null;
    } else if (medio.afecta_caja && primerMedio.caja_id) {
      const c = (cajas ?? []).find((x) => String(x.id) === primerMedio.caja_id);
      monedaId = c?.moneda?.id ?? c?.moneda_id ?? null;
    }
    if (monedaId) {
      setForm((f) => ({ ...f, moneda_id: String(monedaId) }));
    }
  }, [mediosLista, medios, ctasBanco, cajas, form.moneda_id]);

  const submit = () => {
    m.mutate({
      fecha: form.fecha,
      auxiliar_id: Number(form.auxiliar_id),
      moneda_id: Number(form.moneda_id),
      cotizacion: Number(form.cotizacion) || 1,
      concepto: form.concepto || undefined,
      observaciones: form.observaciones || undefined,
      items: items.filter((i) => i.concepto && Number(i.importe) > 0).map((i) => ({
        tipo_item: i.tipo_item, concepto: i.concepto, importe: Number(i.importe),
        // v1.15 ampliación — factura_id viene cuando el item se cargó desde
        // "items pendientes". El backend (Sprint O+) lo usa para lockForUpdate
        // y validar saldo atómicamente.
        ...(i.factura_id ? { factura_id: i.factura_id } : {}),
      })),
      medios: mediosLista.filter((mm) => mm.medio_pago_id && Number(mm.importe) > 0).map((mm) => {
        const out: Record<string, unknown> = {
          medio_pago_id: Number(mm.medio_pago_id), importe: Number(mm.importe),
        };
        if (mm.caja_id) out.caja_id = Number(mm.caja_id);
        if (mm.cuenta_bancaria_id) out.cuenta_bancaria_id = Number(mm.cuenta_bancaria_id);
        if (mm.referencia) out.referencia = mm.referencia;
        if (mm.echeq) out.echeq = mm.echeq;
        return out;
      }),
    });
  };

  const valid = form.fecha && form.auxiliar_id && form.moneda_id && balanceado &&
    items.every((i) => !i.concepto || Number(i.importe) > 0) &&
    mediosLista.every((mm) => !mm.medio_pago_id || Number(mm.importe) > 0);

  return (
    <Modal
      open
      onClose={onClose}
      title="Nuevo cobro"
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending} onClick={submit}>
            {m.isPending ? 'Registrando…' : `Registrar (${fmtMoney(totalItems)})`}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-3 gap-3">
          <Field label="Fecha" type="date" required value={form.fecha}
            onChange={(e) => setForm({ ...form, fecha: e.target.value })} />
          <SelectField label="Cliente" required value={form.auxiliar_id}
            onChange={(e) => setForm({ ...form, auxiliar_id: e.target.value })}
            options={(auxiliares ?? []).map((a) => ({ value: a.id, label: `${a.codigo} ${a.nombre}` }))}
            placeholder="Elegí…" />
          <SelectField label="Moneda" required value={form.moneda_id}
            onChange={(e) => setForm({ ...form, moneda_id: e.target.value })}
            options={(monedas ?? []).map((mv) => ({ value: mv.id, label: mv.codigo }))}
            placeholder="—" />
        </div>
        <Field label="Concepto" value={form.concepto}
          onChange={(e) => setForm({ ...form, concepto: e.target.value })} />

        {/* Items */}
        <div>
          <div className="flex items-center justify-between mb-2">
            <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide">Items</h3>
            <div className="flex gap-2">
              {/* v1.15 ampliación — helper: trae las facturas/ND con saldo del cliente. */}
              <Button size="sm" variant="outline"
                disabled={!form.auxiliar_id || cargarPendientes.isPending}
                onClick={() => cargarPendientes.mutate(Number(form.auxiliar_id))}>
                <ListChecks className="w-3 h-3" />
                {cargarPendientes.isPending ? 'Cargando…' : 'Cargar pendientes'}
              </Button>
              <Button size="sm" variant="outline"
                onClick={() => setItems([...items, { tipo_item: 'OTRO', concepto: '', importe: '' }])}>
                <Plus className="w-3 h-3" /> Item
              </Button>
            </div>
          </div>
          <div className="space-y-2">
            {items.map((it, idx) => (
              <div key={idx} className="grid grid-cols-12 gap-2 items-end">
                <SelectField containerClassName="col-span-3" value={it.tipo_item}
                  placeholder={null}
                  onChange={(e) => {
                    const nu = [...items]; nu[idx].tipo_item = e.target.value; setItems(nu);
                  }}
                  options={[
                    { value: 'FACTURA_VENTA', label: 'Factura' },
                    { value: 'NOTA_DEBITO', label: 'Nota débito' },
                    { value: 'SEÑA', label: 'Seña' },
                    { value: 'OTRO', label: 'Otro' },
                  ]} />
                <Field containerClassName="col-span-7" placeholder="Concepto…" value={it.concepto}
                  onChange={(e) => {
                    const nu = [...items]; nu[idx].concepto = e.target.value; setItems(nu);
                  }} />
                <Field containerClassName="col-span-2" type="number" step="0.01" placeholder="Importe"
                  value={it.importe}
                  onChange={(e) => {
                    const nu = [...items]; nu[idx].importe = e.target.value; setItems(nu);
                  }} />
              </div>
            ))}
          </div>
        </div>

        {/* Medios */}
        <div>
          <div className="flex items-center justify-between mb-2">
            <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide">Medios de pago</h3>
            <Button size="sm" variant="outline"
              onClick={() => setMedios([...mediosLista, { medio_pago_id: '', importe: '' }])}>
              <Plus className="w-3 h-3" /> Medio
            </Button>
          </div>
          <div className="space-y-2">
            {mediosLista.map((mm, idx) => {
              const medio = (medios ?? []).find((mp) => String(mp.id) === mm.medio_pago_id);
              return (
                <div key={idx} className="grid grid-cols-12 gap-2 items-end">
                  <SelectField containerClassName="col-span-3" value={mm.medio_pago_id}
                    onChange={(e) => {
                      const nu = [...mediosLista]; nu[idx].medio_pago_id = e.target.value; setMedios(nu);
                    }}
                    options={(medios ?? []).map((mp) => ({ value: mp.id, label: mp.nombre }))}
                    placeholder="Medio…" />
                  {medio?.afecta_caja ? (
                    <SelectField containerClassName="col-span-3" value={mm.caja_id ?? ''}
                      onChange={(e) => {
                        const nu = [...mediosLista]; nu[idx].caja_id = e.target.value; setMedios(nu);
                      }}
                      options={(cajas ?? []).map((c) => ({ value: c.id, label: c.codigo }))}
                      placeholder="Caja" />
                  ) : medio?.afecta_banco ? (
                    <SelectField containerClassName="col-span-3" value={mm.cuenta_bancaria_id ?? ''}
                      onChange={(e) => {
                        const nu = [...mediosLista]; nu[idx].cuenta_bancaria_id = e.target.value; setMedios(nu);
                      }}
                      options={(ctasBanco ?? []).map((c) => ({ value: c.id, label: c.codigo }))}
                      placeholder="Cuenta" />
                  ) : <div className="col-span-3" />}
                  <Field containerClassName="col-span-4" placeholder="Referencia / Nº"
                    value={mm.referencia ?? ''}
                    onChange={(e) => {
                      const nu = [...mediosLista]; nu[idx].referencia = e.target.value; setMedios(nu);
                    }} />
                  <Field containerClassName="col-span-2" type="number" step="0.01" placeholder="Importe"
                    value={mm.importe}
                    onChange={(e) => {
                      const nu = [...mediosLista]; nu[idx].importe = e.target.value; setMedios(nu);
                    }} />
                </div>
              );
            })}
          </div>
        </div>

        <div className="flex items-center justify-between bg-surface-row border border-line rounded-md p-3 text-[12.5px]">
          <div>
            <strong>Items:</strong> {fmtMoney(totalItems)} ·{' '}
            <strong>Medios:</strong> {fmtMoney(totalMedios)}
          </div>
          <Badge variant={balanceado ? 'success' : 'warning'}>
            {balanceado ? 'Balanceado' : `Δ ${fmtMoney(totalMedios - totalItems)}`}
          </Badge>
        </div>

        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>

      {/* v1.16 — Modal secundario de selección de items pendientes. */}
      {pendientesModal.abierto && (
        <Modal open onClose={() => setPendientesModal({ items: [], abierto: false })}
          title="Items cobrables del cliente" size="lg"
          footer={<>
            <Button variant="secondary" onClick={() => setPendientesModal({ items: [], abierto: false })}>Cancelar</Button>
            <Button variant="primary" onClick={confirmarSeleccionPendientes}>
              <ListChecks className="w-3 h-3" />
              {`Agregar ${[...seleccionPend].filter((id) => pendientesModal.items.find((i) => i.id === id)?.tipo !== 'nc').length} items`}
            </Button>
          </>}>
          <div className="space-y-3">
            <div className="text-[11.5px] text-ink-muted">
              Seleccioná los comprobantes a cobrar. Las <strong>NC libres</strong> aparecen acá para
              tu referencia pero se imputan por la pantalla <code>/erp/cc-clientes/imputar-nc</code>
              (no entran como item del cobro).
            </div>
            <div className="border border-line rounded-md overflow-hidden">
              <table className="w-full text-[12px]">
                <thead className="bg-surface-row text-[11px] uppercase tracking-wider text-ink-muted">
                  <tr>
                    <th className="px-2 py-2 w-[36px]"></th>
                    <th className="px-2 py-2 text-left">Comprobante</th>
                    <th className="px-2 py-2 text-left">Fecha</th>
                    <th className="px-2 py-2 text-right">Total</th>
                    <th className="px-2 py-2 text-right">Saldo</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line/60">
                  {pendientesModal.items.map((it) => {
                    const isNc = it.tipo === 'nc';
                    const sel = seleccionPend.has(it.id);
                    return (
                      <tr key={it.id} className={isNc ? 'opacity-50' : ''}>
                        <td className="px-2 py-1.5">
                          <input type="checkbox" checked={sel} disabled={isNc}
                            onChange={() => {
                              const nu = new Set(seleccionPend);
                              nu.has(it.id) ? nu.delete(it.id) : nu.add(it.id);
                              setSeleccionPend(nu);
                            }} />
                        </td>
                        <td className="px-2 py-1.5">
                          <code className="text-[11px]">{it.label}</code>
                          {isNc && <span className="ml-2"><Badge variant="warning">NC</Badge></span>}
                        </td>
                        <td className="px-2 py-1.5">{fmtDate(it.fecha)}</td>
                        <td className="px-2 py-1.5 text-right">{fmtMoney(it.total)}</td>
                        <td className="px-2 py-1.5 text-right font-semibold">{fmtMoney(it.saldo)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <div className="text-[11.5px] text-ink-muted">
              {pendientesModal.items.filter((i) => i.tipo === 'nc').length > 0 && (
                <>NC del cliente: {pendientesModal.items.filter((i) => i.tipo === 'nc').length}.
                  Imputalas en <code>/erp/cc-clientes/imputar-nc</code> antes del cobro si querés netear el saldo.</>
              )}
            </div>
          </div>
        </Modal>
      )}
    </Modal>
  );
}

function DetalleCobroModal({ id, onClose }: { id: number; onClose: () => void }) {
  const { data, isLoading } = useApi<Cobro & { items?: ItemBorrador[]; medios?: MedioBorrador[] }>(
    ['cobros', id], `/api/erp/cobros/${id}`
  );

  return (
    <Modal open onClose={onClose} title={`Cobro #${data?.numero ?? id}`} size="lg">
      {isLoading ? (
        <div className="text-center py-8 text-ink-muted">Cargando…</div>
      ) : !data ? null : (
        <div className="space-y-3 text-[12.5px]">
          <div className="grid grid-cols-3 gap-3">
            <Info label="Fecha" value={fmtDate(data.fecha)} />
            <Info label="Cliente" value={data.auxiliar?.nombre} />
            <Info label="Importe" value={`${data.moneda?.codigo} ${fmtMoney(data.importe_total)}`} />
            <Info label="Estado" value={<Badge variant={badgeFor(data.estado)}>{data.estado}</Badge>} />
          </div>
          {data.concepto && <Info label="Concepto" value={data.concepto} />}
        </div>
      )}
    </Modal>
  );
}

function Info({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="text-[11px] text-ink-muted uppercase tracking-wide">{label}</div>
      <div className="text-[12.5px] text-ink-2">{value ?? '—'}</div>
    </div>
  );
}

function AnularCobroModal({ cobro, onClose }: { cobro: Cobro; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['cobros']);
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<Cobro, { motivo: string }>(
    (vars) => api.post(`/api/erp/cobros/${cobro.id}/anular`, vars),
    {
      onSuccess: () => {
        toast.success('Cobro anulado');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo anular', errorMessage(e)),
    }
  );
  return (
    <Modal
      open onClose={onClose}
      title={`Anular cobro ${cobro.numero}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={motivo.trim().length < 3 || m.isPending}
            onClick={() => m.mutate({ motivo: motivo.trim() })}>
            {m.isPending ? 'Anulando…' : 'Anular'}
          </Button>
        </>
      }
    >
      <Field label="Motivo" required value={motivo}
        onChange={(e) => setMotivo(e.target.value)} placeholder="Mín. 3 caracteres" />
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}
