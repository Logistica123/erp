import { useQuery } from '@tanstack/react-query';
import { ClipboardList, CheckCircle2, XCircle } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, FormError } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { api } from '@/lib/api';
import { useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';
import { useState } from 'react';

type FceFila = {
  id: number;
  fecha_emision: string;
  numero: number;
  imp_total: number | string;
  estado: string;
  estado_fce: string;
  letra: string;
  tipo: string;
  cliente: string;
  cuit: string;
};

type FceResp = {
  comprobantes: FceFila[];
  por_estado_fce: Record<string, { cantidad: number; importe_total: number }>;
};

const ESTADO_FCE_BADGES: Record<string, 'success' | 'danger' | 'warning' | 'info' | 'neutral'> = {
  EMITIDA_FCE: 'info',
  ACEPTADA_FCE: 'success',
  ACEPTADA_TACITAMENTE: 'success',
  RECHAZADA_FCE: 'danger',
  NEGOCIADA_SIRCREB: 'info',
  NO_APLICA: 'neutral',
};

export function FcePage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['fce-estados'],
    queryFn: () => api.get<{ data: FceResp }>('/api/erp/reportes/fce-estados'),
  });

  const [aceptarOpen, setAceptarOpen] = useState<FceFila | null>(null);
  const [rechazarOpen, setRechazarOpen] = useState<FceFila | null>(null);

  const totalEmitida = data?.data.por_estado_fce?.EMITIDA_FCE?.importe_total ?? 0;
  const totalAceptada = (data?.data.por_estado_fce?.ACEPTADA_FCE?.importe_total ?? 0)
    + (data?.data.por_estado_fce?.ACEPTADA_TACITAMENTE?.importe_total ?? 0);
  const totalRechazada = data?.data.por_estado_fce?.RECHAZADA_FCE?.importe_total ?? 0;

  const columns: Column<FceFila>[] = [
    { key: 'fecha_emision', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha_emision) },
    { key: 'comprobante', header: 'Comprobante', width: '180px',
      render: (r) => `${r.letra} ${r.tipo} #${String(r.numero).padStart(8, '0')}` },
    { key: 'cliente', header: 'Cliente',
      render: (r) => <div>
        <div className="text-[12.5px]">{r.cliente}</div>
        <div className="text-[10.5px] text-ink-muted">CUIT {r.cuit}</div>
      </div> },
    { key: 'imp_total', header: 'Importe', align: 'right', width: '130px',
      render: (r) => fmtMoney(r.imp_total) },
    { key: 'estado_fce', header: 'Estado FCE', width: '180px',
      render: (r) => <Badge variant={ESTADO_FCE_BADGES[r.estado_fce] ?? 'neutral'}>{r.estado_fce}</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '180px',
      render: (r) => (
        <div className="flex justify-end gap-1.5">
          {r.estado_fce === 'EMITIDA_FCE' && (
            <>
              <Button size="sm" variant="primary" onClick={(e) => { e.stopPropagation(); setAceptarOpen(r); }}>
                <CheckCircle2 className="w-3 h-3" /> Aceptar
              </Button>
              <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setRechazarOpen(r); }}>
                <XCircle className="w-3 h-3" />
              </Button>
            </>
          )}
        </div>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><ClipboardList className="w-4 h-4 text-azure" /> FCE MiPyME</div>
        } />
        <CardBody className="p-4 space-y-3">
          {error && <FormError error={errorMessage(error)} />}
          <div className="grid grid-cols-3 gap-3">
            <KPI label="Emitidas" total={totalEmitida} variant="info" />
            <KPI label="Aceptadas" total={totalAceptada} variant="success" />
            <KPI label="Rechazadas" total={totalRechazada} variant="danger" />
          </div>
          <DataTable
            columns={columns}
            rows={data?.data.comprobantes ?? []}
            loading={isLoading}
            empty="Sin facturas FCE"
          />
        </CardBody>
      </Card>

      {aceptarOpen && <AceptarConfirm factura={aceptarOpen} onClose={() => setAceptarOpen(null)} />}
      {rechazarOpen && <RechazarModal factura={rechazarOpen} onClose={() => setRechazarOpen(null)} />}
    </div>
  );
}

function KPI({ label, total, variant }: { label: string; total: number; variant: 'success' | 'danger' | 'warning' | 'info' }) {
  return (
    <div className="border border-line rounded-md p-3 bg-white">
      <div className="text-[11px] text-ink-muted uppercase tracking-wide">{label}</div>
      <div className="mt-1 flex items-end justify-between">
        <strong className="text-[16px] tabular-nums">{fmtMoney(total)}</strong>
        <Badge variant={variant}>{label}</Badge>
      </div>
    </div>
  );
}

function AceptarConfirm({ factura, onClose }: { factura: FceFila; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['fce-estados']);
  const m = useApiMutation<unknown>(
    () => api.post(`/api/erp/facturas-venta/${factura.id}/fce-aceptada`),
    {
      onSuccess: () => {
        toast.success('FCE aceptada', `${factura.letra} ${factura.tipo} ${factura.numero}`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo aceptar', errorMessage(e)),
    }
  );
  return (
    <ConfirmDialog
      open onClose={onClose}
      onConfirm={() => m.mutate(undefined as unknown as void)}
      title="Aceptar FCE"
      message={
        <>
          Marcar como ACEPTADA la FCE de <strong>{factura.cliente}</strong> por{' '}
          <strong>{fmtMoney(factura.imp_total)}</strong>.
        </>
      }
      loading={m.isPending}
    />
  );
}

function RechazarModal({ factura, onClose }: { factura: FceFila; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['fce-estados']);
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<unknown, { motivo: string }>(
    (vars) => api.post(`/api/erp/facturas-venta/${factura.id}/fce-rechazada`, vars),
    {
      onSuccess: () => {
        toast.success('FCE rechazada');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo rechazar', errorMessage(e)),
    }
  );
  return (
    <Modal open onClose={onClose}
      title={`Rechazar FCE Nº ${factura.numero}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={motivo.trim().length < 3 || m.isPending}
            onClick={() => m.mutate({ motivo: motivo.trim() })}>
            {m.isPending ? 'Rechazando…' : 'Rechazar'}
          </Button>
        </>
      }
    >
      <Field label="Motivo del rechazo" required value={motivo}
        onChange={(e) => setMotivo(e.target.value)} placeholder="Mín. 3 caracteres" />
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}
