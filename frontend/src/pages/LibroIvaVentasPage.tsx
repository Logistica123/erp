import { useState } from 'react';
import { Loader2, Receipt, FileSpreadsheet } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { fmtMoney } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

type Comprobante = {
  id: number;
  fecha_emision: string;
  numero: number;
  cae: string | null;
  doc_tipo_afip: number | null;
  doc_nro: string | null;
  imp_neto_gravado: string;
  imp_no_gravado: string;
  imp_exento: string;
  imp_iva: string;
  imp_tributos: string;
  imp_total: string;
  origen: string;
  asiento_id: number | null;
  tipo_codigo: string;
  letra: string | null;
  tipo_signo: number;
  tipo_clase: string;
  pto_vta: number;
  cliente_nombre: string;
  cliente_cuit: string | null;
  condicion_iva: string | null;
};

type PorAlicuota = {
  codigo_interno: string;
  alicuota_nombre: string;
  tasa: string;
  base_total: string;
  iva_total: string;
  cant_cbtes: number;
};

type PorTipo = {
  tipo_codigo: string;
  letra: string | null;
  cant: number;
  neto: number;
  iva: number;
  total: number;
};

type Totales = {
  cant: number;
  neto: number;
  no_gravado: number;
  exento: number;
  iva: number;
  tributos: number;
  total: number;
};

type Resp = {
  periodo: { desde: string; hasta: string };
  comprobantes: Comprobante[];
  por_alicuota: PorAlicuota[];
  por_tipo: PorTipo[];
  totales: Totales;
};

function firstOfMonth(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}
function lastOfMonth(): string {
  const d = new Date();
  const last = new Date(d.getFullYear(), d.getMonth() + 1, 0);
  return last.toISOString().slice(0, 10);
}

function formatNro(tipo: string, letra: string | null, pv: number, nro: number) {
  const lbl = letra ? `${tipo}-${letra}` : tipo;
  return `${lbl} ${String(pv).padStart(4, '0')}-${String(nro).padStart(8, '0')}`;
}

function docTipoNombre(t: number | null): string {
  const map: Record<number, string> = { 80: 'CUIT', 86: 'CUIL', 96: 'DNI', 99: 'C.F.' };
  return t ? map[t] ?? String(t) : '—';
}

