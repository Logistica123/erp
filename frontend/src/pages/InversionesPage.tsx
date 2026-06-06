import { useState } from 'react';
import { TrendingUp, Plus, ArrowRight } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { fmtMoney, fmtDate } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Inversion = {
  id: number; nombre: string; tipo: 'FCI' | 'PLAZO_FIJO' | 'CAUCION' | 'BONO' | 'OTRO';
  entidad: string; moneda: string;
  saldo_actual: number | string; ganancia_acumulada: number | string;
  fecha_alta: string; fecha_vencimiento: string | null;
  plazo_dias: number | null; tasa_nominal: number | string | null;
  activo: number | boolean;
};

type IndexResp = { inversiones: Inversion[]; totales: { saldo_total: number; ganancia_total: number } };

type Movimiento = {
  id: number; fecha: string; tipo: string; importe: number | string;
  saldo_segun_rys: number | string; saldo_segun_fondo: number | string | null;
  observaciones: string | null; registrado_por_nombre: string | null;
};

export function InversionesPage() {
  const [crearOpen, setCrearOpen] = useState(false);
  const [detalleId, setDetalleId] = useState<number | null>(null);
  const [movOpen, setMovOpen] = useState<Inversion | null>(null);

  const { data, isLoading, error } = useApi<IndexResp>(['inversiones'], '/api/erp/inversiones?empresa_id=1');

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><TrendingUp className="w-4 h-4 text-azure" /> Inversiones</div>}
          actions={
            <Button variant="primary" onClick={() => setCrearOpen(true)}>
              <Plus className="w-3 h-3" /> Nueva inversión
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          {error && <FormError error={errorMessage(error)} />}
          {data && (
            <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
              <Stat label="Total invertido" value={fmtMoney(data.totales.saldo_total)} />
              <Stat label="Ganancia acumulada YTD" value={fmtMoney(data.totales.ganancia_total)} positive={data.totales.ganancia_total > 0} />
              <Stat label="Inversiones activas" value={String(data.inversiones.filter((i) => i.activo).length)} />
            </div>
          )}
          {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
          <div className="border border-line rounded-md overflow-hidden">
            <table className="w-full text-[12.5px]">
              <thead className="bg-surface-row">
                <tr className="text-left">
                  <th className="px-2 py-1.5">Inversión</th>
                  <th className="px-2 py-1.5">Tipo</th>
                  <th className="px-2 py-1.5">Entidad</th>
                  <th className="px-2 py-1.5 text-right">Saldo</th>
                  <th className="px-2 py-1.5 text-right">Ganancia</th>
                  <th className="px-2 py-1.5">Vencimiento</th>
                  <th className="px-2 py-1.5">Estado</th>
                  <th className="px-2 py-1.5"></th>
                </tr>
              </thead>
              <tbody>
                {(data?.inversiones ?? []).map((i) => (
                  <tr key={i.id} className="border-t border-line hover:bg-surface-row">
                    <td className="px-2 py-1 font-medium">{i.nombre}</td>
                    <td className="px-2 py-1">
                      <Badge variant={i.tipo === 'FCI' ? 'info' : i.tipo === 'PLAZO_FIJO' ? 'warning' : 'neutral'}>{i.tipo}</Badge>
                    </td>
                    <td className="px-2 py-1">{i.entidad}</td>
                    <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(i.saldo_actual)}</td>
                    <td className="px-2 py-1 text-right tabular-nums text-success">{fmtMoney(i.ganancia_acumulada)}</td>
                    <td className="px-2 py-1">{i.fecha_vencimiento ? fmtDate(i.fecha_vencimiento) : '—'}</td>
                    <td className="px-2 py-1">
                      <Badge variant={i.activo ? 'success' : 'neutral'}>{i.activo ? 'Activa' : 'Cerrada'}</Badge>
                    </td>
                    <td className="px-2 py-1 text-right whitespace-nowrap">
                      <Button variant="secondary" onClick={() => setDetalleId(i.id)}>Histórico</Button>
                      {' '}
                      {i.activo ? (
                        <Button variant="primary" onClick={() => setMovOpen(i)}>
                          <ArrowRight className="w-3 h-3" /> Movimiento
                        </Button>
                      ) : null}
                    </td>
                  </tr>
                ))}
                {!isLoading && (data?.inversiones ?? []).length === 0 && (
                  <tr><td colSpan={8} className="px-2 py-6 text-center text-ink-3">Sin inversiones cargadas.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>

      {crearOpen && <CrearInversionModal onClose={() => setCrearOpen(false)} />}
      {detalleId && <HistoricoModal inversionId={detalleId} onClose={() => setDetalleId(null)} />}
      {movOpen && <MovimientoModal inversion={movOpen} onClose={() => setMovOpen(null)} />}
    </div>
  );
}

function Stat({ label, value, positive }: { label: string; value: string; positive?: boolean }) {
  return (
    <div className="border border-line rounded-md p-3">
      <div className="text-[11px] text-ink-3 uppercase">{label}</div>
      <div className={`text-[16px] font-semibold tabular-nums ${positive ? 'text-success' : ''}`}>{value}</div>
    </div>
  );
}

function CrearInversionModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['inversiones']);
  const [form, setForm] = useState({
    nombre: '', tipo: 'FCI', entidad: '', moneda: 'ARS',
    fecha_alta: new Date().toISOString().slice(0, 10),
    plazo_dias: '', tasa_nominal: '', fecha_vencimiento: '',
  });

  const m = useApiMutation<{ id: number }, Record<string, unknown>>(
    (v) => api.post('/api/erp/inversiones', v),
    {
      onSuccess: () => { toast.success('Inversión creada'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo crear', errorMessage(e)),
    },
  );

  const isPF = form.tipo === 'PLAZO_FIJO' || form.tipo === 'CAUCION';
  const valid = form.nombre && form.tipo && form.entidad && form.fecha_alta;

  return (
    <Modal open onClose={onClose} title="Nueva inversión" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              empresa_id: 1,
              nombre: form.nombre, tipo: form.tipo, entidad: form.entidad, moneda: form.moneda,
              fecha_alta: form.fecha_alta,
              plazo_dias: isPF && form.plazo_dias ? Number(form.plazo_dias) : undefined,
              tasa_nominal: isPF && form.tasa_nominal ? Number(form.tasa_nominal) : undefined,
              fecha_vencimiento: isPF && form.fecha_vencimiento ? form.fecha_vencimiento : undefined,
            })}>{m.isPending ? 'Guardando…' : 'Crear'}</Button>
        </>
      }>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Nombre *" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} />
          <SelectField label="Tipo *" value={form.tipo} onChange={(e) => setForm({ ...form, tipo: e.target.value })}
            options={[
              { value: 'FCI', label: 'FCI (Fondo Común)' },
              { value: 'PLAZO_FIJO', label: 'Plazo Fijo' },
              { value: 'CAUCION', label: 'Caución' },
              { value: 'BONO', label: 'Bono' },
              { value: 'OTRO', label: 'Otro' },
            ]} />
          <Field label="Entidad *" value={form.entidad} onChange={(e) => setForm({ ...form, entidad: e.target.value })}
            placeholder="ICBC, Galicia, Alpha, etc." />
          <SelectField label="Moneda" value={form.moneda} onChange={(e) => setForm({ ...form, moneda: e.target.value })}
            options={[{ value: 'ARS', label: 'ARS' }, { value: 'USD', label: 'USD' }]} />
          <Field label="Fecha alta *" type="date" value={form.fecha_alta}
            onChange={(e) => setForm({ ...form, fecha_alta: e.target.value })} />
        </div>
        {isPF && (
          <div className="grid grid-cols-3 gap-3">
            <Field label="Plazo (días)" type="number" value={form.plazo_dias}
              onChange={(e) => setForm({ ...form, plazo_dias: e.target.value })} />
            <Field label="TNA (%)" type="number" step="0.0001" value={form.tasa_nominal}
              onChange={(e) => setForm({ ...form, tasa_nominal: e.target.value })} />
            <Field label="Vencimiento" type="date" value={form.fecha_vencimiento}
              onChange={(e) => setForm({ ...form, fecha_vencimiento: e.target.value })}
              hint="Si vacío, se calcula con plazo." />
          </div>
        )}
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function MovimientoModal({ inversion, onClose }: { inversion: Inversion; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['inversiones']);
  const [form, setForm] = useState({
    tipo: 'SUSCRIPCION', fecha: new Date().toISOString().slice(0, 10),
    importe: '', saldo_segun_fondo: '', observaciones: '',
  });

  const m = useApiMutation<{ mov_id: number }, Record<string, unknown>>(
    (v) => api.post(`/api/erp/inversiones/${inversion.id}/movimientos`, v),
    {
      onSuccess: () => { toast.success('Movimiento registrado'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo registrar', errorMessage(e)),
    },
  );

  const esAjuste = form.tipo === 'AJUSTE_SALDO_FONDO';
  const isFci = inversion.tipo === 'FCI';
  const isPf = inversion.tipo === 'PLAZO_FIJO' || inversion.tipo === 'CAUCION';

  const valid = form.fecha && (esAjuste ? form.saldo_segun_fondo !== '' : form.importe !== '');

  return (
    <Modal open onClose={onClose} title={`Movimiento — ${inversion.nombre}`} size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending}
            onClick={() => m.mutate({
              tipo: form.tipo, fecha: form.fecha,
              importe: esAjuste ? 0 : Number(form.importe),
              saldo_segun_fondo: form.saldo_segun_fondo ? Number(form.saldo_segun_fondo) : undefined,
              observaciones: form.observaciones || undefined,
            })}>{m.isPending ? 'Guardando…' : 'Registrar'}</Button>
        </>
      }>
      <div className="space-y-3">
        <SelectField label="Tipo *" value={form.tipo} onChange={(e) => setForm({ ...form, tipo: e.target.value })}
          options={[
            { value: 'SUSCRIPCION', label: 'Suscripción (entra plata al fondo)' },
            { value: 'RESCATE', label: 'Rescate (sale plata del fondo)' },
            ...(isPf ? [
              { value: 'INTERES', label: 'Interés devengado' },
              { value: 'VENCIMIENTO', label: 'Vencimiento (cierra PF)' },
              { value: 'CONSTITUCION', label: 'Constitución (alta de PF)' },
            ] : []),
            ...(isFci ? [{ value: 'AJUSTE_SALDO_FONDO', label: 'Ajuste contra saldo informado por el fondo' }] : []),
          ]} />
        <Field label="Fecha *" type="date" value={form.fecha}
          onChange={(e) => setForm({ ...form, fecha: e.target.value })} />
        {!esAjuste && (
          <Field label="Importe *" type="number" step="0.01" value={form.importe}
            onChange={(e) => setForm({ ...form, importe: e.target.value })} />
        )}
        {esAjuste && (
          <Field label="Saldo según fondo *" type="number" step="0.01" value={form.saldo_segun_fondo}
            onChange={(e) => setForm({ ...form, saldo_segun_fondo: e.target.value })}
            hint="El delta vs el saldo actual se registra como ganancia." />
        )}
        <TextareaField label="Observaciones" value={form.observaciones} rows={2}
          onChange={(e) => setForm({ ...form, observaciones: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function HistoricoModal({ inversionId, onClose }: { inversionId: number; onClose: () => void }) {
  const { data, isLoading } = useApi<Movimiento[]>(
    ['inversion-movs', inversionId],
    `/api/erp/inversiones/${inversionId}/movimientos`,
  );

  return (
    <Modal open onClose={onClose} title={`Histórico de movimientos #${inversionId}`} size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}>
      {isLoading && <div className="text-ink-3 text-[12.5px]">Cargando…</div>}
      <div className="border border-line rounded-md overflow-hidden">
        <table className="w-full text-[12.5px]">
          <thead className="bg-surface-row">
            <tr className="text-left">
              <th className="px-2 py-1.5">Fecha</th>
              <th className="px-2 py-1.5">Tipo</th>
              <th className="px-2 py-1.5 text-right">Importe</th>
              <th className="px-2 py-1.5 text-right">Saldo R&S</th>
              <th className="px-2 py-1.5 text-right">Saldo Fondo</th>
              <th className="px-2 py-1.5">Por</th>
            </tr>
          </thead>
          <tbody>
            {(data ?? []).map((m) => (
              <tr key={m.id} className="border-t border-line">
                <td className="px-2 py-1">{fmtDate(m.fecha)}</td>
                <td className="px-2 py-1"><Badge variant="neutral">{m.tipo}</Badge></td>
                <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(m.importe)}</td>
                <td className="px-2 py-1 text-right tabular-nums">{fmtMoney(m.saldo_segun_rys)}</td>
                <td className="px-2 py-1 text-right tabular-nums">{m.saldo_segun_fondo !== null ? fmtMoney(m.saldo_segun_fondo) : '—'}</td>
                <td className="px-2 py-1 text-ink-3">{m.registrado_por_nombre ?? '—'}</td>
              </tr>
            ))}
            {!isLoading && (data ?? []).length === 0 && (
              <tr><td colSpan={6} className="px-2 py-4 text-center text-ink-3">Sin movimientos.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </Modal>
  );
}
