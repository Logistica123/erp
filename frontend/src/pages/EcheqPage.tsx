import { useMemo, useState } from 'react';
import { Banknote, Trash2, Building2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Echeq = {
  id: number;
  tipo: 'PROPIO' | 'TERCERO';
  numero: string;
  cuit_librador: string;
  razon_social_librador: string;
  importe: number | string;
  moneda: { id: number; codigo: string };
  fecha_emision: string;
  fecha_pago: string;
  estado: string;
  cuenta_deposito_id: number | null;
  cuenta_deposito?: { id: number; codigo: string; nombre: string };
  cobro?: { id: number; numero: string };
};

type CuentaBancaria = { id: number; codigo: string; nombre: string };

const ESTADOS = [
  'EN_CARTERA', 'DEPOSITADO', 'ACREDITADO', 'RECHAZADO', 'ENTREGADO', 'ANULADO',
];

function badgeFor(estado: string) {
  switch (estado) {
    case 'ACREDITADO':  return 'success' as const;
    case 'RECHAZADO':
    case 'ANULADO':     return 'danger' as const;
    case 'EN_CARTERA':  return 'info' as const;
    case 'DEPOSITADO':  return 'warning' as const;
    default:            return 'neutral' as const;
  }
}

export function EcheqPage() {
  const [estado, setEstado] = useState('');
  const [librador, setLibrador] = useState('');
  const [page, setPage] = useState(1);

  const qs = useMemo(() => {
    const params = new URLSearchParams();
    if (estado)   params.set('estado', estado);
    if (librador) params.set('librador', librador);
    if (page > 1) params.set('page', String(page));
    return params.toString();
  }, [estado, librador, page]);

  const { data, isLoading, error } = useApi<Paginator<Echeq>>(
    ['echeq', qs],
    `/api/erp/echeq${qs ? `?${qs}` : ''}`
  );

  const [depositOpen, setDepositOpen] = useState<Echeq | null>(null);
  const [rejectOpen, setRejectOpen]   = useState<Echeq | null>(null);
  const [annulOpen, setAnnulOpen]     = useState<Echeq | null>(null);

  const columns: Column<Echeq>[] = [
    { key: 'fecha_pago', header: 'Pago', width: '90px', render: (r) => fmtDate(r.fecha_pago) },
    { key: 'tipo', header: 'Tipo', width: '70px', render: (r) => (
        <Badge variant={r.tipo === 'PROPIO' ? 'warning' : 'info'}>{r.tipo}</Badge>
      ) },
    { key: 'numero', header: 'Nº', width: '120px' },
    { key: 'razon_social_librador', header: 'Librador',
      render: (r) => <div>
        <div className="text-[12.5px]">{r.razon_social_librador}</div>
        <div className="text-[10.5px] text-ink-muted">CUIT {r.cuit_librador}</div>
      </div> },
    { key: 'importe', header: 'Importe', align: 'right', width: '110px',
      render: (r) => `${r.moneda?.codigo ?? ''} ${fmtMoney(r.importe)}` },
    { key: 'estado', header: 'Estado', width: '120px',
      render: (r) => <Badge variant={badgeFor(r.estado)}>{r.estado}</Badge> },
    { key: 'cuenta_deposito', header: 'Cta depósito', width: '140px',
      render: (r) => r.cuenta_deposito ? `${r.cuenta_deposito.codigo} ${r.cuenta_deposito.nombre}` : '—' },
    { key: 'acciones', header: '', align: 'right', width: '180px',
      render: (r) => (
        <div className="flex justify-end gap-1.5">
          {r.estado === 'EN_CARTERA' && r.tipo === 'TERCERO' && (
            <Button size="sm" variant="primary" onClick={(e) => { e.stopPropagation(); setDepositOpen(r); }}>
              <Building2 className="w-3 h-3" /> Depositar
            </Button>
          )}
          {(r.estado === 'DEPOSITADO' || r.estado === 'EN_CARTERA') && (
            <Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); setRejectOpen(r); }}>
              Rechazar
            </Button>
          )}
          {r.estado !== 'ANULADO' && r.estado !== 'ACREDITADO' && r.estado !== 'RECHAZADO' && (
            <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setAnnulOpen(r); }}>
              <Trash2 className="w-3 h-3" />
            </Button>
          )}
        </div>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><Banknote className="w-4 h-4 text-azure" /> eCheqs</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <SelectField
              label="Estado"
              value={estado}
              onChange={(e) => { setEstado(e.target.value); setPage(1); }}
              placeholder="Todos"
              containerClassName="w-[160px]"
              options={ESTADOS.map((s) => ({ value: s, label: s }))}
            />
            <Field
              label="Librador"
              value={librador}
              onChange={(e) => { setLibrador(e.target.value); setPage(1); }}
              placeholder="CUIT o razón social…"
              containerClassName="w-[260px]"
            />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable
            columns={columns}
            paginator={data}
            loading={isLoading}
            empty="Sin eCheqs"
            onPageChange={setPage}
          />
        </CardBody>
      </Card>

      {depositOpen && <DepositarModal echeq={depositOpen} onClose={() => setDepositOpen(null)} />}
      {rejectOpen  && <MotivoModal echeq={rejectOpen}  action="rechazar" onClose={() => setRejectOpen(null)} />}
      {annulOpen   && <MotivoModal echeq={annulOpen}   action="anular"   onClose={() => setAnnulOpen(null)} />}
    </div>
  );
}

