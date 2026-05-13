import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { ShoppingCart, CheckCircle2, AlertTriangle, XCircle, Plus } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
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
  moneda: string;
  asiento_id: number | null;
  asiento_numero: number | null;
  // Addendum v1.13 + v1.14
  no_tomada: number | boolean;
  cliente_auxiliar_id: number | null;
  tipo_gasto: string | null;
  periodo_trabajado_texto: string | null;
  jurisdiccion_codigo: string | null;
  centro_costo_id: number | null;
};

const ESTADOS = ['RECIBIDA', 'CONTROLADA', 'OBSERVADA', 'PAGO_PARCIAL', 'PAGADA', 'ANULADA_POR_NC', 'RECHAZADA'];
const ORIGENES = ['MANUAL', 'LIBRO_IVA_IMPORT', 'MIS_COMPROBANTES', 'DISTRIAPP'];

function badgeFor(estado: string) {
  switch (estado) {
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
  const setEstado = (v: string) => setQueryParam('estado', v);
  const setOrigen = (v: string) => setQueryParam('origen', v);
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');
  // Addendum v1.13 + v1.14 — filtros enriquecidos
  const [noTomada, setNoTomada] = useState<'' | '0' | '1'>('');
  const [tipoGasto, setTipoGasto] = useState('');
  const [periodoTrab, setPeriodoTrab] = useState('');
  const [juris, setJuris] = useState('');

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (estado) p.set('estado', estado);
    if (origen) p.set('origen', origen);
    if (desde)  p.set('desde', desde);
    if (hasta)  p.set('hasta', hasta);
    if (noTomada !== '') p.set('no_tomada', noTomada);
    if (tipoGasto) p.set('tipo_gasto', tipoGasto);
    if (periodoTrab) p.set('periodo_trabajado', periodoTrab);
    if (juris) p.set('jurisdiccion', juris);
    return p.toString();
  }, [estado, origen, desde, hasta, noTomada, tipoGasto, periodoTrab, juris]);

  const { data, isLoading, error } = useQuery({
    queryKey: ['facturas-compra', qs],
    queryFn: () => api.get<{ data: FacturaCompra[] }>(`/api/erp/facturas-compra${qs ? `?${qs}` : ''}`),
  });

  const [verId, setVerId] = useState<number | null>(null);
  const [controlOpen, setControlOpen] = useState<FacturaCompra | null>(null);
  const [observarOpen, setObservarOpen] = useState<FacturaCompra | null>(null);
  const [rechazarOpen, setRechazarOpen] = useState<FacturaCompra | null>(null);

  const columns: Column<FacturaCompra>[] = [
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
          <div className="font-medium">{r.letra} {r.tipo_codigo} — {String(r.punto_venta).padStart(5, '0')}-{String(r.numero).padStart(8, '0')}</div>
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
      render: (r) => `${r.moneda} ${fmtMoney(r.imp_total)}` },
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
          </div>
        );
      } },
    { key: 'tomado', header: 'Tomado', width: '90px',
      render: (r) => Number(r.no_tomada) === 1
        ? <Badge variant="warning">NO</Badge>
        : <Badge variant="success">SÍ</Badge> },
    { key: 'periodo_trabajado_texto', header: 'P. trabaj.', width: '110px',
      render: (r) => r.periodo_trabajado_texto
        ? <code className="text-[11px]">{r.periodo_trabajado_texto}</code>
        : <span className="text-ink-muted">—</span> },
    { key: 'jurisdiccion_codigo', header: 'Juris.', width: '70px',
      render: (r) => r.jurisdiccion_codigo
        ? <code className="text-[11px]">{r.jurisdiccion_codigo}</code>
        : <span className="text-ink-muted">—</span> },
    { key: 'tipo_gasto', header: 'Tipo gasto', width: '120px',
      render: (r) => r.tipo_gasto
        ? <span className="text-[11.5px]">{r.tipo_gasto}</span>
        : <span className="text-ink-muted">—</span> },
    { key: 'estado', header: 'Estado', width: '140px',
      render: (r) => <Badge variant={badgeFor(r.estado)}>{r.estado}</Badge> },
    { key: 'constatacion', header: 'Constat.', width: '120px',
      render: (r) => <Badge variant={constatBadge(r.constatacion_estado)}>{r.constatacion_estado}</Badge> },
    { key: 'asiento', header: 'Asiento', width: '90px',
      render: (r) => r.asiento_numero ? `#${r.asiento_numero}` : '—' },
    { key: 'acciones', header: '', align: 'right', width: '230px',
      render: (r) => (
        <div className="flex justify-end gap-1.5">
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
            <Link to="/erp/facturas-compra/nueva">
              <Button variant="primary" size="sm">
                <Plus className="w-3 h-3" /> Cargar manual
              </Button>
            </Link>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3">
            <SelectField label="Estado" value={estado} placeholder="Todos"
              onChange={(e) => setEstado(e.target.value)}
              containerClassName="w-[170px]"
              options={ESTADOS.map((s) => ({ value: s, label: s }))} />
            <SelectField label="Origen" value={origen} placeholder="Todos"
              onChange={(e) => setOrigen(e.target.value)}
              containerClassName="w-[180px]"
              options={ORIGENES.map((s) => ({ value: s, label: s }))} />
            <Field label="Desde" type="date" value={desde}
              onChange={(e) => setDesde(e.target.value)}
              containerClassName="w-[150px]" />
            <Field label="Hasta" type="date" value={hasta}
              onChange={(e) => setHasta(e.target.value)}
              containerClassName="w-[150px]" />
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
            <Field label="Período trabajado" value={periodoTrab}
              onChange={(e) => setPeriodoTrab(e.target.value)}
              placeholder="2026-03"
              containerClassName="w-[160px]" />
            <Field label="Jurisdicción" value={juris}
              onChange={(e) => setJuris(e.target.value)}
              placeholder="901"
              containerClassName="w-[120px]" />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={columns} rows={data?.data ?? []} loading={isLoading}
            onRowClick={(r) => setVerId(r.id)} empty="Sin facturas en el filtro" />
        </CardBody>
      </Card>

      {verId && <DetalleModal id={verId} onClose={() => setVerId(null)} />}
      {controlOpen && <ControlarConfirm factura={controlOpen} onClose={() => setControlOpen(null)} />}
      {observarOpen && <MotivoModal factura={observarOpen} action="observar" onClose={() => setObservarOpen(null)} />}
      {rechazarOpen && <MotivoModal factura={rechazarOpen} action="rechazar" onClose={() => setRechazarOpen(null)} />}
    </div>
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
          <strong>{factura.moneda} {fmtMoney(factura.imp_total)}</strong>?
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
  } | undefined;

  return (
    <Modal open onClose={onClose} title={`Factura compra #${id}`} size="lg">
      {isLoading ? (
        <div className="text-center py-8 text-ink-muted">Cargando…</div>
      ) : !f ? null : (
        <div className="space-y-4 text-[12.5px]">
          <div className="grid grid-cols-3 gap-3">
            <Info label="Comprobante" value={`${f.letra} ${f.tipo_codigo} ${String(f.punto_venta).padStart(5, '0')}-${String(f.numero).padStart(8, '0')}`} />
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
            <Info label="Proveedor" value={f.razon_social_emisor} />
            <Info label="CUIT" value={f.cuit_emisor} />
            <Info label="Estado" value={<Badge variant={badgeFor(f.estado as string)}>{f.estado as string}</Badge>} />
            <Info label="Neto gravado" value={`${f.moneda} ${fmtMoney(f.imp_neto_gravado)}`} />
            <Info label="IVA" value={`${f.moneda} ${fmtMoney(f.imp_iva)}`} />
            <Info label="Total" value={<strong className="text-navy-800">{f.moneda} {fmtMoney(f.imp_total)}</strong>} />
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
