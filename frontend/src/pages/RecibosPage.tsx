import { useEffect, useMemo, useState } from 'react';
import { Receipt, Plus, Search, Printer, RotateCcw, Trash2, Ban, AlertTriangle } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField } from '@/components/ui/Field';
import { fmtMoney } from '@/lib/cn';
import { api, ApiError } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * v1.32 — Recibos al modelo de DistriApp.
 * Layout 2 columnas: lista lateral + form/preview en vivo.
 * Soporta múltiples comprobantes imputados + retenciones simples (IVA/IIBB/Gan).
 * Numeración PV-NRO sincronizada cross-platform con DistriApp.
 */

type Cliente = {
  id: number; codigo: string; nombre: string; cuit: string | null;
  direccion_1: string; direccion_2: string;
  facturas_pendientes?: number; // v1.34 hint
};
type FacturaImputable = {
  id: number; tipo: string; numero_completo: string;
  fecha_emision: string; imp_total: number; saldo: number; estado: string; origen: string;
};
type Recibo = {
  id: number; punto_venta: string | null; numero: string | null;
  numero_correlativo: string; numero_legacy: string | null;
  fecha_emision: string; cliente_auxiliar_id: number;
  total_factura: number; total_nc_aplicadas: number; total_retenciones: number;
  retencion_iva_total: number; retencion_iibb_total: number; retencion_ganancias_total: number;
  monto_cobrado: number; estado: 'BORRADOR' | 'EMITIDO' | 'CONCILIADO' | 'ANULADO';
  cliente?: Cliente;
  detalle_cobro: string | null;
};
type ReciboDetalle = Recibo & {
  comprobantes_imputados?: Array<{
    id: number; factura_venta_id: number; monto_imputado: number;
    total_factura: number; fecha_factura: string; numero_factura_snapshot: string;
  }>;
  nc_aplicadas?: Array<{
    id: number; nc_factura_id: number; monto_aplicado: number; automatica: boolean;
    nc?: { id: number; numero: number; imp_total: number } | null;
  }>;
  snapshot_empresa_razon_social?: string | null;
  snapshot_empresa_cuit?: string | null;
  snapshot_empresa_direccion_1?: string | null;
  snapshot_empresa_direccion_2?: string | null;
  snapshot_empresa_condicion_iva?: string | null;
  snapshot_empresa_inicio_actividad?: string | null;
  snapshot_cliente_razon_social?: string | null;
  snapshot_cliente_cuit?: string | null;
  snapshot_cliente_direccion_1?: string | null;
  snapshot_cliente_direccion_2?: string | null;
  snapshot_cliente_condicion_iva?: string | null;
};
type CuentaBancaria = { id: number; nombre: string };
type NcLibre = {
  id: number; tipo: string; numero: number; numero_completo: string;
  fecha_emision: string; imp_total: number; saldo_imputable: number;
};

type ProximoNumero = {
  punto_venta: string; numero: string;
  max_local: number; max_distriapp: number; consultado_distriapp: boolean;
};

const ESTADO_BADGE: Record<Recibo['estado'], 'success' | 'warning' | 'info' | 'danger'> = {
  BORRADOR: 'warning', EMITIDO: 'success', CONCILIADO: 'info', ANULADO: 'danger',
};

// Empresa snapshot por defecto (se rellena desde /erp_empresas).
const EMPRESA_DEFAULTS = {
  razon_social: 'LOGISTICA ARGENTINA SRL',
  cuit: '30-71706098-5',
  direccion_1: 'SAN CAYETANO 3470',
  direccion_2: 'SAN CAYETANO - CORRIENTES',
  condicion_iva: 'I.V.A. RESPONSABLE INSCRIPTO',
  inicio_actividad: '2020-08-11',
};

type Draft = {
  puntoVenta: string;
  numero: string;
  fecha: string;
  fechaCobro: string;
  detalleCobro: string;
  empresaNombre: string;
  empresaCuit: string;
  empresaDireccion1: string;
  empresaDireccion2: string;
  empresaIva: string;
  empresaInicioActividad: string;
  clienteId: string;
  clienteNombre: string;
  clienteCuit: string;
  clienteIva: string;
  clienteDireccion1: string;
  clienteDireccion2: string;
  comprobantes: Array<{ factura_venta_id: number; numeroFactura: string; fecha: string; totalFactura: number; imputado: number }>;
  ncAplicadas: Array<{ nc_factura_id: number; numeroNc: string; fecha: string; saldo: number; monto: number }>;
  retencionIva: string;
  retencionIibb: string;
  retencionGanancias: string;
  importeRecibido: string;
  medioCobroId: string;
  observaciones: string;
  autoIncrementar: boolean;
};

const DRAFT_INICIAL: Draft = {
  puntoVenta: '0001',
  numero: '',
  fecha: new Date().toISOString().slice(0, 10),
  fechaCobro: new Date().toISOString().slice(0, 10),
  detalleCobro: '',
  empresaNombre: EMPRESA_DEFAULTS.razon_social,
  empresaCuit: EMPRESA_DEFAULTS.cuit,
  empresaDireccion1: EMPRESA_DEFAULTS.direccion_1,
  empresaDireccion2: EMPRESA_DEFAULTS.direccion_2,
  empresaIva: EMPRESA_DEFAULTS.condicion_iva,
  empresaInicioActividad: EMPRESA_DEFAULTS.inicio_actividad,
  clienteId: '',
  clienteNombre: '',
  clienteCuit: '',
  clienteIva: 'RESP. INSCRIPTO',
  clienteDireccion1: '',
  clienteDireccion2: '',
  comprobantes: [],
  ncAplicadas: [],
  retencionIva: '',
  retencionIibb: '',
  retencionGanancias: '',
  importeRecibido: '',
  medioCobroId: '',
  observaciones: '',
  autoIncrementar: true,
};

