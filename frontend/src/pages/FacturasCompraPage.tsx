import { useMemo, useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { PeriodoTrabajadoCell, EditarPeriodoBulkModal } from '@/components/factura/PeriodoTrabajado';
import { OpExternaCell, FechaPagoCell } from '@/components/factura/PagoInfoCells';
import { CategoriaModal } from '@/components/factura/CategoriaModal';
import { Link, useSearchParams } from 'react-router-dom';
import { ShoppingCart, CheckCircle2, AlertTriangle, XCircle, Plus, Trash2, CalendarRange, FileDown, Undo2, FileText } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { api, ApiError } from '@/lib/api';
import { auth } from '@/lib/auth';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type FacturaCompra = {
  id: number;
  numero: number;
  cae: string | null;
  fecha_emision: string;
  fecha_imputacion: string | null;
  imputacion_diferida: boolean | number;
  periodo_id: number | null;
  fecha_vencimiento: string | null;
  imp_neto_gravado: number | string;
  imp_iva: number | string;
  imp_total: number | string;
  origen: string;
  estado: string;
  constatacion_estado: string;
  tipo_codigo: string;
  letra: string;
  tipo_clase: string;
  punto_venta: number;
  proveedor_id: number | null;
  proveedor_nombre: string | null;
  proveedor_cuit: string | null;
  cuit_emisor: string;
  razon_social_emisor: string;
  // v1.56 — PDF del comprobante original (URL de DistriApp u otra fuente).
  adjunto_url?: string | null;
  // El listado devuelve `moneda` como string (alias del JOIN, ej "ARS").
  // El detalle (show) devuelve `moneda` como objeto Eloquent (relación).
  // monedaStr() normaliza ambos casos al código del símbolo.
  moneda: string | { codigo: string; simbolo?: string; nombre?: string } | null;
  asiento_id: number | null;
  asiento_numero: number | null;
  // Addendum v1.13 + v1.14
  no_tomada: number | boolean;
  cliente_auxiliar_id: number | null;
  tipo_gasto: string | null;
  periodo_trabajado_texto: string | null;
  jurisdiccion_codigo: string | null;
  centro_costo_id: number | null;
  // v1.37 — FACTURA (default, sin badge) vs EFECTIVO (badge ámbar).
  categoria?: 'FACTURA' | 'EFECTIVO';
  // v1.40 — OP externa + fecha de pago (referenciales, opcionales).
  op_externa: string | null;
  fecha_pago: string | null;
  // v1.54 — sync DistriApp.
  sincronizada_desde_distriapp?: number | boolean;
  distriapp_factura_id?: string | null;
  distriapp_liquidacion_id?: number | null;
};

const ESTADOS = ['PENDIENTE_AUTORIZACION_ERP', 'RECIBIDA', 'CONTROLADA', 'OBSERVADA', 'PAGO_PARCIAL', 'PAGADA', 'ANULADA_POR_NC', 'RECHAZADA'];
const ORIGENES = ['MANUAL', 'LIBRO_IVA_IMPORT', 'MIS_COMPROBANTES', 'DISTRIAPP'];

/** Normaliza el campo `moneda` que viene como string (listado) o como objeto Eloquent (detalle). */
function monedaStr(m: FacturaCompra['moneda']): string {
  if (!m) return '';
  if (typeof m === 'string') return m;
  return m.codigo ?? '';
}

function badgeFor(estado: string) {
  switch (estado) {
    case 'PENDIENTE_AUTORIZACION_ERP': return 'warning' as const;
    case 'CONTROLADA':
    case 'PAGADA':           return 'success' as const;
    case 'PAGO_PARCIAL':     return 'info' as const;
    case 'OBSERVADA':        return 'warning' as const;
    case 'RECHAZADA':
    case 'ANULADA_POR_NC':   return 'danger' as const;
    default:                 return 'neutral' as const;
  }
}

function constatBadge(s: string) {
  switch (s) {
    case 'VALIDO':         return 'success' as const;
    case 'INVALIDO':
    case 'NO_ENCONTRADO':  return 'danger' as const;
    case 'OVERRIDE':       return 'warning' as const;
    default:               return 'neutral' as const;
  }
}

export function FacturasCompraPage() {
  // v1.18 Sprint U U3 — filtros estado + origen persistidos en query string.
  // Los demás siguen en state local (cambios de fecha/CC son interactivos).
  const [searchParams, setSearchParams] = useSearchParams();
  const estado = searchParams.get('estado') ?? '';
  const origen = searchParams.get('origen') ?? '';
  const setQueryParam = (key: string, value: string) => {
    const p = new URLSearchParams(searchParams);
    if (value) p.set(key, value); else p.delete(key);
    setSearchParams(p, { replace: true });
  };
  const setEstado = (v: string) => { setPage(1); setQueryParam('estado', v); };
  const setOrigen = (v: string) => { setPage(1); setQueryParam('origen', v); };
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');
  // v1.49 — filtro adicional por fecha de imputación contable (usado típicamente
  // para reportes mensuales y para el export Excel).
  const [impDesde, setImpDesde] = useState('');
  const [impHasta, setImpHasta] = useState('');
  // Addendum v1.13 + v1.14 — filtros enriquecidos
  const [noTomada, setNoTomada] = useState<'' | '0' | '1'>('');
  const [tipoGasto, setTipoGasto] = useState('');
  const [periodoTrab, setPeriodoTrab] = useState('');
  const [juris, setJuris] = useState('');
  // v1.56 — filtro por proveedor / CUIT (pedido 2026-07-09, testing de
  // Sebastián). Input con debounce para no refetchear por tecla.
  const [proveedorQInput, setProveedorQInput] = useState('');
  const [proveedorQ, setProveedorQ] = useState('');
  useEffect(() => {
    const t = setTimeout(() => setProveedorQ(proveedorQInput.trim()), 400);
    return () => clearTimeout(t);
  }, [proveedorQInput]);
  const { data: provSugerencias } = useApi<Array<{ nombre: string; cuit: string | null }>>(
    ['aux-sugerencias', proveedorQInput],
    `/api/erp/auxiliares?q=${encodeURIComponent(proveedorQInput.trim())}`,
    { enabled: proveedorQInput.trim().length >= 2 },
  );
  // v1.42 — paginación server-side (antes el backend limitaba a 200 y el
  // resto era invisible; ahora paginate(50) por defecto).
  const [page, setPage] = useState(1);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (estado) p.set('estado', estado);
    if (origen) p.set('origen', origen);
    if (desde)  p.set('desde', desde);
    if (hasta)  p.set('hasta', hasta);
    if (impDesde) p.set('imp_desde', impDesde);
    if (impHasta) p.set('imp_hasta', impHasta);
    if (noTomada !== '') p.set('no_tomada', noTomada);
    if (tipoGasto) p.set('tipo_gasto', tipoGasto);
    if (periodoTrab) p.set('periodo_trabajado', periodoTrab);
    if (juris) p.set('jurisdiccion', juris);
    if (proveedorQ) p.set('proveedor_q', proveedorQ);
    p.set('page', String(page));
    return p.toString();
  }, [estado, origen, desde, hasta, impDesde, impHasta, noTomada, tipoGasto, periodoTrab, juris, proveedorQ, page]);

  // Reset page=1 cuando cambian los filtros (no la página).
  useEffect(() => { setPage(1); },
    [estado, origen, desde, hasta, impDesde, impHasta, noTomada, tipoGasto, periodoTrab, juris, proveedorQ]);

  // v1.49 — query string SIN page (para el export — no nos importa la página).
  const qsExport = useMemo(() => {
    const p = new URLSearchParams();
    if (estado) p.set('estado', estado);
    if (origen) p.set('origen', origen);
    if (desde)  p.set('desde', desde);
    if (hasta)  p.set('hasta', hasta);
    if (impDesde) p.set('imp_desde', impDesde);
    if (impHasta) p.set('imp_hasta', impHasta);
    if (noTomada !== '') p.set('no_tomada', noTomada);
    if (tipoGasto) p.set('tipo_gasto', tipoGasto);
    if (periodoTrab) p.set('periodo_trabajado', periodoTrab);
    if (juris) p.set('jurisdiccion', juris);
    if (proveedorQ) p.set('proveedor_q', proveedorQ);
    return p.toString();
  }, [estado, origen, desde, hasta, impDesde, impHasta, noTomada, tipoGasto, periodoTrab, juris, proveedorQ]);

  const [exportando, setExportando] = useState(false);
  const exportarExcel = () => {
    const url = `/api/erp/facturas-compra/export.xlsx${qsExport ? `?${qsExport}` : ''}`;
    const token = auth.getToken();
    setExportando(true);
    fetch(url, { headers: token ? { Authorization: `Bearer ${token}` } : {} })
      .then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.blob();
      })
      .then((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        const periodo = impDesde && impHasta ? `_imp_${impDesde}_a_${impHasta}` :
          impDesde ? `_imp_desde_${impDesde}` : '';
        a.download = `facturas_compra${periodo}.xlsx`;
        a.click();
      })
      .catch((e) => {
        // eslint-disable-next-line no-console
        console.error('Export Excel falló', e);
      })
      .finally(() => setExportando(false));
  };

  const { data, isLoading, error } = useQuery({
    queryKey: ['facturas-compra', qs],
    queryFn: () => api.get<{
      data: FacturaCompra[];
      current_page: number;
      per_page: number;
      last_page: number;
      total: number;
    }>(`/api/erp/facturas-compra${qs ? `?${qs}` : ''}`),
  });

  const [verId, setVerId] = useState<number | null>(null);
  const [controlOpen, setControlOpen] = useState<FacturaCompra | null>(null);
  // v1.54 — acciones de facturas sincronizadas desde DistriApp.
  const [autorizarSync, setAutorizarSync] = useState<FacturaCompra | null>(null);
  const [desautorizarSync, setDesautorizarSync] = useState<FacturaCompra | null>(null);
  const [borrarSync, setBorrarSync] = useState<FacturaCompra | null>(null);
  const [observarOpen, setObservarOpen] = useState<FacturaCompra | null>(null);
  const [rechazarOpen, setRechazarOpen] = useState<FacturaCompra | null>(null);
  // v1.22 §13 — bulk select.
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [borrarMasivoOpen, setBorrarMasivoOpen] = useState(false);
  // v1.37 — modal cambio de categoría.
  const [categoriaFC, setCategoriaFC] = useState<FacturaCompra | null>(null);

  // v1.22 §13 + v1.27 — chequeo de permisos.
  const { data: misPermisos } = useApi<Array<{ codigo: string; sensible: boolean }>>(
    ['mi-permisos'],
    '/api/erp/mi-permisos',
  );
  const puedeBorrarMasivo = !!misPermisos?.some((p) => p.codigo === 'compras.facturas.borrar_masivo');
  const puedeEditarPeriodo = !!misPermisos?.some((p) => p.codigo === 'compras.facturas.editar');
  // v1.37 — permisos categoría.
  const puedeEditarCategoria = !!misPermisos?.some((p) => p.codigo === 'facturas.editar_categoria');
  const puedeCrearEfectivo = !!misPermisos?.some((p) => p.codigo === 'facturas.crear_efectivo');

  // v1.27 — dropdown del filtro: períodos distinct cargados de la BD.
  const { data: periodosDistinct } = useApi<string[]>(
    ['facturas-compra-periodos-trabajados'],
    '/api/erp/facturas-compra/periodos-trabajados',
  );

  const [editarPeriodoOpen, setEditarPeriodoOpen] = useState(false);
  const usaSelector = (periodosDistinct?.length ?? 0) > 0;

  // Destomar — revierte una toma accidental (vuelve a no-tomadas + borra asiento).
  const toastDestomar = useToast();
  const invalidateDestomar = useInvalidate(['facturas-compra'], ['libro-iva-compras-no-tomadas']);
  const destomar = useApiMutation<{ destomadas: number }, { factura_ids: number[] }>(
    (vars) => api.post('/api/erp/libro-iva-compras/destomar', vars),
    {
      onSuccess: (r) => {
        toastDestomar.success(`${r.destomadas} factura(s) destomada(s)`,
          'Volvieron al listado de no-tomadas. El asiento del período fue eliminado.');
        setSelectedIds(new Set());
        invalidateDestomar();
      },
      onError: (e) => toastDestomar.error('No se pudo destomar', errorMessage(e)),
    },
  );

  const filas = data?.data ?? [];
  const todoSeleccionado = filas.length > 0 && filas.every((r) => selectedIds.has(r.id));
  const toggleFila = (id: number) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };
  const toggleTodos = () => {
    if (todoSeleccionado) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(filas.map((r) => r.id)));
    }
  };
  const seleccionadasObj = filas.filter((r) => selectedIds.has(r.id));

  const columns: Column<FacturaCompra>[] = [
    ...(puedeBorrarMasivo || puedeEditarPeriodo ? [{
      key: 'select' as const, width: '40px', align: 'center' as const,
      header: (
        <input
          type="checkbox"
          aria-label="Seleccionar todas"
          checked={todoSeleccionado}
          onChange={toggleTodos}
        />
      ),
      render: (r: FacturaCompra) => (
        <input
          type="checkbox"
          checked={selectedIds.has(r.id)}
          onChange={(e) => { e.stopPropagation(); toggleFila(r.id); }}
          onClick={(e) => e.stopPropagation()}
        />
      ),
    }] : []),
    { key: 'fecha_emision', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha_emision) },
    { key: 'imputado', header: 'Imputado', width: '110px',
      render: (r) => {
        const dif = !!r.imputacion_diferida;
        if (!dif) return <span className="text-ink-muted">—</span>;
        const ym = r.fecha_imputacion ? r.fecha_imputacion.slice(0, 7) : '—';
        return (
          <span className="px-1.5 py-0.5 text-[10.5px] rounded bg-amber-100 text-amber-800 font-medium">
            → {ym}
          </span>
        );
      } },
    { key: 'comprobante', header: 'Comprobante', width: '180px',
      render: (r) => (
        <div>
          <div className="font-medium">
            {r.letra} {r.tipo_codigo} — {String(r.punto_venta).padStart(5, '0')}-{String(r.numero).padStart(8, '0')}
            {r.categoria === 'EFECTIVO' && (
              puedeEditarCategoria ? (
                <button onClick={() => setCategoriaFC(r)}
                        title="Cambiar categoría"
                        className="ml-1.5 cursor-pointer">
                  <Badge variant="warning">EFECTIVO</Badge>
                </button>
              ) : (
                <Badge variant="warning" className="ml-1.5">EFECTIVO</Badge>
              )
            )}
            {r.categoria !== 'EFECTIVO' && puedeEditarCategoria && (
              <button onClick={() => setCategoriaFC(r)}
                      title="Marcar como EFECTIVO"
                      className="ml-1.5 text-[10px] text-azure opacity-50 hover:opacity-100 cursor-pointer">
                ⓘ
              </button>
            )}
          </div>
          {r.cae && <div className="text-[10.5px] text-ink-muted">CAE {r.cae}</div>}
        </div>
      ) },
    { key: 'proveedor', header: 'Proveedor',
      render: (r) => (
        <div>
          <div className="text-[12.5px]">{r.razon_social_emisor || r.proveedor_nombre || '—'}</div>
          <div className="text-[10.5px] text-ink-muted">CUIT {r.cuit_emisor || r.proveedor_cuit}</div>
        </div>
      ) },
    { key: 'imp_total', header: 'Total', align: 'right', width: '120px',
      render: (r) => `${monedaStr(r.moneda)} ${fmtMoney(r.imp_total)}` },
    { key: 'origen', header: 'Origen', width: '110px',
      render: (r) => {
        const o = (r as Record<string, unknown>)['origen'] as string;
        const v = (r as Record<string, unknown>)['verificada_arca'] as number | boolean | undefined;
        const variant = o === 'LIBRO_IVA_IMPORT' ? 'info'
          : o === 'MANUAL' ? 'warning'
          : o === 'DISTRIAPP' ? 'default'
          : 'neutral';
        return (
          <div className="flex items-center gap-1">
            <Badge variant={variant}>{o ?? '—'}</Badge>
            {Number(v) === 1 && <span title="Verificada contra ARCA" className="text-success">✓</span>}
            {r.distriapp_liquidacion_id != null && (
              <span className="text-[10px] text-ink-3" title={`DistriApp — Liquidación #${r.distriapp_liquidacion_id}`}>
                Liq #{r.distriapp_liquidacion_id}
              </span>
            )}
          </div>
        );
      } },
    { key: 'tomado', header: 'Tomado', width: '90px',
      render: (r) => Number(r.no_tomada) === 1
        ? <Badge variant="warning">NO</Badge>
        : <Badge variant="success">SÍ</Badge> },
    { key: 'periodo_trabajado_texto', header: 'P. trabaj.', width: '110px',
      render: (r) => (
        <PeriodoTrabajadoCell
          value={r.periodo_trabajado_texto}
          editable={puedeEditarPeriodo}
          endpointUrl={`/api/erp/facturas-compra/${r.id}/periodo-trabajado`}
          invalidateKeys={[['facturas-compra'], ['facturas-compra-periodos-trabajados']]}
        />
      ) },
    { key: 'jurisdiccion_codigo', header: 'Juris.', width: '70px',
      render: (r) => r.jurisdiccion_codigo
        ? <code className="text-[11px]">{r.jurisdiccion_codigo}</code>
        : <span className="text-ink-muted">—</span> },
    { key: 'tipo_gasto', header: 'Tipo gasto', width: '120px',
      render: (r) => r.tipo_gasto
        ? <span className="text-[11.5px]">{r.tipo_gasto}</span>
        : <span className="text-ink-muted">—</span> },
    // v1.40 — OP externa + fecha de pago (inline editable).
    { key: 'op_externa', header: 'OP', width: '110px',
      render: (r) => (
        <OpExternaCell
          value={r.op_externa}
          facturaId={r.id}
          editable={puedeEditarPeriodo}
          invalidateKeys={[['facturas-compra']]}
        />
      ) },
    { key: 'fecha_pago', header: 'Fecha pago', width: '130px',
      render: (r) => (
        <FechaPagoCell
          value={r.fecha_pago}
          facturaId={r.id}
          editable={puedeEditarPeriodo}
          invalidateKeys={[['facturas-compra']]}
        />
      ) },
    { key: 'estado', header: 'Estado', width: '140px',
      render: (r) => <Badge variant={badgeFor(r.estado)}>{r.estado}</Badge> },
    { key: 'constatacion', header: 'Constat.', width: '120px',
      render: (r) => <Badge variant={constatBadge(r.constatacion_estado)}>{r.constatacion_estado}</Badge> },
    { key: 'asiento', header: 'Asiento', width: '90px',
      render: (r) => r.asiento_numero ? `#${r.asiento_numero}` : '—' },
    { key: 'acciones', header: '', align: 'right', width: '230px',
      render: (r) => (
        <div className="flex justify-end gap-1.5">
          {/* v1.56 — PDF del comprobante original. */}
          <button
            title={r.adjunto_url ? 'Ver PDF del comprobante' : 'PDF no disponible'}
            disabled={!r.adjunto_url}
            onClick={(e) => { e.stopPropagation(); if (r.adjunto_url) window.open(r.adjunto_url, '_blank', 'noopener'); }}
            className={`p-1 ${r.adjunto_url ? 'opacity-70 hover:opacity-100 hover:text-azure' : 'opacity-25 cursor-not-allowed'}`}>
            <FileText className="w-3.5 h-3.5" />
          </button>
          {/* v1.54 — facturas sincronizadas desde DistriApp. */}
          {r.estado === 'PENDIENTE_AUTORIZACION_ERP' && (
            <>
              <Button size="sm" variant="primary" title="Autorizar y contabilizar (preview del asiento)."
                onClick={(e) => { e.stopPropagation(); setAutorizarSync(r); }}>
                <CheckCircle2 className="w-3 h-3" /> Autorizar
              </Button>
              <Button size="sm" variant="danger" title="Borrar la factura pendiente (avisa a DistriApp)."
                onClick={(e) => { e.stopPropagation(); setBorrarSync(r); }}>
                <Trash2 className="w-3 h-3" />
              </Button>
            </>
          )}
          {r.estado === 'CONTROLADA' && Number(r.sincronizada_desde_distriapp) === 1 && (
            <Button size="sm" variant="outline" title="Desautorizar: reversa del asiento (fecha de hoy) y vuelve a pendiente."
              onClick={(e) => { e.stopPropagation(); setDesautorizarSync(r); }}>
              <Undo2 className="w-3 h-3" /> Desautorizar
            </Button>
          )}
          {r.estado === 'RECIBIDA' && (
            <Button size="sm" variant="primary" onClick={(e) => { e.stopPropagation(); setControlOpen(r); }}>
              <CheckCircle2 className="w-3 h-3" /> Controlar
            </Button>
          )}
          {(r.estado === 'RECIBIDA' || r.estado === 'OBSERVADA') && (
            <Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); setObservarOpen(r); }}>
              <AlertTriangle className="w-3 h-3" />
            </Button>
          )}
          {r.estado === 'RECIBIDA' && (
            <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setRechazarOpen(r); }}>
              <XCircle className="w-3 h-3" />
            </Button>
          )}
        </div>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><ShoppingCart className="w-4 h-4 text-azure" /> Facturas de compra</div>}
          actions={
            <div className="flex gap-2">
              {/* v1.49 — export Excel del listado filtrado. */}
              <Button variant="outline" size="sm" onClick={exportarExcel} disabled={exportando}>
                <FileDown className="w-3 h-3" /> {exportando ? 'Exportando…' : 'Exportar Excel'}
              </Button>
              <Link to="/erp/facturas-compra/nueva">
                <Button variant="primary" size="sm">
                  <Plus className="w-3 h-3" /> Cargar manual
                </Button>
              </Link>
            </div>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            {/* v1.56 — filtro por proveedor / CUIT con sugerencias. */}
            <Field label="Proveedor / CUIT" value={proveedorQInput}
              onChange={(e) => setProveedorQInput(e.target.value)}
              placeholder="SALIM o 20398651554…"
              list="fc-prov-sugerencias"
              containerClassName="w-[220px]" />
            <datalist id="fc-prov-sugerencias">
              {(provSugerencias ?? []).map((s, i) => (
                <option key={i} value={s.nombre}>{s.cuit ?? ''}</option>
              ))}
            </datalist>
            <SelectField label="Estado" value={estado} placeholder="Todos"
              onChange={(e) => setEstado(e.target.value)}
              containerClassName="w-[170px]"
              options={ESTADOS.map((s) => ({ value: s, label: s }))} />
            <SelectField label="Origen" value={origen} placeholder="Todos"
              onChange={(e) => setOrigen(e.target.value)}
              containerClassName="w-[180px]"
              options={ORIGENES.map((s) => ({ value: s, label: s }))} />
            <Field label="Emis. desde" type="date" value={desde}
              onChange={(e) => setDesde(e.target.value)}
              containerClassName="w-[145px]" />
            <Field label="Emis. hasta" type="date" value={hasta}
              onChange={(e) => setHasta(e.target.value)}
              containerClassName="w-[145px]" />
            {/* v1.49 — filtros por fecha de imputación (clave para reportes mensuales). */}
            <Field label="Imp. desde" type="date" value={impDesde}
              onChange={(e) => setImpDesde(e.target.value)}
              containerClassName="w-[145px]" />
            <Field label="Imp. hasta" type="date" value={impHasta}
              onChange={(e) => setImpHasta(e.target.value)}
              containerClassName="w-[145px]" />
            {/* Addendum v1.13 + v1.14 */}
            <SelectField label="Tomado" value={noTomada} placeholder="Todas"
              onChange={(e) => setNoTomada(e.target.value as '' | '0' | '1')}
              containerClassName="w-[140px]"
              options={[
                { value: '0', label: 'SI (tomadas)' },
                { value: '1', label: 'NO (no_tomadas)' },
              ]} />
            <Field label="Tipo gasto" value={tipoGasto}
              onChange={(e) => setTipoGasto(e.target.value)}
              placeholder="Combustible…"
              containerClassName="w-[170px]" />
            {/* v1.27 — dropdown si hay períodos cargados; input texto libre como fallback. */}
            {usaSelector ? (
              <SelectField label="Período trabajado" value={periodoTrab} placeholder="Todos"
                onChange={(e) => setPeriodoTrab(e.target.value)}
                containerClassName="w-[180px]"
                options={[
                  { value: '__VACIOS__', label: '— Vacíos —' },
                  ...(periodosDistinct ?? []).map((p) => ({ value: p, label: p })),
                ]} />
            ) : (
              <Field label="Período trabajado" value={periodoTrab}
                onChange={(e) => setPeriodoTrab(e.target.value)}
                placeholder="2026-03"
                containerClassName="w-[160px]" />
            )}
            <Field label="Jurisdicción" value={juris}
              onChange={(e) => setJuris(e.target.value)}
              placeholder="901"
              containerClassName="w-[120px]" />
          </div>
          {error && <FormError error={errorMessage(error)} />}

          {/* v1.22 §13 + v1.27 — barra de acción cuando hay selección. */}
          {(puedeBorrarMasivo || puedeEditarPeriodo) && selectedIds.size > 0 && (
            <div className="flex items-center justify-between border border-warning/40 bg-warning-bg/30 rounded-md px-3 py-2 text-[12px]">
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
                {puedeEditarPeriodo && (
                  <Button size="sm" variant="outline"
                    disabled={destomar.isPending}
                    title="Revierte una toma accidental: borra el asiento y la factura vuelve al listado de no-tomadas (su mes original)."
                    onClick={() => {
                      if (confirm(`¿Destomar ${selectedIds.size} factura(s)? Se borra el asiento del período donde fueron imputadas y vuelven al listado de no-tomadas. Solo aplica a importadas del Libro IVA.`)) {
                        destomar.mutate({ factura_ids: [...selectedIds] });
                      }
                    }}>
                    <Undo2 className="w-3 h-3" /> {destomar.isPending ? 'Destomando…' : `Destomar ${selectedIds.size}`}
                  </Button>
                )}
                {puedeBorrarMasivo && (
                  <Button size="sm" variant="danger" onClick={() => setBorrarMasivoOpen(true)}>
                    <Trash2 className="w-3 h-3" /> Borrar {selectedIds.size}
                  </Button>
                )}
              </div>
            </div>
          )}

          <DataTable columns={columns}
            paginator={data ? {
              data: filas,
              current_page: data.current_page,
              per_page: data.per_page,
              last_page: data.last_page,
              total: data.total,
            } : undefined}
            onPageChange={(p) => setPage(p)}
            loading={isLoading}
            onRowClick={(r) => setVerId(r.id)} empty="Sin facturas en el filtro" />
        </CardBody>
      </Card>

      {verId && <DetalleModal id={verId} onClose={() => setVerId(null)} />}
      {controlOpen && <ControlarConfirm factura={controlOpen} onClose={() => setControlOpen(null)} />}
      {autorizarSync && <AutorizarSyncModal factura={autorizarSync} onClose={() => setAutorizarSync(null)} />}
      {desautorizarSync && <DesautorizarSyncModal factura={desautorizarSync} onClose={() => setDesautorizarSync(null)} />}
      {borrarSync && <BorrarSyncModal factura={borrarSync} onClose={() => setBorrarSync(null)} />}
      {observarOpen && <MotivoModal factura={observarOpen} action="observar" onClose={() => setObservarOpen(null)} />}
      {rechazarOpen && <MotivoModal factura={rechazarOpen} action="rechazar" onClose={() => setRechazarOpen(null)} />}
      <CategoriaModal
        factura={categoriaFC}
        tipo="compra"
        puedeCrearEfectivo={puedeCrearEfectivo}
        onClose={() => setCategoriaFC(null)}
        onSuccess={() => {
          setCategoriaFC(null);
          // Invalida listado + reporte consolidado.
          // Si hay un useInvalidate global lo invalidamos; si no, react-query
          // refetcha al volver a montar.
        }}
      />
      {borrarMasivoOpen && (
        <BorrarMasivoModal
          facturas={seleccionadasObj}
          onClose={() => setBorrarMasivoOpen(false)}
          onDone={() => { setSelectedIds(new Set()); setBorrarMasivoOpen(false); }}
        />
      )}
      {editarPeriodoOpen && (
        <EditarPeriodoBulkModal
          facturas={seleccionadasObj}
          endpointUrl="/api/erp/facturas-compra/periodos-trabajados"
          invalidateKeys={[['facturas-compra'], ['facturas-compra-periodos-trabajados']]}
          onClose={() => setEditarPeriodoOpen(false)}
          onDone={() => { setSelectedIds(new Set()); setEditarPeriodoOpen(false); }}
        />
      )}
    </div>
  );
}


