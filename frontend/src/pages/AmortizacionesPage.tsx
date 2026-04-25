import { useState } from 'react';
import { Calculator, Play, FileSearch } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, type Column } from '@/components/ui/DataTable';
import { SelectField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Amortizacion = {
  id: number;
  bien_id: number;
  periodo_anio: number;
  periodo_mes: number;
  cuota_contable: number | string;
  cuota_fiscal: number | string;
  amort_acum_contable: number | string;
  amort_acum_fiscal: number | string;
  asiento_id: number | null;
  bien?: { id: number; nro_inventario: string; descripcion: string };
};

const CURRENT_YEAR = new Date().getFullYear();
const ANIOS = Array.from({ length: 6 }, (_, i) => CURRENT_YEAR - 4 + i);
const MESES = Array.from({ length: 12 }, (_, i) => ({ value: String(i + 1), label: String(i + 1).padStart(2, '0') }));

export function AmortizacionesPage() {
  const [filtros, setFiltros] = useState({
    anio: String(CURRENT_YEAR),
    mes: String(new Date().getMonth() + 1),
  });
  const [submitted, setSubmitted] = useState(filtros);

  const qs = `?anio=${submitted.anio}&mes=${submitted.mes}`;
  const { data, isLoading, error, refetch } = useApi<Amortizacion[]>(
    ['af-amortizaciones', submitted.anio, submitted.mes],
    `/api/erp/af/amortizaciones${qs}`
  );

  const [dryRun, setDryRun] = useState<{ generadas: number; periodo: string; total_cuota: number; bienes: Array<{ bien_id: number; cuota_contable: number; cuota_fiscal: number; nro_inventario?: string }> } | null>(null);

  const toast = useToast();
  const invalidate = useInvalidate(['af-amortizaciones']);

  const generar = useApiMutation<{ generadas?: number; total_cuota?: number; bienes?: unknown[] } & Record<string, unknown>, { dry_run: boolean }>(
    (vars) => api.post('/api/erp/af/amortizaciones/generar', {
      periodo_anio: Number(submitted.anio),
      periodo_mes: Number(submitted.mes),
      dry_run: vars.dry_run,
    }),
    {
      onSuccess: (res, vars) => {
        if (vars?.dry_run) {
          setDryRun({
            generadas: Number(res.generadas ?? 0),
            periodo: `${submitted.anio}/${String(submitted.mes).padStart(2, '0')}`,
            total_cuota: Number(res.total_cuota ?? 0),
            bienes: (res.bienes as Array<{ bien_id: number; cuota_contable: number; cuota_fiscal: number; nro_inventario?: string }>) ?? [],
          });
        } else {
          toast.success('Amortizaciones generadas', `${res.generadas ?? 0} filas`);
          setDryRun(null);
          invalidate();
          refetch();
        }
      },
      onError: (e) => toast.error('Error al generar', errorMessage(e)),
    }
  );

  const columns: Column<Amortizacion>[] = [
    { key: 'bien', header: 'Bien',
      render: (r) => r.bien
        ? <div><div className="text-[12px]">{r.bien.nro_inventario}</div>
          <div className="text-[10.5px] text-ink-muted">{r.bien.descripcion}</div></div>
        : `#${r.bien_id}` },
    { key: 'periodo', header: 'Período', width: '110px',
      render: (r) => `${r.periodo_anio}/${String(r.periodo_mes).padStart(2, '0')}` },
    { key: 'cuota_contable', header: 'Cuota cont.', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.cuota_contable)) },
    { key: 'cuota_fiscal', header: 'Cuota fiscal', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.cuota_fiscal)) },
    { key: 'amort_acum_contable', header: 'Acum. cont.', align: 'right', width: '130px',
      render: (r) => fmtMoney(Number(r.amort_acum_contable)) },
    { key: 'amort_acum_fiscal', header: 'Acum. fiscal', align: 'right', width: '130px',
      render: (r) => fmtMoney(Number(r.amort_acum_fiscal)) },
    { key: 'asiento_id', header: 'Asiento', width: '100px',
      render: (r) => r.asiento_id ? <Badge variant="success">#{r.asiento_id}</Badge> : <Badge variant="neutral">—</Badge> },
  ];

  const total = (data ?? []).reduce(
    (acc, a) => ({
      cont: acc.cont + Number(a.cuota_contable),
      fiscal: acc.fiscal + Number(a.cuota_fiscal),
    }),
    { cont: 0, fiscal: 0 }
  );

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><Calculator className="w-4 h-4 text-azure" /> Amortizaciones mensuales</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <SelectField label="Año" value={filtros.anio}
              onChange={(e) => setFiltros({ ...filtros, anio: e.target.value })}
              options={ANIOS.map((a) => ({ value: String(a), label: String(a) }))}
              placeholder={null}
              containerClassName="w-[120px]" />
            <SelectField label="Mes" value={filtros.mes}
              onChange={(e) => setFiltros({ ...filtros, mes: e.target.value })}
              options={MESES} placeholder={null}
              containerClassName="w-[100px]" />
            <Button variant="outline" onClick={() => setSubmitted(filtros)}>
              <FileSearch className="w-3 h-3" /> Listar
            </Button>
            <Button variant="outline" onClick={() => generar.mutate({ dry_run: true })}
              disabled={generar.isPending}>
              <Play className="w-3 h-3" /> Simular
            </Button>
            <Button variant="primary" onClick={() => generar.mutate({ dry_run: false })}
              disabled={generar.isPending}>
              <Play className="w-3 h-3" /> {generar.isPending ? 'Generando…' : 'Generar y contabilizar'}
            </Button>
          </div>

          {error && <FormError error={errorMessage(error)} />}

          {dryRun && (
            <div className="border border-info/40 bg-info-bg/30 rounded-md p-3 text-[12.5px]">
              <div className="font-semibold mb-1">
                Simulación {dryRun.periodo} — {dryRun.generadas} bienes amortizables
              </div>
              <div className="text-[12px] text-ink-2 mb-2">
                Total cuota contable estimada: <strong>{fmtMoney(dryRun.total_cuota)}</strong>
              </div>
              {dryRun.bienes.length > 0 && (
                <details>
                  <summary className="cursor-pointer text-[11.5px] text-azure">
                    Ver detalle por bien ({dryRun.bienes.length})
                  </summary>
                  <ul className="mt-2 text-[11px] grid grid-cols-2 gap-1">
                    {dryRun.bienes.map((b) => (
                      <li key={b.bien_id}>
                        <code>{b.nro_inventario ?? `#${b.bien_id}`}</code>{' '}
                        <span className="tabular-nums">{fmtMoney(b.cuota_contable)}</span>
                      </li>
                    ))}
                  </ul>
                </details>
              )}
            </div>
          )}

          {data && (
            <div className="grid grid-cols-3 gap-3">
              <KpiCard label="Filas" value={data.length} />
              <KpiCard label="Cuota contable total" value={fmtMoney(total.cont)} />
              <KpiCard label="Cuota fiscal total" value={fmtMoney(total.fiscal)} />
            </div>
          )}

          <DataTable columns={columns} rows={data ?? []} loading={isLoading}
            empty={`Sin amortizaciones en ${submitted.anio}/${submitted.mes}`} />

          <div className="text-[11px] text-ink-muted">
            La generación es idempotente por (bien × año × mes). "Simular" no contabiliza ni persiste; "Generar" crea las filas + asiento del período.
          </div>
        </CardBody>
      </Card>
    </div>
  );
}

function KpiCard({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="border border-line rounded-md p-3 bg-white">
      <div className="text-[11px] uppercase text-ink-muted tracking-wide">{label}</div>
      <div className="text-[16px] font-semibold tabular-nums">{value}</div>
    </div>
  );
}
