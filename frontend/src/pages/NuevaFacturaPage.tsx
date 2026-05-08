import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, Loader2, Plus, Receipt, Trash2 } from 'lucide-react';
import { useQuery, useMutation } from '@tanstack/react-query';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { api } from '@/lib/api';

type Catalogos = {
  clientes: {
    id: number; nombre: string; cuit: string | null; codigo: string;
    centro_costo_id: number | null; centro_costo_codigo: string | null;
  }[];
  tipos_comprobante: {
    id: number;
    codigo_interno: string;
    nombre: string;
    letra: string | null;
    clase: string;
    discrimina_iva: number;
  }[];
  puntos_venta: { id: number; numero: number; nombre: string; tipo_emision: string }[];
  alicuotas_iva: { id: number; codigo_interno: string; nombre: string; tasa: string }[];
  monedas: { id: number; codigo: string; nombre: string; simbolo: string | null }[];
  jurisdicciones: { codigo: string; nombre: string }[];
};

type EmitirResp = {
  message: string;
  factura_id: number;
  cae: string;
  cae_vto: string | null;
  numero: number;
  pto_vta: number;
  tipo_codigo: string;
  letra: string | null;
  imp_neto: number;
  imp_iva: number;
  imp_total: number;
  observaciones: { code: number; msg: string }[];
};

type Item = {
  descripcion: string;
  cantidad: string;
  precio_unit: string;
  alicuota_iva_id: number;
};

const today = () => new Date().toISOString().slice(0, 10);