function BorrarMasivoModal({
  facturas, onClose, onDone,
}: {
  facturas: FacturaCompra[];
  onClose: () => void;
  onDone: () => void;
}) {
  const [motivo, setMotivo] = useState('');
  const toast = useToast();
  const invalidate = useInvalidate(['facturas-compra']);
  const importsInvalidate = useInvalidate(['libro-iva-compras-imports']);

  const importeTotal = facturas.reduce((sum, f) => sum + Number(f.imp_total || 0), 0);
  const conAsiento = facturas.filter((f) => f.asiento_id).length;

  const m = useApiMutation<unknown, { ids: number[]; motivo?: string }>(
    (body) => api.post('/api/erp/facturas-compra/borrar-masivo', body),
    {
      onSuccess: () => {
        toast.success('Facturas borradas',
          `${facturas.length} factura${facturas.length === 1 ? '' : 's'} eliminada${facturas.length === 1 ? '' : 's'}`);
        invalidate();
        importsInvalidate();
        onDone();
      },
      onError: (e) => {
        if (e instanceof ApiError && e.status === 422) {
          const payload = e.payload as { error?: { code?: string; message?: string } };
          const code = payload.error?.code;
          if (code === 'PERIODO_CERRADO_EN_SELECCION') {
            toast.error('Período cerrado',
              'Algunas facturas seleccionadas están en períodos cerrados. Desmarcalas o reabrí el período.');
            return;
          }
          if (code === 'FACTURA_CONCILIADA') {
            toast.error('Factura conciliada',
              'Algunas facturas tienen pagos asociados. Desconciliá primero desde Tesorería.');
            return;
          }
        }
        toast.error('Error al borrar', errorMessage(e));
      },
    },
  );

  return (
    <Modal open onClose={onClose} title="Borrar facturas de compra masivamente" size="lg">
      <div className="space-y-3 text-[12px]">
        <div className="text-ink-muted">
          Vas a borrar <strong>{facturas.length}</strong> factura{facturas.length === 1 ? '' : 's'}.
        </div>

        <dl className="grid grid-cols-[140px_1fr] gap-y-1 gap-x-2 text-[11.5px] bg-azure-soft/30 rounded p-2">
          <dt className="text-ink-muted">Importe total</dt>
          <dd className="font-semibold tabular-nums">{fmtMoney(importeTotal)}</dd>
          <dt className="text-ink-muted">Con asiento</dt>
          <dd>{conAsiento}</dd>
        </dl>

        <details className="text-[11px]">
          <summary className="cursor-pointer text-ink-muted">
            Ver primeras {Math.min(20, facturas.length)} facturas
          </summary>
          <ul className="mt-2 space-y-0.5 max-h-[180px] overflow-y-auto pl-3">
            {facturas.slice(0, 20).map((f) => (
              <li key={f.id} className="font-mono text-[10.5px]">
                {f.letra} {f.tipo_codigo} {String(f.punto_venta).padStart(5, '0')}-
                {String(f.numero).padStart(8, '0')} · {f.razon_social_emisor || f.proveedor_nombre || f.cuit_emisor} · {fmtMoney(Number(f.imp_total))}
              </li>
            ))}
            {facturas.length > 20 && (
              <li className="text-ink-muted italic">… y {facturas.length - 20} más</li>
            )}
          </ul>
        </details>

        <div className="bg-red-50 border border-red-200 rounded p-2 text-[11px]">
          <div className="flex items-start gap-1.5">
            <AlertTriangle className="w-3 h-3 text-red-700 mt-[2px] flex-shrink-0" />
            <div>
              <strong>Acción irreversible.</strong> Los asientos contabilizados se borran físicamente
              (no se generan reversas). Solo aplica en período <strong>ABIERTO</strong>. Si las facturas
              vinieron de un import del Libro IVA y el import queda sin facturas, también se borra
              (libera el hash).
            </div>
          </div>
        </div>

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">
            Motivo del borrado (opcional)
          </label>
          <textarea
            rows={2}
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
            maxLength={500}
            placeholder="Ej: Limpieza de tests del import"
            className="w-full text-[12px] border border-azure-soft rounded px-2 py-1 focus:outline-none focus:border-azure"
          />
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <Button variant="outline" onClick={onClose} disabled={m.isPending}>Cancelar</Button>
          <Button
            variant="danger"
            onClick={() => m.mutate({
              ids: facturas.map((f) => f.id),
              ...(motivo.trim() ? { motivo: motivo.trim() } : {}),
            })}
            disabled={m.isPending}
          >
            <Trash2 className="w-3 h-3" /> Borrar {facturas.length} factura{facturas.length === 1 ? '' : 's'}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

function ControlarConfirm({ factura, onClose }: { factura: FacturaCompra; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['facturas-compra']);
  const m = useApiMutation<FacturaCompra>(
    () => api.post(`/api/erp/facturas-compra/${factura.id}/controlar`),
    {
      onSuccess: () => {
        toast.success('Factura controlada', `${factura.letra} ${factura.tipo_codigo} ${factura.numero}`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo controlar', errorMessage(e)),
    }
  );
  return (
    <ConfirmDialog
      open onClose={onClose}
      onConfirm={() => m.mutate(undefined as unknown as void)}
      title="Controlar factura"
      message={
        <>
          ¿Marcar como CONTROLADA la factura <strong>{factura.letra} {factura.tipo_codigo}</strong> de{' '}
          <strong>{factura.razon_social_emisor}</strong> por{' '}
          <strong>{monedaStr(factura.moneda)} {fmtMoney(factura.imp_total)}</strong>?
          <br /><br />
          Genera el asiento contable y habilita el pago (RN-31).
        </>
      }
      loading={m.isPending}
    />
  );
}

function MotivoModal({
  factura, action, onClose,
}: {
  factura: FacturaCompra;
  action: 'observar' | 'rechazar';
  onClose: () => void;
}) {
  const toast = useToast();
  const invalidate = useInvalidate(['facturas-compra']);
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<FacturaCompra, { motivo: string }>(
    (vars) => api.post(`/api/erp/facturas-compra/${factura.id}/${action}`, vars),
    {
      onSuccess: () => {
        toast.success(`Factura ${action === 'observar' ? 'observada' : 'rechazada'}`,
          `${factura.letra} ${factura.tipo_codigo} ${factura.numero}`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo procesar', errorMessage(e)),
    }
  );
  return (
    <Modal open onClose={onClose}
      title={`${action === 'observar' ? 'Observar' : 'Rechazar'} factura`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant={action === 'rechazar' ? 'danger' : 'primary'}
            disabled={motivo.trim().length < 3 || m.isPending}
            onClick={() => m.mutate({ motivo: motivo.trim() })}>
            {m.isPending ? 'Procesando…' : 'Confirmar'}
          </Button>
        </>
      }
    >
      <Field label="Motivo" required value={motivo}
        onChange={(e) => setMotivo(e.target.value)}
        placeholder="Mín. 3 caracteres" />
      <FormError error={m.error ? errorMessage(m.error) : null} />
    </Modal>
  );
}

function DetalleModal({ id, onClose }: { id: number; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['facturas-compra', id],
    queryFn: () => api.get<{ ok: boolean; data: Record<string, unknown> }>(`/api/erp/facturas-compra/${id}`),
  });
  const f = data?.data as FacturaCompra & {
    items?: Array<{ descripcion?: string; concepto?: string; cantidad?: number; importe_total?: number; precio_unitario?: number }>;
    iva?: Array<{ alicuota?: { tasa?: number }; base_imponible?: number; importe_iva?: number }>;
    asiento?: { id: number; numero: number; fecha: string };
    constatacion?: Record<string, unknown>;
    estado?: string;
    // v1.36 — el endpoint /show usa Eloquent ->with('tipoComprobante') y
    // devuelve la relación anidada; el listado /index aplana via JOIN.
    tipoComprobante?: { codigo_interno?: string; letra?: string | null; nombre?: string };
  } | undefined;

  // v1.36 — derivar tipo/letra desde el shape correcto (listado o relación).
  const tipoCodigo = f?.tipo_codigo ?? f?.tipoComprobante?.codigo_interno ?? '';
  const letra = f?.letra ?? f?.tipoComprobante?.letra ?? '';

  return (
    <Modal open onClose={onClose} title={`Factura compra #${id}`} size="lg">
      {isLoading ? (
        <div className="text-center py-8 text-ink-muted">Cargando…</div>
      ) : !f ? null : (
        <div className="space-y-4 text-[12.5px]">
          <div className="grid grid-cols-3 gap-3">
            <Info label="Comprobante" value={`${letra} ${tipoCodigo} ${String(f.punto_venta).padStart(5, '0')}-${String(f.numero).padStart(8, '0')}`.trim()} />
            <Info label="Fecha emisión" value={fmtDate(f.fecha_emision)} />
            <Info
              label="Fecha imputación"
              value={
                <span className="flex items-center gap-2">
                  {fmtDate(f.fecha_imputacion ?? f.fecha_emision)}
                  {!!f.imputacion_diferida && (
                    <span className="px-1.5 py-0.5 text-[10px] rounded bg-amber-100 text-amber-800 font-medium">
                      Diferida
                    </span>
                  )}
                </span>
              }
            />
            <Info label="CAE" value={f.cae ?? '—'} />
            <Info label="Comprobante original" value={
              f.adjunto_url ? (
                <a href={f.adjunto_url} target="_blank" rel="noopener noreferrer"
                  className="inline-flex items-center gap-1 text-azure hover:underline">
                  <FileText className="w-3.5 h-3.5" /> Ver PDF
                </a>
              ) : '—'
            } />
            <Info label="Proveedor" value={f.razon_social_emisor} />
            <Info label="CUIT" value={f.cuit_emisor} />
            <Info label="Estado" value={<Badge variant={badgeFor(f.estado as string)}>{f.estado as string}</Badge>} />
            <Info label="Neto gravado" value={`${monedaStr(f.moneda)} ${fmtMoney(f.imp_neto_gravado)}`} />
            <Info label="IVA" value={`${monedaStr(f.moneda)} ${fmtMoney(f.imp_iva)}`} />
            <Info label="Total" value={<strong className="text-navy-800">{monedaStr(f.moneda)} {fmtMoney(f.imp_total)}</strong>} />
            {/* Addendum v1.14 — período trabajado, jurisdicción y CC */}
            {(f as Record<string, unknown>)['periodo_trabajado_texto'] != null && (
              <Info label="Período trabajado" value={String((f as Record<string, unknown>)['periodo_trabajado_texto'])} />
            )}
            {(f as Record<string, unknown>)['jurisdiccion_codigo'] != null && (
              <Info label="Jurisdicción IIBB" value={String((f as Record<string, unknown>)['jurisdiccion_codigo'])} />
            )}
            {(f as Record<string, unknown>)['centro_costo_id'] != null && (
              <Info label="Centro de Costos #" value={String((f as Record<string, unknown>)['centro_costo_id'])} />
            )}
            {(f as Record<string, unknown>)['tipo_gasto'] != null && (
              <Info label="Tipo de gasto" value={String((f as Record<string, unknown>)['tipo_gasto'])} />
            )}
          </div>
          {f.items && f.items.length > 0 && (
            <div>
              <h3 className="text-[12px] font-semibold text-navy-800 uppercase tracking-wide mb-2">Items</h3>
              <table className="w-full text-[12px]">
                <thead className="text-[11px] text-ink-muted">
                  <tr>
                    <th className="text-left py-1">Descripción</th>
                    <th className="text-right py-1">Cant</th>
                    <th className="text-right py-1">Precio</th>
                    <th className="text-right py-1">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {f.items.map((it, idx) => (
                    <tr key={idx} className="border-t border-line/60">
                      <td className="py-1.5">{it.descripcion ?? it.concepto}</td>
                      <td className="py-1.5 text-right">{it.cantidad}</td>
                      <td className="py-1.5 text-right">{fmtMoney(it.precio_unitario)}</td>
                      <td className="py-1.5 text-right">{fmtMoney(it.importe_total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}

function Info({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="text-[11px] text-ink-muted uppercase tracking-wide">{label}</div>
      <div className="text-[12.5px] text-ink-2">{value ?? '—'}</div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// v1.54 — Modales de facturas sincronizadas desde DistriApp.
// ---------------------------------------------------------------------------

type PreviewAutorizacion = {
  factura: FacturaCompra & { observaciones?: string | null };
  cliente_liquidacion: string | null;
  lineas: Array<{ lado: 'D' | 'H'; cuenta: string; importe: number }>;
  fecha_asiento: string;
};

function AutorizarSyncModal({ factura, onClose }: { factura: FacturaCompra; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['facturas-compra']);
  const { data: preview, error: previewError } = useApi<PreviewAutorizacion>(
    ['fc-preview-autorizacion', String(factura.id)],
    `/api/erp/facturas-compra/${factura.id}/preview-autorizacion`,
  );
  const m = useApiMutation<FacturaCompra, void>(
    () => api.post(`/api/erp/facturas-compra/${factura.id}/autorizar`),
    {
      onSuccess: () => { toast.success('Factura autorizada', 'Asiento contable generado.'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo autorizar', errorMessage(e)),
    },
  );
  return (
    <Modal open onClose={onClose} title="Autorizar factura de compra" size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!preview || m.isPending} onClick={() => m.mutate()}>
            {m.isPending ? 'Autorizando…' : 'Autorizar y contabilizar'}
          </Button>
        </>
      }>
      <div className="space-y-3 text-[12.5px]">
        <div className="bg-surface-row border border-line rounded-md p-3 grid grid-cols-2 gap-2 text-[12px]">
          <div><span className="text-ink-3">Comprobante:</span> {factura.letra} {factura.tipo_codigo} {String(factura.punto_venta).padStart(5, '0')}-{String(factura.numero).padStart(8, '0')}</div>
          <div><span className="text-ink-3">Fecha:</span> {fmtDate(factura.fecha_emision)}</div>
          <div className="col-span-2"><span className="text-ink-3">Emisor:</span> {factura.razon_social_emisor} (CUIT {factura.cuit_emisor})</div>
          <div><span className="text-ink-3">Total:</span> <strong className="tabular-nums">{fmtMoney(factura.imp_total)}</strong></div>
          <div><span className="text-ink-3">Origen:</span> DistriApp{factura.distriapp_liquidacion_id ? ` — Liquidación #${factura.distriapp_liquidacion_id}` : ''}{preview?.cliente_liquidacion ? ` (${preview.cliente_liquidacion})` : ''}</div>
          {/* v1.56 — revisar el comprobante real antes de autorizar. */}
          <div className="col-span-2">
            {factura.adjunto_url ? (
              <a href={factura.adjunto_url} target="_blank" rel="noopener noreferrer"
                className="inline-flex items-center gap-1 text-azure hover:underline">
                <FileText className="w-3.5 h-3.5" /> Ver comprobante original (PDF)
              </a>
            ) : (
              <span className="text-ink-muted inline-flex items-center gap-1">
                <FileText className="w-3.5 h-3.5" /> PDF no disponible
              </span>
            )}
          </div>
        </div>
        {previewError && <FormError error={errorMessage(previewError)} />}
        {preview && (
          <div className="border border-line rounded-md p-3">
            <div className="text-[11px] font-semibold text-ink-2 mb-1">Asiento contable a generar · {fmtDate(preview.fecha_asiento)}</div>
            <div className="font-mono text-[11.5px] space-y-0.5">
              {preview.lineas.map((l, i) => (
                <div key={i} className="flex justify-between gap-3">
                  <span>{l.lado}&nbsp;&nbsp;{l.cuenta}</span>
                  <span className="tabular-nums">{fmtMoney(l.importe)}</span>
                </div>
              ))}
            </div>
            <div className="text-[10.5px] text-ink-3 mt-1">Centro de costo: GENERAL · el detalle multi-alícuota/percepciones lo arma el generador (v1.23/v1.24).</div>
          </div>
        )}
      </div>
    </Modal>
  );
}

function DesautorizarSyncModal({ factura, onClose }: { factura: FacturaCompra; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['facturas-compra']);
  const [motivo, setMotivo] = useState('');
  const m = useApiMutation<FacturaCompra, void>(
    () => api.post(`/api/erp/facturas-compra/${factura.id}/desautorizar`, { motivo: motivo.trim() }),
    {
      onSuccess: () => { toast.success('Factura desautorizada', 'Asiento revertido — la factura volvió a pendiente.'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo desautorizar', errorMessage(e)),
    },
  );
  return (
    <Modal open onClose={onClose} title="Desautorizar factura" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={motivo.trim().length < 10 || m.isPending} onClick={() => m.mutate()}>
            {m.isPending ? 'Desautorizando…' : 'Desautorizar'}
          </Button>
        </>
      }>
      <div className="space-y-3 text-[12.5px]">
        <div className="border border-warning/40 bg-warning-bg/30 rounded p-2 text-[11.5px]">
          ⚠️ Esta acción anula el asiento contable (reversa D/H espejo <strong>con fecha de hoy</strong>),
          la factura vuelve a PENDIENTE_AUTORIZACION_ERP y después vas a poder borrarla desde el ERP o desde DistriApp.
        </div>
        <div className="bg-surface-row border border-line rounded-md p-3 text-[12px]">
          <div>Factura: <strong>{factura.letra} {factura.tipo_codigo} {String(factura.punto_venta).padStart(5, '0')}-{String(factura.numero).padStart(8, '0')}</strong> · {factura.razon_social_emisor}</div>
          <div>Total: <strong className="tabular-nums">{fmtMoney(factura.imp_total)}</strong> · Asiento original: #{factura.asiento_numero ?? '—'}</div>
        </div>
        <div>
          <label className="text-[11.5px] font-semibold text-ink-2 mb-1 block">Motivo (obligatorio, mínimo 10 caracteres) *</label>
          <textarea rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)}
            className="w-full border border-line rounded-md px-3 py-2 text-[12.5px] focus:outline-none focus:border-azure" />
        </div>
      </div>
    </Modal>
  );
}

function BorrarSyncModal({ factura, onClose }: { factura: FacturaCompra; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['facturas-compra']);
  const m = useApiMutation<{ ok: boolean }, void>(
    () => api.delete(`/api/erp/facturas-compra/${factura.id}/sync`),
    {
      onSuccess: () => { toast.success('Factura borrada', 'Se notificó a DistriApp para desvincular.'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo borrar', errorMessage(e)),
    },
  );
  return (
    <ConfirmDialog
      open onClose={onClose}
      onConfirm={() => m.mutate(undefined as unknown as void)}
      title="Borrar factura sincronizada"
      message={
        <>
          ¿Borrar la factura <strong>{factura.letra} {factura.tipo_codigo} {String(factura.numero)}</strong> de{' '}
          <strong>{factura.razon_social_emisor}</strong> por <strong>{fmtMoney(factura.imp_total)}</strong>?
          <br /><br />
          Solo se borran facturas pendientes de autorizar. Si vino de DistriApp, se le avisa para que desvincule.
        </>
      }
      confirmLabel="Borrar"
      variant="danger"
    />
  );
}
