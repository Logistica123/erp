import { useState } from 'react';
import { Loader2, Search, ShieldCheck, X } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { api } from '@/lib/api';
import { useQuery } from '@tanstack/react-query';

type AuditRow = {
  id: number;
  empresa_id: number | null;
  user_id: number | null;
  modulo: string | null;
  entidad: string | null;
  entidad_id: number | null;
  accion: string | null;
  descripcion: string | null;
  ip: string | null;
  hash_actual: string | null;
  created_at: string;
  // Cargados solo en el detalle (GET /auditoria/{id}); en el listado vienen igual.
  user_agent?: string | null;
  hash_prev?: string | null;
  datos_antes?: unknown;
  datos_despues?: unknown;
};

type Paginated = {
  data: AuditRow[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
};

type Filtros = {
  modulo: string;
  entidad: string;
  accion: string;
  user_id: string;
  desde: string;
  hasta: string;
};

const ACCIONES_COMUNES = ['INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'EMITIR_CAE', 'CONCILIAR', 'IGNORAR', 'IMPORTAR'];

export function AuditoriaPage() {
  const [page, setPage] = useState(1);
  const [filtros, setFiltros] = useState<Filtros>({
    modulo: '', entidad: '', accion: '', user_id: '', desde: '', hasta: '',
  });
  const [aplicados, setAplicados] = useState<Filtros>(filtros);
  const [detalle, setDetalle] = useState<AuditRow | null>(null);

  const { data, isLoading } = useQuery<Paginated>({
    queryKey: ['auditoria', aplicados, page],
    queryFn: () => {
      const qs = new URLSearchParams({ page: String(page), per_page: '50' });
      Object.entries(aplicados).forEach(([k, v]) => v && qs.set(k, v));
      return api.get(`/api/erp/auditoria?${qs}`);
    },
  });

  const aplicar = () => {
    setPage(1);
    setAplicados(filtros);
  };
  const limpiar = () => {
    const empty = { modulo: '', entidad: '', accion: '', user_id: '', desde: '', hasta: '' };
    setFiltros(empty);
    setAplicados(empty);
    setPage(1);
  };

  const verificarCadena = useQuery<{ ok: boolean; data: { rotos: number; primer_id_roto: number | null } }>({
    queryKey: ['auditoria-verificar'],
    queryFn: () => api.get('/api/erp/auditoria/verificar-cadena'),
    enabled: false, // se dispara on-demand
  });

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Auditoría</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            Log inmutable con hash-chain (RN-12). Insert-only por diseño — no se pueden borrar ni
            modificar registros desde la aplicación.
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant="secondary"
            disabled={verificarCadena.isFetching}
            onClick={() => verificarCadena.refetch()}
          >
            {verificarCadena.isFetching ? <Loader2 className="w-3 h-3 animate-spin" /> : <ShieldCheck className="w-3 h-3" />}
            Verificar cadena
          </Button>
        </div>
      </div>

      {verificarCadena.data && (
        <div className={`mb-3 p-3 rounded-md border text-[12px] ${
          verificarCadena.data.data.rotos === 0
            ? 'bg-success-bg border-success/30 text-success'
            : 'bg-danger-bg border-danger/30 text-danger'
        }`}>
          {verificarCadena.data.data.rotos === 0
            ? '✓ Cadena íntegra — todos los hash_prev/hash_actual coinciden.'
            : `⚠ Cadena rota — ${verificarCadena.data.data.rotos} eslabones inconsistentes (primero en id ${verificarCadena.data.data.primer_id_roto}).`}
        </div>
      )}

      <Card className="mb-3">
        <CardHeader title="Filtros" />
        <CardBody>
          <div className="grid grid-cols-6 gap-2 text-[12px]">
            <FInput label="Módulo" value={filtros.modulo} onChange={(v) => setFiltros({ ...filtros, modulo: v })} placeholder="seguridad, tesoreria, …" />
            <FInput label="Entidad" value={filtros.entidad} onChange={(v) => setFiltros({ ...filtros, entidad: v })} placeholder="UsuarioPerfil, MovimientoBancario, …" />
            <div>
              <label className="block text-[10px] uppercase font-semibold text-ink-muted tracking-wider mb-1">Acción</label>
              <select
                value={filtros.accion}
                onChange={(e) => setFiltros({ ...filtros, accion: e.target.value })}
                className="w-full px-[9px] py-[6px] text-[12px] border border-line-strong rounded-md bg-white"
              >
                <option value="">Todas</option>
                {ACCIONES_COMUNES.map((a) => <option key={a} value={a}>{a}</option>)}
              </select>
            </div>
            <FInput label="User ID" value={filtros.user_id} onChange={(v) => setFiltros({ ...filtros, user_id: v })} placeholder="123" />
            <FInput label="Desde" value={filtros.desde} onChange={(v) => setFiltros({ ...filtros, desde: v })} type="date" />
            <FInput label="Hasta" value={filtros.hasta} onChange={(v) => setFiltros({ ...filtros, hasta: v })} type="date" />
          </div>
          <div className="flex gap-2 mt-3 justify-end">
            <Button variant="secondary" size="sm" onClick={limpiar}>
              <X className="w-3 h-3" /> Limpiar
            </Button>
            <Button variant="primary" size="sm" onClick={aplicar}>
              <Search className="w-3 h-3" /> Aplicar
            </Button>
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Eventos"
          actions={
            <div className="text-[11px] text-ink-muted">
              {data && `${data.total} eventos · página ${data.current_page}/${data.last_page}`}
            </div>
          }
        />
        <CardBody>
          <table className="w-full border-collapse text-[12px]">
            <thead>
              <tr className="bg-surface-hover border-b border-line-strong">
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[150px]">Fecha</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[110px]">Módulo</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[160px]">Entidad</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[110px]">Acción</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase w-[80px]">User</th>
                <th className="px-[10px] py-[7px] text-left text-[11px] font-semibold text-navy-800 uppercase">Descripción</th>
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr><td colSpan={6} className="py-10 text-center text-ink-muted"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Cargando…</td></tr>
              )}
              {data?.data.length === 0 && !isLoading && (
                <tr><td colSpan={6} className="py-10 text-center text-ink-muted">Sin eventos para los filtros aplicados.</td></tr>
              )}
              {data?.data.map((r, i) => (
                <tr
                  key={r.id}
                  className={`border-b border-line cursor-pointer hover:bg-surface-hover ${i % 2 ? 'bg-surface-row' : ''}`}
                  onClick={() => setDetalle(r)}
                >
                  <td className="px-[10px] py-[7px] tabular text-ink-2 text-[11px]">
                    {new Date(r.created_at).toLocaleString('es-AR')}
                  </td>
                  <td className="px-[10px] py-[7px] text-ink-2 text-[11px]">{r.modulo ?? '—'}</td>
                  <td className="px-[10px] py-[7px] font-mono text-[11px] text-navy-700">
                    {r.entidad ?? '—'}{r.entidad_id ? ` #${r.entidad_id}` : ''}
                  </td>
                  <td className="px-[10px] py-[7px]">
                    {r.accion ? <Badge variant={accionVariant(r.accion)}>{r.accion}</Badge> : '—'}
                  </td>
                  <td className="px-[10px] py-[7px] text-ink-muted">{r.user_id ?? '—'}</td>
                  <td className="px-[10px] py-[7px] text-ink-2">{r.descripcion ?? <span className="text-ink-muted">—</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>

          {data && data.last_page > 1 && (
            <div className="flex justify-end items-center gap-2 mt-3 text-[12px]">
              <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>← Anterior</Button>
              <span className="text-ink-muted">Página {page} de {data.last_page}</span>
              <Button size="sm" variant="secondary" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>Siguiente →</Button>
            </div>
          )}
        </CardBody>
      </Card>

      {detalle && <DetalleModal row={detalle} onClose={() => setDetalle(null)} />}
    </>
  );
}

function FInput({
  label, value, onChange, placeholder, type,
}: {
  label: string; value: string; onChange: (v: string) => void;
  placeholder?: string; type?: string;
}) {
  return (
    <div>
      <label className="block text-[10px] uppercase font-semibold text-ink-muted tracking-wider mb-1">{label}</label>
      <input
        type={type ?? 'text'}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full px-[9px] py-[6px] text-[12px] border border-line-strong rounded-md bg-white"
      />
    </div>
  );
}

function accionVariant(a: string): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
  if (a === 'INSERT' || a === 'EMITIR_CAE' || a === 'LOGIN') return 'success';
  if (a === 'UPDATE' || a === 'CONCILIAR' || a === 'IMPORTAR') return 'info';
  if (a === 'DELETE' || a === 'IGNORAR') return 'warning';
  if (a === 'LOGOUT') return 'neutral';
  return 'neutral';
}

function DetalleModal({ row, onClose }: { row: AuditRow; onClose: () => void }) {
  const { data } = useQuery<{ data: AuditRow }>({
    queryKey: ['auditoria', row.id],
    queryFn: () => api.get(`/api/erp/auditoria/${row.id}`),
  });
  const full = data?.data ?? row;

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50" onClick={onClose}>
      <div className="bg-white rounded-lg shadow-xl max-w-3xl w-[92vw] max-h-[88vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
        <div className="px-5 py-4 border-b border-line flex items-center justify-between">
          <h3 className="text-[15px] font-semibold text-navy-800">Evento de auditoría #{row.id}</h3>
          <button onClick={onClose} className="text-ink-muted hover:text-ink-2"><X className="w-4 h-4" /></button>
        </div>
        <div className="px-5 py-4 space-y-3 text-[12px]">
          <dl className="grid grid-cols-3 gap-y-2">
            <dt className="text-ink-muted">Fecha</dt>
            <dd className="col-span-2 tabular">{new Date(row.created_at).toLocaleString('es-AR')}</dd>
            <dt className="text-ink-muted">Módulo</dt>
            <dd className="col-span-2">{row.modulo ?? '—'}</dd>
            <dt className="text-ink-muted">Entidad</dt>
            <dd className="col-span-2 font-mono">{row.entidad}{row.entidad_id ? ` #${row.entidad_id}` : ''}</dd>
            <dt className="text-ink-muted">Acción</dt>
            <dd className="col-span-2">{row.accion ?? '—'}</dd>
            <dt className="text-ink-muted">Usuario</dt>
            <dd className="col-span-2">{row.user_id ?? 'sistema'}</dd>
            <dt className="text-ink-muted">IP / User agent</dt>
            <dd className="col-span-2 font-mono text-[10px] break-all">
              {full.ip ?? '—'} {full.user_agent ? `· ${full.user_agent.slice(0, 80)}` : ''}
            </dd>
            <dt className="text-ink-muted">Hash anterior</dt>
            <dd className="col-span-2 font-mono text-[10px] break-all text-ink-muted">{full.hash_prev ?? '—'}</dd>
            <dt className="text-ink-muted">Hash actual</dt>
            <dd className="col-span-2 font-mono text-[10px] break-all">{full.hash_actual ?? '—'}</dd>
          </dl>

          {row.descripcion && (
            <div>
              <div className="text-[10px] uppercase font-semibold text-ink-muted mb-1">Descripción</div>
              <div className="p-2 bg-surface-row border border-line rounded">{row.descripcion}</div>
            </div>
          )}

          {!!full.datos_antes && (
            <div>
              <div className="text-[10px] uppercase font-semibold text-ink-muted mb-1">Datos antes</div>
              <pre className="p-2 bg-surface-row border border-line rounded text-[10px] overflow-x-auto">
                {String(JSON.stringify(full.datos_antes, null, 2))}
              </pre>
            </div>
          )}
          {!!full.datos_despues && (
            <div>
              <div className="text-[10px] uppercase font-semibold text-ink-muted mb-1">Datos después</div>
              <pre className="p-2 bg-surface-row border border-line rounded text-[10px] overflow-x-auto">
                {String(JSON.stringify(full.datos_despues, null, 2))}
              </pre>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
