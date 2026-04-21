import { useMemo, useState } from 'react';
import { Download, Loader2 } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

type Cuenta = {
  id: number;
  codigo: string;
  nombre: string;
  imputable: boolean;
};

type Mov = {
  id: number;
  fecha: string;
  diario: string;
  numero: number;
  glosa: string | null;
  auxiliar: string | null;
  centro_costo: string | null;
  debe: number;
  haber: number;
  saldo: number;
};

type LibroMayorResp = {
  data: {
    cuenta: { id: number; codigo: string; nombre: string; moneda: string };
    rango: { desde: string | null; hasta: string | null };
    saldo_inicial: number;
    movimientos: Mov[];
    totales: { debe: number; haber: number; saldo_final: number };
  };
};

function firstOfMonth(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function LibroMayorPage() {
  const { data: cuentasResp } = useQuery<{ data: Cuenta[] }>({
    queryKey: ['cuentas', 'imputables'],
    queryFn: () => api.get('/api/erp/cuentas?imputable=true'),
  });

  const cuentas = cuentasResp?.data ?? [];

  // Default: ICBC si está, sino la primera imputable
  const cuentaDefault = useMemo(() => {
    if (!cuentas.length) return null;
    return cuentas.find((c) => c.codigo === '1.1.2.01')?.id ?? cuentas[0]?.id ?? null;
  }, [cuentas]);

  const [cuentaId, setCuentaId] = useState<number | null>(null);
  const [desde, setDesde] = useState(firstOfMonth());
  const [hasta, setHasta] = useState(today());

  const effectiveId = cuentaId ?? cuentaDefault;

  const { data: mayor, isLoading } = useQuery<LibroMayorResp>({
    queryKey: ['libro-mayor', effectiveId, desde, hasta],
    queryFn: () =>
      api.get<LibroMayorResp>(
        `/api/erp/libro-mayor?cuenta_id=${effectiveId}&desde=${desde}&hasta=${hasta}`
      ),
    enabled: !!effectiveId,
  });

  const cuentaActual = mayor?.data.cuenta;

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Libro Mayor</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            {cuentaActual
              ? `Cuenta ${cuentaActual.codigo} · ${cuentaActual.nombre} · Período ${desde} — ${hasta}`
              : 'Seleccioná una cuenta para ver el mayor'}
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

      <Card>
        <CardHeader
          title="Movimientos de la cuenta"
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
              <select
                value={effectiveId ?? ''}
                onChange={(e) => setCuentaId(Number(e.target.value))}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white min-w-[280px]"
              >
                <option value="">Seleccionar cuenta…</option>
                {cuentas.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.codigo} — {c.nombre}
                  </option>
                ))}
              </select>
            </div>
          }
        />
        <CardBody>
          <table className="w-full border-collapse text-[12px]">
            <thead>
              <tr className="bg-surface-hover border-b border-line-strong">
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[90px]">Fecha</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[80px]">Diario</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[60px]">N°</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">Glosa</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">Auxiliar</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Debe</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">Haber</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[140px]">Saldo</th>
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr>
                  <td colSpan={8} className="py-10 text-center text-ink-muted">
                    <Loader2 className="w-4 h-4 animate-spin inline mr-2" />
                    Cargando…
                  </td>
                </tr>
              )}

              {mayor && (
                <>
                  <tr className="border-b border-line">
                    <td colSpan={5} className="px-[10px] py-[7px] font-semibold text-ink-2">
                      Saldo inicial al {desde}
                    </td>
                    <td className="px-[10px] py-[7px] text-right text-ink-muted">—</td>
                    <td className="px-[10px] py-[7px] text-right text-ink-muted">—</td>
                    <td className="px-[10px] py-[7px] text-right tabular font-semibold text-navy-800">
                      {fmtMoney(mayor.data.saldo_inicial)}
                    </td>
                  </tr>
                  {mayor.data.movimientos.map((m, i) => (
                    <tr
                      key={m.id}
                      className={`border-b border-line hover:bg-surface-hover ${i % 2 ? 'bg-surface-row' : ''}`}
                    >
                      <td className="px-[10px] py-[7px] tabular text-ink-2">{m.fecha.slice(0, 10)}</td>
                      <td className="px-[10px] py-[7px]">
                        <Badge variant="info">{m.diario}</Badge>
                      </td>
                      <td className="px-[10px] py-[7px] tabular text-ink-2">{m.numero}</td>
                      <td className="px-[10px] py-[7px] text-ink-2">{m.glosa ?? '—'}</td>
                      <td className="px-[10px] py-[7px] text-ink-2">{m.auxiliar ?? '—'}</td>
                      <td
                        className={`px-[10px] py-[7px] text-right tabular ${
                          m.debe ? 'text-success font-medium' : 'text-ink-muted'
                        }`}
                      >
                        {m.debe ? fmtMoney(m.debe) : '—'}
                      </td>
                      <td
                        className={`px-[10px] py-[7px] text-right tabular ${
                          m.haber ? 'text-danger font-medium' : 'text-ink-muted'
                        }`}
                      >
                        {m.haber ? fmtMoney(m.haber) : '—'}
                      </td>
                      <td className="px-[10px] py-[7px] text-right tabular text-ink-2">{fmtMoney(m.saldo)}</td>
                    </tr>
                  ))}
                  {mayor.data.movimientos.length === 0 && (
                    <tr>
                      <td colSpan={8} className="py-10 text-center text-ink-muted">
                        Sin movimientos en el período.
                      </td>
                    </tr>
                  )}
                </>
              )}
            </tbody>
            {mayor && (
              <tfoot>
                <tr className="bg-surface-hover font-semibold">
                  <td colSpan={5} className="px-[10px] py-[7px] text-navy-800">
                    Totales del período
                  </td>
                  <td className="px-[10px] py-[7px] text-right tabular text-success">
                    {fmtMoney(mayor.data.totales.debe)}
                  </td>
                  <td className="px-[10px] py-[7px] text-right tabular text-danger">
                    {fmtMoney(mayor.data.totales.haber)}
                  </td>
                  <td className="px-[10px] py-[7px] text-right tabular text-navy-800">
                    {fmtMoney(mayor.data.totales.saldo_final)}
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
