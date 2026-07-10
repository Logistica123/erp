import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Users, Plus, Pencil, KeyRound, ShieldCheck, Unlock } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Field, FormError } from '@/components/ui/Field';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { api, type ApiError } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * v1.55 Bloque C — ABM de usuarios ERP (reemplaza el placeholder).
 * {id} de los endpoints es erp_usuario_perfil.id, no user.id.
 */

type Rol = { id: number; codigo: string; nombre: string };
type Perfil = {
  id: number;
  user_id: number;
  empresa_id: number;
  legajo: string | null;
  mfa_habilitado: boolean;
  acceso_erp: boolean;
  ultimo_login: string | null;
  bloqueado_hasta: string | null;
  intentos_fallidos: number;
  user: { id: number; name: string; email: string };
  roles: Rol[];
};
type Paginator<T> = { data: T[]; current_page: number; last_page: number; total: number };

export function UsuariosAdminPage() {
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [editar, setEditar] = useState<Perfil | null>(null);
  const [rolesDe, setRolesDe] = useState<Perfil | null>(null);
  const [passwordDe, setPasswordDe] = useState<Perfil | null>(null);

  const toast = useToast();
  const invalidate = useInvalidate(['admin-usuarios']);

  // El backend devuelve el paginador Laravel crudo (sin envoltura {ok,data}).
  const { data: pag, isLoading, error } = useQuery<Paginator<Perfil>, ApiError>({
    queryKey: ['admin-usuarios', page],
    queryFn: () => api.get(`/api/erp/usuarios?page=${page}`),
  });

  const { data: roles } = useApi<Rol[]>(['admin-roles-catalogo'], '/api/erp/roles');

  const filtrados = useMemo(() => {
    const rows = pag?.data ?? [];
    if (!q.trim()) return rows;
    const needle = q.trim().toLowerCase();
    return rows.filter((p) =>
      p.user.name.toLowerCase().includes(needle) ||
      p.user.email.toLowerCase().includes(needle) ||
      (p.legajo ?? '').toLowerCase().includes(needle));
  }, [pag, q]);

  const toggleAcceso = useApiMutation<unknown, Perfil>(
    (p) => api.patch(`/api/erp/usuarios/${p.id}`, { acceso_erp: !p.acceso_erp }),
    {
      onSuccess: () => { toast.success('Acceso actualizado'); invalidate(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );
  const desbloquear = useApiMutation<unknown, number>(
    (id) => api.patch(`/api/erp/usuarios/${id}`, { desbloquear: true }),
    {
      onSuccess: () => { toast.success('Usuario desbloqueado'); invalidate(); },
      onError: (e) => toast.error('Error', errorMessage(e)),
    }
  );

  const bloqueado = (p: Perfil) => !!p.bloqueado_hasta && new Date(p.bloqueado_hasta) > new Date();

  const cols: Column<Perfil>[] = [
    { key: 'name', header: 'Nombre',
      render: (p) => (
        <span className={p.acceso_erp ? '' : 'opacity-50 italic'}>
          {p.user.name}
          {!p.acceso_erp && <span className="ml-2"><Badge variant="warning">SIN ACCESO</Badge></span>}
          {bloqueado(p) && <span className="ml-2"><Badge variant="warning">BLOQUEADO</Badge></span>}
        </span>
      ) },
    { key: 'email', header: 'Email', render: (p) => <span className="text-[12px]">{p.user.email}</span> },
    { key: 'legajo', header: 'Legajo', width: '90px',
      render: (p) => p.legajo ?? <span className="text-ink-muted">—</span> },
    { key: 'roles', header: 'Roles',
      render: (p) => p.roles.length
        ? <div className="flex flex-wrap gap-1">{p.roles.map((r) => (
            <Badge key={r.id} variant={r.codigo === 'super_admin' ? 'warning' : 'default'}>{r.nombre}</Badge>
          ))}</div>
        : <span className="text-ink-muted text-[11.5px]">sin roles</span> },
    { key: 'mfa', header: 'MFA', width: '60px',
      render: (p) => p.mfa_habilitado ? <Badge variant="success">SÍ</Badge> : <Badge variant="neutral">NO</Badge> },
    { key: 'ultimo_login', header: 'Último login', width: '130px',
      render: (p) => p.ultimo_login
        ? <span className="text-[11.5px] tabular">{p.ultimo_login.slice(0, 16).replace('T', ' ')}</span>
        : <span className="text-ink-muted">nunca</span> },
    { key: 'acciones', header: '', align: 'right', width: '150px',
      render: (p) => (
        <div className="flex justify-end gap-1">
          <button onClick={() => setEditar(p)} title="Editar"
            className="p-1 opacity-60 hover:opacity-100 hover:text-azure">
            <Pencil className="w-3 h-3" />
          </button>
          <button onClick={() => setRolesDe(p)} title="Roles"
            className="p-1 opacity-60 hover:opacity-100 hover:text-azure">
            <ShieldCheck className="w-3 h-3" />
          </button>
          <button onClick={() => setPasswordDe(p)} title="Cambiar password"
            className="p-1 opacity-60 hover:opacity-100 hover:text-warning">
            <KeyRound className="w-3 h-3" />
          </button>
          {bloqueado(p) && (
            <button onClick={() => desbloquear.mutate(p.id)} title="Desbloquear"
              disabled={desbloquear.isPending}
              className="p-1 opacity-60 hover:opacity-100 hover:text-success">
              <Unlock className="w-3 h-3" />
            </button>
          )}
          <button onClick={() => toggleAcceso.mutate(p)}
            title={p.acceso_erp ? 'Quitar acceso ERP' : 'Dar acceso ERP'}
            disabled={toggleAcceso.isPending}
            className={`px-1.5 text-[10.5px] rounded border ${p.acceso_erp
              ? 'border-danger/40 text-danger hover:bg-danger-bg/30'
              : 'border-success/40 text-success hover:bg-success-bg/30'}`}>
            {p.acceso_erp ? 'Desactivar' : 'Activar'}
          </button>
        </div>
      ) },
  ];

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title={<span className="flex items-center gap-2"><Users className="w-4 h-4" /> Usuarios</span>}
          actions={
            <Button variant="primary" onClick={() => setNuevoOpen(true)}>
              <Plus className="w-3 h-3" /> Nuevo usuario
            </Button>
          }
        />
        <CardBody>
          <div className="flex items-center gap-3 mb-3">
            <input value={q} onChange={(e) => setQ(e.target.value)}
              placeholder="Filtrar por nombre, email o legajo…"
              className="px-3 py-1.5 text-[12.5px] border border-line rounded-md w-[300px]" />
            {pag && pag.last_page > 1 && (
              <div className="flex items-center gap-2 text-[12px] ml-auto">
                <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>‹</Button>
                <span>página {pag.current_page} / {pag.last_page} · {pag.total} usuarios</span>
                <Button variant="secondary" disabled={page >= pag.last_page} onClick={() => setPage(page + 1)}>›</Button>
              </div>
            )}
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={cols} rows={filtrados} loading={isLoading} empty="Sin usuarios" />
        </CardBody>
      </Card>

      <NuevoUsuarioModal open={nuevoOpen} roles={roles ?? []}
        onClose={() => setNuevoOpen(false)}
        onSuccess={() => { setNuevoOpen(false); invalidate(); }} />
      {editar && (
        <EditarUsuarioModal perfil={editar}
          onClose={() => setEditar(null)}
          onSuccess={() => { setEditar(null); invalidate(); }} />
      )}
      {rolesDe && (
        <RolesUsuarioModal perfil={rolesDe} roles={roles ?? []}
          onClose={() => setRolesDe(null)}
          onSuccess={() => { setRolesDe(null); invalidate(); }} />
      )}
      {passwordDe && (
        <PasswordModal perfil={passwordDe}
          onClose={() => setPasswordDe(null)}
          onSuccess={() => setPasswordDe(null)} />
      )}
    </div>
  );
}

function NuevoUsuarioModal({ open, roles, onClose, onSuccess }: {
  open: boolean; roles: Rol[]; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const [form, setForm] = useState({ name: '', email: '', password: '', legajo: '' });
  const [rolesSel, setRolesSel] = useState<number[]>([]);
  const [err, setErr] = useState<string | null>(null);

  const crear = useApiMutation<unknown, void>(
    () => api.post('/api/erp/usuarios', {
      name: form.name, email: form.email, password: form.password,
      legajo: form.legajo || null, empresa_id: 1, acceso_erp: true, roles: rolesSel,
    }),
    {
      onSuccess: () => {
        toast.success('Usuario creado');
        setForm({ name: '', email: '', password: '', legajo: '' }); setRolesSel([]); setErr(null);
        onSuccess();
      },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  const valid = form.name && form.email.includes('@') && form.password.length >= 14;

  return (
    <Modal open={open} onClose={onClose} title="Nuevo usuario" size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={!valid || crear.isPending} onClick={() => crear.mutate()}>
          Crear usuario
        </Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        <Field label="Nombre" required value={form.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <Field label="Email" required type="email" value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })} />
        <Field label="Password" required type="password" value={form.password}
          hint={`Mínimo 14 caracteres (${form.password.length}/14)`}
          onChange={(e) => setForm({ ...form, password: e.target.value })} />
        <Field label="Legajo" value={form.legajo}
          onChange={(e) => setForm({ ...form, legajo: e.target.value })} />
        <RolesChecklist roles={roles} seleccionados={rolesSel} onChange={setRolesSel} />
        <div className="text-[11px] text-ink-muted">
          Crear usuarios requiere MFA reciente (menos de 15 minutos).
        </div>
      </div>
    </Modal>
  );
}

function EditarUsuarioModal({ perfil, onClose, onSuccess }: {
  perfil: Perfil; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const [form, setForm] = useState({
    name: perfil.user.name, email: perfil.user.email, legajo: perfil.legajo ?? '',
  });
  const [err, setErr] = useState<string | null>(null);

  const guardar = useApiMutation<unknown, void>(
    () => api.patch(`/api/erp/usuarios/${perfil.id}`, {
      name: form.name, email: form.email, legajo: form.legajo || null,
    }),
    {
      onSuccess: () => { toast.success('Usuario actualizado'); onSuccess(); },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  return (
    <Modal open onClose={onClose} title={`Editar · ${perfil.user.name}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={guardar.isPending} onClick={() => guardar.mutate()}>Guardar</Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        <Field label="Nombre" value={form.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <Field label="Email" type="email" value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })} />
        <Field label="Legajo" value={form.legajo}
          onChange={(e) => setForm({ ...form, legajo: e.target.value })} />
      </div>
    </Modal>
  );
}

function RolesUsuarioModal({ perfil, roles, onClose, onSuccess }: {
  perfil: Perfil; roles: Rol[]; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const [sel, setSel] = useState<number[]>(perfil.roles.map((r) => r.id));
  const [err, setErr] = useState<string | null>(null);

  const guardar = useApiMutation<unknown, void>(
    () => api.patch(`/api/erp/usuarios/${perfil.id}/roles`, {
      roles: sel.map((id) => ({ id })),
    }),
    {
      onSuccess: () => { toast.success('Roles actualizados'); onSuccess(); },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  return (
    <Modal open onClose={onClose} title={`Roles · ${perfil.user.name}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={guardar.isPending} onClick={() => guardar.mutate()}>Guardar roles</Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        <RolesChecklist roles={roles} seleccionados={sel} onChange={setSel} />
        <div className="text-[11px] text-ink-muted">
          Cambiar roles requiere MFA reciente (menos de 15 minutos).
        </div>
      </div>
    </Modal>
  );
}

function PasswordModal({ perfil, onClose, onSuccess }: {
  perfil: Perfil; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const [password, setPassword] = useState('');
  const [err, setErr] = useState<string | null>(null);

  const guardar = useApiMutation<unknown, void>(
    () => api.patch(`/api/erp/usuarios/${perfil.id}/password`, { password }),
    {
      onSuccess: () => { toast.success('Password actualizada'); onSuccess(); },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  return (
    <Modal open onClose={onClose} title={`Password · ${perfil.user.name}`} size="sm"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={password.length < 14 || guardar.isPending}
          onClick={() => guardar.mutate()}>Cambiar password</Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        <Field label="Password nueva" required type="password" value={password}
          hint={`Mínimo 14 caracteres (${password.length}/14). Requiere MFA reciente.`}
          onChange={(e) => setPassword(e.target.value)} />
      </div>
    </Modal>
  );
}

function RolesChecklist({ roles, seleccionados, onChange }: {
  roles: Rol[]; seleccionados: number[]; onChange: (ids: number[]) => void;
}) {
  return (
    <div>
      <div className="text-[11.5px] font-semibold text-ink-2 mb-1">Roles</div>
      <div className="border border-line rounded-md p-2 max-h-[180px] overflow-y-auto space-y-1">
        {roles.map((r) => (
          <label key={r.id} className="flex items-center gap-2 cursor-pointer text-[12px]">
            <input type="checkbox" checked={seleccionados.includes(r.id)}
              onChange={(e) => onChange(e.target.checked
                ? [...seleccionados, r.id]
                : seleccionados.filter((id) => id !== r.id))} />
            <span>{r.nombre} <code className="text-[10.5px] text-ink-muted">({r.codigo})</code></span>
          </label>
        ))}
        {!roles.length && <div className="text-[11.5px] text-ink-muted">Sin roles definidos.</div>}
      </div>
    </div>
  );
}
