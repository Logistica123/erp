import { useEffect, useMemo, useState } from 'react';
import { ChevronDown, ChevronRight, Download, Loader2, Plus, Trash2, AlertTriangle } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { api, ApiError } from '@/lib/api';
import { auth } from '@/lib/auth';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useToast } from '@/hooks/useToast';

type Cuenta = {
  id: number;
  codigo: string;
  codigo_padre_id: number | null;
  nivel: 1 | 2 | 3 | 4;
  nombre: string;
  tipo: string;
  imputable: boolean;
  moneda: 'ARS' | 'USD' | null;
  admite_cc: boolean;
  admite_auxiliar: boolean;
  etiqueta_cierre: string | null;
  activo: boolean;
  hijos: Cuenta[];
};

type Resp = {
  data: Cuenta[];
  meta: { total: number; imputables: number };
};

/** Aplana el árbol respetando el estado de expansión. */
function flatten(nodes: Cuenta[], expanded: Set<number>, out: Cuenta[] = []): Cuenta[] {
  for (const n of nodes) {
    out.push(n);
    if (n.hijos?.length && expanded.has(n.id)) {
      flatten(n.hijos, expanded, out);
    }
  }
  return out;
}

function collectIdsUpToLevel(nodes: Cuenta[], maxLevel: number, out: Set<number> = new Set()): Set<number> {
  for (const n of nodes) {
    if (n.nivel <= maxLevel && n.hijos?.length) {
      out.add(n.id);
      collectIdsUpToLevel(n.hijos, maxLevel, out);
    }
  }
  return out;
}

function levelClasses(c: Cuenta, alt: boolean): string {
  switch (c.nivel) {
    case 1:
      return 'bg-navy-700 text-white font-semibold';
    case 2:
      return 'bg-steel text-white font-medium';
    case 3:
      return 'bg-[#DFE8F2] text-navy-800 font-medium';
    default:
      return `${alt ? 'bg-surface-row' : ''} text-ink-2 hover:bg-surface-hover`;
  }
}

function codeColor(nivel: number): string {
  if (nivel === 1) return 'text-[#9FB5D1]';
  if (nivel === 2) return 'text-[#C5D3E3]';
  return 'text-navy-700';
}

function indentClass(nivel: 1 | 2 | 3 | 4): string {
  return { 1: '', 2: 'pl-5', 3: 'pl-[30px]', 4: 'pl-[44px]' }[nivel];
}

// v1.15 Sprint L — niveles de expansión persistidos en localStorage.
type NivelExp = 'all' | '1' | '2' | '3';
const STORAGE_KEY = 'erp.plan-cuentas.nivel-expansion';

function loadNivelExp(): NivelExp {
  const v = localStorage.getItem(STORAGE_KEY);
  return v === 'all' || v === '1' || v === '2' || v === '3' ? v : '2';
}

function collectAllIds(nodes: Cuenta[], out: Set<number> = new Set()): Set<number> {
  for (const n of nodes) {
    if (n.hijos?.length) {
      out.add(n.id);
      collectAllIds(n.hijos, out);
    }
  }
  return out;
}

