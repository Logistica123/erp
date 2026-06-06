import { useMemo, useState } from 'react';
import { Users, ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { fmtDate } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Caja = { id: number; codigo: string; nombre: string };
type UserOpt = { id: number; name: string; email: string };

type Operador = {
  id: number;
  caja_id: number;
  caja_codigo: string;
  user_id: number;
  user_name: string;
  user_email: string;
  fecha_alta: string;
  fecha_baja: string | null;
  motivo_alta: string | null;
  motivo_baja: string | null;
};

export function CajaOperadoresPage() {
  const [cajaId, setCajaId] = useState('');
  const [altaOpen, setAltaOpen] = useState(false);
  const [bajaTarget, setBajaTarget] = useState<Operador | null>(null);

  const { data: cajas } = useApi<Caja[]>(['cajas'], '/api/erp/cajas');
  const qs = cajaId ? `?caja_id=${cajaId}` : '';
  const { data: rows, isLoading, error } = useApi<Operador[]>(
    ['caja-operadores', cajaId],
    `/api/erp/caja/operadores${qs}`,
  );

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Users className="w-4 h-4 text-azure" /> Operadores autorizados</div>}
          actions={
            <div className="flex gap-2">
              <Link to="/erp/arqueos">
                <Button variant="secondary"><ArrowLeft className="w-3 h-3" /> Volver</Button>
              </Link>
              <Button variant="primary" onClick={() => setAltaOpen(true)}>
                <Plus className="w-3 h-3" /> Alta operador
              </Button>
            </div>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex gap-3">
            <SelectField label="Caja" value={cajaId} placeholder="Todas"
              onChange={(e) => setCajaId(e.target.value)}
              containerClassName="w-[260px]"
              options={(cajas ?? []).map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))} />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
          <div className="border border-line rounded-md overflow-hidden">
            <table className="w-full text-[12.5px]">
              <thead className="bg-surface-row">
                <tr className="text-left">
                  <th className="px-2 py-1.5">Caja</th>
                  <th className="px-2 py-1.5">Usuario</th>
                  <th className="px-2 py-1.5">Email</th>
                  <th className="px-2 py-1.5">Alta</th>
                  <th className="px-2 py-1.5">Baja</th>
                  <th className="px-2 py-1.5">Estado</th>
                  <th className="px-2 py-1.5"></th>
                </tr>
              </thead>
              <tbody>
                {(rows ?? []).map((op) => (
                  <tr key={op.id} className="border-t border-line">
                    <td className="px-2 py-1">{op.caja_codigo}</td>
                    <td className="px-2 py-1">{op.user_name}</td>
                    <td className="px-2 py-1 text-ink-3">{op.user_email}</td>
                    <td className="px-2 py-1">{fmtDate(op.fecha_alta)}</td>
                    <td className="px-2 py-1">{op.fecha_baja ? fmtDate(op.fecha_baja) : '—'}</td>
                    <td className="px-2 py-1">
                      <Badge variant={op.fecha_baja ? 'neutral' : 'success'}>
                        {op.fecha_baja ? 'Inactivo' : 'Activo'}
                      </Badge>
                    </td>
                    <td className="px-2 py-1 text-right">
                      {!op.fecha_baja && (
                        <Button variant="secondary" onClick={() => setBajaTarget(op)}>
                          <Trash2 className="w-3 h-3" /> Baja
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
                {!isLoading && (rows ?? []).length === 0 && (
                  <tr><td colSpan={7} className="px-2 py-4 text-center text-ink-3">Sin operadores cargados.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>

      {altaOpen && <AltaOperadorModal cajas={cajas ?? []} onClose={() => setAltaOpen(false)} />}
      {bajaTarget && <BajaOperadorModal operador={bajaTarget} onClose={() => setBajaTarget(null)} />}
    </div>
  );
}

function AltaOperadorModal({ cajas, onClose }: { cajas: Caja[]; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['caja-operadores']);
  const [form, setForm] = useState({ caja_id: '', user_id: '', motivo_alta: '' });

  const { data: users } = useApi<UserOpt[]>(['users-lookup'], '/api/erp/users-lookup');

  const userOptions = useMemo(() => (users ?? []).map((u) => ({
    value: u.id, label: `${u.name} (${u.email})`,
  })), [users]);

  const m = useApiMutation<{ id: number }, Record<string, unknown>>(
    (vars) => api.post('/api/erp/caja/operadores', vars),
    {
      onSuccess: () => { toast.success('Operador autorizado'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo dar de alta', errorMessage(e)),
    },
  );

  const valid = form.caja_id && form.user_id;

  return (
    <Modal open onClose={onClose} title="Alta de operador" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              caja_id: Number(form.caja_id), user_id: Number(form.user_id),
              motivo_alta: form.motivo_alta || undefined,
            })}>
            {m.isPending ? 'Guardando…' : 'Autorizar'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <SelectField label="Caja" required value={form.caja_id}
          onChange={(e) => setForm({ ...form, caja_id: e.target.value })}
          options={cajas.map((c) => ({ value: c.id, label: `${c.codigo} ${c.nombre}` }))}
          placeholder="Elegí caja…" />
        <SelectField label="Usuario" required value={form.user_id}
          onChange={(e) => setForm({ ...form, user_id: e.target.value })}
          options={userOptions} placeholder="Elegí usuario…" />
        <Field label="Motivo (opcional)" value={form.motivo_alta}
          onChange={(e) => setForm({ ...form, motivo_alta: e.target.value })}
          placeholder="Ej: cajero turno mañana" />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function BajaOperadorModal({ operador, onClose }: { operador: Operador; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['caja-operadores']);
  const [motivo, setMotivo] = useState('');

  const m = useApiMutation<{ ok: boolean }, Record<string, unknown>>(
    (vars) => api.delete(`/api/erp/caja/operadores/${operador.id}`, vars),
    {
      onSuccess: () => { toast.success('Operador dado de baja'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo dar de baja', errorMessage(e)),
    },
  );

  const valid = motivo.trim().length >= 5;

  return (
    <Modal open onClose={onClose} title={`Baja de operador: ${operador.user_name}`} size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={!valid || m.isPending}
            onClick={() => m.mutate({ motivo_baja: motivo })}>
            {m.isPending ? 'Guardando…' : 'Confirmar baja'}
          </Button>
        </>
      }>
      <div className="space-y-3 text-[12.5px]">
        <div className="text-ink-2">
          {operador.user_name} dejará de poder operar la caja <strong>{operador.caja_codigo}</strong>.
        </div>
        <Field label="Motivo de la baja *" value={motivo}
          onChange={(e) => setMotivo(e.target.value)} placeholder="Mín 5 caracteres" />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
