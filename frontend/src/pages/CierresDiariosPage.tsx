import { useMemo, useState } from 'react';
import { Calendar, Lock, Wand2, Download, FileText, RefreshCw, Eye, AlertCircle } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { auth } from '@/lib/auth';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type DiaContable = {
  id: number;
  fecha: string;
  estado: 'ABIERTO' | 'EN_PROCESO' | 'CERRADO' | 'REAPERTO';
  saldos_apertura: Record<string, number> | null;
  saldos_cierre: Record<string, number> | null;
  total_movimientos: number;
  total_conciliados: number;
  total_pendientes: number;
  total_ignorados: number;
  asiento_cierre_id: number | null;
  cerrado_por: number | null;
  cerrado_at: string | null;
  observaciones: string | null;
  cerrador?: { id: number; name: string } | null;
};

type CuentaBancaria = { id: number; codigo: string; nombre: string };

function estadoColor(e: DiaContable['estado']): 'success' | 'warning' | 'info' | 'danger' | 'neutral' {
  return ({ CERRADO: 'success', EN_PROCESO: 'warning', ABIERTO: 'info', REAPERTO: 'danger' } as const)[e] ?? 'neutral';
}

function isoDate(d: Date): string {
  return d.toISOString().slice(0, 10);
}

export function CierresDiariosPage() {
  const today = new Date();
  const [mes, setMes] = useState({ anio: today.getFullYear(), mes: today.getMonth() + 1 });
  const [verDia, setVerDia] = useState<string | null>(null);
  const [iniciarFecha, setIniciarFecha] = useState<string | null>(null);
  const [ajusteFecha, setAjusteFecha] = useState<string | null>(null);

  const desde = isoDate(new Date(mes.anio, mes.mes - 1, 1));
  const hasta = isoDate(new Date(mes.anio, mes.mes, 0));

  const { data, isLoading, error, refetch } = useApi<DiaContable[]>(
    ['cierres-diarios', desde, hasta],
    `/api/erp/cierres-diarios?desde=${desde}&hasta=${hasta}`
  );

  const diasMap = useMemo(() => {
    const m = new Map<string, DiaContable>();
    (data ?? []).forEach((d) => m.set(d.fecha.slice(0, 10), d));
    return m;
  }, [data]);

  const proximoPendiente = useMemo(() => {
    const ultimoCerrado = (data ?? [])
      .filter((d) => d.estado === 'CERRADO')
      .sort((a, b) => b.fecha.localeCompare(a.fecha))[0];

    let candidato = ultimoCerrado
      ? new Date(ultimoCerrado.fecha + 'T00:00:00')
      : new Date(mes.anio, mes.mes - 1, 1);
    if (ultimoCerrado) candidato.setDate(candidato.getDate() + 1);

    const hoy0 = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    if (candidato.getTime() > hoy0.getTime()) return null;
    return isoDate(candidato);
  }, [data, mes, today]);

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><Calendar className="w-4 h-4 text-azure" /> Cierres diarios</div>
        } actions={
          <Button variant="ghost" size="sm" onClick={() => refetch()}>
            <RefreshCw className="w-3 h-3" /> Refrescar
          </Button>
        } />
        <CardBody className="p-4 space-y-4">
          <div className="text-[12.5px] text-ink-2">
            Cada día se cierra al día siguiente: subís los archivos del banco, traés MP automático,
            revisás pendientes y sellás. El saldo del día queda snapshot y los movimientos se
            estampan inmutables.
          </div>

          <SelectorMes mes={mes} onChange={setMes} />

          {error && <FormError error={errorMessage(error)} />}

          {proximoPendiente && (
            <div className="border border-azure/40 bg-azure-bg/30 rounded-md p-4 flex items-center justify-between">
              <div>
                <div className="text-[11.5px] uppercase text-ink-muted">Próximo cierre pendiente</div>
                <div className="text-[16px] font-semibold">{fmtDate(proximoPendiente)}</div>
                <div className="text-[11.5px] text-ink-muted">
                  Subí los archivos del banco y sellá el día.
                </div>
              </div>
              <Button variant="primary" onClick={() => setIniciarFecha(proximoPendiente)}>
                <Wand2 className="w-3 h-3" /> Cerrar {fmtDate(proximoPendiente)}
              </Button>
            </div>
          )}

          <CalendarioGrid mes={mes} diasMap={diasMap}
            onClickDia={(fecha) => {
              const d = diasMap.get(fecha);
              if (d) setVerDia(fecha);
              else setIniciarFecha(fecha);
            }} />

          <ListaDiasCard dias={data ?? []} loading={isLoading}
            onVerDia={(f) => setVerDia(f)}
            onAjuste={(f) => setAjusteFecha(f)} />
        </CardBody>
      </Card>

      {iniciarFecha && (
        <WizardCierreModal fecha={iniciarFecha}
          onClose={() => setIniciarFecha(null)}
          onCerrado={(f) => { setIniciarFecha(null); setVerDia(f); refetch(); }} />
      )}
      {verDia && <DetalleDiaModal fecha={verDia} onClose={() => setVerDia(null)} />}
      {ajusteFecha && <AjusteRetroModal fecha={ajusteFecha} onClose={() => setAjusteFecha(null)} />}
    </div>
  );
}