export function PlanCuentasPage() {
  const toast = useToast();
  const qc = useQueryClient();

  const { data, isLoading, error } = useQuery<Resp>({
    queryKey: ['cuentas', 'tree'],
    queryFn: () => api.get<Resp>('/api/erp/cuentas?tree=true'),
  });

  const [expanded, setExpanded] = useState<Set<number>>(new Set());
  const [query, setQuery] = useState('');
  const [nivelExp, setNivelExp] = useState<NivelExp>(loadNivelExp);
  const [nuevaOpen, setNuevaOpen] = useState(false);
  const [eliminarOpen, setEliminarOpen] = useState<Cuenta | null>(null);

  // Aplicar el nivel de expansión cuando cambia o cuando llega la data.
  useEffect(() => {
    if (!data) return;
    const ids = nivelExp === 'all'
      ? collectAllIds(data.data)
      : collectIdsUpToLevel(data.data, parseInt(nivelExp, 10));
    setExpanded(ids);
    localStorage.setItem(STORAGE_KEY, nivelExp);
  }, [data, nivelExp]);

  const exportarCSV = () => {
    const url = '/api/erp/cuentas/exportar';
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `plan_de_cuentas_${new Date().toISOString().slice(0, 10)}.csv`;
        a.click();
      })
      .catch(() => toast.error('No se pudo descargar el CSV'));
  };

  const filas = useMemo(() => {
    if (!data) return [];
    const all = flatten(data.data, expanded);
    if (!query.trim()) return all;
    const q = query.toLowerCase();
    return all.filter((c) => c.codigo.toLowerCase().includes(q) || c.nombre.toLowerCase().includes(q));
  }, [data, expanded, query]);

  function toggle(id: number) {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Plan de Cuentas</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            {data ? (
              <>
                {data.meta.total} cuentas · {data.meta.imputables} imputables · Estructura RT 8/9 FACPCE
              </>
            ) : (
              'Cargando…'
            )}
          </p>
        </div>
        <div className="flex gap-2 items-center">
          <input
            className="px-[9px] py-[6px] text-[12px] border border-line-strong rounded-md bg-white w-[280px]"
            placeholder="Buscar por código o nombre…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          <select
            className="px-[9px] py-[6px] text-[12px] border border-line-strong rounded-md bg-white"
            value={nivelExp}
            onChange={(e) => setNivelExp(e.target.value as NivelExp)}
            title="Nivel de expansión del árbol"
          >
            <option value="all">Todo expandido</option>
            <option value="1">Hasta nivel 1</option>
            <option value="2">Hasta nivel 2</option>
            <option value="3">Hasta nivel 3</option>
          </select>
          <Button variant="secondary" onClick={exportarCSV}>
            <Download className="w-3 h-3" /> Exportar
          </Button>
          <Button variant="primary" onClick={() => setNuevaOpen(true)}>
            <Plus className="w-3 h-3" /> Nueva cuenta
          </Button>
        </div>
      </div>

      {error && (
        <div className="mb-4 p-3 bg-danger-bg text-danger border border-danger/20 rounded-md text-[12px]">
          Error cargando cuentas: {error instanceof Error ? error.message : 'desconocido'}
        </div>
      )}

      <Card>
        <CardBody>
          {/* Header columnas */}
          <div
            className="grid border-b border-line-strong bg-surface-hover text-[10px] uppercase tracking-wider font-semibold text-navy-800"
            style={{ gridTemplateColumns: '120px 1fr 80px 80px 60px 60px 140px 40px' }}
          >
            <div className="px-[10px] py-[7px]">Código</div>
            <div className="px-[10px] py-[7px]">Cuenta</div>
            <div className="px-[10px] py-[7px] text-right">Moneda</div>
            <div className="px-[10px] py-[7px] text-right">CC</div>
            <div className="px-[10px] py-[7px]">Aux.</div>
            <div className="px-[10px] py-[7px]">Impu.</div>
            <div className="px-[10px] py-[7px]">Etiqueta cierre</div>
            <div className="px-[10px] py-[7px]"></div>
          </div>

          {isLoading && (
            <div className="flex items-center justify-center py-10 text-ink-muted">
              <Loader2 className="w-4 h-4 animate-spin mr-2" /> Cargando cuentas…
            </div>
          )}

          {filas.map((c, i) => {
            const hasHijos = c.hijos?.length > 0;
            const isExpanded = expanded.has(c.id);
            return (
              <div
                key={c.id}
                className={`grid border-b border-line text-[12px] items-center ${levelClasses(c, i % 2 === 1)}`}
                style={{ gridTemplateColumns: '120px 1fr 80px 80px 60px 60px 140px 40px' }}
              >
                <div className={`px-[10px] py-[7px] font-mono text-[11px] ${codeColor(c.nivel)}`}>{c.codigo}</div>
                <div className={`px-[10px] py-[7px] ${indentClass(c.nivel)} flex items-center`}>
                  {hasHijos ? (
                    <button
                      onClick={() => toggle(c.id)}
                      className="mr-1 opacity-70 hover:opacity-100 cursor-pointer"
                      aria-label={isExpanded ? 'Colapsar' : 'Expandir'}
                    >
                      {isExpanded ? (
                        <ChevronDown className="w-[10px] h-[10px] inline" />
                      ) : (
                        <ChevronRight className="w-[10px] h-[10px] inline" />
                      )}
                    </button>
                  ) : (
                    <span className="mr-1 w-[10px] inline-block" />
                  )}
                  {c.nombre}
                </div>
                <div className="px-[10px] py-[7px] text-right">
                  {c.moneda && <Badge variant={c.moneda === 'USD' ? 'info' : 'neutral'}>{c.moneda}</Badge>}
                </div>
                <div className="px-[10px] py-[7px] text-right">
                  {c.admite_cc && <Badge variant="success">SI</Badge>}
                </div>
                <div className="px-[10px] py-[7px]">
                  {c.admite_auxiliar ? 'SI' : c.nivel === 4 ? '—' : ''}
                </div>
                <div className="px-[10px] py-[7px]">
                  {c.imputable && <Badge variant="success">SI</Badge>}
                </div>
                <div className={`px-[10px] py-[7px] font-mono text-[11px] ${codeColor(c.nivel)}`}>
                  {c.etiqueta_cierre ?? ''}
                </div>
                <div className="px-[6px] py-[4px] text-right">
                  {c.activo && c.nivel >= 3 && (
                    <button
                      onClick={(e) => { e.stopPropagation(); setEliminarOpen(c); }}
                      className="opacity-50 hover:opacity-100 hover:text-danger cursor-pointer"
                      title="Eliminar cuenta"
                    >
                      <Trash2 className="w-3 h-3" />
                    </button>
                  )}
                </div>
              </div>
            );
          })}

          {data && filas.length === 0 && !isLoading && (
            <div className="py-10 text-center text-ink-muted text-[12px]">
              {query ? 'No hay cuentas que coincidan con la búsqueda.' : 'Sin cuentas.'}
            </div>
          )}
        </CardBody>
      </Card>

      <div className="p-[14px_18px] bg-[#EEF3F8] border border-[#D1DCE8] rounded-lg text-[12px] text-navy-700 leading-relaxed">
        <strong className="text-navy-800">Estructura jerárquica.</strong> Niveles 1–3 son agrupadoras (no admiten
        asientos). Nivel 4 es imputable. La columna <strong>Etiqueta cierre</strong> vincula cada cuenta al pipeline
        actual de cierres contables diarios para auto-imputar movimientos bancarios al importar extractos.
      </div>

      {nuevaOpen && data && (
        <NuevaCuentaModal
          cuentas={flatten(data.data, new Set(collectAllIds(data.data)))}
          onClose={() => setNuevaOpen(false)}
          onCreated={() => { qc.invalidateQueries({ queryKey: ['cuentas'] }); setNuevaOpen(false); }}
        />
      )}
      {eliminarOpen && (
        <EliminarCuentaModal
          cuenta={eliminarOpen}
          onClose={() => setEliminarOpen(null)}
          onDeleted={() => { qc.invalidateQueries({ queryKey: ['cuentas'] }); setEliminarOpen(null); }}
        />
      )}
    </>
  );
}

