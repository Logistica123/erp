import { useState } from 'react';
import { Loader2, FileText, ExternalLink } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { Link } from 'react-router-dom';

type Factura = {
  id: number;
  numero: number;
  cae: string | null;
  fecha_vto_cae: string | null;
  fecha_emision: string;
  imp_neto_gravado: string;
  imp_iva: string;
  imp_total: string;
  origen: string;
  estado: string;
  es_fce: number;
  tipo_codigo: string;
  tipo_nombre: string;
  letra: string | null;
  tipo_clase: string;
  tipo_signo: number;
  pto_vta: number;
  cliente_id: number;
  cliente_nombre: string;
  cliente_cuit: string | null;
  moneda: string;
  asiento_id: number | null;
  asiento_numero: number | null;
  asiento_estado: string | null;
};

type Resp = { data: Factura[] };

function estadoBadge(estado: string) {
  const map: Record<string, 'success' | 'warning' | 'danger' | 'default'> = {
    EMITIDA: 'success',
    COBRADA: 'success',
    CONTROLADA: 'success',
    PREPARADA: 'warning',
    COBRO_PARCIAL: 'warning',
    ANULADA_POR_NC: 'danger',
    RECHAZADA: 'danger',
    EMISION_FALLIDA: 'danger',
  };
  const variant = map[estado] ?? 'default';
  return <Badge variant={variant}>{estado}</Badge>;
}