// Addendum v1.14: default = mes anterior (caso típico de servicios logísticos
// facturados a posteriori). YYYY-MM.
const defaultPeriodoTrabajado = () => {
  const d = new Date();
  d.setMonth(d.getMonth() - 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

const itemVacio = (alicuotaIvaId: number): Item => ({
  descripcion: '',
  cantidad: '1',
  precio_unit: '',
  alicuota_iva_id: alicuotaIvaId,
});

export function NuevaFacturaPage() {
  const navigate = useNavigate();
  const [result, setResult] = useState<EmitirResp | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [header, setHeader] = useState({
    cliente_id: 0,
    tipo_comprobante_id: 0,
    punto_venta_id: 0,
    concepto_afip: 2,
    fecha_emision: today(),
    moneda_id: 1,
    // Addendum v1.14
    periodo_trabajado_texto: defaultPeriodoTrabajado(),
    jurisdiccion_codigo: '',
  });
  const [items, setItems] = useState<Item[]>([itemVacio(0)]);

  const { data: cats } = useQuery<Catalogos>({
    queryKey: ['fv-catalogos'],
    queryFn: () => api.get<Catalogos>('/api/erp/facturas-venta/catalogos'),
  });

  useEffect(() => {
    if (!cats) return;
    const defaultAlic =
      cats.alicuotas_iva.find((a) => a.codigo_interno === 'IVA_21')?.id ??
      cats.alicuotas_iva[0]?.id ??
      0;

    setHeader((h) => ({
      ...h,
      cliente_id: h.cliente_id || cats.clientes[0]?.id || 0,
      tipo_comprobante_id:
        h.tipo_comprobante_id ||
        cats.tipos_comprobante.find((t) => t.codigo_interno === 'FB')?.id ||
        cats.tipos_comprobante[0]?.id ||
        0,
      punto_venta_id: h.punto_venta_id || cats.puntos_venta[0]?.id || 0,
    }));
    setItems((prev) =>
      prev.map((it) => (it.alicuota_iva_id === 0 ? { ...it, alicuota_iva_id: defaultAlic } : it))
    );
  }, [cats]);

  const tipoCbte = cats?.tipos_comprobante.find((t) => t.id === header.tipo_comprobante_id);
  const esLetraC = tipoCbte?.letra === 'C';

  const calc = useMemo(() => {
    let neto = 0;
    let iva = 0;
    for (const it of items) {
      const n = (parseFloat(it.cantidad) || 0) * (parseFloat(it.precio_unit) || 0);
      const alic = cats?.alicuotas_iva.find((a) => a.id === it.alicuota_iva_id);
      const tasa = alic ? parseFloat(alic.tasa) : 0;
      neto += n;
      if (!esLetraC) iva += n * tasa;
    }
    return {
      neto: +neto.toFixed(2),
      iva: +iva.toFixed(2),
      total: +(neto + iva).toFixed(2),
    };
  }, [items, cats, esLetraC]);

  const mutation = useMutation<EmitirResp, Error, { header: typeof header; items: Item[] }>({
    mutationFn: (payload) =>
      api.post<EmitirResp>('/api/erp/facturas-venta/emitir', {
        ...payload.header,
        items: payload.items.map((i) => ({
          descripcion: i.descripcion,
          cantidad: i.cantidad,
          precio_unit: i.precio_unit,
          alicuota_iva_id: i.alicuota_iva_id,
        })),
      }),
    onSuccess: (data) => { setResult(data); setError(null); },
    onError: (e) => setError(e.message),
  });

  const onSubmit = (ev: React.FormEvent) => {
    ev.preventDefault();
    setError(null);
    // Validar items
    const invalid = items.some(
      (i) => !i.descripcion.trim() || parseFloat(i.cantidad) <= 0 || parseFloat(i.precio_unit) < 0
    );
    if (invalid) { setError('Revisá los ítems: descripción, cantidad > 0 y precio >= 0.'); return; }
    mutation.mutate({ header, items });
  };

  const addItem = () => {
    const alic = cats?.alicuotas_iva.find((a) => a.codigo_interno === 'IVA_21')?.id ??
      cats?.alicuotas_iva[0]?.id ?? 0;
    setItems([...items, itemVacio(alic)]);
  };
  const removeItem = (idx: number) => {
    setItems(items.length === 1 ? [itemVacio(items[0].alicuota_iva_id)] : items.filter((_, i) => i !== idx));
  };
  const updateItem = (idx: number, patch: Partial<Item>) => {
    setItems(items.map((it, i) => (i === idx ? { ...it, ...patch } : it)));
  };

  if (result) {
    return (
      <div className="space-y-4 max-w-3xl">
        <div className="flex items-center gap-2 text-[13px] text-gray-500">
          <Link to="/erp/facturacion" className="hover:text-gray-700">Facturación</Link>
          <span>›</span><span className="text-gray-800 font-medium">Nueva</span>
        </div>
        <Card><CardBody className="p-8 text-center space-y-4">
          <CheckCircle2 className="w-16 h-16 text-emerald-500 mx-auto" strokeWidth={1.5} />
          <div>
            <h2 className="text-2xl font-bold text-gray-900">¡Factura emitida!</h2>
            <p className="text-gray-500 mt-1">
              {result.tipo_codigo}-{result.letra ?? ''}{' '}
              <span className="font-mono">
                {String(result.pto_vta).padStart(4, '0')}-{String(result.numero).padStart(8, '0')}
              </span>
            </p>
          </div>
          <div className="grid grid-cols-2 gap-4 max-w-md mx-auto text-left border rounded-md p-4 bg-gray-50">
            <div>
              <div className="text-xs text-gray-500 uppercase">CAE</div>
              <div className="font-mono text-sm font-semibold">{result.cae}</div>
            </div>
            <div>
              <div className="text-xs text-gray-500 uppercase">Vto CAE</div>
              <div className="font-mono text-sm font-semibold">{result.cae_vto ?? '—'}</div>
            </div>
            <div>
              <div className="text-xs text-gray-500 uppercase">Neto</div>
              <div className="font-mono text-sm">{fmtMoney(result.imp_neto)}</div>
            </div>
            <div>
              <div className="text-xs text-gray-500 uppercase">IVA</div>
              <div className="font-mono text-sm">{fmtMoney(result.imp_iva)}</div>
            </div>
            <div className="col-span-2 pt-2 border-t">
              <div className="text-xs text-gray-500 uppercase">Total</div>
              <div className="font-mono text-xl font-bold text-gray-900">{fmtMoney(result.imp_total)}</div>
            </div>
          </div>
          {result.observaciones.length > 0 && (
            <div className="text-sm text-amber-600 text-left max-w-md mx-auto">
              <div className="font-semibold mb-1">Observaciones AFIP:</div>
              {result.observaciones.map((o, i) => <div key={i}>• [{o.code}] {o.msg}</div>)}
            </div>
          )}
          <div className="flex justify-center gap-3 pt-2">
            <Button variant="outline" onClick={() => navigate('/erp/facturacion')}>Ver listado</Button>
            <Button onClick={() => {
              setResult(null);
              const alic = cats?.alicuotas_iva.find((a) => a.codigo_interno === 'IVA_21')?.id ??
                cats?.alicuotas_iva[0]?.id ?? 0;
              setItems([itemVacio(alic)]);
            }}>Emitir otra</Button>
          </div>
        </CardBody></Card>
      </div>
    );
  }

  return (
    <div className="space-y-4 max-w-4xl">
      <div className="flex items-center gap-2 text-[13px] text-gray-500">
        <Link to="/erp/facturacion" className="hover:text-gray-700">Facturación</Link>
        <span>›</span><span className="text-gray-800 font-medium">Nueva factura</span>
      </div>

      <div className="flex items-center gap-3">
        <Link to="/erp/facturacion" className="text-gray-400 hover:text-gray-700">
          <ArrowLeft className="w-5 h-5" />
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <Receipt className="w-6 h-6 text-azure" /> Nueva factura
          </h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Emisión directa vía ARCA Gateway. CAE en línea.
          </p>
        </div>
      </div>

      <Card>
        <CardBody>
          <form onSubmit={onSubmit} className="space-y-5">
            {/* Cabecera */}
            <div className="grid grid-cols-2 gap-4">
              <div className="col-span-2">
                <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Cliente</label>
                <select
                  value={header.cliente_id}
                  onChange={(e) => setHeader({ ...header, cliente_id: +e.target.value })}
                  className="w-full border rounded-md px-3 py-2 text-sm" required
                >
                  <option value={0} disabled>Seleccionar cliente...</option>
                  {cats?.clientes.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.nombre} {c.cuit ? `(${c.cuit})` : ''}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Tipo comprobante</label>
                <select
                  value={header.tipo_comprobante_id}
                  onChange={(e) => setHeader({ ...header, tipo_comprobante_id: +e.target.value })}
                  className="w-full border rounded-md px-3 py-2 text-sm" required
                >
                  {cats?.tipos_comprobante.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.codigo_interno} {t.letra ? `(${t.letra})` : ''} — {t.nombre}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Punto de venta</label>
                <select
                  value={header.punto_venta_id}
                  onChange={(e) => setHeader({ ...header, punto_venta_id: +e.target.value })}
                  className="w-full border rounded-md px-3 py-2 text-sm" required
                >
                  {cats?.puntos_venta.map((p) => (
                    <option key={p.id} value={p.id}>
                      {String(p.numero).padStart(4, '0')} — {p.nombre}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Fecha emisión</label>
                <input
                  type="date" value={header.fecha_emision}
                  onChange={(e) => setHeader({ ...header, fecha_emision: e.target.value })}
                  className="w-full border rounded-md px-3 py-2 text-sm" required
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Concepto AFIP</label>
                <select
                  value={header.concepto_afip}
                  onChange={(e) => setHeader({ ...header, concepto_afip: +e.target.value })}
                  className="w-full border rounded-md px-3 py-2 text-sm"
                >
                  <option value={1}>1 — Productos</option>
                  <option value={2}>2 — Servicios</option>
                  <option value={3}>3 — Productos y servicios</option>
                </select>
              </div>

              {/* Addendum v1.14 — período trabajado + jurisdicción + CC derivado */}
              <div>
                <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                  Período trabajado
                </label>
                <input
                  type="text" value={header.periodo_trabajado_texto}
                  onChange={(e) => setHeader({ ...header, periodo_trabajado_texto: e.target.value })}
                  placeholder="2026-03 o 2026-03-Q1"
                  className="w-full border rounded-md px-3 py-2 text-sm"
                />
                <div className="text-[11px] text-gray-500 mt-1">
                  YYYY-MM (mensual) o YYYY-MM-Q1/Q2 (quincenal)
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                  Jurisdicción IIBB
                </label>
                <select
                  value={header.jurisdiccion_codigo}
                  onChange={(e) => setHeader({ ...header, jurisdiccion_codigo: e.target.value })}
                  className="w-full border rounded-md px-3 py-2 text-sm"
                >
                  <option value="">— sin jurisdicción —</option>
                  {cats?.jurisdicciones?.map((j) => (
                    <option key={j.codigo} value={j.codigo}>
                      {j.codigo} — {j.nombre}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {/* CC derivado del cliente — read-only */}
            {(() => {
              const c = cats?.clientes.find((x) => x.id === header.cliente_id);
              if (!c) return null;
              return (
                <div className="text-[11.5px] text-gray-600 bg-gray-50 border border-gray-200 rounded-md px-3 py-2">
                  Centro de Costos asociado al cliente:{' '}
                  {c.centro_costo_codigo ? (
                    <span className="font-mono font-semibold text-gray-800">
                      {c.centro_costo_codigo} — {c.nombre}
                    </span>
                  ) : (
                    <span className="text-amber-600 italic">— sin CC (se creará al emitir) —</span>
                  )}
                </div>
              );
            })()}

            {/* Items */}
            <div>
              <div className="flex items-center justify-between mb-2">
                <label className="block text-xs font-semibold text-gray-600 uppercase">
                  Ítems de la factura
                </label>
                <Button type="button" variant="outline" size="sm" onClick={addItem}>
                  <Plus className="w-3 h-3" /> Agregar línea
                </Button>
              </div>

              <div className="overflow-x-auto border rounded-md">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 text-[11px] uppercase text-gray-500 tracking-wider">
                    <tr>
                      <th className="px-2 py-2 text-left">Descripción</th>
                      <th className="px-2 py-2 text-right w-[100px]">Cantidad</th>
                      <th className="px-2 py-2 text-right w-[140px]">Precio unit.</th>
                      <th className="px-2 py-2 text-left w-[160px]">Alícuota IVA</th>
                      <th className="px-2 py-2 text-right w-[120px]">Subtotal</th>
                      <th className="px-2 py-2 w-[40px]"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {items.map((it, idx) => {
                      const sub = (parseFloat(it.cantidad) || 0) * (parseFloat(it.precio_unit) || 0);
                      return (
                        <tr key={idx}>
                          <td className="px-2 py-1">
                            <input
                              type="text" value={it.descripcion}
                              onChange={(e) => updateItem(idx, { descripcion: e.target.value })}
                              className="w-full border-0 px-2 py-1 text-sm focus:ring-1 focus:ring-azure rounded"
                              placeholder="Descripción del ítem"
                              required
                            />
                          </td>
                          <td className="px-2 py-1">
                            <input
                              type="number" step="0.01" min="0.01" value={it.cantidad}
                              onChange={(e) => updateItem(idx, { cantidad: e.target.value })}
                              className="w-full border-0 px-2 py-1 text-sm font-mono text-right focus:ring-1 focus:ring-azure rounded"
                              required
                            />
                          </td>
                          <td className="px-2 py-1">
                            <input
                              type="number" step="0.01" min="0" value={it.precio_unit}
                              onChange={(e) => updateItem(idx, { precio_unit: e.target.value })}
                              className="w-full border-0 px-2 py-1 text-sm font-mono text-right focus:ring-1 focus:ring-azure rounded"
                              placeholder="0.00"
                              required
                            />
                          </td>
                          <td className="px-2 py-1">
                            <select
                              value={it.alicuota_iva_id}
                              onChange={(e) => updateItem(idx, { alicuota_iva_id: +e.target.value })}
                              className="w-full border-0 px-2 py-1 text-sm focus:ring-1 focus:ring-azure rounded"
                              required
                            >
                              {cats?.alicuotas_iva.map((a) => (
                                <option key={a.id} value={a.id}>{a.nombre}</option>
                              ))}
                            </select>
                          </td>
                          <td className="px-2 py-1 text-right font-mono text-gray-700">
                            {fmtMoney(+sub.toFixed(2))}
                          </td>
                          <td className="px-2 py-1 text-center">
                            <button type="button" onClick={() => removeItem(idx)}
                              className="text-gray-400 hover:text-red-500"
                              title="Quitar línea">
                              <Trash2 className="w-4 h-4" />
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Totales */}
            <div className="bg-gray-50 rounded-md p-4 flex items-center justify-end gap-6 font-mono">
              <div><span className="text-xs text-gray-500">NETO</span>
                <span className="ml-2 text-lg">{fmtMoney(calc.neto)}</span></div>
              <div><span className="text-xs text-gray-500">IVA</span>
                <span className="ml-2 text-lg">
                  {esLetraC ? '— no aplica' : fmtMoney(calc.iva)}
                </span></div>
              <div><span className="text-xs text-gray-500">TOTAL</span>
                <span className="ml-2 text-xl font-bold text-gray-900">{fmtMoney(calc.total)}</span></div>
            </div>

            {error && (
              <div className="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                {error}
              </div>
            )}

            <div className="flex justify-end gap-3 pt-2">
              <Link to="/erp/facturacion"><Button variant="outline" type="button">Cancelar</Button></Link>
              <Button type="submit" disabled={mutation.isPending || calc.total <= 0}>
                {mutation.isPending ? (
                  <><Loader2 className="w-4 h-4 animate-spin mr-2" /> Emitiendo...</>
                ) : (
                  <>Emitir CAE</>
                )}
              </Button>
            </div>
          </form>
        </CardBody>
      </Card>
    </div>
  );
}
