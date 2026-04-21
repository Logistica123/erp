import { useState } from 'react';
import { Check, Download, Loader2, X } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

type Fila = {
  id: number;
  codigo: string;
  nombre: string;
  nivel: number;
  tipo: string;
  moneda: string | null;
  saldo_inicial: number;
  debe: number;
  haber: number;
  saldo_final: number;
};

type Resp = {
  data: {
    rango: { desde: string | null; hasta: string | null };
    filas: Fila[];
    totales: { saldo_inicial: number; debe: number; haber: number; saldo_final: number };
    balanceado: boolean;
  };
};

function firstOfYear(): string {
  return `${new Date().getFullYear()}-01-01`;
}
function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function BalanceSSPage() {
  const [desde, setDesde] = useState(firstOfYear());
  const [hasta, setHasta] = useState(today());

  const { data, isLoading } = useQuery<Resp>({
    queryKey: ['balance-ss', { desde, hasta }],
    queryFn: () => api.get<Resp>(`/api/erp/balance-sumas-saldos?desde=${desde}&hasta=${hasta}`),
  });

  const filas = data?.data.filas ?? [];
  const totales = data?.data.totales;
  const balanceado = data?.data.balanceado ?? false;

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">
            Balance de Sumas y Saldos
          </h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            Período {desde} — {hasta}
            {data && ` · ${filas.length} cuentas con movimiento`}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary">
            <Download className="w-3 h-3" /> Exportar Excel
          </Button>
          <Button variant="secondary">
            <Download className="w-3 h-3" /> Exportar PDF
          </Button>
        </div>
      </div>

      {totales && (
        <div
          className={`mb-4 px-4 py-3 rounded-md text-[12px] border flex items-center gap-2 ${
            balanceado
              ? 'bg-success-bg text-success border-success/30'
              : 'bg-warning-bg text-warning border-warning/30'
          }`}
        >
          {balanceado ? <Check className="w-4 h-4" /> : <X className="w-4 h-4" />}
          <strong>{balanceado ? 'Balance cuadra.' : 'Balance descuadrado.'}</strong>
          <span>
            Saldo final total: <span className="tabular font-semibold">{fmtMoney(totales.saldo_final)}</span>
          </span>
          <span className="opacity-70">
            (debe ≡ {fmtMoney(totales.debe)} · haber ≡ {fmtMoney(totales.haber)})
          </span>
        </div>
      )}

      <Card>
        <CardHeader
          title="Cuentas con movimiento en el período"
          actions={
            <div className="flex gap-2 items-center">
              <input
                type="date"
                value={desde}
                onChange={(e) => setDesde(e.target.value)}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
              />
              <span className="text-ink-muted text-[11px]">→</span>
              <input
                type="date"
                value={hasta}
                onChange={(e) => setHasta(e.target.value)}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
              />
            </div>
          }
        />
        <CardBody>
          <table className="w-full border-collapse text-[12px]">
            <thead>
              <tr className="bg-surface-hover border-b border-line-strong">
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">
                  Código
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                  Cuenta
                </th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[140px]">
                  Saldo inicial
                </th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[140px]">
                  Debe
                </th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[140px]">
                  Haber
                </th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[160px]">
                  Saldo final
                </th>
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-ink-muted">
                    <Loader2 className="w-4 h-4 animate-spin inline mr-2" /> Calculando balance…
                  </td>
                </tr>
              )}
              {filas.map((f, i) => (
                <tr
                  key={f.id}
                  className={`border-b border-line hover:bg-surface-hover ${i % 2 ? 'bg-surface-row' : ''}`}
                >
                  <td className="px-[10px] py-[7px] font-mono text-[11px] text-navy-700">{f.codigo}</td>
                  <td className="px-[10px] py-[7px] text-ink-2">{f.nombre}</td>
                  <td
                    className={`px-[10px] py-[7px] text-right tabular ${
                      f.saldo_inicial === 0
                        ? 'text-ink-muted'
                        : f.saldo_inicial < 0
                          ? 'text-danger'
                          : 'text-ink-2'
                    }`}
                  >
                    {f.saldo_inicial === 0 ? '—' : fmtMoney(f.saldo_inicial)}
                  </td>
                  <td
                    className={`px-[10px] py-[7px] text-right tabular ${
                      f.debe ? 'text-success font-medium' : 'text-ink-muted'
                    }`}
                  >
                    {f.debe ? fmtMoney(f.debe) : '—'}
                  </td>
                  <td
                    className={`px-[10px] py-[7px] text-right tabular ${
                      f.haber ? 'text-danger font-medium' : 'text-ink-muted'
                    }`}
                  >
                    {f.haber ? fmtMoney(f.haber) : '—'}
                  </td>
                  <td
                    className={`px-[10px] py-[7px] text-right tabular font-semibold ${
                      f.saldo_final < 0 ? 'text-danger' : 'text-navy-800'
                    }`}
                  >
                    {fmtMoney(f.saldo_final)}
                  </td>
                </tr>
              ))}
              {data && filas.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-ink-muted">
                    Sin movimientos en el período.
                  </td>
                </tr>
              )}
            </tbody>
            {totales && filas.length > 0 && (
              <tfoot>
                <tr className="bg-surface-hover font-semibold">
                  <td colSpan={2} className="px-[10px] py-[7px] text-navy-800">
                    Totales
                  </td>
                  <td className="px-[10px] py-[7px] text-right tabular text-ink-2">
                    {fmtMoney(totales.saldo_inicial)}
                  </td>
                  <td className="px-[10px] py-[7px] text-right tabular text-success">
                    {fmtMoney(totales.debe)}
                  </td>
                  <td className="px-[10px] py-[7px] text-right tabular text-danger">
                    {fmtMoney(totales.haber)}
                  </td>
                  <td
                    className={`px-[10px] py-[7px] text-right tabular ${
                      balanceado ? 'text-success' : 'text-danger'
                    }`}
                  >
                    {fmtMoney(totales.saldo_final)}
                  </td>
                </tr>
              </tfoot>
            )}
          </table>
        </CardBody>
      </Card>
    </>
  );
}
