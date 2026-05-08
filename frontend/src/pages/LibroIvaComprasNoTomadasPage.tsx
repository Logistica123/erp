import { useState, useMemo } from 'react';
import { CheckSquare, Square, Wand2, ScrollText } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { SelectField, FormError } from '@/components/ui/Field';
import { fmtMoney, fmtDate } from '@/components/ui/DataTable';
import { api, ApiError } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * ADDENDUM v1.13.1 — facturas marcadas Tomado=NO en el import del contador.
 * Permite multi-select y reactivar en un período X.
 */

type FacturaNoTomada = {
  id: number;
  fecha_emision: string;
  punto_venta: number;
  numero: number;
  cuit_emisor: string;
  razon_social_emisor: string;
  imp_neto_gravado: number | string;
  imp_iva: number | string;
  imp_total: number | string;
  observaciones: string | null;
  tipo_gasto: string | null;
  tipo_comprobante?: { codigo_interno: string; letra: string };
  auxiliar?: { nombre: string; cuit: string };
  import_id: number | null;
};

type Periodo = { id: number; anio: number; mes: number; estado: string };
type Ejercicio = { id: number };

const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

export function LibroIvaComprasNoTomadasPage() {
  const [seleccion, setSeleccion] = useState<Set<number>>(new Set());
  const [periodoTarget, setPeriodoTarget] = useState('');

  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-compras-no-tomadas']);

  const { data: filas, isLoading, error } = useApi<FacturaNoTomada[]>(
    ['libro-iva-compras-no-tomadas'],
    '/api/erp/libro-iva-compras/no-tomadas'
  );

  const { data: ejercicios } = useApi<Ejercicio[]>(['ejercicios'], '/api/erp/ejercicios');
  const { data: periodos } = useApi<Periodo[]>(
    ['periodos-no-tomadas', ejercicios?.[0]?.id],
    `/api/erp/periodos?ejercicio_id=${ejercicios?.[0]?.id ?? ''}`,
    { enabled: !!ejercicios?.[0] }
  );

  const opciones = useMemo(
    () => (periodos ?? []).map((p) => ({
      value: String(p.id),
      label: `${MESES[p.mes]} ${p.anio} (${p.estado})`,
    })),
    [periodos]
  );

  const tomar = useApiMutation<{ tomadas: number }, { factura_ids: number[]; periodo_id: number }>(
    (vars) => api.post('/api/erp/libro-iva-compras/no-tomadas/tomar', vars),
    {
      onSuccess: (r) => {
        toast.success(`${r.tomadas} facturas reactivadas`,
          'Se generaron los asientos correspondientes en el período seleccionado.');
        setSeleccion(new Set());
        invalidate();
      },
      onError: (e) => {
        const apiErr = e as ApiError;
        toast.error('Error al tomar', apiErr.message);
      },
    }
  );

  const toggleAll = () => {
    if (!filas) return;
    if (seleccion.size === filas.length) setSeleccion(new Set());
    else setSeleccion(new Set(filas.map((f) => f.id)));
  };

  const toggle = (id: number) => {
    const next = new Set(seleccion);
    next.has(id) ? next.delete(id) : next.add(id);
    setSeleccion(next);
  };

  const ejecutar = () => {
    if (seleccion.size === 0 || !periodoTarget) return;
    tomar.mutate({ factura_ids: [...seleccion], periodo_id: Number(periodoTarget) });
  };

  const totalNeto = filas?.filter((f) => seleccion.has(f.id))
    .reduce((a, f) => a + Number(f.imp_neto_gravado), 0) ?? 0;
  const totalTotal = filas?.filter((f) => seleccion.has(f.id))
    .reduce((a, f) => a + Number(f.imp_total), 0) ?? 0;

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2">
            <ScrollText className="w-4 h-4 text-azure" /> Facturas no tomadas (Tomado=NO)
          </div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            Facturas importadas con <code>Tomado=NO</code> — están en la base pero no
            generaron asiento ni impactaron el Libro IVA del ERP. Seleccioná las que
            querés reactivar y elegí el período de imputación. El sistema genera los
            asientos correspondientes y las marca como tomadas.
          </div>

          {error && <FormError error={errorMessage(error)} />}

          <div className="flex flex-wrap gap-3 items-end border-b border-line pb-3">
            <Button variant="ghost" size="sm" onClick={toggleAll}>
              {seleccion.size === (filas?.length ?? 0) && filas?.length
                ? <><CheckSquare className="w-3 h-3" /> Deseleccionar todas</>
                : <><Square className="w-3 h-3" /> Seleccionar todas</>}
            </Button>
            <SelectField label="Período de imputación" value={periodoTarget}
              placeholder="Elegí un período" options={opciones}
              onChange={(e) => setPeriodoTarget(e.target.value)}
              containerClassName="w-[260px]" />
            <Button variant="primary" size="sm"
              disabled={seleccion.size === 0 || !periodoTarget || tomar.isPending}
              onClick={ejecutar}>
              <Wand2 className="w-3 h-3" />
              {tomar.isPending ? 'Tomando…' : `Tomar ${seleccion.size} factura${seleccion.size === 1 ? '' : 's'}`}
            </Button>
            {seleccion.size > 0 && (
              <div className="text-[11.5px] text-ink-muted ml-auto">
                Selección: <strong>{seleccion.size}</strong> facturas ·
                Neto: <strong>{fmtMoney(totalNeto)}</strong> ·
                Total: <strong>{fmtMoney(totalTotal)}</strong>
              </div>
            )}
          </div>

          {isLoading ? (
            <div className="text-center py-8 text-ink-muted">Cargando…</div>
          ) : !filas || filas.length === 0 ? (
            <div className="text-center py-8 text-ink-muted">
              No hay facturas con <code>Tomado=NO</code> en este momento.
            </div>
          ) : (
            <div className="overflow-x-auto border border-line rounded-md">
              <table className="w-full text-[12.5px]">
                <thead className="bg-surface-row text-[11px] uppercase tracking-wider text-ink-muted">
                  <tr>
                    <th className="px-2 py-2 w-[40px]"></th>
                    <th className="px-2 py-2 text-left w-[90px]">Fecha</th>
                    <th className="px-2 py-2 text-left w-[180px]">Comprobante</th>
                    <th className="px-2 py-2 text-left">Proveedor</th>
                    <th className="px-2 py-2 text-right w-[120px]">Neto</th>
                    <th className="px-2 py-2 text-right w-[110px]">IVA</th>
                    <th className="px-2 py-2 text-right w-[130px]">Total</th>
                    <th className="px-2 py-2 text-left w-[140px]">Tipo gasto</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line/60">
                  {filas.map((f) => {
                    const sel = seleccion.has(f.id);
                    return (
                      <tr key={f.id}
                        className={`cursor-pointer ${sel ? 'bg-azure/10' : 'hover:bg-surface-hover'}`}
                        onClick={() => toggle(f.id)}>
                        <td className="px-2 py-1.5">
                          <input type="checkbox" checked={sel}
                            onChange={() => toggle(f.id)}
                            onClick={(e) => e.stopPropagation()} />
                        </td>
                        <td className="px-2 py-1.5">{fmtDate(f.fecha_emision)}</td>
                        <td className="px-2 py-1.5">
                          <div className="font-medium">
                            {f.tipo_comprobante?.letra ?? ''} {f.tipo_comprobante?.codigo_interno ?? '?'} —{' '}
                            {String(f.punto_venta).padStart(5, '0')}-{String(f.numero).padStart(8, '0')}
                          </div>
                        </td>
                        <td className="px-2 py-1.5">
                          <div>{f.razon_social_emisor || f.auxiliar?.nombre || '—'}</div>
                          <div className="text-[10.5px] text-ink-muted">CUIT {f.cuit_emisor || f.auxiliar?.cuit}</div>
                        </td>
                        <td className="px-2 py-1.5 text-right">{fmtMoney(Number(f.imp_neto_gravado))}</td>
                        <td className="px-2 py-1.5 text-right">{fmtMoney(Number(f.imp_iva))}</td>
                        <td className="px-2 py-1.5 text-right font-semibold">{fmtMoney(Number(f.imp_total))}</td>
                        <td className="px-2 py-1.5">
                          {f.tipo_gasto ? <Badge variant="default">{f.tipo_gasto}</Badge> : <span className="text-ink-muted">—</span>}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