function NuevaCuentaModal({
  cuentas, onClose, onCreated,
}: {
  cuentas: Cuenta[];
  onClose: () => void;
  onCreated: () => void;
}) {
  const toast = useToast();
  const [form, setForm] = useState({
    codigo: '', nombre: '',
    codigo_padre_id: '' as string,
    tipo: 'A' as 'A' | 'P' | 'PN' | 'R+' | 'R-' | 'O',
    imputable: false,
    moneda: 'ARS' as '' | 'ARS' | 'USD',
    admite_cc: false,
    admite_auxiliar: false,
  });
  const [error, setError] = useState<string | null>(null);

  const m = useMutation<unknown, ApiError, typeof form>({
    mutationFn: (vars) => api.post('/api/erp/cuentas', {
      ...vars,
      codigo_padre_id: vars.codigo_padre_id ? Number(vars.codigo_padre_id) : null,
      moneda: vars.moneda || null,
    }),
    onSuccess: () => { toast.success('Cuenta creada'); onCreated(); },
    onError: (e) => setError(e.message),
  });

  return (
    <Modal open onClose={onClose} title="Nueva cuenta contable" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!form.codigo || !form.nombre || m.isPending}
            onClick={() => m.mutate(form)}>
            {m.isPending ? 'Creando…' : 'Crear cuenta'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Código" required value={form.codigo}
            onChange={(e) => setForm({ ...form, codigo: e.target.value })}
            placeholder="1.1.4.99" />
          <SelectField label="Tipo" required value={form.tipo}
            onChange={(e) => setForm({ ...form, tipo: e.target.value as typeof form.tipo })}
            options={[
              { value: 'A', label: 'A — Activo' },
              { value: 'P', label: 'P — Pasivo' },
              { value: 'PN', label: 'PN — Patrimonio Neto' },
              { value: 'R+', label: 'R+ — Resultado positivo' },
              { value: 'R-', label: 'R- — Resultado negativo' },
              { value: 'O', label: 'O — Orden' },
            ]} />
        </div>
        <Field label="Nombre" required value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })}
          placeholder="Cuenta nueva" />
        <SelectField label="Cuenta padre (opcional)"
          value={form.codigo_padre_id} placeholder="— sin padre (raíz) —"
          onChange={(e) => setForm({ ...form, codigo_padre_id: e.target.value })}
          options={cuentas
            .filter((c) => c.activo && c.nivel < 4 && !c.imputable)
            .map((c) => ({ value: String(c.id), label: `${c.codigo} — ${c.nombre}` }))} />
        <div className="grid grid-cols-2 gap-3">
          <SelectField label="Moneda" value={form.moneda}
            onChange={(e) => setForm({ ...form, moneda: e.target.value as '' | 'ARS' | 'USD' })}
            options={[
              { value: '', label: '— sin moneda —' },
              { value: 'ARS', label: 'ARS' },
              { value: 'USD', label: 'USD' },
            ]} />
          <label className="flex items-center gap-2 text-[12px] mt-6 cursor-pointer">
            <input type="checkbox" checked={form.imputable}
              onChange={(e) => setForm({ ...form, imputable: e.target.checked })} />
            Imputable (acepta asientos)
          </label>
          <label className="flex items-center gap-2 text-[12px] cursor-pointer">
            <input type="checkbox" checked={form.admite_cc}
              onChange={(e) => setForm({ ...form, admite_cc: e.target.checked })} />
            Admite Centro de Costos
          </label>
          <label className="flex items-center gap-2 text-[12px] cursor-pointer">
            <input type="checkbox" checked={form.admite_auxiliar}
              onChange={(e) => setForm({ ...form, admite_auxiliar: e.target.checked })} />
            Admite Auxiliar
          </label>
        </div>
        <FormError error={error} />
      </div>
    </Modal>
  );
}