function SelectorMes({ mes, onChange }: { mes: { anio: number; mes: number }; onChange: (m: { anio: number; mes: number }) => void }) {
  const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  const prev = () => onChange(mes.mes === 1 ? { anio: mes.anio - 1, mes: 12 } : { anio: mes.anio, mes: mes.mes - 1 });
  const next = () => onChange(mes.mes === 12 ? { anio: mes.anio + 1, mes: 1 } : { anio: mes.anio, mes: mes.mes + 1 });
  return (
    <div className="flex items-center gap-2">
      <Button size="sm" variant="outline" onClick={prev}>‹</Button>
      <div className="text-[14px] font-semibold min-w-[180px] text-center">
        {meses[mes.mes - 1]} {mes.anio}
      </div>
      <Button size="sm" variant="outline" onClick={next}>›</Button>
    </div>
  );
}

function CalendarioGrid({
  mes, diasMap, onClickDia,
}: {
  mes: { anio: number; mes: number };
  diasMap: Map<string, DiaContable>;
  onClickDia: (fecha: string) => void;
}) {
  const today = new Date();
  const todayIso = isoDate(today);
  const primerDia = new Date(mes.anio, mes.mes - 1, 1);
  const cantDias = new Date(mes.anio, mes.mes, 0).getDate();
  const diaSemanaInicio = (primerDia.getDay() + 6) % 7; // lun=0

  const celdas: Array<{ fecha?: string; dia?: DiaContable }> = [];
  for (let i = 0; i < diaSemanaInicio; i++) celdas.push({});
  for (let d = 1; d <= cantDias; d++) {
    const fecha = isoDate(new Date(mes.anio, mes.mes - 1, d));
    celdas.push({ fecha, dia: diasMap.get(fecha) });
  }
  while (celdas.length % 7 !== 0) celdas.push({});

  return (
    <div>
      <div className="grid grid-cols-7 gap-1 mb-1 text-[10.5px] uppercase text-ink-muted text-center">
        {['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'].map((d) => <div key={d}>{d}</div>)}
      </div>
      <div className="grid grid-cols-7 gap-1">
        {celdas.map((c, i) => {
          if (!c.fecha) return <div key={i} className="h-[60px]" />;
          const esHoy = c.fecha === todayIso;
          const esPasado = c.fecha < todayIso;
          const estado = c.dia?.estado;
          const cls = estado === 'CERRADO'    ? 'bg-success-bg/40 border-success/40 hover:bg-success-bg/60'
                    : estado === 'EN_PROCESO' ? 'bg-warning-bg/40 border-warning/40 hover:bg-warning-bg/60'
                    : esPasado && !c.dia      ? 'bg-danger-bg/30 border-danger/30 hover:bg-danger-bg/50'
                    : esHoy                   ? 'bg-azure-bg/30 border-azure/40 hover:bg-azure-bg/50'
                    :                           'bg-white border-line hover:bg-bg-soft';
          const num = c.fecha.slice(8, 10);
          return (
            <button key={i}
              onClick={() => onClickDia(c.fecha!)}
              className={`h-[60px] border rounded-md p-1.5 text-left transition ${cls} ${esHoy ? 'ring-2 ring-azure/30' : ''}`}>
              <div className="text-[12.5px] font-semibold">{Number(num)}</div>
              {c.dia && (
                <div className="text-[9.5px] text-ink-muted truncate">
                  {c.dia.total_movimientos} movs
                  {c.dia.total_pendientes > 0 && <> · {c.dia.total_pendientes} pend.</>}
                </div>
              )}
              {!c.dia && esPasado && (
                <div className="text-[9.5px] text-danger">⚠ sin cerrar</div>
              )}
            </button>
          );
        })}
      </div>
    </div>
  );
}