function parseMontoEs(s: string | number | null | undefined): number {
  if (s === null || s === undefined || s === '') return 0;
  if (typeof s === 'number') return s;
  const str = String(s).trim();
  if (str === '') return 0;
  const tieneComa = str.includes(',');
  const tienePunto = str.includes('.');
  // El antiguo `replace(/\./g, '')` asumía formato AR (1.234,56) y
  // rompía los <input type="number">, que por spec HTML siempre devuelven el
  // decimal con punto (56.78 -> 5678). Detectamos el formato real:
  //  - coma y punto -> AR formateado: puntos = miles, coma = decimal.
  //  - solo coma     -> AR decimal "56,78".
  //  - solo punto / sin separadores -> decimal nativo (input type=number).
  let normalizado: string;
  if (tieneComa && tienePunto) {
    normalizado = str.replace(/\./g, '').replace(',', '.');
  } else if (tieneComa) {
    normalizado = str.replace(',', '.');
  } else {
    normalizado = str;
  }
  return Number(normalizado) || 0;
}
function fmtFecha(s?: string | null): string {
  if (!s) return '—';
  const m = String(s).match(/(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : s;
}

export function RecibosPage() {
  const toast = useToast();
  const invalidate = useInvalidate(['recibos']);
  const [busqueda, setBusqueda] = useState('');
  const [draft, setDraft] = useState<Draft>(DRAFT_INICIAL);
  const [selectedReciboId, setSelectedReciboId] = useState<number | null>(null);
  const [anularTarget, setAnularTarget] = useState<Recibo | null>(null);
  const [agregarCompModalOpen, setAgregarCompModalOpen] = useState(false);

  const { data: recibosResp, refetch: refetchRecibos } = useApi<Recibo[]>(
    ['recibos'],
    '/api/erp/tesoreria/recibos',
  );
  const recibos = recibosResp ?? [];
  const recibosFiltrados = useMemo(() => {
    const q = busqueda.trim().toLowerCase();
    if (!q) return recibos;
    return recibos.filter((r) =>
      (r.numero ?? '').toLowerCase().includes(q)
      || (r.numero_correlativo ?? '').toLowerCase().includes(q)
      || (r.cliente?.nombre ?? '').toLowerCase().includes(q),
    );
  }, [recibos, busqueda]);

  const { data: clientesResp } = useApi<Cliente[]>(
    ['clientes-para-recibos'],
    '/api/erp/clientes/para-recibos',
  );
  const clientes = clientesResp ?? [];
  const { data: bancosResp } = useApi<{ ok: boolean; data: CuentaBancaria[] }>(
    ['cuentas-bancarias'], '/api/erp/cuentas-bancarias',
  );
  const bancos = bancosResp?.data ?? [];

  // Cargar facturas del cliente seleccionado.
  const { data: facturasResp } = useApi<FacturaImputable[]>(
    ['facturas-imputables', draft.clienteId],
    `/api/erp/clientes/${draft.clienteId}/facturas-imputables-recibo`,
    { enabled: !!draft.clienteId },
  );
  const facturasDelCliente = facturasResp ?? [];

  // NC libres del cliente (con saldo imputable > 0).
  const { data: ncResp } = useApi<NcLibre[]>(
    ['nc-libres', draft.clienteId],
    `/api/erp/clientes/${draft.clienteId}/notas-credito-libres`,
    { enabled: !!draft.clienteId },
  );
  const ncDelCliente = ncResp ?? [];

  // Próximo número auto al montar / al pedir nuevo borrador.
  const { data: proximoNumeroResp, refetch: refetchProximoNumero } = useApi<ProximoNumero>(
    ['proximo-numero', draft.puntoVenta],
    `/api/erp/tesoreria/recibos/proximo-numero?pv=${draft.puntoVenta}`,
    { enabled: !selectedReciboId },
  );

  useEffect(() => {
    if (!selectedReciboId && proximoNumeroResp && !draft.numero) {
      setDraft((d) => ({ ...d, numero: proximoNumeroResp.numero }));
    }
  }, [proximoNumeroResp, selectedReciboId, draft.numero]);

  // Al elegir cliente: auto-completar datos.
  const onPickCliente = (clienteId: string) => {
    const c = clientes.find((x) => String(x.id) === clienteId);
    setDraft((d) => ({
      ...d,
      clienteId,
      clienteNombre: c?.nombre ?? '',
      clienteCuit: c?.cuit ?? '',
      clienteDireccion1: c?.direccion_1 ?? '',
      clienteDireccion2: c?.direccion_2 ?? '',
      comprobantes: [],
      ncAplicadas: [],
    }));
  };

  // Cargar recibo emitido a la vista.
  const { data: reciboDetalle } = useApi<ReciboDetalle>(
    ['recibo-detalle', selectedReciboId],
    `/api/erp/tesoreria/recibos/${selectedReciboId}`,
    { enabled: !!selectedReciboId },
  );
  useEffect(() => {
    if (reciboDetalle) {
      setDraft({
        puntoVenta: reciboDetalle.punto_venta ?? '0001',
        numero: reciboDetalle.numero ?? '',
        fecha: String(reciboDetalle.fecha_emision).slice(0, 10),
        fechaCobro: String(reciboDetalle.fecha_emision).slice(0, 10),
        detalleCobro: reciboDetalle.detalle_cobro ?? '',
        empresaNombre: reciboDetalle.snapshot_empresa_razon_social ?? EMPRESA_DEFAULTS.razon_social,
        empresaCuit: reciboDetalle.snapshot_empresa_cuit ?? EMPRESA_DEFAULTS.cuit,
        empresaDireccion1: reciboDetalle.snapshot_empresa_direccion_1 ?? EMPRESA_DEFAULTS.direccion_1,
        empresaDireccion2: reciboDetalle.snapshot_empresa_direccion_2 ?? EMPRESA_DEFAULTS.direccion_2,
        empresaIva: reciboDetalle.snapshot_empresa_condicion_iva ?? EMPRESA_DEFAULTS.condicion_iva,
        empresaInicioActividad: reciboDetalle.snapshot_empresa_inicio_actividad ?? EMPRESA_DEFAULTS.inicio_actividad,
        clienteId: String(reciboDetalle.cliente_auxiliar_id),
        clienteNombre: reciboDetalle.snapshot_cliente_razon_social ?? reciboDetalle.cliente?.nombre ?? '',
        clienteCuit: reciboDetalle.snapshot_cliente_cuit ?? '',
        clienteIva: reciboDetalle.snapshot_cliente_condicion_iva ?? 'RESP. INSCRIPTO',
        clienteDireccion1: reciboDetalle.snapshot_cliente_direccion_1 ?? '',
        clienteDireccion2: reciboDetalle.snapshot_cliente_direccion_2 ?? '',
        comprobantes: (reciboDetalle.comprobantes_imputados ?? []).map((c) => ({
          factura_venta_id: c.factura_venta_id,
          numeroFactura: c.numero_factura_snapshot,
          fecha: c.fecha_factura,
          totalFactura: Number(c.total_factura),
          imputado: Number(c.monto_imputado),
        })),
        ncAplicadas: (reciboDetalle.nc_aplicadas ?? []).map((n) => ({
          nc_factura_id: n.nc_factura_id,
          numeroNc: `NC #${n.nc?.numero ?? n.nc_factura_id}`,
          fecha: '',
          saldo: Number(n.monto_aplicado),
          monto: Number(n.monto_aplicado),
        })),
        retencionIva: String(reciboDetalle.retencion_iva_total || ''),
        retencionIibb: String(reciboDetalle.retencion_iibb_total || ''),
        retencionGanancias: String(reciboDetalle.retencion_ganancias_total || ''),
        importeRecibido: String(reciboDetalle.monto_cobrado),
        medioCobroId: '',
        observaciones: '',
        autoIncrementar: true,
      });
    }
  }, [reciboDetalle]);

  const totalImputado = draft.comprobantes.reduce((s, c) => s + c.imputado, 0);
  const totalNc = draft.ncAplicadas.reduce((s, n) => s + n.monto, 0);
  const totalRet = parseMontoEs(draft.retencionIva) + parseMontoEs(draft.retencionIibb) + parseMontoEs(draft.retencionGanancias);
  const totalCobro = parseMontoEs(draft.importeRecibido) + totalRet;
  const montoCobrable = Math.max(0, totalImputado - totalNc - totalRet);

  const crearMut = useApiMutation<{ data: Recibo }, Record<string, unknown>>(
    (body) => api.post('/api/erp/tesoreria/recibos', body),
    {
      onSuccess: () => { /* handled by orquestador */ },
      onError: (e) => toast.error('Error', (e as ApiError).message),
    },
  );

  const handleNuevoBorrador = () => {
    setSelectedReciboId(null);
    setDraft({ ...DRAFT_INICIAL, numero: '' });
    refetchProximoNumero();
  };
  const handleRestablecerEjemplo = () => {
    setSelectedReciboId(null);
    setDraft({
      ...DRAFT_INICIAL,
      clienteNombre: 'OCASA SA (ejemplo)',
      clienteCuit: '30-71706098-5',
      clienteDireccion1: 'AV. EJEMPLO 1234',
      clienteDireccion2: 'CABA',
      clienteIva: 'RESP. INSCRIPTO',
      detalleCobro: 'ECHEQ BANCO SUPERVIELLE N° 00005916 vto 31/05',
      comprobantes: [
        { factura_venta_id: 0, numeroFactura: '0002-00000596', fecha: '2026-04-07', totalFactura: 2764494.26, imputado: 2764494.26 },
        { factura_venta_id: 0, numeroFactura: '0002-00000625', fecha: '2026-04-15', totalFactura: 681641.40, imputado: 681641.40 },
      ],
      retencionGanancias: '25234.98',
      importeRecibido: '3420900.68',
      numero: proximoNumeroResp?.numero ?? '',
    });
    toast.success('Ejemplo cargado', 'Datos ficticios para training/testing');
  };
  const handleEmitirEImprimir = async () => {
    if (!draft.clienteId) { toast.error('Cliente requerido', 'Seleccioná un cliente.'); return; }
    if (draft.comprobantes.length === 0) {
      toast.error('Sin comprobantes', 'Agregá al menos una factura.');
      return;
    }
    const fakeIds = draft.comprobantes.filter((c) => !c.factura_venta_id);
    if (fakeIds.length > 0) {
      toast.error('Restablecer ejemplo activo', 'Los comprobantes del ejemplo son ficticios. Restablecé y elegí facturas reales.');
      return;
    }
    try {
      const body = {
        cliente_auxiliar_id: Number(draft.clienteId),
        fecha_emision: draft.fecha,
        detalle_cobro: draft.detalleCobro || null,
        comprobantes_imputados: draft.comprobantes.map((c) => ({
          factura_venta_id: c.factura_venta_id,
          monto_imputado: c.imputado,
        })),
        nc_aplicadas: draft.ncAplicadas.map((n) => ({
          nc_factura_id: n.nc_factura_id,
          monto_aplicado: n.monto,
        })),
        auto_imputar_nc: false, // el operador elige las NC manualmente desde el modal.
        monto_cobrado: parseMontoEs(draft.importeRecibido),
        medio_cobro_id: draft.medioCobroId ? Number(draft.medioCobroId) : null,
        retencion_iva_total: parseMontoEs(draft.retencionIva),
        retencion_iibb_total: parseMontoEs(draft.retencionIibb),
        retencion_ganancias_total: parseMontoEs(draft.retencionGanancias),
        observaciones: draft.observaciones || null,
      };
      const created = await api.post<{ data: Recibo }>('/api/erp/tesoreria/recibos', body);
      const emitido = await api.post<{ data: { recibo_id: number; estado: string } }>(`/api/erp/tesoreria/recibos/${created.data.id}/emitir`, {});
      toast.success('Recibo emitido', `Recibo ${draft.puntoVenta}-${draft.numero}`);
      invalidate();
      // Imprimir via window.print del HTML del preview.
      printRecibo(draft, { total_cobro: totalCobro, total_imputado: totalImputado - totalNc, watermark: null });
      if (draft.autoIncrementar) {
        // Próximo número auto.
        const next = await api.get<ProximoNumero>(`/api/erp/tesoreria/recibos/proximo-numero?pv=${draft.puntoVenta}`);
        setSelectedReciboId(null);
        setDraft({ ...DRAFT_INICIAL, numero: next.numero });
      } else {
        setSelectedReciboId(emitido.data.recibo_id);
      }
    } catch (e) {
      toast.error('No se pudo emitir', (e as ApiError).message);
    }
  };

  return (
    <div className="flex h-[calc(100vh-60px)]">
      {/* Sidebar — lista de recibos */}
      <div className="w-80 border-r border-line bg-surface-row flex flex-col">
        <div className="p-2 border-b border-line">
          <div className="flex items-center gap-1.5 text-[13px] font-semibold text-navy-800 mb-2">
            <Receipt className="w-4 h-4 text-azure" /> Recibos emitidos
          </div>
          <div className="relative">
            <Search className="w-3 h-3 absolute left-2 top-2 text-ink-muted" />
            <input value={busqueda} onChange={(e) => setBusqueda(e.target.value)}
              placeholder="Buscar por nro o cliente…"
              className="w-full pl-7 pr-2 py-1 text-[12px] border border-line rounded focus:outline-none focus:border-azure" />
          </div>
        </div>
        <div className="flex-1 overflow-auto">
          {recibosFiltrados.length === 0 && (
            <div className="p-3 text-[11px] text-ink-muted italic">Sin recibos.</div>
          )}
          {recibosFiltrados.map((r) => (
            <button key={r.id}
              onClick={() => setSelectedReciboId(r.id)}
              className={`w-full text-left p-2 border-b border-line text-[11.5px] hover:bg-azure-soft/20 ${
                selectedReciboId === r.id ? 'bg-azure-soft/30 border-l-2 border-l-azure' : ''
              }`}>
              <div className="flex items-center justify-between">
                <strong className="font-mono">{r.punto_venta && r.numero ? `${r.punto_venta}-${r.numero}` : r.numero_correlativo}</strong>
                <Badge variant={ESTADO_BADGE[r.estado]}>{r.estado}</Badge>
              </div>
              <div className="text-ink-muted truncate">{r.cliente?.nombre ?? `#${r.cliente_auxiliar_id}`}</div>
              <div className="text-[10px] text-ink-muted">
                {fmtFecha(r.fecha_emision)} · ${fmtMoney(r.monto_cobrado)}
              </div>
            </button>
          ))}
        </div>
      </div>

      {/* Main — Form + Preview */}
      <div className="flex-1 overflow-auto p-3 space-y-3">
        {/* Header acciones */}
        <div className="flex items-center justify-between gap-2 sticky top-0 bg-bg-base z-10 pb-2 border-b border-line">
          <div className="text-[14px] font-semibold text-navy-800 flex items-center gap-2">
            <Receipt className="w-4 h-4" /> Recibos (v1.32)
            {selectedReciboId && reciboDetalle && (
              <span className="ml-2 text-[12px] text-ink-muted font-normal">
                Viendo {reciboDetalle.punto_venta}-{reciboDetalle.numero} <Badge variant={ESTADO_BADGE[reciboDetalle.estado]}>{reciboDetalle.estado}</Badge>
                {reciboDetalle.numero_legacy && <span className="ml-2 font-mono text-[10px]">({reciboDetalle.numero_legacy})</span>}
              </span>
            )}
          </div>
          <div className="flex gap-1.5">
            <Button variant="ghost" size="sm" onClick={handleNuevoBorrador}>
              <Plus className="w-3 h-3" /> Nuevo borrador
            </Button>
            <Button variant="ghost" size="sm" onClick={handleRestablecerEjemplo}>
              <RotateCcw className="w-3 h-3" /> Restablecer ejemplo
            </Button>
            {!selectedReciboId && (
              <Button variant="primary" size="sm" onClick={handleEmitirEImprimir} disabled={crearMut.isPending}>
                <Printer className="w-3 h-3" /> Emitir e imprimir
              </Button>
            )}
            {selectedReciboId && reciboDetalle?.estado === 'EMITIDO' && (
              <Button variant="danger" size="sm" onClick={() => setAnularTarget(reciboDetalle)}>
                <Ban className="w-3 h-3" /> Anular
              </Button>
            )}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          {/* Form */}
          <div className="space-y-3">
            <Card>
              <CardBody className="space-y-2">
                <div className="grid grid-cols-3 gap-2 text-[11.5px]">
                  <Field label="PV" value={draft.puntoVenta}
                    onChange={(e) => setDraft({ ...draft, puntoVenta: e.target.value })}
                    disabled={!!selectedReciboId} />
                  <Field label="Número" value={draft.numero}
                    onChange={(e) => setDraft({ ...draft, numero: e.target.value })}
                    disabled={!!selectedReciboId}
                    hint={proximoNumeroResp?.consultado_distriapp ? `Distri max=${proximoNumeroResp.max_distriapp}` : undefined} />
                  <Field label="Fecha recibo *" type="date" value={draft.fecha}
                    onChange={(e) => setDraft({ ...draft, fecha: e.target.value })}
                    disabled={!!selectedReciboId} />
                </div>
                <div className="text-[10.5px] text-ink-muted">
                  <label className="flex items-center gap-1">
                    <input type="checkbox" checked={draft.autoIncrementar}
                      onChange={(e) => setDraft({ ...draft, autoIncrementar: e.target.checked })} />
                    Auto incrementar recibo al imprimir
                  </label>
                </div>
              </CardBody>
            </Card>

            <Card>
              <CardHeader title={<div className="text-[12px] font-semibold">Cliente</div>} />
              <CardBody className="space-y-2">
                <SelectField label="Cliente *" value={draft.clienteId}
                  onChange={(e) => onPickCliente(e.target.value)}
                  disabled={!!selectedReciboId}
                  options={[{ value: '', label: clientes.length === 0 ? 'Cargando…' : 'Elegí cliente…' },
                    ...clientes.map((c) => ({
                      value: String(c.id),
                      label: `${c.nombre}${c.facturas_pendientes ? ` · ${c.facturas_pendientes} fact. pend.` : ''}`,
                    }))]} />
                <div className="grid grid-cols-2 gap-2 text-[11.5px]">
                  <Field label="CUIT" value={draft.clienteCuit}
                    onChange={(e) => setDraft({ ...draft, clienteCuit: e.target.value })}
                    disabled={!!selectedReciboId} />
                  <Field label="Cond. IVA" value={draft.clienteIva}
                    onChange={(e) => setDraft({ ...draft, clienteIva: e.target.value })}
                    disabled={!!selectedReciboId} />
                  <Field label="Dirección" value={draft.clienteDireccion1}
                    onChange={(e) => setDraft({ ...draft, clienteDireccion1: e.target.value })}
                    disabled={!!selectedReciboId} />
                  <Field label="Localidad" value={draft.clienteDireccion2}
                    onChange={(e) => setDraft({ ...draft, clienteDireccion2: e.target.value })}
                    disabled={!!selectedReciboId} />
                </div>
              </CardBody>
            </Card>

            <Card>
              <CardHeader title={
                <div className="flex items-center justify-between">
                  <div className="text-[12px] font-semibold">Comprobantes imputados ({draft.comprobantes.length})</div>
                  {!selectedReciboId && draft.clienteId && (
                    <Button variant="ghost" size="sm" onClick={() => setAgregarCompModalOpen(true)}>
                      <Plus className="w-3 h-3" /> Agregar
                    </Button>
                  )}
                </div>
              } />
              <CardBody>
                {draft.comprobantes.length === 0 ? (
                  <div className="text-[11px] text-ink-muted italic">Sin comprobantes.</div>
                ) : (
                  <table className="w-full text-[11px]">
                    <thead><tr className="text-ink-muted">
                      <th className="text-left px-1">Fecha</th>
                      <th className="text-left">N° Fact</th>
                      <th className="text-right">Total fact</th>
                      <th className="text-right">Imputado</th>
                      {!selectedReciboId && <th></th>}
                    </tr></thead>
                    <tbody>
                      {draft.comprobantes.map((c, i) => (
                        <tr key={i} className="border-t border-line">
                          <td className="px-1">{fmtFecha(c.fecha)}</td>
                          <td className="font-mono">{c.numeroFactura}</td>
                          <td className="text-right tabular">${fmtMoney(c.totalFactura)}</td>
                          <td className="text-right tabular font-semibold">${fmtMoney(c.imputado)}</td>
                          {!selectedReciboId && (
                            <td className="text-right">
                              <button onClick={() => setDraft({ ...draft, comprobantes: draft.comprobantes.filter((_, idx) => idx !== i) })}>
                                <Trash2 className="w-3 h-3 text-danger" />
                              </button>
                            </td>
                          )}
                        </tr>
                      ))}
                      <tr className="font-semibold border-t border-line bg-azure-soft/10">
                        <td colSpan={3} className="text-right px-1">Total imputado</td>
                        <td className="text-right tabular">${fmtMoney(totalImputado)}</td>
                        {!selectedReciboId && <td></td>}
                      </tr>
                    </tbody>
                  </table>
                )}
              </CardBody>
            </Card>

            {/* NC aplicadas */}
            <Card>
              <CardHeader title={
                <div className="flex items-center justify-between">
                  <div className="text-[12px] font-semibold text-success">Notas de crédito aplicadas ({draft.ncAplicadas.length})</div>
                </div>
              } />
              <CardBody>
                {draft.ncAplicadas.length === 0 ? (
                  <div className="text-[11px] text-ink-muted italic">
                    {ncDelCliente.length > 0
                      ? `El cliente tiene ${ncDelCliente.length} NC disponible${ncDelCliente.length === 1 ? '' : 's'} — agregalas desde el botón "Agregar" de Comprobantes.`
                      : 'Sin NC disponibles para este cliente.'}
                  </div>
                ) : (
                  <table className="w-full text-[11px]">
                    <thead><tr className="text-ink-muted">
                      <th className="text-left px-1">NC</th>
                      <th className="text-right">Saldo NC</th>
                      <th className="text-right">Aplicado</th>
                      {!selectedReciboId && <th></th>}
                    </tr></thead>
                    <tbody>
                      {draft.ncAplicadas.map((n, i) => (
                        <tr key={i} className="border-t border-line bg-success-bg/10">
                          <td className="px-1 font-mono">{n.numeroNc}</td>
                          <td className="text-right tabular">${fmtMoney(n.saldo)}</td>
                          <td className="text-right tabular font-semibold text-success">−${fmtMoney(n.monto)}</td>
                          {!selectedReciboId && (
                            <td className="text-right">
                              <button onClick={() => setDraft({ ...draft, ncAplicadas: draft.ncAplicadas.filter((_, idx) => idx !== i) })}>
                                <Trash2 className="w-3 h-3 text-danger" />
                              </button>
                            </td>
                          )}
                        </tr>
                      ))}
                      <tr className="font-semibold border-t border-line bg-success-bg/20">
                        <td colSpan={2} className="text-right px-1">Total NC</td>
                        <td className="text-right tabular text-success">−${fmtMoney(totalNc)}</td>
                        {!selectedReciboId && <td></td>}
                      </tr>
                    </tbody>
                  </table>
                )}
              </CardBody>
            </Card>

            <Card>
              <CardHeader title={<div className="text-[12px] font-semibold">Cobro</div>} />
              <CardBody className="space-y-2">
                <div className="grid grid-cols-2 gap-2 text-[11.5px]">
                  <Field label="Fecha cobro" type="date" value={draft.fechaCobro}
                    onChange={(e) => setDraft({ ...draft, fechaCobro: e.target.value })}
                    disabled={!!selectedReciboId} />
                  <Field label="Detalle cobro" value={draft.detalleCobro}
                    onChange={(e) => setDraft({ ...draft, detalleCobro: e.target.value })}
                    placeholder="ECHEQ BANCO X N°..."
                    disabled={!!selectedReciboId} />
                  <Field label="Importe recibido" type="number" value={draft.importeRecibido}
                    onChange={(e) => setDraft({ ...draft, importeRecibido: e.target.value })}
                    disabled={!!selectedReciboId} />
                  <SelectField label="Medio de cobro" value={draft.medioCobroId}
                    onChange={(e) => setDraft({ ...draft, medioCobroId: e.target.value })}
                    disabled={!!selectedReciboId}
                    options={[{ value: '', label: '—' },
                      ...bancos.map((b) => ({ value: String(b.id), label: b.nombre }))]} />
                </div>
                <div className="grid grid-cols-3 gap-2 text-[11.5px]">
                  <Field label="Ret IVA" type="number" value={draft.retencionIva}
                    onChange={(e) => setDraft({ ...draft, retencionIva: e.target.value })}
                    disabled={!!selectedReciboId} />
                  <Field label="Ret IIBB" type="number" value={draft.retencionIibb}
                    onChange={(e) => setDraft({ ...draft, retencionIibb: e.target.value })}
                    disabled={!!selectedReciboId} />
                  <Field label="Ret Ganancias" type="number" value={draft.retencionGanancias}
                    onChange={(e) => setDraft({ ...draft, retencionGanancias: e.target.value })}
                    disabled={!!selectedReciboId} />
                </div>
                <div className="text-[11.5px] space-y-0.5 bg-azure-soft/10 border border-azure-soft rounded p-2">
                  <div className="flex justify-between"><span>Total imputado (facturas):</span><span className="tabular">${fmtMoney(totalImputado)}</span></div>
                  {totalNc > 0 && <div className="flex justify-between text-success"><span>− NC aplicadas:</span><span className="tabular">−${fmtMoney(totalNc)}</span></div>}
                  {totalRet > 0 && <div className="flex justify-between"><span>− Retenciones:</span><span className="tabular">−${fmtMoney(totalRet)}</span></div>}
                  <div className="flex justify-between font-semibold border-t border-azure-soft pt-0.5">
                    <span>Monto cobrable:</span><span className="tabular">${fmtMoney(montoCobrable)}</span>
                  </div>
                  <div className="flex justify-between font-bold">
                    <span>Total cobro (recibido + ret):</span><span className="tabular">${fmtMoney(totalCobro)}</span>
                  </div>
                </div>
              </CardBody>
            </Card>
          </div>

          {/* Preview en vivo */}
          <ReciboPreview draft={draft} totalCobro={totalCobro} totalImputado={totalImputado}
            watermark={selectedReciboId && reciboDetalle?.estado === 'ANULADO' ? 'RECIBO ANULADO' : (!selectedReciboId ? 'BORRADOR' : null)} />
        </div>
      </div>

      {agregarCompModalOpen && (
        <AgregarComprobanteModal
          facturas={facturasDelCliente}
          ncs={ncDelCliente}
          yaAgregadas={new Set(draft.comprobantes.map((c) => c.factura_venta_id))}
          ncYaAgregadas={new Set(draft.ncAplicadas.map((n) => n.nc_factura_id))}
          onClose={() => setAgregarCompModalOpen(false)}
          onAgregar={(facturasSel, ncsSel) => {
            setDraft({
              ...draft,
              comprobantes: [...draft.comprobantes, ...facturasSel.map((f) => ({
                factura_venta_id: f.id,
                numeroFactura: f.numero_completo,
                fecha: f.fecha_emision,
                totalFactura: Number(f.imp_total),
                imputado: Number(f.saldo),
              }))],
              ncAplicadas: [...draft.ncAplicadas, ...ncsSel.map((n) => ({
                nc_factura_id: n.id,
                numeroNc: `${n.tipo} ${n.numero_completo}`,
                fecha: n.fecha_emision,
                saldo: Number(n.saldo_imputable),
                monto: Number(n.saldo_imputable),
              }))],
            });
            setAgregarCompModalOpen(false);
          }}
        />
      )}

      {anularTarget && (
        <AnularModal recibo={anularTarget} onClose={() => setAnularTarget(null)}
          onSuccess={() => { invalidate(); refetchRecibos(); setAnularTarget(null); setSelectedReciboId(null); }} />
      )}
    </div>
  );
}

function ReciboPreview({ draft, totalCobro, totalImputado, watermark }: {
  draft: Draft; totalCobro: number; totalImputado: number; watermark: string | null;
}) {
  return (
    <div className="sticky top-12 bg-white border border-line rounded p-3 text-[11px]" style={{ fontFamily: 'Arial, sans-serif', color: '#111827' }}>
      <div className="border border-black rounded relative overflow-hidden">
        {watermark && (
          <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
            <span className="border-[6px] border-danger/70 text-danger/40 text-[26px] font-black rotate-[-20deg] px-4 py-2">
              {watermark}
            </span>
          </div>
        )}
        {/* Header */}
        <div className="grid border-b border-black" style={{ gridTemplateColumns: '1.4fr 0.42fr 0.88fr' }}>
          <div className="p-2">
            <div className="font-bold text-[12px]">{draft.empresaNombre}</div>
            <div>{draft.empresaDireccion1}</div>
            <div>{draft.empresaDireccion2}</div>
            <div>{draft.empresaIva}</div>
          </div>
          <div className="border-l border-r border-black flex flex-col items-center justify-center p-1">
            <div className="text-[28px] leading-none font-bold">X</div>
            <div className="text-[8px] font-bold leading-tight text-center">DOCUMENTO<br />NO VALIDO<br />COMO FACTURA</div>
          </div>
          <div className="p-2 text-center">
            <div className="text-[14px] font-extrabold">RECIBO</div>
            <div className="text-[10px]">{draft.puntoVenta} - {draft.numero || '00000000'}</div>
            <div className="mt-2 grid grid-cols-2 gap-x-1 text-[9.5px] text-left">
              <span className="font-bold">FECHA:</span><span>{fmtFecha(draft.fecha)}</span>
              <span className="font-bold">CUIT:</span><span>{draft.empresaCuit}</span>
              <span className="font-bold">INICIO ACT.:</span><span>{fmtFecha(draft.empresaInicioActividad)}</span>
            </div>
          </div>
        </div>
        {/* Cliente */}
        <div className="grid grid-cols-2 gap-2 p-2 border-b border-black text-[10px]">
          <div>
            <div><span className="font-bold">CLIENTE</span> {draft.clienteNombre || '—'}</div>
            <div><span className="font-bold">DIRECCIÓN</span> {draft.clienteDireccion1 || '—'}</div>
            <div>{draft.clienteDireccion2}</div>
          </div>
          <div>
            <div><span className="font-bold">CUIT</span> {draft.clienteCuit || '—'}</div>
            <div><span className="font-bold">IVA</span> {draft.clienteIva || '—'}</div>
          </div>
        </div>
        {/* Cobro */}
        <div className="p-2 text-[10px]">
          <div className="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 max-w-md">
            <span className="font-bold">FECHA DEL COBRO</span><span className="text-right">{fmtFecha(draft.fechaCobro)}</span>
            <span className="font-bold">DETALLE DEL COBRO</span><span className="text-right">{draft.detalleCobro || '—'}</span>
            <span className="font-bold">IMPORTE RECIBIDO</span><span className="text-right">${fmtMoney(parseMontoEs(draft.importeRecibido))}</span>
            {draft.ncAplicadas.length > 0 && (
              <>
                <span className="font-bold">NOTAS DE CRÉDITO</span>
                <span className="text-right">−${fmtMoney(draft.ncAplicadas.reduce((s, n) => s + n.monto, 0))}</span>
              </>
            )}
            <span className="font-bold">RETENCIONES IVA</span><span className="text-right">${fmtMoney(parseMontoEs(draft.retencionIva))}</span>
            <span className="font-bold">RETENCIONES IIBB</span><span className="text-right">${fmtMoney(parseMontoEs(draft.retencionIibb))}</span>
            <span className="font-bold">RETENCIONES GANANCIAS</span><span className="text-right">${fmtMoney(parseMontoEs(draft.retencionGanancias))}</span>
            <span className="font-extrabold">TOTAL COBRO</span><span className="text-right font-extrabold">${fmtMoney(totalCobro)}</span>
          </div>
        </div>
        {/* Tabla comprobantes */}
        <div className="border-t border-black p-2 text-[10px]">
          <div className="font-extrabold mb-1">DETALLE DE COMPROBANTES IMPUTADOS</div>
          <table className="w-full border-collapse">
            <thead><tr>
              <th className="border border-black px-1 py-0.5 text-left">FECHA</th>
              <th className="border border-black px-1 py-0.5 text-left">N° FACT</th>
              <th className="border border-black px-1 py-0.5 text-right">TOTAL FACT</th>
              <th className="border border-black px-1 py-0.5 text-right">IMPUTADO</th>
            </tr></thead>
            <tbody>
              {draft.comprobantes.length === 0 && draft.ncAplicadas.length === 0 ? (
                <tr><td colSpan={4} className="border border-black px-1 italic text-ink-muted">Sin comprobantes imputados.</td></tr>
              ) : (
                <>
                  {draft.comprobantes.map((c, i) => (
                    <tr key={`f${i}`}>
                      <td className="border border-black px-1">{fmtFecha(c.fecha)}</td>
                      <td className="border border-black px-1 font-mono">{c.numeroFactura}</td>
                      <td className="border border-black px-1 text-right">${fmtMoney(c.totalFactura)}</td>
                      <td className="border border-black px-1 text-right">${fmtMoney(c.imputado)}</td>
                    </tr>
                  ))}
                  {draft.ncAplicadas.map((n, i) => (
                    <tr key={`n${i}`}>
                      <td className="border border-black px-1">{fmtFecha(n.fecha)}</td>
                      <td className="border border-black px-1 font-mono">{n.numeroNc}</td>
                      <td className="border border-black px-1 text-right">−${fmtMoney(n.saldo)}</td>
                      <td className="border border-black px-1 text-right">−${fmtMoney(n.monto)}</td>
                    </tr>
                  ))}
                </>
              )}
            </tbody>
          </table>
        </div>
        {/* Footer */}
        <div className="border-t border-black p-2 flex justify-end gap-3 font-extrabold text-[12px]">
          <span>TOTAL IMPUTADO</span>
          <span>${fmtMoney(totalImputado - draft.ncAplicadas.reduce((s, n) => s + n.monto, 0))}</span>
        </div>
      </div>
    </div>
  );
}

function AgregarComprobanteModal({ facturas, ncs, yaAgregadas, ncYaAgregadas, onClose, onAgregar }: {
  facturas: FacturaImputable[];
  ncs: NcLibre[];
  yaAgregadas: Set<number>;
  ncYaAgregadas: Set<number>;
  onClose: () => void;
  onAgregar: (facturasSel: FacturaImputable[], ncsSel: NcLibre[]) => void;
}) {
  const facturasDisp = facturas.filter((f) => !yaAgregadas.has(f.id));
  const ncsDisp = ncs.filter((n) => !ncYaAgregadas.has(n.id));
  const [selFact, setSelFact] = useState<Set<number>>(new Set());
  const [selNc, setSelNc] = useState<Set<number>>(new Set());
  const [busq, setBusq] = useState('');

  const q = busq.trim().toLowerCase();
  const facturasFilt = useMemo(() => !q ? facturasDisp
    : facturasDisp.filter((f) => f.numero_completo.toLowerCase().includes(q) || String(f.fecha_emision).includes(q)),
    [facturasDisp, q]);
  const ncsFilt = useMemo(() => !q ? ncsDisp
    : ncsDisp.filter((n) => n.numero_completo.toLowerCase().includes(q) || String(n.fecha_emision).includes(q)),
    [ncsDisp, q]);

  const toggleFact = (id: number) => {
    const s = new Set(selFact); s.has(id) ? s.delete(id) : s.add(id); setSelFact(s);
  };
  const toggleNc = (id: number) => {
    const s = new Set(selNc); s.has(id) ? s.delete(id) : s.add(id); setSelNc(s);
  };
  const toggleAllFact = () => setSelFact(selFact.size === facturasFilt.length ? new Set() : new Set(facturasFilt.map((f) => f.id)));

  const totalFact = facturasFilt.filter((f) => selFact.has(f.id)).reduce((s, f) => s + f.saldo, 0);
  const totalNc = ncsFilt.filter((n) => selNc.has(n.id)).reduce((s, n) => s + n.saldo_imputable, 0);
  const totalSel = selFact.size + selNc.size;
  const nada = facturasDisp.length === 0 && ncsDisp.length === 0;

  return (
    <Modal open onClose={onClose} title="Agregar comprobantes imputados" size="lg" footer={
      <>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={totalSel === 0}
          onClick={() => onAgregar(
            facturasFilt.filter((f) => selFact.has(f.id)),
            ncsFilt.filter((n) => selNc.has(n.id)),
          )}>
          Agregar {totalSel > 0 ? `(${totalSel})` : ''}
        </Button>
      </>
    }>
      <div className="space-y-3 text-[12px]">
        <input value={busq} onChange={(e) => setBusq(e.target.value)}
          placeholder="Buscar por nro o fecha…"
          className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />

        {nada && (
          <div className="text-[11.5px] text-ink-muted border border-line rounded p-3 bg-surface-row">
            Este cliente no tiene comprobantes con saldo pendiente. Verificá que las facturas
            estén registradas con estado <code>EMITIDA</code> o <code>COBRO_PARCIAL</code> en{' '}
            <a href="/erp/facturacion" className="text-azure underline">Facturación</a>.
          </div>
        )}

        {/* Facturas */}
        {facturasDisp.length > 0 && (
          <div className="space-y-1">
            <div className="flex items-center justify-between">
              <label className="text-[11px] cursor-pointer font-semibold">
                <input type="checkbox" className="mr-1"
                  checked={selFact.size === facturasFilt.length && facturasFilt.length > 0}
                  onChange={toggleAllFact} />
                Facturas ({facturasFilt.length})
              </label>
              <span className="text-[11.5px] text-ink-muted">Seleccionadas: <strong>${fmtMoney(totalFact)}</strong></span>
            </div>
            <div className="max-h-56 overflow-auto border border-line rounded">
              <table className="w-full text-[11px]">
                <thead className="bg-surface-row sticky top-0"><tr>
                  <th className="px-2 py-1 w-8"></th><th className="text-left">Nº</th><th className="text-left">Fecha</th>
                  <th className="text-right">Total</th><th className="text-right">Saldo</th><th className="text-left">Origen</th>
                </tr></thead>
                <tbody>
                  {facturasFilt.map((f) => (
                    <tr key={f.id} className={`border-t border-line cursor-pointer ${selFact.has(f.id) ? 'bg-azure-soft/30' : 'hover:bg-azure-soft/10'}`}
                      onClick={() => toggleFact(f.id)}>
                      <td className="px-2 py-0.5"><input type="checkbox" checked={selFact.has(f.id)} readOnly /></td>
                      <td className="font-mono">{f.tipo} {f.numero_completo}</td>
                      <td>{fmtFecha(f.fecha_emision)}</td>
                      <td className="text-right tabular">${fmtMoney(f.imp_total)}</td>
                      <td className="text-right tabular font-semibold">${fmtMoney(f.saldo)}</td>
                      <td><span className="text-[9.5px] px-1 py-0.5 rounded bg-azure-soft/40 text-azure">{f.origen}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* Notas de crédito */}
        {ncsDisp.length > 0 && (
          <div className="space-y-1">
            <div className="flex items-center justify-between">
              <span className="text-[11px] font-semibold text-success">Notas de crédito disponibles ({ncsFilt.length})</span>
              <span className="text-[11.5px] text-ink-muted">Seleccionadas: <strong className="text-success">−${fmtMoney(totalNc)}</strong></span>
            </div>
            <div className="text-[10px] text-ink-muted">Se imputan al recibo reduciendo el monto cobrable.</div>
            <div className="max-h-44 overflow-auto border border-success/30 rounded">
              <table className="w-full text-[11px]">
                <thead className="bg-success-bg/20 sticky top-0"><tr>
                  <th className="px-2 py-1 w-8"></th><th className="text-left">Nº NC</th><th className="text-left">Fecha</th>
                  <th className="text-right">Total NC</th><th className="text-right">Saldo imputable</th>
                </tr></thead>
                <tbody>
                  {ncsFilt.map((n) => (
                    <tr key={n.id} className={`border-t border-success/10 cursor-pointer ${selNc.has(n.id) ? 'bg-success-bg/30' : 'hover:bg-success-bg/10'}`}
                      onClick={() => toggleNc(n.id)}>
                      <td className="px-2 py-0.5"><input type="checkbox" checked={selNc.has(n.id)} readOnly /></td>
                      <td className="font-mono">{n.tipo} {n.numero_completo}</td>
                      <td>{fmtFecha(n.fecha_emision)}</td>
                      <td className="text-right tabular">${fmtMoney(n.imp_total)}</td>
                      <td className="text-right tabular font-semibold text-success">${fmtMoney(n.saldo_imputable)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    </Modal>
  );
}

function AnularModal({ recibo, onClose, onSuccess }: { recibo: Recibo; onClose: () => void; onSuccess: () => void }) {
  const toast = useToast();
  const [motivo, setMotivo] = useState('');
  const mut = useApiMutation<unknown, { motivo: string }>(
    (body) => api.post(`/api/erp/tesoreria/recibos/${recibo.id}/anular`, body),
    {
      onSuccess: () => { toast.success('Recibo anulado', `Reversa de ${recibo.punto_venta}-${recibo.numero}`); onSuccess(); },
      onError: (e) => toast.error('No se pudo anular', (e as ApiError).message),
    },
  );
  return (
    <Modal open onClose={onClose} title={`Anular recibo ${recibo.punto_venta}-${recibo.numero}`} size="md" footer={
      <>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="danger" disabled={motivo.trim().length < 5 || mut.isPending}
          onClick={() => mut.mutate({ motivo: motivo.trim() })}>
          {mut.isPending ? 'Anulando…' : 'Anular'}
        </Button>
      </>
    }>
      <div className="space-y-2 text-[12px]">
        <div className="border border-warning/40 bg-warning-bg/20 rounded p-2 flex items-start gap-1.5">
          <AlertTriangle className="w-4 h-4 text-warning shrink-0" />
          <div>Genera asiento reversa, libera saldos de las facturas imputadas y des-imputa las NC.
            Queda en audit log.</div>
        </div>
        <textarea rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)}
          maxLength={500}
          placeholder="Motivo (mín 5 chars)…"
          className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
      </div>
    </Modal>
  );
}

function printRecibo(d: Draft, opts: { total_cobro: number; total_imputado: number; watermark: string | null }) {
  const w = window.open('', '_blank', 'width=1024,height=768');
  if (!w) return;
  const escape = (s: string) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const fmt = (n: number) => n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const rowsFact = d.comprobantes.map((c) => `
    <tr>
      <td>${escape(fmtFecha(c.fecha))}</td>
      <td>${escape(c.numeroFactura)}</td>
      <td style="text-align:right">$${escape(fmt(c.totalFactura))}</td>
      <td style="text-align:right">$${escape(fmt(c.imputado))}</td>
    </tr>`).join('');
  const rowsNc = d.ncAplicadas.map((n) => `
    <tr>
      <td>${escape(fmtFecha(n.fecha))}</td>
      <td>${escape(n.numeroNc)}</td>
      <td style="text-align:right">-$${escape(fmt(n.saldo))}</td>
      <td style="text-align:right">-$${escape(fmt(n.monto))}</td>
    </tr>`).join('');
  const rows = rowsFact + rowsNc;
  const watermark = opts.watermark ? `<div class="wm"><span>${escape(opts.watermark)}</span></div>` : '';
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Recibo ${d.puntoVenta}-${d.numero}</title>
  <style>
    @page { size: A4 landscape; margin: 10mm; }
    body { font-family: Arial, sans-serif; color:#111827; margin:0; }
    .r { border:1px solid #111827; position:relative; }
    .wm { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; pointer-events:none; }
    .wm span { border:6px solid rgba(220,38,38,.72); color:rgba(220,38,38,.34); font-size:42px; font-weight:900; padding:18px 26px; transform:rotate(-28deg); }
    .top { display:grid; grid-template-columns:1.4fr .42fr .88fr; border-bottom:1px solid #111827; }
    .top > div { padding:10px 12px; }
    .x { border-left:1px solid #111827; border-right:1px solid #111827; text-align:center; }
    .x .l { font-size:70px; line-height:1; }
    .x .t { font-size:14px; font-weight:700; }
    .meta-t { font-size:24px; font-weight:800; text-align:center; }
    .meta-s { margin-top:4px; font-size:18px; text-align:center; }
    .grid { display:grid; grid-template-columns:1fr auto; gap:6px 16px; margin-top:18px; }
    .c { display:grid; grid-template-columns:1.8fr .9fr; gap:24px; padding:10px 12px; border-bottom:1px solid #111827; }
    .lbl { font-weight:700; }
    .amts { padding:14px 12px 16px; }
    .ag { display:grid; grid-template-columns:1fr auto; gap:6px 16px; max-width:560px; }
    .agt { font-weight:800; }
    .tbl { padding:10px 12px; border-top:1px solid #111827; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th, td { padding:4px 6px; border:1px solid #111827; }
    th { text-align:left; }
    .foot { padding:10px 12px; border-top:1px solid #111827; display:flex; justify-content:flex-end; gap:14px; font-size:15px; font-weight:800; }
  </style></head><body>
  <article class="r">
    ${watermark}
    <section class="top">
      <div>
        <div style="font-weight:bold;font-size:14px">${escape(d.empresaNombre)}</div>
        <div>${escape(d.empresaDireccion1)}</div>
        <div>${escape(d.empresaDireccion2)}</div>
        <div>${escape(d.empresaIva)}</div>
      </div>
      <div class="x"><div class="l">X</div><div class="t">DOCUMENTO<br/>NO VALIDO<br/>COMO FACTURA</div></div>
      <div>
        <div class="meta-t">RECIBO</div>
        <div class="meta-s">${escape(d.puntoVenta)} - ${escape(d.numero)}</div>
        <div class="grid">
          <span class="lbl">FECHA:</span><span>${escape(fmtFecha(d.fecha))}</span>
          <span class="lbl">CUIT:</span><span>${escape(d.empresaCuit)}</span>
          <span class="lbl">INICIO ACT.:</span><span>${escape(fmtFecha(d.empresaInicioActividad))}</span>
        </div>
      </div>
    </section>
    <section class="c">
      <div>
        <div><span class="lbl">CLIENTE</span> ${escape(d.clienteNombre || '—')}</div>
        <div><span class="lbl">DIRECCION</span> ${escape(d.clienteDireccion1 || '—')}</div>
        <div>${escape(d.clienteDireccion2 || '')}</div>
      </div>
      <div>
        <div><span class="lbl">CUIT</span> ${escape(d.clienteCuit || '—')}</div>
        <div><span class="lbl">IVA</span> ${escape(d.clienteIva || '—')}</div>
      </div>
    </section>
    <section class="amts">
      <div class="ag">
        <span class="lbl">FECHA DEL COBRO</span><span style="text-align:right">${escape(fmtFecha(d.fechaCobro))}</span>
        <span class="lbl">DETALLE DEL COBRO</span><span style="text-align:right">${escape(d.detalleCobro || '—')}</span>
        <span class="lbl">IMPORTE RECIBIDO</span><span style="text-align:right">$${escape(fmt(parseMontoEs(d.importeRecibido)))}</span>
        <span class="lbl">RETENCIONES IVA</span><span style="text-align:right">$${escape(fmt(parseMontoEs(d.retencionIva)))}</span>
        <span class="lbl">RETENCIONES IIBB</span><span style="text-align:right">$${escape(fmt(parseMontoEs(d.retencionIibb)))}</span>
        <span class="lbl">RETENCIONES GANANCIAS</span><span style="text-align:right">$${escape(fmt(parseMontoEs(d.retencionGanancias)))}</span>
        <span class="lbl agt">TOTAL COBRO</span><span class="agt" style="text-align:right">$${escape(fmt(opts.total_cobro))}</span>
      </div>
    </section>
    <section class="tbl">
      <div style="font-weight:800;margin-bottom:2px">DETALLE DE COMPROBANTES IMPUTADOS</div>
      <table><thead><tr><th>FECHA</th><th>N° FACT</th><th style="text-align:right">TOTAL FACT</th><th style="text-align:right">IMPUTADO</th></tr></thead><tbody>
      ${rows || '<tr><td colspan="4">Sin comprobantes imputados.</td></tr>'}
      </tbody></table>
    </section>
    <section class="foot"><span>TOTAL IMPUTADO</span><span>$${escape(fmt(opts.total_imputado))}</span></section>
  </article>
  <script>window.onload = () => window.print();</script>
  </body></html>`);
  w.document.close();
}