function EliminarCuentaModal({
  cuenta, onClose, onDeleted,
}: {
  cuenta: Cuenta;
  onClose: () => void;
  onDeleted: () => void;
}) {
  const toast = useToast();
  const [bloqueos, setBloqueos] = useState<Array<{ tipo: string; count: number; descripcion: string }> | null>(null);
  const [forzar, setForzar] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const m = useMutation<unknown, ApiError, void>({
    mutationFn: () => api.delete(`/api/erp/cuentas/${cuenta.id}${forzar ? '?force=1' : ''}`),
    onSuccess: () => {
      toast.success(`Cuenta ${cuenta.codigo} desactivada`);
      onDeleted();
    },
    onError: (e) => {
      const payload = e.payload as { error?: { code?: string; bloqueos?: typeof bloqueos } };
      if (payload?.error?.code === 'CUENTA_CON_REFERENCIAS' && payload.error.bloqueos) {
        setBloqueos(payload.error.bloqueos);
      } else {
        setError(e.message);
      }
    },
  });

  return (
    <Modal open onClose={onClose} title={`Eliminar cuenta ${cuenta.codigo}`} size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={m.isPending || !!(bloqueos && !forzar)}
            onClick={() => { setError(null); m.mutate(); }}>
            {m.isPending ? 'Procesando…'
              : bloqueos
                ? (forzar ? 'Desactivar igualmente' : 'Eliminar')
                : 'Eliminar'}
          </Button>
        </>
      }
    >
      <div className="space-y-3 text-[12.5px]">
        <div>
          ¿Eliminar la cuenta <code className="font-semibold">{cuenta.codigo} — {cuenta.nombre}</code>?
        </div>
        <div className="text-[11.5px] text-ink-muted">
          La eliminación es <strong>soft delete</strong>: se marca como inactiva pero no se borra
          de la base. No aparece más en formularios pero los movimientos históricos se conservan.
        </div>

        {bloqueos && (
          <div className="border border-warning/30 bg-warning-bg/20 rounded-md p-3 space-y-2">
            <div className="flex items-center gap-1 text-[12px] font-semibold text-warning">
              <AlertTriangle className="w-3.5 h-3.5" /> Esta cuenta tiene referencias activas
            </div>
            <ul className="text-[11.5px] space-y-1 ml-4 list-disc">
              {bloqueos.map((b) => (
                <li key={b.tipo}><strong>{b.tipo}</strong> · {b.descripcion}</li>
              ))}
            </ul>
            <label className="flex items-start gap-2 text-[11.5px] cursor-pointer">
              <input type="checkbox" checked={forzar}
                onChange={(e) => setForzar(e.target.checked)} />
              <span>Confirmo: desactivar igualmente. La cuenta queda invisible en formularios
                nuevos pero las referencias y movimientos históricos se mantienen.</span>
            </label>
          </div>
        )}

        <FormError error={error} />
      </div>
    </Modal>
  );
}
