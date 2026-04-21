import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, Loader2, Receipt } from 'lucide-react';
import { useQuery, useMutation } from '@tanstack/react-query';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { api } from '@/lib/api';

type Catalogos = {
  clientes: { id: number; nombre: string; cuit: string | null; codigo: string }[];
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

const today = () => new Date().toISOString().slice(0, 10);

export function NuevaFacturaPage() {
  const navigate = useNavigate();
  const [result, setResult] = useState<EmitirResp | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [form, setForm] = useState({
    cliente_id: 0,
    tipo_comprobante_id: 0,
    punto_venta_id: 0,
    concepto_afip: 2,
    fecha_emision: today(),
    descripcion: 'Servicios de distribución',
    cantidad: '1',
    precio_unit: '',
    alicuota_iva_id: 0,
    moneda_id: 1,
  });

  const { data: cats } = useQuery<Catalogos>({
    queryKey: ['fv-catalogos'],
    queryFn: () => api.get<Catalogos>('/api/erp/facturas-venta/catalogos'),
  });

  // Defaults cuando llegan los catálogos
  useEffect(() => {
    if (!cats) return;
    setForm((f) => ({
      ...f,
      cliente_id: f.cliente_id || cats.clientes[0]?.id || 0,
      tipo_comprobante_id:
        f.tipo_comprobante_id ||
        cats.tipos_comprobante.find((t) => t.codigo_interno === 'FB')?.id ||
        cats.tipos_comprobante[0]?.id ||
        0,
      punto_venta_id: f.punto_venta_id || cats.puntos_venta[0]?.id || 0,
      alicuota_iva_id:
        f.alicuota_iva_id ||
        cats.alicuotas_iva.find((a) => a.codigo_interno === 'IVA_21')?.id ||
        cats.alicuotas_iva[0]?.id ||
        0,
    }));
  }, [cats]);

  // Cálculos live
  const calc = useMemo(() => {
    const cant = parseFloat(form.cantidad) || 0;
    const precio = parseFloat(form.precio_unit) || 0;
    const neto = +(cant * precio).toFixed(2);
    const tipo = cats?.tipos_comprobante.find((t) => t.id === form.tipo_comprobante_id);
    const alic = cats?.alicuotas_iva.find((a) => a.id === form.alicuota_iva_id);
    const tasa = alic ? parseFloat(alic.tasa) : 0;
    const esLetraC = tipo?.letra === 'C';
    const iva = esLetraC ? 0 : +(neto * tasa).toFixed(2);
    const total = +(neto + iva).toFixed(2);
    const discrimina = !!tipo?.discrimina_iva;
    return { neto, iva, total, discrimina, tasa, esLetraC };
  }, [form, cats]);

  const mutation = useMutation<EmitirResp, Error, typeof form>({
    mutationFn: (payload) =>
      api.post<EmitirResp>('/api/erp/facturas-venta/emitir', payload),
    onSuccess: (data) => {
      setResult(data);
      setError(null);
    },
    onError: (e) => setError(e.message),
  });

  const onSubmit = (ev: React.FormEvent) => {
    ev.preventDefault();
    setError(null);
    mutation.mutate(form);
  };

  if (result) {
    return (
      <div className="space-y-4 max-w-3xl">
        <div className="flex items-center gap-2 text-[13px] text-gray-500">
          <Link to="/erp/facturacion" className="hover:text-gray-700">Facturación</Link>
          <span>›</span>
          <span className="text-gray-800 font-medium">Nueva</span>
        </div>

        <Card>
          <CardBody className="p-8 text-center space-y-4">
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
                <div className="font-mono text-xl font-bold text-gray-900">
                  {fmtMoney(result.imp_total)}
                </div>
              </div>
            </div>
            {result.observaciones.length > 0 && (
              <div className="text-sm text-amber-600 text-left max-w-md mx-auto">
                <div className="font-semibold mb-1">Observaciones AFIP:</div>
                {result.observaciones.map((o, i) => (
                  <div key={i}>• [{o.code}] {o.msg}</div>
                ))}
              </div>
            )}
            <div className="flex justify-center gap-3 pt-2">
              <Button variant="outline" onClick={() => navigate('/erp/facturacion')}>
                Ver listado
              </Button>
              <Button onClick={() => { setResult(null); setForm((f) => ({ ...f, precio_unit: '' })); }}>
                Emitir otra
              </Button>
            </div>
          </CardBody>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-4 max-w-3xl">
      <div className="flex items-center gap-2 text-[13px] text-gray-500">
        <Link to="/erp/facturacion" className="hover:text-gray-700">Facturación</Link>
        <span>›</span>
        <span className="text-gray-800 font-medium">Nueva factura</span>
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
            Emisión directa vía ARCA Gateway. El CAE se obtiene en línea.
          </p>
        </div>
      </div>

      <Card>
        <CardBody>
          <form onSubmit={onSubmit} className="grid grid-cols-2 gap-4">
            {/* Cliente */}
            <div className="col-span-2">
              <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                Cliente
              </label>
              <select
                value={form.cliente_id}
                onChange={(e) => setForm({ ...form, cliente_id: +e.target.value })}
                className="w-full border rounded-md px-3 py-2 text-sm"
                required
              >
                <option value={0} disabled>Seleccionar cliente...</option>
                {cats?.clientes.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.nombre} {c.cuit ? `(${c.cuit})` : ''}
                  </option>
                ))}
              </select>
            </div>

            {/* Tipo */}
            <div>
              <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                Tipo comprobante
              </label>
              <select
                value={form.tipo_comprobante_id}
                onChange={(e) => setForm({ ...form, tipo_comprobante_id: +e.target.value })}
                className="w-full border rounded-md px-3 py-2 text-sm"
                required
              >
                {cats?.tipos_comprobante.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.codigo_interno} {t.letra ? `(${t.letra})` : ''} — {t.nombre}
                  </option>
                ))}
              </select>
            </div>

            {/* PV */}
            <div>
              <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                Punto de venta
              </label>
              <select
                value={form.punto_venta_id}
                onChange={(e) => setForm({ ...form, punto_venta_id: +e.target.value })}
                className="w-full border rounded-md px-3 py-2 text-sm"
                required
              >
                {cats?.puntos_venta.map((p) => (
                  <option key={p.id} value={p.id}>
                    {String(p.numero).padStart(4, '0')} — {p.nombre}
                  </option>
                ))}
              </select>
            </div>

            {/* Fecha */}
            <div>
              <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                Fecha emisión
              </label>
              <input
                type="date"
                value={form.fecha_emision}
                onChange={(e) => setForm({ ...form, fecha_emision: e.target.value })}
                className="w-full border rounded-md px-3 py-2 text-sm"
                required
              />
            </div>

            {/* Concepto */}
            <div>
              <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                Concepto AFIP
              </label>
              <select
                value={form.concepto_afip}
                onChange={(e) => setForm({ ...form, concepto_afip: +e.target.value })}
                className="w-full border rounded-md px-3 py-2 text-sm"
              >
                <option value={1}>1 — Productos</option>
                <option value={2}>2 — Servicios</option>
                <option value={3}>3 — Productos y servicios</option>
              </select>
            </div>

            {/* Descripción */}
            <div className="col-span-2">
              <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                Descripción
              </label>
              <input
                type="text"
                value={form.descripcion}
                onChange={(e) => setForm({ ...form, descripcion: e.target.value })}
                className="w-full border rounded-md px-3 py-2 text-sm"
                required
              />
            </div>

            {/* Cantidad / precio / alícuota */}
            <div className="col-span-2 grid grid-cols-3 gap-3">
              <div>
                <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                  Cantidad
                </label>
                <input
                  type="number"
                  step="0.01"
                  min="0.01"
                  value={form.cantidad}
                  onChange={(e) => setForm({ ...form, cantidad: e.target.value })}
                  className="w-full border rounded-md px-3 py-2 text-sm font-mono"
                  required
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                  Precio unitario
                </label>
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  value={form.precio_unit}
                  onChange={(e) => setForm({ ...form, precio_unit: e.target.value })}
                  className="w-full border rounded-md px-3 py-2 text-sm font-mono"
                  placeholder="0.00"
                  required
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                  Alícuota IVA
                </label>
                <select
                  value={form.alicuota_iva_id}
                  onChange={(e) => setForm({ ...form, alicuota_iva_id: +e.target.value })}
                  className="w-full border rounded-md px-3 py-2 text-sm"
                  required
                >
                  {cats?.alicuotas_iva.map((a) => (
                    <option key={a.id} value={a.id}>{a.nombre}</option>
                  ))}
                </select>
              </div>
            </div>

            {/* Totales calculados */}
            <div className="col-span-2 bg-gray-50 rounded-md p-4 flex items-center justify-between font-mono">
              <div>
                <span className="text-xs text-gray-500">NETO</span>
                <span className="ml-2 text-lg">{fmtMoney(calc.neto)}</span>
              </div>
              <div>
                <span className="text-xs text-gray-500">IVA</span>
                <span className="ml-2 text-lg">
                  {calc.esLetraC ? '— no aplica' : fmtMoney(calc.iva)}
                </span>
              </div>
              <div>
                <span className="text-xs text-gray-500">TOTAL</span>
                <span className="ml-2 text-xl font-bold text-gray-900">{fmtMoney(calc.total)}</span>
              </div>
            </div>

            {error && (
              <div className="col-span-2 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                {error}
              </div>
            )}

            <div className="col-span-2 flex justify-end gap-3 pt-2">
              <Link to="/erp/facturacion">
                <Button variant="outline" type="button">Cancelar</Button>
              </Link>
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
