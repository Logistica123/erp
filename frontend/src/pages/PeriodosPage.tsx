import { useState } from 'react';
import { AlertTriangle, CheckCircle2, Loader2, Lock, Unlock } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

type EstadoPeriodo = 'ABIERTO' | 'EN_CIERRE' | 'CERRADO' | 'BLOQUEADO';

type Periodo = {
  id: number;
  ejercicio_id: number;
  anio: number;
  mes: number;
  fecha_inicio: string;
  fecha_fin: string;
  estado: EstadoPeriodo;
  fecha_cierre: string | null;
  usuario_cierre_id: number | null;
  cierre_iva: boolean;
  cierre_iibb: boolean;
};

type Ejercicio = {
  id: number;
  numero: number;
  nombre: string;
  fecha_inicio: string;
  fecha_cierre: string;
  estado: string;
  fecha_cierre_real: string | null;
};

type CierreEjercicioResp = {
  data: {
    ejercicio: Ejercicio;
    asiento_refundicion: { id: number; numero: number; total_debe: string; total_haber: string };
    resultado: number;
  };
};

const MESES = [
  '', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
];

function estadoBadge(estado: EstadoPeriodo) {
  if (estado === 'ABIERTO') return <Badge variant="success">Abierto</Badge>;
  if (estado === 'CERRADO') return <Badge variant="neutral">Cerrado</Badge>;
  if (estado === 'BLOQUEADO') return <Badge variant="danger">Bloqueado</Badge>;
  return <Badge variant="warning">En cierre</Badge>;
}