export function LibroIvaVentasPage() {
  const [desde, setDesde] = useState(firstOfMonth());
  const [hasta, setHasta] = useState(lastOfMonth());

  const { data, isLoading, error } = useQuery<Resp>({
    queryKey: ['libro-iva-ventas', { desde, hasta }],
    queryFn: () => {
      const qs = new URLSearchParams({ desde, hasta });
      return api.get<Resp>(`/api/erp/libro-iva/ventas?${qs.toString()}`);
    },
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2 text-[13px] text-gray-500">
        <span>ERP</span> <span>›</span> <span>Fiscal</span> <span>›</span>
        <span className="text-gray-800 font-medium">Libro IVA — Ventas</span>
      </div>

      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <FileSpreadsheet className="w-6 h-6 text-azure" /> Libro IVA — Ventas
          </h1>
          <p className="text-sm text-gray-500 mt-1">
            Listado de comprobantes emitidos con discriminación por alícuota. Base para DDJJ F.2002.
          </p>
        </div>
      </div>

      {/* Filtros */}
      <Card>
        <CardBody className="flex flex-wrap items-end gap-3">
          <div>
            <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Desde</label>
            <input type="date" value={desde} onChange={(e) => setDesde(e.target.value)}
              className="border rounded-md px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Hasta</label>
            <input type="date" value={hasta} onChange={(e) => setHasta(e.target.value)}
              className="border rounded-md px-3 py-2 text-sm" />
          </div>
        </CardBody>
      </Card>

      {isLoading && (
        <Card><CardBody className="p-8 text-center text-sm text-gray-500">
          <Loader2 className="w-4 h-4 animate-spin inline mr-2" /> Cargando...
        </CardBody></Card>
      )}

      {error && (
        <Card><CardBody className="p-6 text-sm text-red-600">
          Error al cargar: {(error as Error).message}
        </CardBody></Card>
      )}

      {data && (
        <>
          {/* KPIs */}
          <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <Card><CardBody>
              <div className="text-xs font-medium text-gray-500 uppercase">Comprobantes</div>
              <div className="mt-1 text-xl font-bold">{data.totales.cant}</div>
            </CardBody></Card>
            <Card><CardBody>
              <div className="text-xs font-medium text-gray-500 uppercase">Neto gravado</div>
              <div className="mt-1 text-xl font-bold">{fmtMoney(data.totales.neto)}</div>
            </CardBody></Card>
            <Card><CardBody>
              <div className="text-xs font-medium text-gray-500 uppercase">No gravado + Exento</div>
              <div className="mt-1 text-xl font-bold">{fmtMoney(data.totales.no_gravado + data.totales.exento)}</div>
            </CardBody></Card>
            <Card><CardBody>
              <div className="text-xs font-medium text-gray-500 uppercase">IVA DF</div>
              <div className="mt-1 text-xl font-bold text-azure">{fmtMoney(data.totales.iva)}</div>
            </CardBody></Card>
            <Card><CardBody>
              <div className="text-xs font-medium text-gray-500 uppercase">Total</div>
              <div className="mt-1 text-xl font-bold">{fmtMoney(data.totales.total)}</div>
            </CardBody></Card>
          </div>

          {/* Resumen por alícuota */}
          {data.por_alicuota.length > 0 && (
            <Card>
              <CardHeader>
                <div className="font-semibold flex items-center gap-2">
                  <Receipt className="w-4 h-4 text-azure" /> Discriminación por alícuota
                </div>
              </CardHeader>
              <CardBody className="p-0">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 text-[11px] font-semibold uppercase text-gray-500 tracking-wider">
                    <tr>
                      <th className="px-4 py-2 text-left">Alícuota</th>
                      <th className="px-4 py-2 text-right">Cant. cbtes.</th>
                      <th className="px-4 py-2 text-right">Base imponible</th>
                      <th className="px-4 py-2 text-right">IVA</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {data.por_alicuota.map((a) => (
                      <tr key={a.codigo_interno} className="hover:bg-gray-50">
                        <td className="px-4 py-2">{a.alicuota_nombre}</td>
                        <td className="px-4 py-2 text-right font-mono">{a.cant_cbtes}</td>
                        <td className="px-4 py-2 text-right font-mono">{fmtMoney(parseFloat(a.base_total))}</td>
                        <td className="px-4 py-2 text-right font-mono font-semibold">{fmtMoney(parseFloat(a.iva_total))}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </CardBody>
            </Card>
          )}

          {/* Listado detallado */}
          <Card>
            <CardHeader>
              <div className="font-semibold">Comprobantes del período</div>
            </CardHeader>
            <CardBody className="p-0">
              {data.comprobantes.length === 0 ? (
                <div className="p-8 text-center text-sm text-gray-500">Sin comprobantes en el período.</div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-[11px] font-semibold uppercase text-gray-500 tracking-wider">
                      <tr>
                        <th className="px-3 py-2 text-left">Fecha</th>
                        <th className="px-3 py-2 text-left">Comprobante</th>
                        <th className="px-3 py-2 text-left">Cliente</th>
                        <th className="px-3 py-2 text-left">CUIT/Doc.</th>
                        <th className="px-3 py-2 text-left">Cond.</th>
                        <th className="px-3 py-2 text-right">Neto grav.</th>
                        <th className="px-3 py-2 text-right">No grav.</th>
                        <th className="px-3 py-2 text-right">Exento</th>
                        <th className="px-3 py-2 text-right">IVA</th>
                        <th className="px-3 py-2 text-right">Total</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {data.comprobantes.map((c) => {
                        const esNC = c.tipo_clase === 'NOTA_CREDITO';
                        return (
                          <tr key={c.id} className={`hover:bg-gray-50 ${esNC ? 'text-red-700' : ''}`}>
                            <td className="px-3 py-2 whitespace-nowrap">{c.fecha_emision?.slice(0, 10)}</td>
                            <td className="px-3 py-2 font-mono text-[12px] whitespace-nowrap">
                              {formatNro(c.tipo_codigo, c.letra, c.pto_vta, c.numero)}
                              {esNC && <Badge variant="danger" className="ml-2">NC</Badge>}
                            </td>
                            <td className="px-3 py-2">{c.cliente_nombre}</td>
                            <td className="px-3 py-2 font-mono text-[11px]">
                              {docTipoNombre(c.doc_tipo_afip)} {c.doc_nro ?? ''}
                            </td>
                            <td className="px-3 py-2 text-[12px]">{c.condicion_iva ?? '—'}</td>
                            <td className="px-3 py-2 text-right font-mono">{fmtMoney(parseFloat(c.imp_neto_gravado))}</td>
                            <td className="px-3 py-2 text-right font-mono text-gray-500">
                              {parseFloat(c.imp_no_gravado) > 0 ? fmtMoney(parseFloat(c.imp_no_gravado)) : '—'}
                            </td>
                            <td className="px-3 py-2 text-right font-mono text-gray-500">
                              {parseFloat(c.imp_exento) > 0 ? fmtMoney(parseFloat(c.imp_exento)) : '—'}
                            </td>
                            <td className="px-3 py-2 text-right font-mono">{fmtMoney(parseFloat(c.imp_iva))}</td>
                            <td className="px-3 py-2 text-right font-mono font-semibold">{fmtMoney(parseFloat(c.imp_total))}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                    <tfoot className="bg-gray-50 font-semibold">
                      <tr>
                        <td colSpan={5} className="px-3 py-2 text-right">Totales ({data.totales.cant})</td>
                        <td className="px-3 py-2 text-right font-mono">{fmtMoney(data.totales.neto)}</td>
                        <td className="px-3 py-2 text-right font-mono">{fmtMoney(data.totales.no_gravado)}</td>
                        <td className="px-3 py-2 text-right font-mono">{fmtMoney(data.totales.exento)}</td>
                        <td className="px-3 py-2 text-right font-mono text-azure">{fmtMoney(data.totales.iva)}</td>
                        <td className="px-3 py-2 text-right font-mono">{fmtMoney(data.totales.total)}</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              )}
            </CardBody>
          </Card>
        </>
      )}
    </div>
  );
}
