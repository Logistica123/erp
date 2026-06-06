import { useState } from 'react';
import { ClipboardCheck, ArrowLeft } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { fmtMoney, fmtDate } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Caja = { id: number; codigo: string; nombre: string };

type Arqueo = {
  id: number;
  caja: Caja;
  fecha: string;
  saldo_teorico: number | string;
  saldo_fisico: number | string;
  diferencia: number | string;
  motivo?: string | null;
  estado: string;
  realizado_por?: { id: number; name: string } | null;
};

export function ArqueosPendientesPage() {
  const { data: rows, isLoading, error } = useApi<Arqueo[]>(
    ['arqueos-pendientes'],
    '/api/erp/caja/arqueos-pendientes',
  );
  const [autorizar, setAutorizar] = useState<Arqueo | null>(null);

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={
            <div className="flex items-center gap-2">
              <ClipboardCheck className="w-4 h-4 text-warning" />
              Arqueos pendientes de autorización
            </div>
          }
          actions={
            <Link to="/erp/arqueos">
              <Button variant="secondary"><ArrowLeft className="w-3 h-3" /> Volver</Button>
            </Link>
          }
        />
        <CardBody className="p-4 space-y-3">
          {error && <FormError error={errorMessage(error)} />}
          {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
          {!isLoading && (rows ?? []).length === 0 && (
            <div className="text-ink-3 text-[12.5px] py-6 text-center">
              No hay arqueos pendientes.
            </div>
          )}
          <div className="space-y-2">
            {(rows ?? []).map((a) => {
              const dif = Number(a.diferencia);
              return (
                <div key={a.id} className="border border-line rounded-md p-3 flex items-center justify-between gap-3">
                  <div className="flex-1 grid grid-cols-2 md:grid-cols-5 gap-3 text-[12.5px]">
                    <div>
                      <div className="text-ink-3 text-[11px]">Caja / Fecha</div>
                      <div className="font-medium">{a.caja.codigo} · {fmtDate(a.fecha)}</div>
                    </div>
                    <div>
                      <div className="text-ink-3 text-[11px]">Teórico</div>
                      <div className="tabular-nums">{fmtMoney(a.saldo_teorico)}</div>
                    </div>
                    <div>
                      <div className="text-ink-3 text-[11px]">Físico</div>
                      <div className="tabular-nums">{fmtMoney(a.saldo_fisico)}</div>
                    </div>
                    <div>
                      <div className="text-ink-3 text-[11px]">Diferencia</div>
                      <Badge variant={dif > 0 ? 'warning' : 'danger'}>{fmtMoney(dif)}</Badge>
                    </div>
                    <div>
                      <div className="text-ink-3 text-[11px]">Operador</div>
                      <div>{a.realizado_por?.name ?? '—'}</div>
                    </div>
                  </div>
                  <Button variant="primary" onClick={() => setAutorizar(a)}>Resolver</Button>
                </div>
              );
            })}
          </div>
        </CardBody>
      </Card>

      {autorizar && (
        <AutorizarModal arqueo={autorizar} onClose={() => setAutorizar(null)} />
      )}
    </div>
  );
}

function AutorizarModal({ arqueo, onClose }: { arqueo: Arqueo; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['arqueos-pendientes'], ['arqueos']);
  const [decision, setDecision] = useState('');
  const [motivo, setMotivo] = useState('');
  const dif = Number(arqueo.diferencia);

  const m = useApiMutation<Arqueo, Record<string, unknown>>(
    (vars) => api.post(`/api/erp/caja/arqueos/${arqueo.id}/autorizar`, vars),
    {
      onSuccess: () => {
        toast.success('Arqueo resuelto');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo autorizar', errorMessage(e)),
    },
  );

  const needsMotivo = decision !== 'AJUSTAR';
  const valid = !!decision && (!needsMotivo || motivo.trim().length >= 10);

  return (
    <Modal
      open
      onClose={onClose}
      title={`Resolver arqueo #${arqueo.id}`}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({ decision, motivo: motivo || undefined })}>
            {m.isPending ? 'Resolviendo…' : 'Confirmar decisión'}
          </Button>
        </>
      }
    >
      <div className="space-y-3 text-[12.5px]">
        <div className="bg-surface-row border border-line rounded-md p-3 grid grid-cols-3 gap-3">
          <div><div className="text-ink-3 text-[11px]">Teórico</div><div className="tabular-nums">{fmtMoney(arqueo.saldo_teorico)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Físico</div><div className="tabular-nums">{fmtMoney(arqueo.saldo_fisico)}</div></div>
          <div><div className="text-ink-3 text-[11px]">Diferencia</div><Badge variant={dif > 0 ? 'warning' : 'danger'}>{fmtMoney(dif)}</Badge></div>
        </div>
        {arqueo.motivo && (
          <div className="text-ink-2">
            <div className="text-ink-3 text-[11px]">Motivo del operador</div>
            <div>{arqueo.motivo}</div>
          </div>
        )}
        <SelectField label="Decisión" required value={decision}
          onChange={(e) => setDecision(e.target.value)}
          options={[
            { value: 'AJUSTAR', label: 'AJUSTAR — generar asiento RN-23 y ajustar saldo' },
            { value: 'CERRAR_CON_DISCREPANCIA', label: 'CERRAR CON DISCREPANCIA — sin asiento, queda documentada' },
            { value: 'RECHAZAR', label: 'RECHAZAR — operador deberá registrar nuevo arqueo' },
          ]}
          placeholder="Elegí una opción…"
        />
        <TextareaField label={`Motivo de la decisión${needsMotivo ? ' *' : ''}`}
          value={motivo} rows={3}
          onChange={(e) => setMotivo(e.target.value)}
          hint={needsMotivo ? 'Mínimo 10 caracteres.' : 'Opcional cuando decidís AJUSTAR.'} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
