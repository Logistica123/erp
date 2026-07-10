import { useMemo, useState } from 'react';
import { ShieldCheck, Plus, Pencil, Trash2, Clock } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * v1.55 Bloque C — Roles y permisos (reemplaza el placeholder).
 * Incluye el panel de permisos temporales (backend del v1.29 que hasta ahora
 * se operaba por curl/SQL).
 */

type Permiso = { id: number; codigo: string; modulo: string; entidad: string; accion: string; descripcion: string | null; sensible: boolean | number };
type Rol = {
  id: number; codigo: string; nombre: string; descripcion: string | null;
  nivel_jerarquia: number; protegido: boolean | number; activo: boolean | number;
  permisos: Array<{ id: number; codigo: string; sensible: boolean | number }>;
};
type PermisoTemporal = {
  id: number; user_id: number; user_name: string; user_email: string;
  permiso_codigo: string; otorgado_por_name: string; motivo: string;
  otorgado_at: string; expira_at: string; usado_at: string | null; revocado_at: string | null;
};

export function RolesPermisosAdminPage() {
  const [rolSelId, setRolSelId] = useState<number | null>(null);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [editar, setEditar] = useState<Rol | null>(null);
  const [borrar, setBorrar] = useState<Rol | null>(null);

  const toast = useToast();
  const invalidate = useInvalidate(['admin-roles'], ['admin-roles-catalogo']);

  const { data: roles, isLoading, error } = useApi<Rol[]>(['admin-roles'], '/api/erp/roles');
  const { data: permisos } = useApi<Permiso[]>(['admin-permisos'], '/api/erp/permisos');

  const rolSel = roles?.find((r) => r.id === rolSelId) ?? null;

  const eliminar = useApiMutation<unknown, number>(
    (id) => api.delete(`/api/erp/roles/${id}`),
    {
      onSuccess: () => { toast.success('Rol borrado'); setBorrar(null); setRolSelId(null); invalidate(); },
      onError: (e) => { toast.error('No se pudo borrar', errorMessage(e)); setBorrar(null); },
    }
  );

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 lg:grid-cols-[340px_1fr] gap-4 items-start">
        <Card>
          <CardHeader
            title={<span className="flex items-center gap-2"><ShieldCheck className="w-4 h-4" /> Roles</span>}
            actions={<Button variant="primary" onClick={() => setNuevoOpen(true)}><Plus className="w-3 h-3" /> Nuevo</Button>}
          />
          <CardBody>
            {error && <FormError error={errorMessage(error)} />}
            {isLoading && <div className="text-ink-muted text-[12px]">Cargando…</div>}
            <div className="space-y-1">
              {(roles ?? []).map((r) => (
                <button key={r.id} onClick={() => setRolSelId(r.id)}
                  className={`w-full text-left p-2 rounded border transition ${
                    rolSelId === r.id ? 'border-azure bg-azure-soft/30' : 'border-line hover:bg-surface-hover'
                  } ${r.activo ? '' : 'opacity-50'}`}>
                  <div className="flex items-center justify-between">
                    <span className="text-[12.5px] font-medium">{r.nombre}</span>
                    <div className="flex gap-1">
                      {!!r.protegido && <Badge variant="warning">protegido</Badge>}
                      {!r.activo && <Badge variant="neutral">inactivo</Badge>}
                    </div>
                  </div>
                  <div className="text-[11px] text-ink-muted">
                    <code>{r.codigo}</code> · jerarquía {r.nivel_jerarquia} · {r.permisos.length} permisos
                  </div>
                </button>
              ))}
            </div>
          </CardBody>
        </Card>

        {rolSel ? (
          <PermisosDelRol key={rolSel.id} rol={rolSel} permisos={permisos ?? []}
            onEditar={() => setEditar(rolSel)}
            onBorrar={() => setBorrar(rolSel)}
            onChanged={invalidate} />
        ) : (
          <Card><CardBody>
            <div className="text-ink-muted text-[12.5px] py-8 text-center">
              Seleccioná un rol para ver y editar sus permisos.
            </div>
          </CardBody></Card>
        )}
      </div>

      <PermisosTemporalesPanel permisos={permisos ?? []} />

      <NuevoRolModal open={nuevoOpen} onClose={() => setNuevoOpen(false)}
        onSuccess={() => { setNuevoOpen(false); invalidate(); }} />
      {editar && (
        <EditarRolModal rol={editar} onClose={() => setEditar(null)}
          onSuccess={() => { setEditar(null); invalidate(); }} />
      )}
      {borrar && (
        <Modal open onClose={() => setBorrar(null)} title={`Borrar rol · ${borrar.nombre}`} size="sm"
          footer={<>
            <Button variant="secondary" onClick={() => setBorrar(null)}>Cancelar</Button>
            <Button variant="primary" disabled={eliminar.isPending} onClick={() => eliminar.mutate(borrar.id)}>
              Borrar definitivamente
            </Button>
          </>}>
          <div className="text-[12.5px]">
            Se va a borrar el rol <strong>{borrar.nombre}</strong> (<code>{borrar.codigo}</code>).
            Solo es posible si no tiene usuarios asignados.
          </div>
        </Modal>
      )}
    </div>
  );
}

