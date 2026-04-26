import { useState } from 'react';
import { Edit, Loader2, Plus, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { api, ApiError } from '@/lib/api';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

type Regla = {
  id: number;
  codigo: string;
  descripcion: string;
  tipo: 'CONCEPTO_REGEX' | 'IMPORTE_EXACTO' | 'COMBINADA';
  patron_concepto: string | null;
  patron_importe_desde: string | null;
  patron_importe_hasta: string | null;
  cuenta_contable_id: number | null;
  banco_id: number | null;
  cod_concepto: string | null;
  signo: 'DEBITO' | 'CREDITO' | 'AMBOS';
  confianza: number;
  orden_prioridad: number;
  activa: boolean;
  observacion: string | null;
  cuenta_contable: { id: number; codigo: string; nombre: string } | null;
  banco: { id: number; codigo: string; nombre: string } | null;
};
type Cuenta = { id: number; codigo: string; nombre: string; imputable: boolean };
type Banco = { id: number; codigo: string; nombre: string };

const TIPO_LABELS: Record<Regla['tipo'], string> = {
  CONCEPTO_REGEX: 'Concepto (regex)',
  IMPORTE_EXACTO: 'Importe',
  COMBINADA: 'Combinada',
};

export function ConciliacionReglasPage() {
  const qc = useQueryClient();
  const [editing, setEditing] = useState<Regla | null>(null);
  const [creating, setCreating] = useState(false);
  const [bancoId, setBancoId] = useState<number | ''>('');
  const [search, setSearch] = useState('');
  const [err, setErr] = useState<string | null>(null);

  const { data: bancos } = useQuery<{ data: Banco[] }>({
    queryKey: ['bancos'],
    queryFn: () => api.get('/api/erp/bancos'),
  });
  const { data: reglas, isLoading } = useQuery<{ data: Regla[] }>({
    queryKey: ['conc-reglas', bancoId, search],
    queryFn: () => {
      const qs = new URLSearchParams();
      if (bancoId) qs.set('banco_id', String(bancoId));
      if (search) qs.set('q', search);
      return api.get(`/api/erp/conciliacion-reglas?${qs}`);
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/api/erp/conciliacion-reglas/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['conc-reglas'] }),
    onError: (e) => setErr(e instanceof ApiError ? e.message : 'Error'),
  });

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Reglas de auto-conciliación</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            {reglas?.data.length ?? 0} regla{reglas?.data.length === 1 ? '' : 's'} configurada{reglas?.data.length === 1 ? '' : 's'}
          </p>
        </div>
        <Button variant="primary" onClick={() => setCreating(true)}>
          <Plus className="w-3 h-3" /> Nueva regla
        </Button>
      </div>

      {err && (
        <div className="mb-4 p-3 bg-danger-bg text-danger border border-danger/30 rounded-md text-[12px]">{err}</div>
      )}

      <Card>
        <CardHeader
          title="Reglas activas"
          actions={
            <div className="flex gap-2 items-center">
              <input
                placeholder="Buscar código/patrón…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white w-[180px]"
              />
              <select
                value={bancoId}
                onChange={(e) => setBancoId(e.target.value ? Number(e.target.value) : '')}
                className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
              >
                <option value="">Todos los bancos</option>
                {bancos?.data.map((b) => (
                  <option key={b.id} value={b.id}>{b.codigo}</option>
                ))}
              </select>
            </div>
          }
        />
        <CardBody>
          <table className="w-full border-collapse text-[12px]">
            <thead>
              <tr className="bg-surface-hover border-b border-line-strong">
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[60px]">Prio</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[110px]">Código</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase">Descripción</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[120px]">Tipo</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[80px]">Banco</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[80px]">Signo</th>
                <th className="px-[10px] py-[7px] text-right text-[11px] font-semibold text-navy-800 uppercase w-[70px]">Conf.</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[80px]">Estado</th>
                <th className="w-[100px]" />
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr><td colSpan={9} className="py-10 text-center text-ink-muted"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Cargando…</td></tr>
              )}
              {reglas?.data.length === 0 && !isLoading && (
                <tr><td colSpan={9} className="py-10 text-center text-ink-muted">No hay reglas. Creá la primera para auto-etiquetar movimientos.</td></tr>
              )}
              {reglas?.data.map((r, i) => (
                <tr key={r.id} className={`border-b border-line ${i % 2 ? 'bg-surface-row' : ''}`}>
                  <td className="px-[10px] py-[7px] tabular text-ink-muted">{r.orden_prioridad}</td>
                  <td className="px-[10px] py-[7px] font-mono text-[11px] text-navy-700">{r.codigo}</td>
                  <td className="px-[10px] py-[7px] text-ink-2">
                    <div>{r.descripcion}</div>
                    {r.patron_concepto && (
                      <div className="text-[10px] text-ink-muted font-mono mt-0.5">/{r.patron_concepto}/</div>
                    )}
                  </td>
                  <td className="px-[10px] py-[7px] text-ink-2 text-[11px]">{TIPO_LABELS[r.tipo]}</td>
                  <td className="px-[10px] py-[7px] text-ink-muted text-[11px]">{r.banco?.codigo ?? 'TODOS'}</td>
                  <td className="px-[10px] py-[7px] text-[11px]">
                    {r.signo === 'CREDITO' && <span className="text-success">+</span>}
                    {r.signo === 'DEBITO' && <span className="text-danger">−</span>}
                    {r.signo === 'AMBOS' && <span className="text-ink-muted">±</span>}
                  </td>
                  <td className="px-[10px] py-[7px] text-right tabular text-ink-2">{r.confianza}%</td>
                  <td className="px-[10px] py-[7px]">
                    {r.activa ? <Badge variant="success">Activa</Badge> : <Badge variant="neutral">Pausada</Badge>}
                  </td>
                  <td className="px-[10px] py-[7px] text-right">
                    <div className="flex gap-1 justify-end">
                      <Button size="sm" variant="secondary" onClick={() => setEditing(r)}>
                        <Edit className="w-3 h-3" />
                      </Button>
                      <Button
                        size="sm"
                        variant="danger"
                        onClick={() => {
                          if (confirm(`¿Borrar regla ${r.codigo}?`)) remove.mutate(r.id);
                        }}
                      >
                        <Trash2 className="w-3 h-3" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardBody>
      </Card>

      <ReglaModal
        regla={editing}
        creating={creating}
        bancos={bancos?.data ?? []}
        onClose={() => {
          setEditing(null);
          setCreating(false);
        }}
        onSuccess={() => {
          setEditing(null);
          setCreating(false);
          qc.invalidateQueries({ queryKey: ['conc-reglas'] });
        }}
        onError={setErr}
      />
    </>
  );
}

function ReglaModal({
  regla,
  creating,
  bancos,
  onClose,
  onSuccess,
  onError,
}: {
  regla: Regla | null;
  creating: boolean;
  bancos: Banco[];
  onClose: () => void;
  onSuccess: () => void;
  onError: (e: string) => void;
}) {
  const open = !!regla || creating;
  const [form, setForm] = useState(() => emptyForm(regla));
  const [probarMovId, setProbarMovId] = useState<string>('');
  const [probarResult, setProbarResult] = useState<string | null>(null);

  // Resetea el form al abrir.
  useState(() => {
    setForm(emptyForm(regla));
  });

  const { data: cuentas } = useQuery<{ data: Cuenta[] }>({
    queryKey: ['cuentas', 'imputables'],
    queryFn: () => api.get('/api/erp/cuentas?imputable=true'),
    enabled: open,
  });

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, banco_id: form.banco_id || null };
      if (regla) return api.patch(`/api/erp/conciliacion-reglas/${regla.id}`, payload);
      return api.post('/api/erp/conciliacion-reglas', payload);
    },
    onSuccess,
    onError: (e) => onError(e instanceof ApiError ? e.message : 'Error'),
  });

  type ProbarResp = { data: { esta_regla_es_la_ganadora: boolean; estrategia_ganadora: string; confianza: number } };
  const probar = useMutation<ProbarResp>({
    mutationFn: () =>
      api.post(`/api/erp/conciliacion-reglas/${regla!.id}/probar`, {
        movimiento_id: Number(probarMovId),
      }) as Promise<ProbarResp>,
    onSuccess: (resp) => {
      setProbarResult(
        resp.data.esta_regla_es_la_ganadora
          ? `✓ Esta regla matchea (confianza ${resp.data.confianza}%)`
          : `⚠ No matchea — ganó estrategia "${resp.data.estrategia_ganadora}"`,
      );
    },
    onError: (e) => setProbarResult(e instanceof ApiError ? e.message : 'Error'),
  });

  if (!open) return null;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={regla ? `Editar regla #${regla.id}` : 'Nueva regla'}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={save.isPending} onClick={() => save.mutate()}>
            {save.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
            {regla ? 'Guardar cambios' : 'Crear regla'}
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-3">
        <Field label="Código">
          <input
            value={form.codigo}
            onChange={(e) => setForm({ ...form, codigo: e.target.value })}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          />
        </Field>
        <Field label="Tipo">
          <select
            value={form.tipo}
            onChange={(e) => setForm({ ...form, tipo: e.target.value as Regla['tipo'] })}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          >
            <option value="CONCEPTO_REGEX">Concepto (regex)</option>
            <option value="IMPORTE_EXACTO">Importe</option>
            <option value="COMBINADA">Combinada</option>
          </select>
        </Field>

        <Field label="Descripción" colSpan={2}>
          <input
            value={form.descripcion}
            onChange={(e) => setForm({ ...form, descripcion: e.target.value })}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          />
        </Field>

        {form.tipo !== 'IMPORTE_EXACTO' && (
          <Field label="Patrón concepto (regex PHP)" colSpan={2}>
            <input
              value={form.patron_concepto}
              onChange={(e) => setForm({ ...form, patron_concepto: e.target.value })}
              placeholder="^Rendimientos|^Pago de servicio Aguas"
              className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white font-mono text-[12px]"
            />
          </Field>
        )}

        {form.tipo !== 'CONCEPTO_REGEX' && (
          <>
            <Field label="Importe desde">
              <input
                type="number"
                step="0.01"
                value={form.patron_importe_desde}
                onChange={(e) => setForm({ ...form, patron_importe_desde: e.target.value })}
                className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white tabular"
              />
            </Field>
            <Field label="Importe hasta">
              <input
                type="number"
                step="0.01"
                value={form.patron_importe_hasta}
                onChange={(e) => setForm({ ...form, patron_importe_hasta: e.target.value })}
                className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white tabular"
              />
            </Field>
          </>
        )}

        <Field label="Banco (vacío = todos)">
          <select
            value={form.banco_id ?? ''}
            onChange={(e) => setForm({ ...form, banco_id: e.target.value ? Number(e.target.value) : null })}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          >
            <option value="">Todos</option>
            {bancos.map((b) => (
              <option key={b.id} value={b.id}>{b.codigo} — {b.nombre}</option>
            ))}
          </select>
        </Field>
        <Field label="Signo">
          <select
            value={form.signo}
            onChange={(e) => setForm({ ...form, signo: e.target.value as Regla['signo'] })}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          >
            <option value="AMBOS">Ambos</option>
            <option value="DEBITO">Débito</option>
            <option value="CREDITO">Crédito</option>
          </select>
        </Field>

        <Field label="Cuenta contable propuesta" colSpan={2}>
          <select
            value={form.cuenta_contable_id ?? ''}
            onChange={(e) => setForm({ ...form, cuenta_contable_id: e.target.value ? Number(e.target.value) : null })}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white"
          >
            <option value="">—</option>
            {cuentas?.data.map((c) => (
              <option key={c.id} value={c.id}>{c.codigo} — {c.nombre}</option>
            ))}
          </select>
        </Field>

        <Field label="Prioridad (menor = primero)">
          <input
            type="number"
            value={form.orden_prioridad}
            onChange={(e) => setForm({ ...form, orden_prioridad: Number(e.target.value) })}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white tabular"
          />
        </Field>
        <Field label="Confianza (0-100)">
          <input
            type="number"
            min={0}
            max={100}
            value={form.confianza}
            onChange={(e) => setForm({ ...form, confianza: Number(e.target.value) })}
            className="w-full px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white tabular"
          />
        </Field>

        <Field label="Activa" colSpan={2}>
          <label className="inline-flex items-center gap-2 text-[12px]">
            <input
              type="checkbox"
              checked={form.activa}
              onChange={(e) => setForm({ ...form, activa: e.target.checked })}
            />
            La regla está activa y se aplica al importar
          </label>
        </Field>
      </div>

      {regla && (
        <div className="mt-4 p-3 bg-surface-row rounded-md border border-line">
          <div className="text-[11px] font-semibold text-navy-800 uppercase tracking-wider mb-2">Probar contra mov real</div>
          <div className="flex gap-2 items-center">
            <input
              type="number"
              placeholder="ID movimiento"
              value={probarMovId}
              onChange={(e) => setProbarMovId(e.target.value)}
              className="px-[9px] py-[6px] text-[13px] border border-line-strong rounded-md bg-white tabular w-[180px]"
            />
            <Button size="sm" variant="secondary" disabled={!probarMovId || probar.isPending} onClick={() => probar.mutate()}>
              {probar.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
              Probar
            </Button>
            {probarResult && <span className="text-[12px] text-ink-2">{probarResult}</span>}
          </div>
        </div>
      )}
    </Modal>
  );
}

function Field({ label, colSpan, children }: { label: string; colSpan?: 1 | 2; children: React.ReactNode }) {
  return (
    <div className={colSpan === 2 ? 'col-span-2' : ''}>
      <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">{label}</label>
      {children}
    </div>
  );
}

function emptyForm(r: Regla | null) {
  return {
    codigo: r?.codigo ?? '',
    descripcion: r?.descripcion ?? '',
    tipo: r?.tipo ?? ('CONCEPTO_REGEX' as Regla['tipo']),
    patron_concepto: r?.patron_concepto ?? '',
    patron_importe_desde: r?.patron_importe_desde ?? '',
    patron_importe_hasta: r?.patron_importe_hasta ?? '',
    cuenta_contable_id: r?.cuenta_contable_id ?? null,
    banco_id: r?.banco_id ?? null,
    signo: r?.signo ?? ('AMBOS' as Regla['signo']),
    confianza: r?.confianza ?? 80,
    orden_prioridad: r?.orden_prioridad ?? 100,
    activa: r?.activa ?? true,
  };
}