function origenBadge(origen: string) {
  const map: Record<string, string> = {
    MANUAL: 'bg-indigo-500/10 text-indigo-700 ring-indigo-500/20',
    DISTRIAPP: 'bg-blue-500/10 text-blue-700 ring-blue-500/20',
    ARCA_IMPORT: 'bg-purple-500/10 text-purple-700 ring-purple-500/20',
    WSFE_ERP: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20',
    MIS_COMPROBANTES: 'bg-amber-500/10 text-amber-700 ring-amber-500/20',
  };
  const cls = map[origen] ?? 'bg-gray-500/10 text-gray-700 ring-gray-500/20';
  return (
    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${cls}`}>
      {origen}
    </span>
  );
}

function formatNro(tipo: string, letra: string | null, pv: number, nro: number): string {
  const lbl = letra ? `${tipo}-${letra}` : tipo;
  const pvStr = String(pv).padStart(4, '0');
  const nroStr = String(nro).padStart(8, '0');
  return `${lbl}  ${pvStr}-${nroStr}`;
}

export function FacturacionPage() {
  const [estado, setEstado] = useState<string>('');
  const [origen, setOrigen] = useState<string>('');

  const { data, isLoading, error } = useQuery<Resp>({
    queryKey: ['facturas-venta', { estado, origen }],
    queryFn: () => {
      const qs = new URLSearchParams();
      if (estado) qs.set('estado', estado);
      if (origen) qs.set('origen', origen);
      const suf = qs.toString() ? `?${qs.toString()}` : '';
      return api.get<Resp>(`/api/erp/facturas-venta${suf}`);
    },
  });

  const facturas = data?.data ?? [];
  const totales = facturas.reduce(
    (acc, f) => {
      const signo = f.tipo_signo ?? 1;
      const imp = parseFloat(f.imp_total) * signo;
      return {
        cant: acc.cant + 1,
        total: acc.total + imp,
        iva: acc.iva + parseFloat(f.imp_iva) * signo,
      };
    },
    { cant: 0, total: 0, iva: 0 }
  );

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2 text-[13px] text-gray-500">
        <span>ERP</span> <span>›</span> <span>Fiscal</span> <span>›</span>
        <span className="text-gray-800 font-medium">Facturación</span>
      </div>

      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Facturación (ARCA)</h1>
          <p className="text-sm text-gray-500 mt-1">
            Facturas emitidas por el ERP, sincronizadas desde DistriApp o importadas vía ARCA.
          </p>
        </div>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <Card>
          <CardBody>
            <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">
              Facturas en lista
            </div>
            <div className="mt-2 text-2xl font-bold text-gray-900">{totales.cant}</div>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">
              Total neto
            </div>
            <div className="mt-2 text-2xl font-bold text-gray-900">
              {fmtMoney(totales.total - totales.iva)}
            </div>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">
              IVA Débito Fiscal
            </div>
            <div className="mt-2 text-2xl font-bold text-gray-900">{fmtMoney(totales.iva)}</div>
          </CardBody>
        </Card>
      </div>

      {/* Tabla */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between gap-3 flex-wrap">
            <div className="font-semibold text-gray-900 flex items-center gap-2">
              <FileText className="w-4 h-4 text-azure" /> Listado
            </div>
            <div className="flex items-center gap-2">
              <select
                value={estado}
                onChange={(e) => setEstado(e.target.value)}
                className="text-sm border rounded-md px-2 py-1 bg-white"
              >
                <option value="">Todos los estados</option>
                <option value="EMITIDA">EMITIDA</option>
                <option value="COBRADA">COBRADA</option>
                <option value="COBRO_PARCIAL">COBRO_PARCIAL</option>
                <option value="PREPARADA">PREPARADA</option>
                <option value="EMISION_FALLIDA">EMISION_FALLIDA</option>
              </select>
              <select
                value={origen}
                onChange={(e) => setOrigen(e.target.value)}
                className="text-sm border rounded-md px-2 py-1 bg-white"
              >
                <option value="">Todos los orígenes</option>
                <option value="MANUAL">MANUAL</option>
                <option value="DISTRIAPP">DISTRIAPP</option>
                <option value="WSFE_ERP">WSFE_ERP</option>
                <option value="ARCA_IMPORT">ARCA_IMPORT</option>
              </select>
            </div>
          </div>
        </CardHeader>
        <CardBody className="p-0">
          {isLoading ? (
            <div className="p-8 flex items-center justify-center gap-2 text-gray-500 text-sm">
              <Loader2 className="w-4 h-4 animate-spin" /> Cargando...
            </div>
          ) : error ? (
            <div className="p-8 text-center text-sm text-red-600">Error al cargar facturas.</div>
          ) : facturas.length === 0 ? (
            <div className="p-8 text-center text-sm text-gray-500">Sin facturas en el filtro.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 text-left text-[11px] font-semibold uppercase text-gray-500 tracking-wider">
                  <tr>
                    <th className="px-4 py-3">Fecha</th>
                    <th className="px-4 py-3">Comprobante</th>
                    <th className="px-4 py-3">Cliente</th>
                    <th className="px-4 py-3">CAE</th>
                    <th className="px-4 py-3 text-right">Neto</th>
                    <th className="px-4 py-3 text-right">IVA</th>
                    <th className="px-4 py-3 text-right">Total</th>
                    <th className="px-4 py-3">Origen</th>
                    <th className="px-4 py-3">Estado</th>
                    <th className="px-4 py-3">Asiento</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {facturas.map((f) => (
                    <tr key={f.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-gray-700 whitespace-nowrap">
                        {f.fecha_emision?.slice(0, 10)}
                      </td>
                      <td className="px-4 py-3 font-mono text-[12px] text-gray-800 whitespace-nowrap">
                        {formatNro(f.tipo_codigo, f.letra, f.pto_vta, f.numero)}
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900">{f.cliente_nombre}</div>
                        {f.cliente_cuit && (
                          <div className="text-[11px] text-gray-500 font-mono">{f.cliente_cuit}</div>
                        )}
                      </td>
                      <td className="px-4 py-3 font-mono text-[11px] text-gray-700">
                        {f.cae ?? '—'}
                      </td>
                      <td className="px-4 py-3 text-right font-mono text-gray-700">
                        {fmtMoney(parseFloat(f.imp_neto_gravado))}
                      </td>
                      <td className="px-4 py-3 text-right font-mono text-gray-700">
                        {fmtMoney(parseFloat(f.imp_iva))}
                      </td>
                      <td className="px-4 py-3 text-right font-mono font-semibold text-gray-900">
                        {fmtMoney(parseFloat(f.imp_total))}
                      </td>
                      <td className="px-4 py-3">{origenBadge(f.origen)}</td>
                      <td className="px-4 py-3">{estadoBadge(f.estado)}</td>
                      <td className="px-4 py-3">
                        {f.asiento_id ? (
                          <Link
                            to={`/erp/libro-diario?asiento=${f.asiento_id}`}
                            className="inline-flex items-center gap-1 text-[12px] text-azure hover:underline"
                          >
                            #{f.asiento_numero}
                            <ExternalLink className="w-3 h-3" />
                          </Link>
                        ) : (
                          <span className="text-[12px] text-gray-400">—</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