function PermisosDelRol({ rol, permisos, onEditar, onBorrar, onChanged }: {
  rol: Rol; permisos: Permiso[]; onEditar: () => void; onBorrar: () => void; onChanged: () => void;
}) {
  const toast = useToast();
  const [sel, setSel] = useState<Set<number>>(new Set(rol.permisos.map((p) => p.id)));
  const [err, setErr] = useState<string | null>(null);

  const porModulo = useMemo(() => {
    const grupos = new Map<string, Permiso[]>();
    for (const p of permisos) {
      if (!grupos.has(p.modulo)) grupos.set(p.modulo, []);
      grupos.get(p.modulo)!.push(p);
    }
    return [...grupos.entries()].sort(([a], [b]) => a.localeCompare(b));
  }, [permisos]);

  const dirty = useMemo(() => {
    const originales = new Set(rol.permisos.map((p) => p.id));
    if (originales.size !== sel.size) return true;
    for (const id of sel) if (!originales.has(id)) return true;
    return false;
  }, [rol, sel]);

  const guardar = useApiMutation<unknown, void>(
    () => api.put(`/api/erp/roles/${rol.id}/permisos`, { permisos: [...sel] }),
    {
      onSuccess: () => { toast.success('Permisos guardados'); setErr(null); onChanged(); },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  const toggle = (id: number) => {
    const n = new Set(sel);
    n.has(id) ? n.delete(id) : n.add(id);
    setSel(n);
  };
  const toggleModulo = (perms: Permiso[], marcar: boolean) => {
    const n = new Set(sel);
    perms.forEach((p) => marcar ? n.add(p.id) : n.delete(p.id));
    setSel(n);
  };

  return (
    <Card>
      <CardHeader
        title={<span>{rol.nombre} <code className="text-[11px] text-ink-muted ml-1">{rol.codigo}</code></span>}
        actions={
          <div className="flex gap-2">
            <Button variant="secondary" onClick={onEditar}><Pencil className="w-3 h-3" /> Editar rol</Button>
            {!rol.protegido && (
              <Button variant="secondary" onClick={onBorrar}><Trash2 className="w-3 h-3" /> Borrar</Button>
            )}
            <Button variant="primary" disabled={!dirty || guardar.isPending} onClick={() => guardar.mutate()}>
              Guardar permisos ({sel.size})
            </Button>
          </div>
        }
      />
      <CardBody>
        <FormError error={err} />
        {rol.descripcion && <div className="text-[12px] text-ink-muted mb-3">{rol.descripcion}</div>}
        <div className="space-y-3">
          {porModulo.map(([modulo, perms]) => {
            const marcados = perms.filter((p) => sel.has(p.id)).length;
            return (
              <div key={modulo} className="border border-line rounded-md">
                <div className="flex items-center justify-between px-3 py-1.5 bg-surface-hover/60 border-b border-line">
                  <span className="text-[12px] font-semibold uppercase tracking-wide">{modulo}</span>
                  <div className="flex items-center gap-2 text-[11px]">
                    <span className="text-ink-muted">{marcados}/{perms.length}</span>
                    <button className="text-azure hover:underline" onClick={() => toggleModulo(perms, true)}>todos</button>
                    <button className="text-azure hover:underline" onClick={() => toggleModulo(perms, false)}>ninguno</button>
                  </div>
                </div>
                <div className="p-2 grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-1">
                  {perms.map((p) => (
                    <label key={p.id} className="flex items-start gap-2 cursor-pointer text-[12px]">
                      <input type="checkbox" className="mt-0.5" checked={sel.has(p.id)} onChange={() => toggle(p.id)} />
                      <span>
                        <code className="text-[11px]">{p.codigo}</code>
                        {!!p.sensible && <span className="ml-1"><Badge variant="warning">sensible</Badge></span>}
                        {p.descripcion && <span className="block text-[10.5px] text-ink-muted">{p.descripcion}</span>}
                      </span>
                    </label>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      </CardBody>
    </Card>
  );
}

function NuevoRolModal({ open, onClose, onSuccess }: {
  open: boolean; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const [form, setForm] = useState({ codigo: '', nombre: '', descripcion: '', nivel_jerarquia: '50' });
  const [err, setErr] = useState<string | null>(null);

  const crear = useApiMutation<unknown, void>(
    () => api.post('/api/erp/roles', {
      codigo: form.codigo, nombre: form.nombre,
      descripcion: form.descripcion || null,
      nivel_jerarquia: Number(form.nivel_jerarquia) || 50,
    }),
    {
      onSuccess: () => {
        toast.success('Rol creado');
        setForm({ codigo: '', nombre: '', descripcion: '', nivel_jerarquia: '50' }); setErr(null);
        onSuccess();
      },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  return (
    <Modal open={open} onClose={onClose} title="Nuevo rol" size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!form.codigo || !form.nombre || crear.isPending}
          onClick={() => crear.mutate()}>Crear rol</Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        <Field label="Código" required value={form.codigo} placeholder="ej: auditor_externo"
          hint="Minúsculas, números y guión bajo."
          onChange={(e) => setForm({ ...form, codigo: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '') })} />
        <Field label="Nombre" required value={form.nombre}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })} />
        <TextareaField label="Descripción" rows={2} value={form.descripcion}
          onChange={(e) => setForm({ ...form, descripcion: e.target.value })} />
        <Field label="Nivel de jerarquía" type="number" min={1} max={99} value={form.nivel_jerarquia}
          hint="Menor número = mayor jerarquía."
          onChange={(e) => setForm({ ...form, nivel_jerarquia: e.target.value })} />
      </div>
    </Modal>
  );
}

function EditarRolModal({ rol, onClose, onSuccess }: {
  rol: Rol; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const [form, setForm] = useState({
    nombre: rol.nombre, descripcion: rol.descripcion ?? '',
    nivel_jerarquia: String(rol.nivel_jerarquia), activo: !!rol.activo,
  });
  const [err, setErr] = useState<string | null>(null);
  const protegido = !!rol.protegido;

  const guardar = useApiMutation<unknown, void>(
    () => api.patch(`/api/erp/roles/${rol.id}`, protegido
      ? { descripcion: form.descripcion || null, activo: form.activo }
      : {
          nombre: form.nombre, descripcion: form.descripcion || null,
          nivel_jerarquia: Number(form.nivel_jerarquia) || rol.nivel_jerarquia, activo: form.activo,
        }),
    {
      onSuccess: () => { toast.success('Rol actualizado'); onSuccess(); },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  return (
    <Modal open onClose={onClose} title={`Editar rol · ${rol.nombre}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={guardar.isPending} onClick={() => guardar.mutate()}>Guardar</Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        {protegido && (
          <div className="text-[11.5px] border border-warning/40 bg-warning-bg/20 rounded p-2">
            Rol protegido del sistema: solo se puede editar descripción y activo.
          </div>
        )}
        <Field label="Nombre" value={form.nombre} disabled={protegido}
          onChange={(e) => setForm({ ...form, nombre: e.target.value })} />
        <TextareaField label="Descripción" rows={2} value={form.descripcion}
          onChange={(e) => setForm({ ...form, descripcion: e.target.value })} />
        <Field label="Nivel de jerarquía" type="number" min={1} max={99} value={form.nivel_jerarquia}
          disabled={protegido}
          onChange={(e) => setForm({ ...form, nivel_jerarquia: e.target.value })} />
        <label className="flex items-center gap-2 cursor-pointer text-[12.5px]">
          <input type="checkbox" checked={form.activo}
            onChange={(e) => setForm({ ...form, activo: e.target.checked })} />
          Activo
        </label>
      </div>
    </Modal>
  );
}

/** v1.29 diferido — panel de permisos temporales (antes solo curl/SQL). */
function PermisosTemporalesPanel({ permisos }: { permisos: Permiso[] }) {
  const toast = useToast();
  const [estado, setEstado] = useState<'activos' | 'vencidos' | 'todos'>('activos');
  const [otorgarOpen, setOtorgarOpen] = useState(false);
  const invalidate = useInvalidate(['admin-permisos-temp']);

  const { data: temporales, error } = useApi<PermisoTemporal[]>(
    ['admin-permisos-temp', estado],
    `/api/erp/admin/permisos-temporales?estado=${estado}`
  );

  const revocar = useApiMutation<unknown, number>(
    (id) => api.delete(`/api/erp/admin/permisos-temporales/${id}`),
    {
      onSuccess: () => { toast.success('Permiso temporal revocado'); invalidate(); },
      onError: (e) => toast.error('Error al revocar', errorMessage(e)),
    }
  );

  return (
    <Card>
      <CardHeader
        title={<span className="flex items-center gap-2"><Clock className="w-4 h-4" /> Permisos temporales</span>}
        actions={
          <div className="flex items-center gap-2">
            <SelectField value={estado} onChange={(e) => setEstado(e.target.value as typeof estado)}>
              <option value="activos">Activos</option>
              <option value="vencidos">Vencidos</option>
              <option value="todos">Todos</option>
            </SelectField>
            <Button variant="primary" onClick={() => setOtorgarOpen(true)}>
              <Plus className="w-3 h-3" /> Otorgar
            </Button>
          </div>
        }
      />
      <CardBody>
        {error && <FormError error={errorMessage(error)} />}
        {(temporales ?? []).length === 0 ? (
          <div className="text-ink-muted text-[12px]">Sin permisos temporales {estado === 'todos' ? '' : estado}.</div>
        ) : (
          <div className="space-y-1">
            {(temporales ?? []).map((t) => {
              const vigente = !t.revocado_at && new Date(t.expira_at) > new Date();
              return (
                <div key={t.id} className="flex items-center gap-3 p-2 border border-line rounded text-[12px]">
                  <div className="flex-1">
                    <span className="font-medium">{t.user_name}</span>
                    <code className="ml-2 text-[11px] text-azure">{t.permiso_codigo}</code>
                    <div className="text-[10.5px] text-ink-muted">
                      {t.motivo} · otorgó {t.otorgado_por_name} · expira {t.expira_at.slice(0, 16).replace('T', ' ')}
                    </div>
                  </div>
                  {t.revocado_at
                    ? <Badge variant="neutral">revocado</Badge>
                    : vigente
                      ? <Badge variant="success">vigente</Badge>
                      : <Badge variant="warning">vencido</Badge>}
                  {vigente && (
                    <Button variant="secondary" disabled={revocar.isPending}
                      onClick={() => revocar.mutate(t.id)}>Revocar</Button>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </CardBody>

      <OtorgarTemporalModal open={otorgarOpen} permisos={permisos}
        onClose={() => setOtorgarOpen(false)}
        onSuccess={() => { setOtorgarOpen(false); invalidate(); }} />
    </Card>
  );
}

function OtorgarTemporalModal({ open, permisos, onClose, onSuccess }: {
  open: boolean; permisos: Permiso[]; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const [form, setForm] = useState({ user_id: '', permiso_codigo: '', motivo: '', duracion_horas: '4' });
  const [err, setErr] = useState<string | null>(null);

  type PerfilLite = { id: number; user_id: number; user: { id: number; name: string; email: string } };
  const { data: usuarios } = useApi<never>(['admin-usuarios-lite'], '/api/erp/usuarios', {
    // El endpoint devuelve el paginador crudo: data = página de perfiles.
    enabled: open,
  }) as { data: PerfilLite[] | undefined };

  const otorgar = useApiMutation<unknown, void>(
    () => api.post('/api/erp/admin/permisos-temporales', {
      user_id: Number(form.user_id),
      permiso_codigo: form.permiso_codigo,
      motivo: form.motivo,
      duracion_horas: Number(form.duracion_horas),
    }),
    {
      onSuccess: () => {
        toast.success('Permiso temporal otorgado');
        setForm({ user_id: '', permiso_codigo: '', motivo: '', duracion_horas: '4' }); setErr(null);
        onSuccess();
      },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  const valid = form.user_id && form.permiso_codigo && form.motivo.trim().length >= 10
    && Number(form.duracion_horas) >= 1 && Number(form.duracion_horas) <= 72;

  return (
    <Modal open={open} onClose={onClose} title="Otorgar permiso temporal" size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!valid || otorgar.isPending} onClick={() => otorgar.mutate()}>
          Otorgar
        </Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        <SelectField label="Usuario" required value={form.user_id}
          onChange={(e) => setForm({ ...form, user_id: e.target.value })}>
          <option value="">Seleccionar…</option>
          {(usuarios ?? []).map((u) => (
            <option key={u.user_id} value={u.user_id}>{u.user.name} ({u.user.email})</option>
          ))}
        </SelectField>
        <SelectField label="Permiso" required value={form.permiso_codigo}
          onChange={(e) => setForm({ ...form, permiso_codigo: e.target.value })}>
          <option value="">Seleccionar…</option>
          {permisos.map((p) => (
            <option key={p.id} value={p.codigo}>{p.codigo}{p.sensible ? ' ⚠' : ''}</option>
          ))}
        </SelectField>
        <TextareaField label="Motivo" required rows={2} value={form.motivo}
          hint={`Mínimo 10 caracteres (${form.motivo.trim().length}/10). Queda en el audit log.`}
          onChange={(e) => setForm({ ...form, motivo: e.target.value })} />
        <Field label="Duración (horas)" required type="number" min={1} max={72} value={form.duracion_horas}
          onChange={(e) => setForm({ ...form, duracion_horas: e.target.value })} />
      </div>
    </Modal>
  );
}
