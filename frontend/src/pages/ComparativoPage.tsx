import { useState } from 'react';
import { TrendingUp } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { fmtMoney } from '@/components/ui/DataTable';
import { useApi, errorMessage } from '@/hooks/useApi';

type Fila = {
  cuenta_id: number;
  codigo: string;
  nombre: string;
  tipo: string;
  valores: Record<string, number>;
  variaciones_vs_base: Record<string, { absoluto: number; porcentual: number | null }>;
};

type Resp = {
  reporte: 'resultado' | 'balance';
  periodos: string[];
  rangos: Record<string, { desde: string; hasta: string }>;
  filas: Fila[];
};

export function ComparativoPage() {
  const [reporte, setReporte] = useState<'resultado' | 'balance'>('resultado');
  const [periodosInput, setPeriodosInput] = useState('2025-12,2026-12');
  const [submitted, setSubmitted] = useState('');

  const qs = submitted
    ? `?reporte=${reporte}&periodos=${encodeURIComponent(submitted)}`
    : '';

  const { data, isLoading, error } = useApi<Resp>(
    ['comparativo', reporte, submitted],
    `/api/erp/reportes/comparativo${qs}`,
    { enabled: Boolean(submitted) }
  );

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><TrendingUp className="w-4 h-4 text-azure" /> Comparativo período vs período</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <SelectField label="Reporte" value={reporte}
              onChange={(e) => setReporte(e.target.value as 'resultado' | 'balance')}
              options={[
                { value: 'resultado', label: 'Estado de Resultados (RP-RN)' },
                { value: 'balance', label: 'Balance General (A/P/PN)' },
              ]} placeholder={null}
              containerClassName="w-[280px]" />
            <Field label="Períodos (separados por coma)" value={periodosInput}
              onChange={(e) => setPeriodosInput(e.target.value)}
              hint="YYYY-MM, YYYY o YYYY-MM-DD"
              containerClassName="w-[360px]" />
            <Button variant="primary" onClick={() => setSubmitted(periodosInput)}>
              Comparar
            </Button>
          </div>

          {error && <FormError error={errorMessage(error)} />}

          {isLoading && <div className="py-8 text-center text-ink-muted">Cargando…</div>}

          {data && (
            <div className="overflow-x-auto border border-line rounded-lg bg-white">
              <table className="w-full text-[12px]">
                <thead className="bg-[#FAFBFC] text-[11px] uppercase text-ink-muted">
                  <tr>
                    <th className="text-left px-3 py-2.5">Cuenta</th>
                    <th className="text-left px-3 py-2.5">Tipo</th>
                    {data.periodos.map((p) => (
                      <th key={p} className="text-right px-3 py-2.5">{p}</th>
                    ))}
                    {data.periodos.slice(1).map((p) => (
                      <th key={p + '-var'} className="text-right px-3 py-2.5">Δ vs {data.periodos[0]}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {data.filas.map((f) => (
                    <tr key={f.cuenta_id} className="border-b border-line/60">
                      <td className="px-3 py-1.5">
                        <div className="text-[12px]">{f.codigo}</div>
                        <div className="text-[10.5px] text-ink-muted">{f.nombre}</div>
                      </td>
                      <td className="px-3 py-1.5">
                        <Badge variant="neutral">{f.tipo}</Badge>
                      </td>
                      {data.periodos.map((p) => (
                        <td key={p} className="px-3 py-1.5 text-right tabular-nums">{fmtMoney(f.valores[p] ?? 0)}</td>
                      ))}
                      {data.periodos.slice(1).map((p) => {
                        const v = f.variaciones_vs_base[p];
                        const abs = v?.absoluto ?? 0;
                        const pct = v?.porcentual;
                        return (
                          <td key={p + '-v'} className="px-3 py-1.5 text-right tabular-nums">
                            <div className={abs > 0 ? 'text-success' : abs < 0 ? 'text-danger' : ''}>
                              {abs > 0 ? '+' : ''}{fmtMoney(abs)}
                            </div>
                            {pct !== null && pct !== undefined && (
                              <div className="text-[10.5px] text-ink-muted">
                                {pct > 0 ? '+' : ''}{pct.toFixed(1)}%
                              </div>
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                  {data.filas.length === 0 && (
                    <tr>
                      <td colSpan={2 + data.periodos.length * 2 - 1} className="text-center py-8 text-ink-muted">
                        Sin datos en los períodos seleccionados.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