function ListaDiasCard({ dias, loading, onVerDia, onAjuste }: {
  dias: DiaContable[]; loading: boolean;
  onVerDia: (fecha: string) => void; onAjuste: (fecha: string) => void;
}) {
  const cols: Column<DiaContable>[] = [
    { key: 'fecha', header: 'Fecha', width: '110px', render: (r) => fmtDate(r.fecha) },
    { key: 'estado', header: 'Estado', width: '120px',
      render: (r) => <Badge variant={estadoColor(r.estado)}>{r.estado}</Badge> },
    { key: 'movs', header: 'Movs', align: 'right', width: '80px', render: (r) => r.total_movimientos },
    { key: 'conciliados', header: 'Conciliados', align: 'right', width: '110px', render: (r) => r.total_conciliados },
    { key: 'pendientes', header: 'Pendientes', align: 'right', width: '110px',
      render: (r) => r.total_pendientes > 0
        ? <Badge variant="warning">{r.total_pendientes}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'cerrado_at', header: 'Cerrado', width: '140px',
      render: (r) => r.cerrado_at ? new Date(r.cerrado_at).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—' },
    { key: 'cerrador', header: 'Por',
      render: (r) => r.cerrador?.name ?? <span className="text-ink-muted">—</span> },
    { key: 'acciones', header: '', align: 'right', width: '160px',
      render: (r) => (
        <div className="flex justify-end gap-1.5">
          <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); onVerDia(r.fecha.slice(0, 10)); }}>
            <Eye className="w-3 h-3" /> Ver
          </Button>
          {r.estado === 'CERRADO' && (
            <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); onAjuste(r.fecha.slice(0, 10)); }}>
              <AlertCircle className="w-3 h-3" /> Ajuste
            </Button>
          )}
        </div>
      ) },
  ];
  return (
    <DataTable columns={cols} rows={dias} loading={loading}
      onRowClick={(r) => onVerDia(r.fecha.slice(0, 10))}
      empty="Sin cierres registrados en este mes" />
  );
}

// ---- Wizard de cierre (3 pasos) -------------------------------------------

type CuentaConArchivo = { cuenta_id: number; cuenta_codigo: string; archivo: File | null };