export function PeriodosPage() {
  const qc = useQueryClient();
  const [err, setErr] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<null | { periodo: Periodo; tipo: 'cerrar' | 'reabrir' }>(null);
  const [motivo, setMotivo] = useState('');
  const [cerrarEjercicioOpen, setCerrarEjercicioOpen] = useState(false);
  const [reabrirEjercicioOpen, setReabrirEjercicioOpen] = useState(false);
  const [cierreResult, setCierreResult] = useState<CierreEjercicioResp['data'] | null>(null);

  const { data: ejercicios } = useQuery<{ data: Ejercicio[] }>({
    queryKey: ['ejercicios'],
    queryFn: () => api.get('/api/erp/ejercicios'),
  });

  const ejercicioActual = ejercicios?.data[0];

  const { data: periodosResp, isLoading } = useQuery<{ data: Periodo[] }>({
    queryKey: ['periodos', ejercicioActual?.id],
    queryFn: () => api.get(`/api/erp/periodos?ejercicio_id=${ejercicioActual!.id}`),
    enabled: !!ejercicioActual,
  });

  const cerrar = useMutation({
    mutationFn: (id: number) => api.post(`/api/erp/periodos/${id}/cerrar`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['periodos'] });
      qc.invalidateQueries({ queryKey: ['periodo', 'abierto'] });
      setConfirming(null);
    },
    onError: (e) => setErr(e instanceof ApiError ? e.message : 'Error'),
  });

  const reabrir = useMutation({
    mutationFn: ({ id, motivo }: { id: number; motivo: string }) =>
      api.post(`/api/erp/periodos/${id}/reabrir`, { motivo }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['periodos'] });
      qc.invalidateQueries({ queryKey: ['periodo', 'abierto'] });
      setConfirming(null);
      setMotivo('');
    },
    onError: (e) => setErr(e instanceof ApiError ? e.message : 'Error'),
  });

  const cerrarEjercicio = useMutation({
    mutationFn: (id: number) => api.post<CierreEjercicioResp>(`/api/erp/ejercicios/${id}/cerrar`),
    onSuccess: (resp) => {
      qc.invalidateQueries({ queryKey: ['ejercicios'] });
      qc.invalidateQueries({ queryKey: ['periodos'] });
      qc.invalidateQueries({ queryKey: ['asientos'] });
      setCerrarEjercicioOpen(false);
      setCierreResult(resp.data);
    },
    onError: (e) => setErr(e instanceof ApiError ? e.message : 'Error'),
  });

  const reabrirEjercicio = useMutation({
    mutationFn: ({ id, motivo }: { id: number; motivo: string }) =>
      api.post(`/api/erp/ejercicios/${id}/reabrir`, { motivo }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['ejercicios'] });
      qc.invalidateQueries({ queryKey: ['periodos'] });
      setReabrirEjercicioOpen(false);
      setMotivo('');
      setCierreResult(null);
    },
    onError: (e) => setErr(e instanceof ApiError ? e.message : 'Error'),
  });

  const periodos = periodosResp?.data ?? [];

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Períodos contables</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            {ejercicioActual
              ? `Ejercicio ${ejercicioActual.numero} · ${ejercicioActual.nombre} · ${ejercicioActual.fecha_inicio.slice(0, 10)} → ${ejercicioActual.fecha_cierre.slice(0, 10)}`
              : 'Cargando…'}
          </p>
        </div>
        {ejercicioActual?.estado === 'ABIERTO' && (
          <Button variant="danger" onClick={() => setCerrarEjercicioOpen(true)}>
            <Lock className="w-3 h-3" /> Cerrar ejercicio {ejercicioActual.numero}
          </Button>
        )}
        {ejercicioActual?.estado === 'CERRADO' && (
          <div className="flex items-center gap-2">
            <Badge variant="neutral" className="px-3 py-1 text-[12px]">
              Ejercicio cerrado el {ejercicioActual.fecha_cierre_real?.slice(0, 10)}
            </Badge>
            <Button variant="secondary" onClick={() => setReabrirEjercicioOpen(true)}>
              <Unlock className="w-3 h-3" /> Reabrir ejercicio
            </Button>
          </div>
        )}
      </div>

      {cierreResult && (
        <div className="mb-4 p-4 bg-success-bg border border-success/30 rounded-md text-[13px]">
          <div className="flex items-center gap-2 text-success font-semibold mb-2">
            <CheckCircle2 className="w-4 h-4" />
            Ejercicio {cierreResult.ejercicio.numero} cerrado.
          </div>
          <div className="text-success/90 text-[12px] space-y-1">
            <div>
              Resultado del ejercicio:{' '}
              <strong className="tabular">
                {cierreResult.resultado >= 0 ? 'ganancia' : 'pérdida'} de {fmtMoney(Math.abs(cierreResult.resultado))}
              </strong>
            </div>
            <div>
              Asiento de refundición{' '}
              <strong>
                N° {cierreResult.asiento_refundicion.numero}
              </strong>{' '}
              generado en el diario CIE, totales debe = haber = {fmtMoney(Number(cierreResult.asiento_refundicion.total_debe))}.
            </div>
          </div>
        </div>
      )}

      {err && (
        <div className="mb-4 p-3 bg-danger-bg text-danger border border-danger/30 rounded-md text-[12px]">
          {err}
        </div>
      )}

      <Card>
        <CardHeader title={ejercicioActual ? `Los 12 períodos del ejercicio ${ejercicioActual.numero}` : 'Períodos'} />
        <CardBody>
          <table className="w-full border-collapse text-[12px]">
            <thead>
              <tr className="bg-surface-hover border-b border-line-strong">
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[60px]">
                  Mes
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider">
                  Período
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[200px]">
                  Rango
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[120px]">
                  Estado
                </th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[160px]">
                  Cerrado el
                </th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase tracking-wider w-[280px]">
                  Acciones
                </th>
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-ink-muted">
                    <Loader2 className="w-4 h-4 animate-spin inline mr-2" /> Cargando…
                  </td>
                </tr>
              )}
              {periodos.map((p, i) => (
                <tr key={p.id} className={`border-b border-line hover:bg-surface-hover ${i % 2 ? 'bg-surface-row' : ''}`}>
                  <td className="px-[10px] py-[7px] font-mono text-navy-700 text-[11px]">
                    {String(p.mes).padStart(2, '0')}
                  </td>
                  <td className="px-[10px] py-[7px] text-ink-2 font-medium">
                    {MESES[p.mes]} {p.anio}
                  </td>
                  <td className="px-[10px] py-[7px] text-ink-muted tabular text-[11px]">
                    {p.fecha_inicio.slice(0, 10)} → {p.fecha_fin.slice(0, 10)}
                  </td>
                  <td className="px-[10px] py-[7px]">{estadoBadge(p.estado)}</td>
                  <td className="px-[10px] py-[7px] text-ink-muted tabular text-[11px]">
                    {p.fecha_cierre ? p.fecha_cierre.slice(0, 16).replace('T', ' ') : '—'}
                  </td>
                  <td className="px-[10px] py-[7px] text-right">
                    {p.estado === 'ABIERTO' && (
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => setConfirming({ periodo: p, tipo: 'cerrar' })}
                      >
                        <Lock className="w-3 h-3" /> Cerrar período
                      </Button>
                    )}
                    {p.estado === 'CERRADO' && (
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => setConfirming({ periodo: p, tipo: 'reabrir' })}
                      >
                        <Unlock className="w-3 h-3" /> Reabrir
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardBody>
      </Card>

      <div className="mt-[18px] p-[14px_18px] bg-[#EEF3F8] border border-[#D1DCE8] rounded-lg text-[12px] text-navy-700 leading-relaxed">
        <strong className="text-navy-800">Cierre de período.</strong> Al cerrar: recompone los saldos materializados
        (`erp_saldos_cuenta`), firma el cierre con hash SHA-256 de los saldos, registra en el audit log. Ningún
        asiento nuevo puede contabilizarse con fecha dentro del período cerrado. La reapertura requiere permiso
        `contabilidad.periodos.reabrir` (solo super_admin) y deja traza en el audit log.
      </div>

      {/* ============ MODAL CONFIRMAR ============ */}
      <Modal
        open={!!confirming}
        onClose={() => {
          setConfirming(null);
          setMotivo('');
          setErr(null);
        }}
        title={
          confirming?.tipo === 'cerrar'
            ? `Cerrar período ${String(confirming.periodo.mes).padStart(2, '0')}/${confirming.periodo.anio}`
            : confirming
              ? `Reabrir período ${String(confirming.periodo.mes).padStart(2, '0')}/${confirming.periodo.anio}`
              : ''
        }
        size="sm"
        footer={
          confirming && (
            <>
              <Button variant="secondary" onClick={() => setConfirming(null)}>
                Cancelar
              </Button>
              {confirming.tipo === 'cerrar' ? (
                <Button
                  variant="danger"
                  onClick={() => cerrar.mutate(confirming.periodo.id)}
                  disabled={cerrar.isPending}
                >
                  {cerrar.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
                  Confirmar cierre
                </Button>
              ) : (
                <Button
                  variant="danger"
                  disabled={motivo.trim().length < 3 || reabrir.isPending}
                  onClick={() =>
                    reabrir.mutate({ id: confirming.periodo.id, motivo: motivo.trim() })
                  }
                >
                  {reabrir.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
                  Confirmar reapertura
                </Button>
              )}
            </>
          )
        }
      >
        {confirming?.tipo === 'cerrar' && (
          <>
            <div className="flex gap-2 p-3 bg-warning-bg text-warning rounded-md mb-3">
              <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
              <div className="text-[12px]">
                Al cerrar no se podrán crear ni editar asientos con fecha dentro del período. Para corregir uno
                contabilizado después del cierre hay que <strong>reabrir primero</strong>. Todos los asientos en
                BORRADOR deben estar contabilizados o eliminados antes.
              </div>
            </div>
            <p className="text-[12px] text-ink-2">
              Se generará un snapshot de saldos + hash SHA-256 de cierre. Quedará auditado.
            </p>
          </>
        )}
        {confirming?.tipo === 'reabrir' && (
          <>
            <p className="text-[12px] text-ink-2 mb-3">
              Reabrir un período cerrado es una operación sensible y queda registrada en el audit log. Indicá el
              motivo.
            </p>
            <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
              Motivo
            </label>
            <textarea
              rows={3}
              className="w-full px-3 py-2 text-[13px] border border-line-strong rounded-md"
              placeholder="Ej: Ajuste contable solicitado por el estudio externo"
              value={motivo}
              onChange={(e) => setMotivo(e.target.value)}
            />
          </>
        )}
      </Modal>

      {/* ============ MODAL CERRAR EJERCICIO ============ */}
      <Modal
        open={cerrarEjercicioOpen}
        onClose={() => setCerrarEjercicioOpen(false)}
        title={ejercicioActual ? `Cerrar ejercicio ${ejercicioActual.numero}` : 'Cerrar ejercicio'}
        size="md"
        footer={
          ejercicioActual && (
            <>
              <Button variant="secondary" onClick={() => setCerrarEjercicioOpen(false)}>
                Cancelar
              </Button>
              <Button
                variant="danger"
                disabled={cerrarEjercicio.isPending}
                onClick={() => cerrarEjercicio.mutate(ejercicioActual.id)}
              >
                {cerrarEjercicio.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
                <Lock className="w-3 h-3" /> Cerrar ejercicio + generar refundición
              </Button>
            </>
          )
        }
      >
        <div className="flex gap-2 p-3 bg-warning-bg text-warning rounded-md mb-3">
          <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
          <div className="text-[12px]">
            Acción <strong>irreversible</strong>. Se va a:
            <ul className="list-disc ml-5 mt-1 space-y-0.5">
              <li>Calcular saldos netos de todas las cuentas de resultado (ingresos y egresos)</li>
              <li>Generar un asiento de refundición automático en el diario CIE, con fecha del último día del ejercicio</li>
              <li>Acreditar (si ganancia) o debitar (si pérdida) la cuenta <span className="font-mono">3.3.02 Resultado del Ejercicio</span></li>
              <li>Cerrar el último período del ejercicio</li>
              <li>Marcar el ejercicio como CERRADO</li>
              <li>Registrar hash de cierre en el audit log</li>
            </ul>
          </div>
        </div>

        <p className="text-[12px] text-ink-2 mb-2">
          <strong>Precondición:</strong> el último período del ejercicio debe estar ABIERTO y sin asientos en BORRADOR.
        </p>
        <p className="text-[12px] text-ink-muted">
          Si necesitás reabrir el ejercicio después (para ajustes del estudio contable), requerirá el permiso{' '}
          <span className="font-mono">contabilidad.ejercicios.reabrir</span> (solo super_admin).
        </p>
      </Modal>

      {/* ============ MODAL REABRIR EJERCICIO ============ */}
      <Modal
        open={reabrirEjercicioOpen}
        onClose={() => {
          setReabrirEjercicioOpen(false);
          setMotivo('');
        }}
        title={ejercicioActual ? `Reabrir ejercicio ${ejercicioActual.numero}` : 'Reabrir ejercicio'}
        size="sm"
        footer={
          ejercicioActual && (
            <>
              <Button variant="secondary" onClick={() => setReabrirEjercicioOpen(false)}>
                Cancelar
              </Button>
              <Button
                variant="danger"
                disabled={motivo.trim().length < 3 || reabrirEjercicio.isPending}
                onClick={() => reabrirEjercicio.mutate({ id: ejercicioActual.id, motivo: motivo.trim() })}
              >
                {reabrirEjercicio.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
                Reabrir ejercicio
              </Button>
            </>
          )
        }
      >
        <p className="text-[12px] text-ink-2 mb-3">
          Operación sensible. Queda auditada. El asiento de refundición previo permanece en libros (hay que anularlo
          manualmente si se quiere eliminar su efecto antes de volver a cerrar).
        </p>
        <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
          Motivo
        </label>
        <textarea
          rows={3}
          className="w-full px-3 py-2 text-[13px] border border-line-strong rounded-md"
          placeholder="Ej: Ajuste solicitado por el estudio contable externo"
          value={motivo}
          onChange={(e) => setMotivo(e.target.value)}
        />
      </Modal>
    </>
  );
}
