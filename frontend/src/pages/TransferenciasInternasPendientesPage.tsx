import { useState } from 'react';
import { ArrowLeftRight, Check, Loader2, X } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

type Candidato = { id: number; fecha: string; concepto: string; debito: string; credito: string; cuenta_nombre: string };
type Pendiente = {
  id: number; fecha: string; concepto: string; monto: number; signo: 'DEBITO' | 'CREDITO';
  cuenta_nombre: string; candidatos: Candidato[];
};

export default function TransferenciasInternasPendientesPage() {
  const qc = useQueryClient();
  const [err, setErr] = useState('');
  const [sel, setSel] = useState<Record<number, string>>({});

  const { data, isLoading } = useQuery<{ data: Pendiente[] }>({
    queryKey: ['transf-internas-pendientes'],
    queryFn: () => api.get('/api/erp/conciliacion/transferencias-internas-pendientes'),
  });

  const emparejar = useMutation({
    mutationFn: (v: { movId: number; espejoId: number }) =>
      api.post(`/api/erp/conciliacion/transferencias-internas/${v.movId}/emparejar`, { espejo_id: v.espejoId }),
    onSuccess: () => { setErr(''); qc.invalidateQueries({ queryKey: ['transf-internas-pendientes'] }); },
    onError: (e: ApiError) => setErr(e.message),
  });
  const descartar = useMutation({
    mutationFn: (movId: number) =>
      api.post(`/api/erp/conciliacion/transferencias-internas/${movId}/descartar`, {}),
    onSuccess: () => { setErr(''); qc.invalidateQueries({ queryKey: ['transf-internas-pendientes'] }); },
    onError: (e: ApiError) => setErr(e.message),
  });

  const rows = data?.data ?? [];

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-[18px] font-semibold text-navy-800 flex items-center gap-2">
          <ArrowLeftRight className="w-5 h-5" /> Transferencias internas pendientes
        </h1>
        <p className="text-[12px] text-ink-2">Movimientos detectados como transferencia entre cuentas propias sin espejo emparejado automáticamente.</p>
      </div>

      {err && <div className="mb-4 p-3 bg-danger-bg text-danger rounded-md text-[12px]">{err}</div>}

      <Card>
        <CardHeader title={`Pendientes (${rows.length})`} />
        <CardBody>
          {isLoading ? (
            <div className="text-[12px] text-ink-2 py-6 text-center"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Cargando…</div>
          ) : rows.length === 0 ? (
            <div className="text-[12px] text-ink-2 py-6 text-center">No hay transferencias internas pendientes. 🎉</div>
          ) : (
            <table className="w-full text-[12px]">
              <thead>
                <tr className="text-left text-[11px] uppercase font-semibold text-ink-2 border-b border-line">
                  <th className="py-2">Fecha</th><th>Cuenta</th><th>Concepto</th>
                  <th className="text-right">Monto</th><th>Signo</th><th>Emparejar con</th><th></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={r.id} className={i % 2 ? 'bg-surface-row' : ''}>
                    <td className="py-2">{r.fecha.slice(0, 10)}</td>
                    <td>{r.cuenta_nombre}</td>
                    <td className="max-w-[220px] truncate" title={r.concepto}>{r.concepto}</td>
                    <td className="text-right tabular font-semibold">{fmtMoney(r.monto)}</td>
                    <td>{r.signo}</td>
                    <td>
                      <select value={sel[r.id] ?? ''} onChange={(e) => setSel({ ...sel, [r.id]: e.target.value })}
                        className="px-2 py-1 border border-line-strong rounded bg-white max-w-[260px]">
                        <option value="">{r.candidatos.length ? 'Elegir espejo…' : 'Sin candidatos'}</option>
                        {r.candidatos.map((c) => (
                          <option key={c.id} value={c.id}>
                            #{c.id} · {c.fecha.slice(0, 10)} · {c.cuenta_nombre} · {c.concepto.slice(0, 30)}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="text-right whitespace-nowrap">
                      <Button variant="success" size="sm" className="mr-1"
                        disabled={!sel[r.id] || emparejar.isPending}
                        onClick={() => emparejar.mutate({ movId: r.id, espejoId: Number(sel[r.id]) })}>
                        <Check className="w-3 h-3" /> Emparejar
                      </Button>
                      <Button variant="outline" size="sm"
                        disabled={descartar.isPending}
                        onClick={() => { if (confirm('¿Marcar como NO transferencia interna? Vuelve a PENDIENTE.')) descartar.mutate(r.id); }}>
                        <X className="w-3 h-3" /> No es interna
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