function WizardCierreModal({ fecha, onClose, onCerrado }: { fecha: string; onClose: () => void; onCerrado: (f: string) => void }) {
  const [paso, setPaso] = useState<1 | 2 | 3>(1);
  const [importarMp, setImportarMp] = useState(true);
  const [mpCuentaId, setMpCuentaId] = useState<string>('');
  const [archivos, setArchivos] = useState<CuentaConArchivo[]>([]);
  const [resumen, setResumen] = useState<unknown>(null);
  const [confirmarPendientes, setConfirmarPendientes] = useState(false);

  const { data: cuentas } = useApi<CuentaBancaria[]>(['cuentas-bancarias'], '/api/erp/cuentas-bancarias');

  const toast = useToast();
  const invalidate = useInvalidate(['cierres-diarios']);

  const iniciar = useApiMutation<{ dia: DiaContable; resumen: any }, FormData>(
    (fd) => api.post(`/api/erp/cierres-diarios/${fecha}/iniciar`, fd),
    {
      onSuccess: (res) => {
        setResumen(res);
        invalidate();
        setPaso(3);
      },
      onError: (e) => toast.error('No se pudo iniciar', errorMessage(e)),
    }
  );

  const sellar = useApiMutation<DiaContable, { confirmar_pendientes: boolean }>(
    (vars) => api.post(`/api/erp/cierres-diarios/${fecha}/sellar`, vars),
    {
      onSuccess: () => {
        toast.success('Día sellado');
        invalidate();
        onCerrado(fecha);
      },
      onError: (e) => toast.error('No se pudo sellar', errorMessage(e)),
    }
  );

  const submitIniciar = () => {
    const fd = new FormData();
    let i = 0;
    for (const a of archivos) {
      if (!a.archivo) continue;
      fd.append(`archivos[${i}][cuenta_id]`, String(a.cuenta_id));
      fd.append(`archivos[${i}][file]`, a.archivo);
      i++;
    }
    if (importarMp && mpCuentaId) {
      fd.append('importar_mp', '1');
      fd.append('mp_cuenta_id', mpCuentaId);
    }
    iniciar.mutate(fd);
  };

  const dia = (resumen as any)?.dia as DiaContable | undefined;

  return (
    <Modal open onClose={onClose} title={`Cerrar ${fmtDate(fecha)} — Paso ${paso} de 3`} size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          {paso === 1 && (
            <Button variant="primary" onClick={() => setPaso(2)}>Siguiente</Button>
          )}
          {paso === 2 && (
            <>
              <Button variant="ghost" onClick={() => setPaso(1)}>‹ Atrás</Button>
              <Button variant="primary" disabled={iniciar.isPending} onClick={submitIniciar}>
                {iniciar.isPending ? 'Procesando…' : 'Procesar archivos'}
              </Button>
            </>
          )}
          {paso === 3 && (
            <>
              <Button variant="ghost" onClick={() => setPaso(2)}>‹ Atrás</Button>
              <Button variant="primary" disabled={sellar.isPending}
                onClick={() => sellar.mutate({ confirmar_pendientes: confirmarPendientes })}>
                <Lock className="w-3 h-3" /> {sellar.isPending ? 'Sellando…' : 'Sellar día'}
              </Button>
            </>
          )}
        </>
      }>
      {paso === 1 && (
        <div className="space-y-3">
          <div className="text-[12.5px] text-ink-2">
            Asociá un archivo de extracto a cada cuenta bancaria. Mercado Pago se trae
            automático vía Reports API si activás el flag.
          </div>
          {(cuentas ?? []).map((c) => (
            <div key={c.id} className="flex items-center gap-3 border border-line rounded-md p-2">
              <div className="w-[180px] text-[12.5px]">
                <code>{c.codigo}</code> {c.nombre}
              </div>
              <input type="file" className="text-[11.5px] flex-1"
                accept=".csv,.xlsx,.xls"
                onChange={(e) => {
                  const f = e.target.files?.[0] ?? null;
                  setArchivos((prev) => {
                    const otros = prev.filter((a) => a.cuenta_id !== c.id);
                    return f ? [...otros, { cuenta_id: c.id, cuenta_codigo: c.codigo, archivo: f }] : otros;
                  });
                }} />
            </div>
          ))}
          <div className="border border-azure/30 rounded-md p-3 flex items-center gap-3 bg-azure-bg/15">
            <input type="checkbox" id="mpAuto" checked={importarMp}
              onChange={(e) => setImportarMp(e.target.checked)} />
            <label htmlFor="mpAuto" className="text-[12.5px] flex-1">Traer Mercado Pago automático (Reports API)</label>
            <SelectField label="" value={mpCuentaId} placeholder="Cuenta MP"
              onChange={(e) => setMpCuentaId(e.target.value)}
              options={(cuentas ?? []).filter((c) => c.codigo.toUpperCase().includes('MP') || c.nombre.toUpperCase().includes('MERCADO')).map((c) => ({ value: String(c.id), label: c.codigo }))}
              containerClassName="w-[160px]" />
          </div>
        </div>
      )}

      {paso === 2 && (
        <div className="space-y-3 text-[12.5px]">
          <div>Vas a procesar:</div>
          <ul className="list-disc list-inside ml-2 space-y-1">
            {archivos.filter((a) => a.archivo).map((a) => (
              <li key={a.cuenta_id}>
                <code>{a.cuenta_codigo}</code> ← <strong>{a.archivo!.name}</strong>
              </li>
            ))}
            {importarMp && mpCuentaId && (
              <li>Mercado Pago (Reports API automática) → cuenta #{mpCuentaId}</li>
            )}
          </ul>
          {iniciar.error && <FormError error={errorMessage(iniciar.error)} />}
        </div>
      )}

      {paso === 3 && dia && (
        <div className="space-y-3">
          <div className="grid grid-cols-4 gap-2 text-[12px]">
            <Stat label="Movs totales" value={dia.total_movimientos} />
            <Stat label="Conciliados" value={dia.total_conciliados} />
            <Stat label="Pendientes" value={dia.total_pendientes > 0 ? <Badge variant="warning">{dia.total_pendientes}</Badge> : 0} />
            <Stat label="Estado" value={<Badge variant={estadoColor(dia.estado)}>{dia.estado}</Badge>} />
          </div>
          {dia.saldos_cierre && (
            <div>
              <div className="text-[11.5px] uppercase font-semibold text-ink-muted mb-1">Saldos calculados</div>
              <div className="grid grid-cols-3 gap-2 text-[12px]">
                {Object.entries(dia.saldos_cierre).map(([cuentaId, saldo]) => {
                  const c = (cuentas ?? []).find((cc) => String(cc.id) === cuentaId);
                  return (
                    <div key={cuentaId} className="border border-line rounded-md p-2 bg-white">
                      <div className="text-[10.5px] uppercase text-ink-muted">{c?.codigo ?? `#${cuentaId}`}</div>
                      <div className="font-medium tabular-nums">{fmtMoney(Number(saldo))}</div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
          {dia.total_pendientes > 0 && (
            <div className="border border-warning/40 bg-warning-bg/30 rounded-md p-3 text-[12px] flex items-start gap-2">
              <AlertCircle className="w-4 h-4 text-warning mt-0.5" />
              <div className="flex-1">
                <div className="font-semibold mb-1">{dia.total_pendientes} pendientes sin etiquetar</div>
                <label className="flex items-center gap-2 text-[11.5px]">
                  <input type="checkbox" checked={confirmarPendientes}
                    onChange={(e) => setConfirmarPendientes(e.target.checked)} />
                  Sellar igual con los pendientes (quedan estampados pero conciliables después)
                </label>
              </div>
            </div>
          )}
          {sellar.error && <FormError error={errorMessage(sellar.error)} />}
        </div>
      )}
    </Modal>
  );
}

// ---- Detalle del día -------------------------------------------------------

function DetalleDiaModal({ fecha, onClose }: { fecha: string; onClose: () => void }) {
  type Movimiento = {
    id: number; fecha: string; concepto: string; comprobante_banco: string | null;
    debito: number | string; credito: number | string; saldo: number | string | null;
    estado: string; etiqueta_sugerida: string | null;
    cuentaBancaria?: { id: number; codigo: string; nombre: string };
    asiento?: { id: number; numero: string } | null;
  };
  type Detalle = {
    dia: DiaContable;
    movimientos: Movimiento[];
    ajustes_retro: Array<{ id: number; fecha_dia_afectado: string; fecha_asiento_ajuste: string; motivo: string; asiento?: { numero: string; fecha: string }; iniciador?: { name: string } }>;
  };

  const { data, isLoading, error } = useApi<Detalle>(
    ['cierres-dia-detalle', fecha],
    `/api/erp/cierres-diarios/${fecha}`
  );

  const descargar = (tipo: 'liber' | 'pdf') => {
    const url = `/api/erp/cierres-diarios/${fecha}/exportar-${tipo}`;
    const token = auth.getToken();
    if (tipo === 'pdf') {
      const win = window.open('', '_blank');
      fetch(url, { headers: { Authorization: `Bearer ${token}`, Accept: 'text/html' } })
        .then((r) => r.text())
        .then((html) => { if (win) { win.document.write(html); win.document.close(); } });
    } else {
      fetch(url, { headers: { Authorization: `Bearer ${token}` } })
        .then((r) => r.blob())
        .then((blob) => {
          const a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = `cierre_${fecha}.xlsx`;
          a.click();
        });
    }
  };

  return (
    <Modal open onClose={onClose} title={`Detalle del día ${fmtDate(fecha)}`} size="lg"
      footer={
        <>
          <Button variant="outline" onClick={() => descargar('pdf')}>
            <FileText className="w-3 h-3" /> PDF
          </Button>
          <Button variant="outline" onClick={() => descargar('liber')}>
            <Download className="w-3 h-3" /> Excel LIBER
          </Button>
          <Button variant="secondary" onClick={onClose}>Cerrar</Button>
        </>
      }>
      {isLoading && <div className="py-8 text-center text-ink-muted">Cargando…</div>}
      {error && <FormError error={errorMessage(error)} />}
      {data && (
        <div className="space-y-3">
          <div className="grid grid-cols-4 gap-2 text-[12px]">
            <Stat label="Estado" value={<Badge variant={estadoColor(data.dia.estado)}>{data.dia.estado}</Badge>} />
            <Stat label="Movs" value={data.dia.total_movimientos} />
            <Stat label="Conciliados" value={data.dia.total_conciliados} />
            <Stat label="Pendientes" value={data.dia.total_pendientes} />
          </div>

          {data.dia.saldos_cierre && (
            <div>
              <div className="text-[11.5px] uppercase font-semibold text-ink-muted mb-1">Saldos por cuenta</div>
              <table className="w-full text-[12px]">
                <thead className="bg-bg-soft text-[11px] uppercase text-ink-muted">
                  <tr>
                    <th className="p-2 text-left">Cuenta</th>
                    <th className="p-2 text-right">Apertura</th>
                    <th className="p-2 text-right">Cierre</th>
                    <th className="p-2 text-right">Δ</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.keys({ ...(data.dia.saldos_apertura ?? {}), ...(data.dia.saldos_cierre ?? {}) }).map((cid) => {
                    const ap = data.dia.saldos_apertura?.[cid] ?? 0;
                    const ci = data.dia.saldos_cierre?.[cid] ?? 0;
                    const delta = Number(ci) - Number(ap);
                    return (
                      <tr key={cid} className="border-t border-line/60">
                        <td className="p-2"><code>#{cid}</code></td>
                        <td className="p-2 text-right tabular-nums">{fmtMoney(Number(ap))}</td>
                        <td className="p-2 text-right tabular-nums font-semibold">{fmtMoney(Number(ci))}</td>
                        <td className={`p-2 text-right tabular-nums ${delta >= 0 ? 'text-success' : 'text-danger'}`}>{fmtMoney(delta)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          <div>
            <div className="text-[11.5px] uppercase font-semibold text-ink-muted mb-1">
              Movimientos ({data.movimientos.length})
            </div>
            <div className="max-h-[280px] overflow-auto border border-line rounded-md">
              <table className="w-full text-[11.5px]">
                <thead className="bg-bg-soft text-[10.5px] uppercase text-ink-muted sticky top-0">
                  <tr>
                    <th className="p-2 text-left">Cuenta</th>
                    <th className="p-2 text-left">Concepto</th>
                    <th className="p-2 text-right">Débito</th>
                    <th className="p-2 text-right">Crédito</th>
                    <th className="p-2 text-right">Saldo</th>
                    <th className="p-2 text-left">Estado</th>
                  </tr>
                </thead>
                <tbody>
                  {data.movimientos.length === 0
                    ? <tr><td colSpan={6} className="p-4 text-center text-ink-muted">Sin movimientos</td></tr>
                    : data.movimientos.map((m) => (
                      <tr key={m.id} className="border-t border-line/60">
                        <td className="p-2"><code>{m.cuentaBancaria?.codigo ?? '—'}</code></td>
                        <td className="p-2">{m.concepto}</td>
                        <td className="p-2 text-right tabular-nums">{Number(m.debito) > 0 ? fmtMoney(Number(m.debito)) : ''}</td>
                        <td className="p-2 text-right tabular-nums">{Number(m.credito) > 0 ? fmtMoney(Number(m.credito)) : ''}</td>
                        <td className="p-2 text-right tabular-nums">{m.saldo !== null ? fmtMoney(Number(m.saldo)) : ''}</td>
                        <td className="p-2">
                          <Badge variant={m.estado === 'CONCILIADO' ? 'success' : m.estado === 'ETIQUETADO' ? 'info' : 'warning'}>{m.estado}</Badge>
                        </td>
                      </tr>
                    ))}
                </tbody>
              </table>
            </div>
          </div>

          {data.ajustes_retro.length > 0 && (
            <div>
              <div className="text-[11.5px] uppercase font-semibold text-ink-muted mb-1">
                Ajustes retroactivos ({data.ajustes_retro.length})
              </div>
              <ul className="text-[11.5px] space-y-1">
                {data.ajustes_retro.map((a) => (
                  <li key={a.id} className="border border-line rounded-md p-2 bg-bg-soft">
                    <Badge variant="default">Asiento {a.asiento?.numero}</Badge>{' '}
                    fecha_ajuste: {fmtDate(a.fecha_asiento_ajuste)} · por {a.iniciador?.name ?? '—'}
                    <div className="text-[11px] text-ink-muted mt-1">{a.motivo}</div>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}

// ---- Ajuste retroactivo ----------------------------------------------------

function AjusteRetroModal({ fecha, onClose }: { fecha: string; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['cierres-diarios', 'cierres-dia-detalle']);
  const [form, setForm] = useState({
    motivo: '',
    cuenta_debe_id: '', cuenta_haber_id: '',
    importe: '', glosa: '',
  });
  const m = useApiMutation(
    () => api.post(`/api/erp/cierres-diarios/${fecha}/ajuste-retroactivo`, {
      motivo: form.motivo,
      asiento: {
        cuenta_debe_id: Number(form.cuenta_debe_id),
        cuenta_haber_id: Number(form.cuenta_haber_id),
        importe: Number(form.importe),
        glosa: form.glosa || undefined,
      },
    }),
    {
      onSuccess: () => { toast.success('Ajuste retroactivo creado'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo crear el ajuste', errorMessage(e)),
    }
  );

  const valid = form.motivo.length >= 5 && form.cuenta_debe_id && form.cuenta_haber_id && Number(form.importe) > 0;

  return (
    <Modal open onClose={onClose} title={`Ajuste retroactivo del ${fmtDate(fecha)}`} size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" disabled={!valid || m.isPending}
            onClick={() => m.mutate(undefined as unknown as void)}>
            {m.isPending ? 'Creando…' : 'Crear ajuste forward'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <div className="text-[12px] bg-warning-bg/40 border border-warning/30 rounded-md p-3">
          El día original NO se modifica. Se crea un asiento con fecha <strong>de hoy</strong> y
          glosa "Ajuste retroactivo del {fmtDate(fecha)} · {'{motivo}'}".
        </div>
        <TextareaField label="Motivo" required rows={2} value={form.motivo}
          onChange={(e) => setForm({ ...form, motivo: e.target.value })}
          placeholder="Ej: olvido carga de movimiento Brubank del día" />
        <div className="grid grid-cols-2 gap-3">
          <Field label="ID cuenta DEBE" required type="number" value={form.cuenta_debe_id}
            onChange={(e) => setForm({ ...form, cuenta_debe_id: e.target.value })} />
          <Field label="ID cuenta HABER" required type="number" value={form.cuenta_haber_id}
            onChange={(e) => setForm({ ...form, cuenta_haber_id: e.target.value })} />
        </div>
        <Field label="Importe" required type="number" step="0.01" min={0.01} value={form.importe}
          onChange={(e) => setForm({ ...form, importe: e.target.value })} />
        <Field label="Glosa (opcional)" value={form.glosa}
          onChange={(e) => setForm({ ...form, glosa: e.target.value })} />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="border border-line rounded-md p-2 bg-white">
      <div className="text-[10.5px] uppercase text-ink-muted">{label}</div>
      <div className="font-medium tabular-nums">{value}</div>
    </div>
  );
}
