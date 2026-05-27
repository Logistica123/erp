import { useState } from 'react';
import { Loader2, FileText, ExternalLink, Plus, DollarSign, CalendarRange, Trash2, AlertTriangle } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { fmtMoney } from '@/lib/cn';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';
import { Link, useSearchParams } from 'react-router-dom';
import { PeriodoTrabajadoCell, EditarPeriodoBulkModal } from '@/components/factura/PeriodoTrabajado';

type Factura = {
  id: number;
  numero: number;
  cae: string | null;
  fecha_vto_cae: string | null;
  fecha_emision: string;
  imp_neto_gravado: string;
  imp_iva: string;
  imp_total: string;
  origen: string;
  verificada_arca?: number | boolean;
  estado: string;
  es_fce: number;
  tipo_codigo: string;
  tipo_nombre: string;
  letra: string | null;
  tipo_clase: string;
  tipo_signo: number;
  pto_vta: number;
  cliente_id: number;
  cliente_nombre: string;
  cliente_cuit: string | null;
  moneda: string;
  asiento_id: number | null;
  asiento_numero: number | null;
  asiento_estado: string | null;
  // v1.27 — exposed by backend listing
  periodo_trabajado_texto?: string | null;
  jurisdiccion_codigo?: string | null;
};

type Resp = { data: Factura[] };

function estadoBadge(estado: string) {
  const map: Record<string, 'success' | 'warning' | 'danger' | 'default'> = {
    EMITIDA: 'success',
    COBRADA: 'success',
    CONTROLADA: 'success',
    PREPARADA: 'warning',
    COBRO_PARCIAL: 'warning',
    ANULADA_POR_NC: 'danger',
    RECHAZADA: 'danger',
    EMISION_FALLIDA: 'danger',
  };
  const variant = map[estado] ?? 'default';
  return <Badge variant={variant}>{estado}</Badge>;
}