function DepositarModal({ echeq, onClose }: { echeq: Echeq; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['echeq']);
  const { data: cuentas } = useApi<CuentaBancaria[]>(
    ['cuentas-bancarias'],
    '/api/erp/cuentas-bancarias'
  );
  const [cuentaId, setCuentaId] = useState('');

  const m = useApiMutation<Echeq, { cuenta_bancaria_id: number }>(
    (vars) => api.post(`/api/erp/echeq/${echeq.id}/depositar`, vars),
    {
      onSuccess: () => {
        toast.success('eCheq depositado', `Nº ${echeq.numero} → cuenta seleccionada`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo depositar', errorMessage(e)),
    }
  );

  return (
    <Modal
      open
      onClose={onClose}
      title={`Depositar eCheq Nº ${echeq.numero}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button
            variant="primary"
            disabled={!cuentaId || m.isPending}
            onClick={() => m.mutate({ cuenta_bancaria_id: Number(cuentaId) })}
          >
            {m.isPending ? 'Depositando…' : 'Confirmar depósito'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="text-[12.5px] text-ink-2">
          Librador: <span className="font-medium">{echeq.razon_social_librador}</span>
          <br />
          Importe: <span className="font-medium">{echeq.moneda?.codigo} {fmtMoney(echeq.importe)}</span>
        </div>
        <SelectField
          label="Cuenta bancaria de depósito"
          required
          value={cuentaId}
          onChange={(e) => setCuentaId(e.target.value)}
          placeholder="Elegí una cuenta…"
          options={(cuentas ?? []).map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))}
        />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function MotivoModal({
  echeq, action, onClose,
}: {
  echeq: Echeq;
  action: 'rechazar' | 'anular';
  onClose: () => void;
}) {
  const toast = useToast();
  const invalidate = useInvalidate(['echeq']);
  const [motivo, setMotivo] = useState('');

  const m = useApiMutation<Echeq, { motivo: string }>(
    (vars) => api.post(`/api/erp/echeq/${echeq.id}/${action}`, vars),
    {
      onSuccess: () => {
        toast.success(`eCheq ${action === 'anular' ? 'anulado' : 'rechazado'}`,
          `Nº ${echeq.numero}`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo procesar', errorMessage(e)),
    }
  );

  const isDanger = action === 'anular' || action === 'rechazar';
  return (
    <Modal
      open
      onClose={onClose}
      title={`${action === 'anular' ? 'Anular' : 'Rechazar'} eCheq Nº ${echeq.numero}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button
            variant={isDanger ? 'danger' : 'primary'}
            disabled={motivo.trim().length < 3 || m.isPending}
            onClick={() => m.mutate({ motivo: motivo.trim() })}
          >
            {m.isPending ? 'Procesando…' : 'Confirmar'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="text-[12.5px] text-ink-2">
          Esta acción es irreversible. Indicá el motivo.
        </div>
        <Field
          label="Motivo"
          required
          value={motivo}
          onChange={(e) => setMotivo(e.target.value)}
          placeholder="Mín. 3 caracteres"
        />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
