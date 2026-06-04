import { useEffect, useMemo, useState } from 'react';
import { Receipt, Plus, Search, Printer, Trash2, Ban, AlertTriangle, Save } from 'lucide-react';
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
  puntoVenta: '00001',
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
  // Sacamos espacios, símbolo de peso, NBSP. Cualquier cosa que no sea
  // dígito/coma/punto/menos se descarta acá.
  const str = String(s).replace(/[\s$ ]/g, '').trim();
  if (!str) return 0;
  const tieneComa = str.includes(',');
  const tienePunto = str.includes('.');

  // Caso 1: tiene ambos. El SEGUNDO separador (el más a la derecha) es el
  // decimal; el otro es el de miles. AR: 13.000,26 / US: 13,000.26.
  if (tieneComa && tienePunto) {
    const lastComma = str.lastIndexOf(',');
    const lastDot = str.lastIndexOf('.');
    if (lastComma > lastDot) {
      return Number(str.replace(/\./g, '').replace(',', '.')) || 0; // AR
    }
    return Number(str.replace(/,/g, '')) || 0; // US
  }

  // Caso 2: solo coma. En AR la coma es decimal salvo que vengan grupos
  // múltiples (raro). "13,5" → 13.5; "13,50" → 13.50; "13,000" → 13.0.
  if (tieneComa) {
    const parts = str.split(',');
    if (parts.length > 2) return Number(str.replace(/,/g, '')) || 0;
    return Number(str.replace(',', '.')) || 0;
  }

  // Caso 3: solo punto. Acá está el quilombo de los <input type=number>
  // y los pegados desde Excel:
  //  - "56.78" / "0.5" → decimal (input type=number).
  //  - "13.000" → AR miles (pegado de Excel): 1 punto, 3 dígitos atrás.
  //  - "1.000.000" → AR miles: varios puntos.
  // Heurística: si hay 2+ puntos, son todos miles. Si hay 1 punto con
  // EXACTAMENTE 3 dígitos atrás y al menos 1 dígito adelante, también es
  // miles. En cualquier otro caso es decimal.
  if (tienePunto) {
    const parts = str.split('.');
    if (parts.length > 2) return Number(str.replace(/\./g, '')) || 0;
    const trailing = parts[1] ?? '';
    if (trailing.length === 3 && /^\d+$/.test(parts[0]) && parts[0].length >= 1) {
      return Number(str.replace('.', '')) || 0;
    }
    return Number(str) || 0;
  }

  // Caso 4: sin separadores.
  return Number(str) || 0;
}
function fmtFecha(s?: string | null): string {
  if (!s) return '—';
  const m = String(s).match(/(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : s;
}

// v1.32 — zero-pad para PV (5 dígitos) y Número (8 dígitos) de recibos.
// Si el valor está vacío o no es solo dígitos, lo devolvemos como está
// (no rompemos lo que el usuario tipea mientras edita).
function padPv(s: string): string {
  const t = String(s ?? '').trim();
  return /^\d+$/.test(t) ? t.padStart(5, '0') : t;
}
function padNumero(s: string): string {
  const t = String(s ?? '').trim();
  return /^\d+$/.test(t) ? t.padStart(8, '0') : t;
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
  // El campo se llama "Medio de cobro" pero en este ERP FK a erp_cuentas_bancarias:
  // representa la cuenta/caja destino (es lo que tiene la cuenta_contable_id que el
  // asiento de cobro debita). Las opciones del dropdown son cuentas tipo "Banco
  // Supervielle cta. operativa", "Caja chica", etc. El TIPO de instrumento
  // (transferencia/efectivo/cheque) lo describe el usuario en "Detalle cobro".
  //
  // Antes el dropdown salía vacío por bug de shape: useApi YA desempaqueta el
  // .data del response (return resp.data), así que el data es el array directo.
  // Tipar como {data: array} y luego acceder .data daba undefined → [].
  const { data: bancos = [] } = useApi<Array<{ id: number; nombre: string; codigo?: string }>>(
    ['cuentas-bancarias'], '/api/erp/cuentas-bancarias',
  );

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
      setDraft((d) => ({ ...d, numero: padNumero(proximoNumeroResp.numero) }));
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
  // v1.32 — Un recibo es editable si es nuevo (sin seleccionar) o si el
  // seleccionado está en BORRADOR. EMITIDO/CONCILIADO/ANULADO: read-only.
  const esEditable = !selectedReciboId || reciboDetalle?.estado === 'BORRADOR';
  useEffect(() => {
    if (reciboDetalle) {
      setDraft({
        puntoVenta: padPv(reciboDetalle.punto_venta ?? '00001'),
        numero: padNumero(reciboDetalle.numero ?? ''),
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
  // Monto cobrable = total que se está saldando con este recibo (= total
  // imputado neto de NC). Las retenciones no se restan acá: forman parte del
  // total cobrado (el cliente las paga al fisco en lugar de a nosotros).
  // Así Monto cobrable == Total cobro (recibido + ret) == TOTAL IMPUTADO del
  // recibo impreso. El cash neto que entra es `importeRecibido`.
  const montoCobrable = Math.max(0, totalImputado - totalNc);
  // Validación del recibo: los 4 importes (Monto cobrable / Total imputado del
  // recibo impreso / Total cobro recibido+ret / TOTAL COBRO impreso) deben
  // coincidir. Si no, hay que bloquear "Emitir e imprimir" y mostrar la
  // diferencia (positiva = falta cargar; negativa = sobra).
  const diferenciaCobro = Math.round((totalCobro - montoCobrable) * 100) / 100;
  const cobroCuadra = Math.abs(diferenciaCobro) < 0.01;

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
  // Construye el body común para crear/actualizar un recibo.
  const armarBodyRecibo = () => ({
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
    monto_cobrado: parseMontoEs(draft.importeRecibido),
    medio_cobro_id: draft.medioCobroId ? Number(draft.medioCobroId) : null,
    retencion_iva_total: parseMontoEs(draft.retencionIva),
    retencion_iibb_total: parseMontoEs(draft.retencionIibb),
    retencion_ganancias_total: parseMontoEs(draft.retencionGanancias),
    observaciones: draft.observaciones || null,
  });

  // v1.32 — Guarda como BORRADOR sin emitir. No exige cobroCuadra (un borrador
  // puede quedar incompleto). Si hay un recibo seleccionado en BORRADOR, hace
  // PATCH; si no, hace POST (nuevo borrador).
  const handleGuardarBorrador = async () => {
    if (!draft.clienteId) { toast.error('Cliente requerido', 'Seleccioná un cliente.'); return; }
    if (draft.comprobantes.length === 0) {
      toast.error('Sin comprobantes', 'Agregá al menos una factura.');
      return;
    }
    try {
      const body = { ...armarBodyRecibo(), auto_imputar_nc: false };
      let reciboId: number;
      if (selectedReciboId && reciboDetalle?.estado === 'BORRADOR') {
        const updated = await api.patch<{ data: Recibo }>(`/api/erp/tesoreria/recibos/${selectedReciboId}`, body);
        reciboId = updated.data.id;
        toast.success('Borrador actualizado');
      } else {
        const created = await api.post<{ data: Recibo }>('/api/erp/tesoreria/recibos', body);
        reciboId = created.data.id;
        toast.success('Borrador guardado', 'Podés seguir editándolo o emitirlo más tarde.');
      }
      invalidate();
      setSelectedReciboId(reciboId);
    } catch (e) {
      toast.error('No se pudo guardar', (e as ApiError).message);
    }
  };

  const handleEmitirEImprimir = async () => {
    if (!draft.clienteId) { toast.error('Cliente requerido', 'Seleccioná un cliente.'); return; }
    if (draft.comprobantes.length === 0) {
      toast.error('Sin comprobantes', 'Agregá al menos una factura.');
      return;
    }
    if (!cobroCuadra) {
      toast.error('El cobro no cuadra',
        `Diferencia ${diferenciaCobro >= 0 ? '+' : ''}${fmtMoney(diferenciaCobro)} — ajustá importe recibido o retenciones para que el total cobro = monto cobrable.`);
      return;
    }
    try {
      const body = { ...armarBodyRecibo(), auto_imputar_nc: false };
      // Si estamos editando un BORRADOR existente, PATCH antes de emitir.
      // Si es uno nuevo, POST para crearlo en BORRADOR.
      let reciboId: number;
      if (selectedReciboId && reciboDetalle?.estado === 'BORRADOR') {
        const updated = await api.patch<{ data: Recibo }>(`/api/erp/tesoreria/recibos/${selectedReciboId}`, body);
        reciboId = updated.data.id;
      } else {
        const created = await api.post<{ data: Recibo }>('/api/erp/tesoreria/recibos', body);
        reciboId = created.data.id;
      }
      const emitido = await api.post<{ data: { recibo_id: number; estado: string } }>(`/api/erp/tesoreria/recibos/${reciboId}/emitir`, {});
      toast.success('Recibo emitido', `Recibo ${padPv(draft.puntoVenta)}-${padNumero(draft.numero)}`);
      invalidate();
      printRecibo(draft, { total_cobro: totalCobro, total_imputado: totalImputado - totalNc, watermark: null });
      if (draft.autoIncrementar) {
        const next = await api.get<ProximoNumero>(`/api/erp/tesoreria/recibos/proximo-numero?pv=${draft.puntoVenta}`);
        setSelectedReciboId(null);
        setDraft({ ...DRAFT_INICIAL, numero: padNumero(next.numero) });
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
                {fmtFecha(r.fecha_emision)} · {fmtMoney(r.monto_cobrado)}
              </div>
            </button>
          ))}
        </div>
      </div>

      {/* Main — Form + Preview */}
      <div className="flex-1 overflow-auto p-3 space-y-3">
        {/* Header acciones */}
        <div className="flex items-center justify-between gap-2 sticky top-0 bg-surface-bg z-10 pb-2 pt-1 border-b border-line shadow-sm">
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
              <Plus className="w-3 h-3" /> Nuevo recibo
            </Button>
            {esEditable && (
              <Button variant="secondary" size="sm" onClick={handleGuardarBorrador}
                disabled={crearMut.isPending}
                title="Guarda como borrador sin emitir. Lo podés seguir editando o emitir más tarde.">
                <Save className="w-3 h-3" /> Guardar borrador
              </Button>
            )}
            {esEditable && (
              <Button variant="primary" size="sm" onClick={handleEmitirEImprimir}
                disabled={crearMut.isPending || !cobroCuadra}
                title={cobroCuadra ? '' : `Falta cuadrar el cobro: diferencia ${diferenciaCobro >= 0 ? '+' : ''}${fmtMoney(diferenciaCobro)}`}>
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
                    onBlur={(e) => setDraft({ ...draft, puntoVenta: padPv(e.target.value) })}
                    placeholder="00001"
                    disabled={!esEditable} />
                  <Field label="Número" value={draft.numero}
                    onChange={(e) => setDraft({ ...draft, numero: e.target.value })}
                    onBlur={(e) => setDraft({ ...draft, numero: padNumero(e.target.value) })}
                    placeholder="00000001"
                    disabled={!esEditable}
                    hint={proximoNumeroResp?.consultado_distriapp ? `Distri max=${proximoNumeroResp.max_distriapp}` : undefined} />
                  <Field label="Fecha recibo *" type="date" value={draft.fecha}
                    onChange={(e) => setDraft({ ...draft, fecha: e.target.value })}
                    disabled={!esEditable} />
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
                  disabled={!esEditable}
                  options={[{ value: '', label: clientes.length === 0 ? 'Cargando…' : 'Elegí cliente…' },
                    ...clientes.map((c) => ({
                      value: String(c.id),
                      label: `${c.nombre}${c.facturas_pendientes ? ` · ${c.facturas_pendientes} fact. pend.` : ''}`,
                    }))]} />
                <div className="grid grid-cols-2 gap-2 text-[11.5px]">
                  <Field label="CUIT" value={draft.clienteCuit}
                    onChange={(e) => setDraft({ ...draft, clienteCuit: e.target.value })}
                    disabled={!esEditable} />
                  <Field label="Cond. IVA" value={draft.clienteIva}
                    onChange={(e) => setDraft({ ...draft, clienteIva: e.target.value })}
                    disabled={!esEditable} />
                  <Field label="Dirección" value={draft.clienteDireccion1}
                    onChange={(e) => setDraft({ ...draft, clienteDireccion1: e.target.value })}
                    disabled={!esEditable} />
                  <Field label="Localidad" value={draft.clienteDireccion2}
                    onChange={(e) => setDraft({ ...draft, clienteDireccion2: e.target.value })}
                    disabled={!esEditable} />
                </div>
              </CardBody>
            </Card>

            <Card>
              <CardHeader title={
                <div className="flex items-center justify-between">
                  <div className="text-[12px] font-semibold">Comprobantes imputados ({draft.comprobantes.length})</div>
                  {esEditable && draft.clienteId && (
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
                      {esEditable && <th></th>}
                    </tr></thead>
                    <tbody>
                      {draft.comprobantes
                        // Orden desc por numeroFactura (numeric:true → 0002-00000600 > 0002-00000099).
                        // Mantengo el `originalIdx` para que delete/edit apunten al item real.
                        .map((c, originalIdx) => ({ c, i: originalIdx }))
                        .sort((a, b) => b.c.numeroFactura.localeCompare(a.c.numeroFactura, undefined, { numeric: true }))
                        .map(({ c, i }) => {
                        const imputadoInvalido = c.imputado <= 0 || c.imputado > c.totalFactura + 0.01;
                        return (
                        <tr key={i} className="border-t border-line">
                          <td className="px-1">{fmtFecha(c.fecha)}</td>
                          <td className="font-mono text-[13px] font-semibold">{c.numeroFactura}</td>
                          <td className="text-right tabular">{fmtMoney(c.totalFactura)}</td>
                          <td className="text-right tabular font-semibold">
                            {esEditable ? (
                              <div className="inline-flex items-center gap-1 justify-end">
                                <input
                                  type="text" inputMode="decimal"
                                  value={Number.isFinite(c.imputado) ? c.imputado.toFixed(2) : ''}
                                  onChange={(e) => {
                                    const v = parseMontoEs(e.target.value);
                                    setDraft({
                                      ...draft,
                                      comprobantes: draft.comprobantes.map((cc, idx) =>
                                        idx === i ? { ...cc, imputado: v } : cc),
                                    });
                                  }}
                                  title={imputadoInvalido
                                    ? `Debe ser > 0 y ≤ ${fmtMoney(c.totalFactura)}`
                                    : ''}
                                  className={`w-[140px] px-1.5 py-0.5 text-right tabular border rounded focus:outline-none ${
                                    imputadoInvalido
                                      ? 'border-danger text-danger focus:border-danger'
                                      : 'border-azure-soft focus:border-azure'}`} />
                                <button
                                  type="button"
                                  onClick={() => setDraft({
                                    ...draft,
                                    comprobantes: draft.comprobantes.map((cc, idx) =>
                                      idx === i ? { ...cc, imputado: cc.totalFactura } : cc),
                                  })}
                                  title="Imputar el total de la factura"
                                  className="px-1 text-[10px] text-azure hover:bg-azure-soft/40 rounded cursor-pointer">
                                  total
                                </button>
                              </div>
                            ) : (
                              <>{fmtMoney(c.imputado)}</>
                            )}
                          </td>
                          {esEditable && (
                            <td className="text-right">
                              <button onClick={() => setDraft({ ...draft, comprobantes: draft.comprobantes.filter((_, idx) => idx !== i) })}>
                                <Trash2 className="w-3 h-3 text-danger" />
                              </button>
                            </td>
                          )}
                        </tr>
                        );
                      })}
                      <tr className="font-semibold border-t border-line bg-azure-soft/10">
                        <td colSpan={3} className="text-right px-1">Total imputado</td>
                        <td className="text-right tabular">{fmtMoney(totalImputado)}</td>
                        {esEditable && <td></td>}
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
                      {esEditable && <th></th>}
                    </tr></thead>
                    <tbody>
                      {draft.ncAplicadas.map((n, i) => (
                        <tr key={i} className="border-t border-line bg-success-bg/10">
                          <td className="px-1 font-mono">{n.numeroNc}</td>
                          <td className="text-right tabular">{fmtMoney(n.saldo)}</td>
                          <td className="text-right tabular font-semibold text-success">−{fmtMoney(n.monto)}</td>
                          {esEditable && (
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
                        <td className="text-right tabular text-success">−{fmtMoney(totalNc)}</td>
                        {esEditable && <td></td>}
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
                    disabled={!esEditable} />
                  <Field label="Detalle cobro" value={draft.detalleCobro}
                    onChange={(e) => setDraft({ ...draft, detalleCobro: e.target.value })}
                    placeholder="ECHEQ BANCO X N°..."
                    disabled={!esEditable} />
                  <Field label="Importe recibido" type="text" inputMode="decimal"
                    value={draft.importeRecibido}
                    onChange={(e) => setDraft({ ...draft, importeRecibido: e.target.value })}
                    placeholder="0,00 o 13.000,26"
                    disabled={!esEditable} />
                  <SelectField label="Medio de cobro" value={draft.medioCobroId}
                    onChange={(e) => setDraft({ ...draft, medioCobroId: e.target.value })}
                    disabled={!esEditable}
                    options={[{ value: '', label: '—' },
                      ...bancos.map((b) => ({ value: String(b.id), label: b.nombre }))]} />
                </div>
                <div className="grid grid-cols-3 gap-2 text-[11.5px]">
                  <Field label="Ret IVA" type="text" inputMode="decimal"
                    value={draft.retencionIva}
                    onChange={(e) => setDraft({ ...draft, retencionIva: e.target.value })}
                    disabled={!esEditable} />
                  <Field label="Ret IIBB" type="text" inputMode="decimal"
                    value={draft.retencionIibb}
                    onChange={(e) => setDraft({ ...draft, retencionIibb: e.target.value })}
                    disabled={!esEditable} />
                  <Field label="Ret Ganancias" type="text" inputMode="decimal"
                    value={draft.retencionGanancias}
                    onChange={(e) => setDraft({ ...draft, retencionGanancias: e.target.value })}
                    disabled={!esEditable} />
                </div>
                <div className="text-[11.5px] space-y-0.5 bg-azure-soft/10 border border-azure-soft rounded p-2">
                  <div className="flex justify-between"><span>Total imputado (facturas):</span><span className="tabular">{fmtMoney(totalImputado)}</span></div>
                  {totalNc > 0 && <div className="flex justify-between text-success"><span>− NC aplicadas:</span><span className="tabular">−{fmtMoney(totalNc)}</span></div>}
                  <div className="flex justify-between font-semibold border-t border-azure-soft pt-0.5">
                    <span>Monto cobrable:</span><span className="tabular">{fmtMoney(montoCobrable)}</span>
                  </div>
                  {totalRet > 0 && (
                    <div className="flex justify-between text-ink-muted text-[11px]">
                      <span>Retenciones (las paga el cliente al fisco):</span>
                      <span className="tabular">{fmtMoney(totalRet)}</span>
                    </div>
                  )}
                  <div className="flex justify-between font-bold">
                    <span>Total cobro (recibido + ret):</span><span className="tabular">{fmtMoney(totalCobro)}</span>
                  </div>
                  {!cobroCuadra && (
                    <div className="mt-1 -mx-2 -mb-2 px-2 py-1.5 border-t border-danger/40 bg-danger-bg/60 text-danger flex justify-between items-center font-semibold">
                      <span>
                        {diferenciaCobro > 0
                          ? '⚠ Sobra (recibido + ret > cobrable):'
                          : '⚠ Falta cargar (recibido + ret < cobrable):'}
                      </span>
                      <span className="tabular">{fmtMoney(Math.abs(diferenciaCobro))}</span>
                    </div>
                  )}
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
            <div className="text-[10px]">{padPv(draft.puntoVenta)} - {padNumero(draft.numero) || '00000000'}</div>
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
            <span className="font-bold">IMPORTE RECIBIDO</span><span className="text-right">{fmtMoney(parseMontoEs(draft.importeRecibido))}</span>
            {draft.ncAplicadas.length > 0 && (
              <>
                <span className="font-bold">NOTAS DE CRÉDITO</span>
                <span className="text-right">−{fmtMoney(draft.ncAplicadas.reduce((s, n) => s + n.monto, 0))}</span>
              </>
            )}
            <span className="font-bold">RETENCIONES IVA</span><span className="text-right">{fmtMoney(parseMontoEs(draft.retencionIva))}</span>
            <span className="font-bold">RETENCIONES IIBB</span><span className="text-right">{fmtMoney(parseMontoEs(draft.retencionIibb))}</span>
            <span className="font-bold">RETENCIONES GANANCIAS</span><span className="text-right">{fmtMoney(parseMontoEs(draft.retencionGanancias))}</span>
            <span className="font-extrabold">TOTAL COBRO</span><span className="text-right font-extrabold">{fmtMoney(totalCobro)}</span>
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
                      <td className="border border-black px-1 text-right">{fmtMoney(c.totalFactura)}</td>
                      <td className="border border-black px-1 text-right">{fmtMoney(c.imputado)}</td>
                    </tr>
                  ))}
                  {draft.ncAplicadas.map((n, i) => (
                    <tr key={`n${i}`}>
                      <td className="border border-black px-1">{fmtFecha(n.fecha)}</td>
                      <td className="border border-black px-1 font-mono">{n.numeroNc}</td>
                      <td className="border border-black px-1 text-right">−{fmtMoney(n.saldo)}</td>
                      <td className="border border-black px-1 text-right">−{fmtMoney(n.monto)}</td>
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
          <span>{fmtMoney(totalImputado - draft.ncAplicadas.reduce((s, n) => s + n.monto, 0))}</span>
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
  // Orden descendente por numero_completo (las más nuevas primero).
  // Uso localeCompare con `numeric:true` para que "0002-00000600" > "0002-00000099".
  const ordenDesc = <T extends { numero_completo: string }>(arr: T[]) =>
    [...arr].sort((a, b) => b.numero_completo.localeCompare(a.numero_completo, undefined, { numeric: true }));
  const facturasFilt = useMemo(() => {
    const base = !q ? facturasDisp
      : facturasDisp.filter((f) => f.numero_completo.toLowerCase().includes(q) || String(f.fecha_emision).includes(q));
    return ordenDesc(base);
  }, [facturasDisp, q]);
  const ncsFilt = useMemo(() => {
    const base = !q ? ncsDisp
      : ncsDisp.filter((n) => n.numero_completo.toLowerCase().includes(q) || String(n.fecha_emision).includes(q));
    return ordenDesc(base);
  }, [ncsDisp, q]);

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
              <span className="text-[11.5px] text-ink-muted">Seleccionadas: <strong>{fmtMoney(totalFact)}</strong></span>
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
                      <td className="font-mono text-[13px] font-semibold">{f.tipo} {f.numero_completo}</td>
                      <td>{fmtFecha(f.fecha_emision)}</td>
                      <td className="text-right tabular">{fmtMoney(f.imp_total)}</td>
                      <td className="text-right tabular font-semibold">{fmtMoney(f.saldo)}</td>
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
              <span className="text-[11.5px] text-ink-muted">Seleccionadas: <strong className="text-success">−{fmtMoney(totalNc)}</strong></span>
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
                      <td className="font-mono text-[13px] font-semibold">{n.tipo} {n.numero_completo}</td>
                      <td>{fmtFecha(n.fecha_emision)}</td>
                      <td className="text-right tabular">{fmtMoney(n.imp_total)}</td>
                      <td className="text-right tabular font-semibold text-success">{fmtMoney(n.saldo_imputable)}</td>
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
  const pvPad = padPv(d.puntoVenta);
  const nroPad = padNumero(d.numero);
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Recibo ${pvPad}-${nroPad}</title>
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
        <div class="meta-s">${escape(pvPad)} - ${escape(nroPad)}</div>
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