function origenBadge(origen: string) {
  const map: Record<string, string> = {
    MANUAL: 'bg-indigo-500/10 text-indigo-700 ring-indigo-500/20',
    DISTRIAPP: 'bg-blue-500/10 text-blue-700 ring-blue-500/20',
    ARCA_IMPORT: 'bg-purple-500/10 text-purple-700 ring-purple-500/20',
    WSFE_ERP: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20',
    MIS_COMPROBANTES: 'bg-amber-500/10 text-amber-700 ring-amber-500/20',
  };
  const cls = map[origen] ?? 'bg-gray-500/10 text-gray-700 ring-gray-500/20';
  return (
    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${cls}`}>
      {origen}
    </span>
  );
}

function formatNro(tipo: string, letra: string | null, pv: number, nro: number): string {
  const lbl = letra ? `${tipo}-${letra}` : tipo;
  const pvStr = String(pv).padStart(4, '0');
  const nroStr = String(nro).padStart(8, '0');
  return `${lbl}  ${pvStr}-${nroStr}`;
}

type CobroCatalogos = {
  medios_pago: { id: number; codigo: string; nombre: string; afecta_caja: number; afecta_banco: number }[];
  cajas: { id: number; codigo: string; nombre: string }[];
  cuentas_bancarias: { id: number; codigo: string; nombre: string }[];
};

export function FacturacionPage() {
  const qc = useQueryClient();
  // v1.18 Sprint U U3 — filtros persistidos en query string (bookmarking).
  const [searchParams, setSearchParams] = useSearchParams();
  const estado = searchParams.get('estado') ?? '';
  const origen = searchParams.get('origen') ?? '';
  const periodoTrab = searchParams.get('periodo_trabajado') ?? '';
  const setEstado = (v: string) => {
    const p = new URLSearchParams(searchParams);
    if (v) p.set('estado', v); else p.delete('estado');
    setSearchParams(p, { replace: true });
  };
  const setOrigen = (v: string) => {
    const p = new URLSearchParams(searchParams);
    if (v) p.set('origen', v); else p.delete('origen');
    setSearchParams(p, { replace: true });
  };
  const setPeriodoTrab = (v: string) => {
    const p = new URLSearchParams(searchParams);
    if (v) p.set('periodo_trabajado', v); else p.delete('periodo_trabajado');
    setSearchParams(p, { replace: true });
  };
  const [cobroFactura, setCobroFactura] = useState<Factura | null>(null);
  // v1.29 — modal de eliminación con doble confirmación para WS con CAE.
  const [eliminarFactura, setEliminarFactura] = useState<Factura | null>(null);
  // v1.27 — bulk select + edición.
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [editarPeriodoOpen, setEditarPeriodoOpen] = useState(false);
  const [borrarMasivoOpen, setBorrarMasivoOpen] = useState(false);

  // v1.27 — permisos + lista distinct.
  const { data: misPermisos } = useApi<Array<{ codigo: string }>>(
    ['mi-permisos'],
    '/api/erp/mi-permisos',
  );
  const puedeEditarPeriodo = !!misPermisos?.some((p) => p.codigo === 'ventas.facturas.editar');
  // v1.29 — permisos para eliminar (3 escalones según origen + CAE).
  const puedeEliminarWs = !!misPermisos?.some((p) => p.codigo === 'ventas.facturas.eliminar_ws');
  const puedeEliminarSinCae = !!misPermisos?.some((p) => p.codigo === 'ventas.facturas.eliminar_sin_cae');
  const puedeBorrarManual = !!misPermisos?.some((p) => p.codigo === 'compras.facturas.borrar_masivo');
  const puedeBorrarMasivo = !!misPermisos?.some((p) => p.codigo === 'ventas.facturas.borrar_masivo');
  const puedeSeleccionar = puedeEditarPeriodo || puedeBorrarMasivo;
  const { data: periodosDistinct } = useApi<string[]>(
    ['facturas-venta-periodos-trabajados'],
    '/api/erp/facturas-venta/periodos-trabajados',
  );
  const usaSelectorPeriodo = (periodosDistinct?.length ?? 0) > 0;

  const { data, isLoading, error } = useQuery<Resp>({
    queryKey: ['facturas-venta', { estado, origen, periodoTrab }],
    queryFn: () => {
      const qs = new URLSearchParams();
      if (estado) qs.set('estado', estado);
      if (origen) qs.set('origen', origen);
      if (periodoTrab) qs.set('periodo_trabajado', periodoTrab);
      const suf = qs.toString() ? `?${qs.toString()}` : '';
      return api.get<Resp>(`/api/erp/facturas-venta${suf}`);
    },
  });

  const facturas = data?.data ?? [];
  const todoSeleccionado = facturas.length > 0 && facturas.every((f) => selectedIds.has(f.id));
  const toggleFila = (id: number) => setSelectedIds((prev) => {
    const next = new Set(prev);
    next.has(id) ? next.delete(id) : next.add(id);
    return next;
  });
  const toggleTodos = () => {
    if (todoSeleccionado) setSelectedIds(new Set());
    else setSelectedIds(new Set(facturas.map((f) => f.id)));
  };
  const seleccionadas = facturas.filter((f) => selectedIds.has(f.id));
  const totales = facturas.reduce(
    (acc, f) => {
      const signo = f.tipo_signo ?? 1;
      const imp = parseFloat(f.imp_total) * signo;
      return {
        cant: acc.cant + 1,
        total: acc.total + imp,
        iva: acc.iva + parseFloat(f.imp_iva) * signo,
      };
    },
    { cant: 0, total: 0, iva: 0 }
  );

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Facturación (ARCA)</h1>
          <p className="text-sm text-gray-500 mt-1">
            Facturas emitidas por el ERP, sincronizadas desde DistriApp o importadas vía ARCA.
          </p>
        </div>
        <div className="flex gap-2">
          {/* v1.45 — Importador CSV/Excel del Libro IVA Ventas. */}
          <Link to="/erp/libro-iva-ventas/import">
            <Button variant="outline">
              <Plus className="w-4 h-4 mr-1" /> Importar Libro IVA
            </Button>
          </Link>
          {/* v1.39 — Wizard batch import de PDFs de AFIP. */}
          <Link to="/erp/facturacion/importar-pdfs">
            <Button variant="outline">
              <Plus className="w-4 h-4 mr-1" /> Importar PDFs AFIP
            </Button>
          </Link>
          {/* v1.17 — Botón para carga manual (NO emite ARCA). */}
          <Link to="/erp/facturacion/nueva-manual">
            <Button variant="outline">
              <Plus className="w-4 h-4 mr-1" /> Nueva manual
            </Button>
          </Link>
          <Link to="/erp/facturacion/nueva">
            <Button>
              <Plus className="w-4 h-4 mr-1" /> Emitir factura (ARCA)
            </Button>
          </Link>
        </div>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <Card>
          <CardBody>
            <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">
              Facturas en lista
            </div>
            <div className="mt-2 text-2xl font-bold text-gray-900">{totales.cant}</div>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">
              Total neto del período (sin IVA)
            </div>
            <div className="mt-2 text-2xl font-bold text-gray-900">
              {fmtMoney(totales.total - totales.iva)}
            </div>
            <div className="text-[11px] text-gray-500 mt-1">
              Suma de la base imponible de las facturas filtradas
            </div>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">
              IVA Débito Fiscal
            </div>
            <div className="mt-2 text-2xl font-bold text-gray-900">{fmtMoney(totales.iva)}</div>
          </CardBody>
        </Card>
      </div>

      {/* Tabla */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between gap-3 flex-wrap">
            <div className="font-semibold text-gray-900 flex items-center gap-2">
              <FileText className="w-4 h-4 text-azure" /> Listado
            </div>
            <div className="flex items-center gap-2">
              <select
                value={estado}
                onChange={(e) => setEstado(e.target.value)}
                className="text-sm border rounded-md px-2 py-1 bg-white"
              >
                <option value="">Todos los estados</option>
                <option value="EMITIDA">EMITIDA</option>
                <option value="COBRADA">COBRADA</option>
                <option value="COBRO_PARCIAL">COBRO_PARCIAL</option>
                <option value="PREPARADA">PREPARADA</option>
                <option value="EMISION_FALLIDA">EMISION_FALLIDA</option>
              </select>
              {/* v1.27 — filtro período trabajado. Dropdown si hay valores cargados. */}
              {usaSelectorPeriodo ? (
                <select
                  value={periodoTrab}
                  onChange={(e) => setPeriodoTrab(e.target.value)}
                  className="text-sm border rounded-md px-2 py-1 bg-white"
                  title="Período trabajado"
                >
                  <option value="">Todos los períodos</option>
                  <option value="__VACIOS__">— Vacíos —</option>
                  {(periodosDistinct ?? []).map((p) => (
                    <option key={p} value={p}>{p}</option>
                  ))}
                </select>
              ) : (
                <input
                  type="text"
                  value={periodoTrab}
                  onChange={(e) => setPeriodoTrab(e.target.value)}
                  placeholder="P. trabaj."
                  className="text-sm border rounded-md px-2 py-1 bg-white w-[120px]"
                />
              )}
              <select
                value={origen}
                onChange={(e) => setOrigen(e.target.value)}
                className="text-sm border rounded-md px-2 py-1 bg-white"
              >
                <option value="">Todos los orígenes</option>
                <option value="MANUAL">MANUAL</option>
                <option value="DISTRIAPP">DISTRIAPP</option>
                <option value="WSFE_ERP">WSFE_ERP</option>
                <option value="ARCA_IMPORT">ARCA_IMPORT</option>
              </select>
            </div>
          </div>
        </CardHeader>
        <CardBody className="p-0">
          {/* v1.27 — barra de acción con selección. */}
          {puedeSeleccionar && selectedIds.size > 0 && (
            <div className="flex items-center justify-between border-b border-warning/40 bg-warning-bg/30 px-4 py-2 text-[12px]">
              <span className="text-warning font-medium">
                {selectedIds.size} factura{selectedIds.size === 1 ? '' : 's'} seleccionada{selectedIds.size === 1 ? '' : 's'}
              </span>
              <div className="flex gap-2">
                <Button size="sm" variant="outline" onClick={() => setSelectedIds(new Set())}>
                  Limpiar
                </Button>
                {puedeEditarPeriodo && (
                  <Button size="sm" variant="primary" onClick={() => setEditarPeriodoOpen(true)}>
                    <CalendarRange className="w-3 h-3" /> Asignar período
                  </Button>
                )}
                {puedeBorrarMasivo && (
                  <Button size="sm" variant="danger" onClick={() => setBorrarMasivoOpen(true)}>
                    <Trash2 className="w-3 h-3" /> Borrar seleccionadas (excepto WSFE)
                  </Button>
                )}
              </div>
            </div>
          )}
          {isLoading ? (
            <div className="p-8 flex items-center justify-center gap-2 text-gray-500 text-sm">
              <Loader2 className="w-4 h-4 animate-spin" /> Cargando...
            </div>
          ) : error ? (
            <div className="p-8 text-center text-sm text-red-600">Error al cargar facturas.</div>
          ) : facturas.length === 0 ? (
            <div className="p-8 text-center text-sm text-gray-500">Sin facturas en el filtro.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 text-left text-[11px] font-semibold uppercase text-gray-500 tracking-wider">
                  <tr>
                    {puedeSeleccionar && (
                      <th className="px-2 py-3 w-[40px]">
                        <input type="checkbox" checked={todoSeleccionado} onChange={toggleTodos} />
                      </th>
                    )}
                    <th className="px-4 py-3">Fecha</th>
                    <th className="px-4 py-3">Comprobante</th>
                    <th className="px-4 py-3">Cliente</th>
                    <th className="px-4 py-3">CAE</th>
                    <th className="px-4 py-3 text-right">Neto</th>
                    <th className="px-4 py-3 text-right">IVA</th>
                    <th className="px-4 py-3 text-right">Total</th>
                    <th className="px-4 py-3">P. trabaj.</th>
                    <th className="px-4 py-3">Origen</th>
                    <th className="px-4 py-3">Estado</th>
                    <th className="px-4 py-3">Asiento</th>
                    <th className="px-4 py-3"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {facturas.map((f) => (
                    <tr key={f.id} className="hover:bg-gray-50">
                      {puedeSeleccionar && (
                        <td className="px-2 py-3 text-center">
                          <input
                            type="checkbox"
                            checked={selectedIds.has(f.id)}
                            onChange={() => toggleFila(f.id)}
                          />
                        </td>
                      )}
                      <td className="px-4 py-3 text-gray-700 whitespace-nowrap">
                        {f.fecha_emision?.slice(0, 10)}
                      </td>
                      <td className="px-4 py-3 font-mono text-[12px] text-gray-800 whitespace-nowrap">
                        {formatNro(f.tipo_codigo, f.letra, f.pto_vta, f.numero)}
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900">{f.cliente_nombre}</div>
                        {f.cliente_cuit && (
                          <div className="text-[11px] text-gray-500 font-mono">{f.cliente_cuit}</div>
                        )}
                      </td>
                      <td className="px-4 py-3 font-mono text-[11px] text-gray-700">
                        {f.cae ?? '—'}
                      </td>
                      <td className="px-4 py-3 text-right font-mono text-gray-700">
                        {fmtMoney(parseFloat(f.imp_neto_gravado))}
                      </td>
                      <td className="px-4 py-3 text-right font-mono text-gray-700">
                        {fmtMoney(parseFloat(f.imp_iva))}
                      </td>
                      <td className="px-4 py-3 text-right font-mono font-semibold text-gray-900">
                        {fmtMoney(parseFloat(f.imp_total))}
                      </td>
                      <td className="px-4 py-3 text-[11px]">
                        <PeriodoTrabajadoCell
                          value={f.periodo_trabajado_texto}
                          editable={puedeEditarPeriodo}
                          endpointUrl={`/api/erp/facturas-venta/${f.id}/periodo-trabajado`}
                          invalidateKeys={[['facturas-venta'], ['facturas-venta-periodos-trabajados']]}
                        />
                      </td>
                      <td className="px-4 py-3">
                        <div className="inline-flex items-center gap-1">
                          {origenBadge(f.origen)}
                          {/* v1.18 U6 — ✓ si verificada contra ARCA. */}
                          {Number(f.verificada_arca) === 1 && (
                            <span title="Verificada contra ARCA" className="text-emerald-600 font-bold">✓</span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3">{estadoBadge(f.estado)}</td>
                      <td className="px-4 py-3">
                        {f.asiento_id ? (
                          <Link
                            to={`/erp/libro-diario?asiento=${f.asiento_id}`}
                            className="inline-flex items-center gap-1 text-[12px] text-azure hover:underline"
                          >
                            #{f.asiento_numero}
                            <ExternalLink className="w-3 h-3" />
                          </Link>
                        ) : (
                          <span className="text-[12px] text-gray-400">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex gap-1 flex-wrap">
                          {f.estado === 'EMITIDA' && f.tipo_clase === 'FACTURA' && (
                            <Button size="sm" variant="outline" onClick={() => setCobroFactura(f)}>
                              <DollarSign className="w-3 h-3" /> Cobrar
                            </Button>
                          )}
                          {/* v1.29 — Eliminar con permiso condicional. El backend
                              valida el permiso real. Las emitidas por Web Service
                              (WSFE_ERP) NO se borran desde acá: lo correcto es
                              emitir una NC. Se oculta el tacho para ese origen. */}
                          {f.origen !== 'WSFE_ERP' && (puedeEliminarWs || puedeEliminarSinCae || puedeBorrarManual) && (
                            <Button size="sm" variant="ghost" onClick={() => setEliminarFactura(f)}>
                              <Trash2 className="w-3 h-3 text-danger" />
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <CobroModal
        factura={cobroFactura}
        onClose={() => setCobroFactura(null)}
        onSuccess={() => {
          setCobroFactura(null);
          qc.invalidateQueries({ queryKey: ['facturas-venta'] });
          qc.invalidateQueries({ queryKey: ['dashboard-stats'] });
        }}
      />
      {editarPeriodoOpen && (
        <EditarPeriodoBulkModal
          facturas={seleccionadas}
          endpointUrl="/api/erp/facturas-venta/periodos-trabajados"
          invalidateKeys={[['facturas-venta'], ['facturas-venta-periodos-trabajados']]}
          onClose={() => setEditarPeriodoOpen(false)}
          onDone={() => { setSelectedIds(new Set()); setEditarPeriodoOpen(false); }}
        />
      )}

      {/* v1.29 — Modal de eliminación con doble confirmación para WS con CAE */}
      <EliminarFacturaModal
        factura={eliminarFactura}
        onClose={() => setEliminarFactura(null)}
        onSuccess={() => {
          setEliminarFactura(null);
          qc.invalidateQueries({ queryKey: ['facturas-venta'] });
        }}
      />

      {borrarMasivoOpen && (
        <BorrarMasivoModal
          facturas={seleccionadas}
          onClose={() => setBorrarMasivoOpen(false)}
          onDone={() => {
            setSelectedIds(new Set());
            setBorrarMasivoOpen(false);
            qc.invalidateQueries({ queryKey: ['facturas-venta'] });
            qc.invalidateQueries({ queryKey: ['dashboard-stats'] });
          }}
        />
      )}
    </div>
  );
}

function BorrarMasivoModal({ facturas, onClose, onDone }: {
  facturas: Factura[];
  onClose: () => void;
  onDone: () => void;
}) {
  const toast = useToast();
  const [motivo, setMotivo] = useState('');
  const wsfe = facturas.filter((f) => f.origen === 'WSFE_ERP');
  const candidatas = facturas.filter((f) => f.origen !== 'WSFE_ERP');

  const mut = useMutation<{ data: {
    borradas: number; omitidas_ws: number;
    omitidas_referenciadas: Array<{ id: number; motivo: string }>;
    errores: Array<{ id: number; error: string }>;
  } }, ApiError, void>({
    mutationFn: () => api.post('/api/erp/facturas-venta/borrar-masivo', {
      ids: facturas.map((f) => f.id),
      motivo: motivo.trim() || undefined,
    }),
    onSuccess: (r) => {
      const d = r.data;
      const partes = [`${d.borradas} borradas`];
      if (d.omitidas_ws > 0) partes.push(`${d.omitidas_ws} WSFE omitidas`);
      if (d.omitidas_referenciadas.length > 0) partes.push(`${d.omitidas_referenciadas.length} con referencias omitidas`);
      if (d.errores.length > 0) partes.push(`${d.errores.length} con error`);
      toast.success('Borrado masivo terminado', partes.join(' · '));
      onDone();
    },
    onError: (e) => toast.error('No se pudo borrar', e.message),
  });

  return (
    <Modal open onClose={onClose} title="Borrar facturas seleccionadas" size="md" footer={
      <>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="danger" disabled={candidatas.length === 0 || mut.isPending}
          onClick={() => mut.mutate()}>
          {mut.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Trash2 className="w-3 h-3" />}
          Borrar {candidatas.length} factura{candidatas.length === 1 ? '' : 's'}
        </Button>
      </>
    }>
      <div className="space-y-2 text-[12px]">
        <div className="border border-danger/40 bg-danger-bg/20 rounded p-2 flex items-start gap-1.5">
          <AlertTriangle className="w-4 h-4 text-danger shrink-0 mt-0.5" />
          <div>
            Borrado físico irreversible de <strong>{candidatas.length}</strong> factura{candidatas.length === 1 ? '' : 's'}.
            Cada una queda en el audit log.
          </div>
        </div>
        {wsfe.length > 0 && (
          <div className="text-[11.5px] text-ink">
            <strong>{wsfe.length}</strong> seleccionada{wsfe.length === 1 ? '' : 's'} {wsfe.length === 1 ? 'es' : 'son'} <code>WSFE_ERP</code> (emitida{wsfe.length === 1 ? '' : 's'} por Web Service) →
            <strong> no se borran</strong>, se omiten automáticamente.
          </div>
        )}
        <div className="text-[11px] text-ink-muted">
          Las facturas que estén imputadas en recibos, cobradas, con NC o incluidas en un Libro IVA generado
          también se omiten (se reportan al terminar).
        </div>
        <div>
          <label className="block text-[11px] text-ink-muted mb-1">Motivo (opcional)</label>
          <textarea rows={2} value={motivo} onChange={(e) => setMotivo(e.target.value)}
            maxLength={500}
            placeholder="Ej: limpieza de comprobantes importados de prueba"
            className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
        </div>
      </div>
    </Modal>
  );
}

function CobroModal({
  factura, onClose, onSuccess,
}: {
  factura: Factura | null;
  onClose: () => void;
  onSuccess: () => void;
}) {
  const [medioId, setMedioId] = useState<number>(0);
  const [cajaId, setCajaId] = useState<number>(0);
  const [ctaBancId, setCtaBancId] = useState<number>(0);
  const [fecha, setFecha] = useState(() => new Date().toISOString().slice(0, 10));
  const [referencia, setReferencia] = useState('');
  const [error, setError] = useState<string | null>(null);

  const { data: cats } = useQuery<CobroCatalogos>({
    queryKey: ['fv-catalogos-cobro'],
    queryFn: () => api.get<CobroCatalogos>('/api/erp/facturas-venta/catalogos'),
    enabled: !!factura,
  });

  // Defaults cuando llegan los catálogos
  if (cats && medioId === 0 && cats.medios_pago[0]) {
    setMedioId(cats.medios_pago[0].id);
  }
  if (cats && cajaId === 0 && cats.cajas[0]) setCajaId(cats.cajas[0].id);
  if (cats && ctaBancId === 0 && cats.cuentas_bancarias[0]) setCtaBancId(cats.cuentas_bancarias[0].id);

  const medio = cats?.medios_pago.find((m) => m.id === medioId);
  const afectaCaja = !!medio?.afecta_caja;
  const afectaBanco = !!medio?.afecta_banco;

  const mutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      api.post(`/api/erp/facturas-venta/${factura!.id}/cobrar`, payload),
    onSuccess: () => { setError(null); onSuccess(); },
    onError: (e: Error) => setError(e.message),
  });

  const onSubmit = (ev: React.FormEvent) => {
    ev.preventDefault();
    setError(null);
    mutation.mutate({
      fecha,
      medio_pago_id: medioId,
      caja_id: afectaCaja ? cajaId : null,
      cuenta_bancaria_id: afectaBanco ? ctaBancId : null,
      referencia: referencia || null,
    });
  };

  if (!factura) return null;

  return (
    <Modal
      open={!!factura}
      onClose={onClose}
      title={`Cobrar — ${factura.tipo_codigo}${factura.letra ? '-' + factura.letra : ''} ${String(factura.pto_vta).padStart(4, '0')}-${String(factura.numero).padStart(8, '0')}`}
      size="sm"
    >
      <form onSubmit={onSubmit} className="space-y-4">
        <div className="bg-gray-50 rounded-md p-3 text-sm">
          <div className="text-xs text-gray-500 uppercase">Cliente</div>
          <div className="font-medium">{factura.cliente_nombre}</div>
          <div className="mt-2 text-xs text-gray-500 uppercase">Monto a cobrar</div>
          <div className="font-mono text-xl font-bold">{fmtMoney(parseFloat(factura.imp_total))}</div>
        </div>

        <div>
          <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Fecha</label>
          <input
            type="date"
            value={fecha}
            onChange={(e) => setFecha(e.target.value)}
            className="w-full border rounded-md px-3 py-2 text-sm"
            required
          />
        </div>

        <div>
          <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Medio de pago</label>
          <select
            value={medioId}
            onChange={(e) => setMedioId(+e.target.value)}
            className="w-full border rounded-md px-3 py-2 text-sm"
            required
          >
            <option value={0} disabled>Seleccionar medio...</option>
            {cats?.medios_pago.map((m) => (
              <option key={m.id} value={m.id}>{m.nombre}</option>
            ))}
          </select>
        </div>

        {afectaCaja && (
          <div>
            <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Caja</label>
            <select
              value={cajaId}
              onChange={(e) => setCajaId(+e.target.value)}
              className="w-full border rounded-md px-3 py-2 text-sm"
              required
            >
              <option value={0} disabled>Seleccionar caja...</option>
              {cats?.cajas.map((c) => (
                <option key={c.id} value={c.id}>{c.nombre}</option>
              ))}
            </select>
          </div>
        )}

        {afectaBanco && (
          <div>
            <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">Cuenta bancaria</label>
            <select
              value={ctaBancId}
              onChange={(e) => setCtaBancId(+e.target.value)}
              className="w-full border rounded-md px-3 py-2 text-sm"
              required
            >
              <option value={0} disabled>Seleccionar cuenta...</option>
              {cats?.cuentas_bancarias.map((c) => (
                <option key={c.id} value={c.id}>{c.nombre}</option>
              ))}
            </select>
            {(!cats?.cuentas_bancarias || cats.cuentas_bancarias.length === 0) && (
              <div className="text-xs text-amber-600 mt-1">
                No hay cuentas bancarias. Usá medio EFECTIVO o creá una en Tesorería.
              </div>
            )}
          </div>
        )}

        <div>
          <label className="block text-xs font-semibold text-gray-600 uppercase mb-1">
            Referencia (opcional)
          </label>
          <input
            type="text"
            value={referencia}
            onChange={(e) => setReferencia(e.target.value)}
            placeholder="Nro. transferencia / cheque / recibo..."
            className="w-full border rounded-md px-3 py-2 text-sm"
          />
        </div>

        {error && (
          <div className="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
            {error}
          </div>
        )}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
          <Button
            type="submit"
            disabled={
              mutation.isPending ||
              !medioId ||
              (afectaCaja && !cajaId) ||
              (afectaBanco && !ctaBancId)
            }
          >
            {mutation.isPending ? <><Loader2 className="w-4 h-4 animate-spin mr-2" /> Registrando...</> : 'Registrar cobro'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}


// v1.29 — Modal de eliminación con doble confirmación (texto "ELIMINAR" + motivo ≥20 chars)
function EliminarFacturaModal({ factura, onClose, onSuccess }: {
  factura: Factura | null;
  onClose: () => void;
  onSuccess: () => void;
}) {
  const [confirmText, setConfirmText] = useState('');
  const [motivo, setMotivo] = useState('');
  const [err, setErr] = useState<string | null>(null);

  if (!factura) return null;

  const esWs = ['EMITIDA', 'WSFE', 'WS', 'WSFE_ERP', 'MIS_COMPROBANTES'].includes(factura.origen);
  const tieneCae = !!factura.cae;
  const caeVigente = tieneCae && factura.estado !== 'EMISION_FALLIDA' && factura.estado !== 'ANULADA_POR_NC';
  const requiereDoble = esWs && caeVigente;

  const submitMut = useMutation({
    mutationFn: () =>
      api.delete(`/api/erp/facturas-venta/${factura.id}`, requiereDoble
        ? { confirm_text: confirmText, motivo: motivo.trim() }
        : { motivo: motivo.trim() || undefined }),
    onSuccess: () => {
      setConfirmText(''); setMotivo(''); setErr(null);
      onSuccess();
    },
    onError: (e: ApiError) => setErr(e.message),
  });

  const valid = requiereDoble
    ? (confirmText === 'ELIMINAR' && motivo.trim().length >= 20)
    : true;

  return (
    <Modal open onClose={onClose}
      title={requiereDoble ? '⚠⚠ Eliminar factura WS con CAE válido' : `Eliminar factura ${factura.tipo_codigo} ${factura.numero}`}
      size="lg">
      <div className="space-y-3 text-[12px]">
        {requiereDoble && (
          <div className="border border-danger/40 bg-danger-bg/30 rounded p-2 text-[11.5px] space-y-1">
            <div className="flex items-start gap-1.5">
              <AlertTriangle className="w-4 h-4 text-danger flex-shrink-0 mt-0.5" />
              <div>
                <strong>Esta factura tiene CAE válido emitido por ARCA.</strong>
                <br />Eliminarla rompe la cadena fiscal. Lo correcto es emitir una Nota de Crédito (NC).
                <br />Si insistís en eliminarla, escribí <code className="bg-danger/10 px-1">ELIMINAR</code> en mayúsculas y un motivo de al menos 20 caracteres.
              </div>
            </div>
          </div>
        )}

        <dl className="grid grid-cols-[140px_1fr] gap-y-1 gap-x-2 text-[11px] bg-azure-soft/30 rounded p-2">
          <dt className="text-ink-muted">Tipo</dt><dd>{factura.tipo_codigo} {factura.letra ?? ''} {factura.numero}</dd>
          <dt className="text-ink-muted">Origen</dt><dd>{factura.origen}</dd>
          <dt className="text-ink-muted">CAE</dt><dd className="font-mono">{factura.cae ?? '— sin CAE —'}</dd>
          <dt className="text-ink-muted">Estado</dt><dd>{factura.estado}</dd>
          <dt className="text-ink-muted">Total</dt><dd className="font-semibold tabular">${fmtMoney(Number(factura.imp_total))}</dd>
        </dl>

        {requiereDoble && (
          <div>
            <label className="block text-[11px] text-ink-muted mb-1">
              Escribí "ELIMINAR" para confirmar
            </label>
            <input type="text" value={confirmText} onChange={(e) => setConfirmText(e.target.value)}
              placeholder="ELIMINAR"
              className={`w-full px-2 py-1 text-[12px] border rounded focus:outline-none ${
                confirmText === 'ELIMINAR' ? 'border-success focus:border-success' :
                confirmText ? 'border-danger focus:border-danger' :
                'border-azure-soft focus:border-azure'
              }`} />
          </div>
        )}

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">
            Motivo {requiereDoble ? '* (mínimo 20 chars)' : '(opcional)'}
          </label>
          <textarea rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)}
            maxLength={500}
            placeholder="Ej: Factura emitida por error a CUIT incorrecto; cliente solicitó eliminación; ya se emitió la NC compensatoria #..."
            className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
          {requiereDoble && (
            <div className="text-[10px] text-ink-muted mt-0.5">
              {motivo.length} / 500 — {motivo.trim().length < 20 ? `faltan ${20 - motivo.trim().length}` : '✓'}
            </div>
          )}
        </div>

        {err && (
          <div className="border border-danger/30 bg-danger-bg/20 rounded p-2 text-[11.5px] text-danger">
            {err}
          </div>
        )}

        <div className="flex justify-end gap-2 pt-2 border-t border-line">
          <Button variant="secondary" onClick={onClose} disabled={submitMut.isPending}>Cancelar</Button>
          <Button variant="danger" disabled={!valid || submitMut.isPending}
            onClick={() => submitMut.mutate()}>
            {submitMut.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Trash2 className="w-3 h-3" />}
            Eliminar definitivamente
          </Button>
        </div>
      </div>
    </Modal>
  );
}
