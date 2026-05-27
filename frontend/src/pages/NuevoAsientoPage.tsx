import { useMemo, useState } from 'react';
import { Check, Loader2, Plus, Trash2, Bookmark, Save } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { SelectorCuentaContable } from '@/components/contabilidad/SelectorCuentaContable';
import { useToast } from '@/hooks/useToast';

type Diario = { id: number; codigo: string; nombre: string; tipo: string };
type Cuenta = {
  id: number;
  codigo: string;
  nombre: string;
  imputable: boolean;
  admite_cc: boolean;
  admite_auxiliar: boolean;
  tipo_auxiliar: string | null;
};
type CC = { id: number; codigo: string; nombre: string };
type Auxiliar = { id: number; codigo: string; nombre: string; tipo: string };
type Periodo = { id: number; anio: number; mes: number; fecha_inicio: string; fecha_fin: string; estado: string };
type Plantilla = { id: number; codigo: string; nombre: string; descripcion: string | null; diario_id: number };
type PlantillaDetalle = {
  id: number; nombre: string; diario_id: number | null;
  glosa_default: string | null; observaciones_default: string | null;
  lineas: Array<{ cuenta_id: number | null; cuenta_codigo: string; centro_costo_id: number | null;
    auxiliar_id: number | null; glosa: string; debe: number; haber: number }>;
};

type Linea = {
  id: string;
  cuenta_id: number | null;
  cuenta_codigo: string; // lo que se ve / escribe
  centro_costo_id: number | null;
  auxiliar_id: number | null;
  glosa: string;
  debe: number;
  haber: number;
};

function emptyLinea(): Linea {
  return {
    id: crypto.randomUUID(),
    cuenta_id: null,
    cuenta_codigo: '',
    centro_costo_id: null,
    auxiliar_id: null,
    glosa: '',
    debe: 0,
    haber: 0,
  };
}

function parseMoney(s: string): number {
  const clean = s.replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
  const n = Number(clean);
  return Number.isFinite(n) ? n : 0;
}

export function NuevoAsientoPage() {
  const qc = useQueryClient();
  const toast = useToast();

  const { data: diariosResp } = useQuery<{ data: Diario[] }>({
    queryKey: ['diarios'],
    queryFn: () => api.get('/api/erp/diarios'),
  });
  const { data: cuentasResp } = useQuery<{ data: Cuenta[] }>({
    queryKey: ['cuentas', 'imputables'],
    queryFn: () => api.get('/api/erp/cuentas?imputable=true'),
  });
  const { data: ccResp } = useQuery<{ data: CC[] }>({
    queryKey: ['centros-costo'],
    queryFn: () => api.get('/api/erp/centros-costo'),
  });
  const { data: auxResp } = useQuery<{ data: Auxiliar[] }>({
    queryKey: ['auxiliares'],
    queryFn: () => api.get('/api/erp/auxiliares'),
  });
  const { data: periodoResp } = useQuery<{ data: Periodo | null }>({
    queryKey: ['periodo', 'abierto'],
    queryFn: () => api.get('/api/erp/periodos/abierto'),
  });

  const diarios = diariosResp?.data ?? [];
  const cuentas = cuentasResp?.data ?? [];
  const ccs = ccResp?.data ?? [];
  const auxiliares = auxResp?.data ?? [];

  const [diarioId, setDiarioId] = useState<number | null>(null);
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10));
  const [glosa, setGlosa] = useState('');
  const [observaciones, setObservaciones] = useState(''); // v1.15 Sprint M
  const [lineas, setLineas] = useState<Linea[]>([emptyLinea(), emptyLinea()]);
  const [resultado, setResultado] = useState<{ ok: string; detalle?: string } | null>(null);

  // Plantillas/modelos de asiento (asientos repetitivos).
  const [plantillaSel, setPlantillaSel] = useState('');
  const [guardarOpen, setGuardarOpen] = useState(false);
  const { data: plantillasResp } = useQuery<{ data: Plantilla[] }>({
    queryKey: ['asiento-plantillas'],
    queryFn: () => api.get('/api/erp/asiento-plantillas'),
  });
  const plantillas = plantillasResp?.data ?? [];

  async function aplicarPlantilla(id: string) {
    setPlantillaSel(id);
    if (!id) return;
    try {
      const resp = await api.get<{ data: PlantillaDetalle }>(`/api/erp/asiento-plantillas/${id}`);
      const p = resp.data;
      if (p.diario_id) setDiarioId(p.diario_id);
      if (p.glosa_default) setGlosa(p.glosa_default);
      if (p.observaciones_default) setObservaciones(p.observaciones_default);
      setLineas(p.lineas.length
        ? p.lineas.map((l) => ({
            id: crypto.randomUUID(),
            cuenta_id: l.cuenta_id,
            cuenta_codigo: l.cuenta_codigo,
            centro_costo_id: l.centro_costo_id,
            auxiliar_id: l.auxiliar_id,
            glosa: l.glosa,
            debe: Number(l.debe),
            haber: Number(l.haber),
          }))
        : [emptyLinea(), emptyLinea()]);
      toast.success('Plantilla cargada', p.nombre);
    } catch (e) {
      toast.error('No se pudo cargar la plantilla', e instanceof ApiError ? e.message : 'Error');
    }
  }

  const guardarPlantilla = useMutation<{ data: { id: number } }, ApiError, { nombre: string; descripcion: string }>({
    mutationFn: (body) => api.post('/api/erp/asiento-plantillas', {
      nombre: body.nombre,
      descripcion: body.descripcion || null,
      diario_id: diarioId,
      glosa_default: glosa || null,
      observaciones_default: observaciones || null,
      lineas: lineas.filter((l) => l.cuenta_id).map((l) => ({
        cuenta_id: l.cuenta_id,
        centro_costo_id: l.centro_costo_id,
        auxiliar_id: l.auxiliar_id,
        glosa: l.glosa || null,
        debe: l.debe,
        haber: l.haber,
      })),
    }),
    onSuccess: () => {
      toast.success('Plantilla guardada');
      setGuardarOpen(false);
      qc.invalidateQueries({ queryKey: ['asiento-plantillas'] });
    },
    onError: (e) => toast.error('No se pudo guardar', e.message),
  });

  const eliminarPlantilla = useMutation<unknown, ApiError, number>({
    mutationFn: (id) => api.delete(`/api/erp/asiento-plantillas/${id}`),
    onSuccess: () => {
      toast.success('Plantilla eliminada');
      setPlantillaSel('');
      qc.invalidateQueries({ queryKey: ['asiento-plantillas'] });
    },
    onError: (e) => toast.error('No se pudo eliminar', e.message),
  });

  const tieneLineasConCuenta = lineas.some((l) => l.cuenta_id);

  // Default diario al primero cargado
  useMemo(() => {
    if (diarioId === null && diarios.length > 0) {
      setDiarioId(diarios[0].id);
    }
  }, [diarioId, diarios]);

  const totalDebe = lineas.reduce((s, l) => s + l.debe, 0);
  const totalHaber = lineas.reduce((s, l) => s + l.haber, 0);
  const diff = totalDebe - totalHaber;
  const balanced = Math.abs(diff) < 0.005 && totalDebe > 0;
  // v1.15 Sprint M+: bloquea submit si alguna línea no tiene cuenta seleccionada.
  const lineasSinCuenta = lineas.filter((l) => l.cuenta_id == null && (l.debe > 0 || l.haber > 0));
  const todasLineasConCuenta = lineasSinCuenta.length === 0;

  function updateLinea(id: string, patch: Partial<Linea>) {
    setLineas((prev) => prev.map((l) => (l.id === id ? { ...l, ...patch } : l)));
  }

  function removeLinea(id: string) {
    setLineas((prev) => (prev.length > 2 ? prev.filter((l) => l.id !== id) : prev));
  }

  // v1.15 Sprint M+: onCuentaChange + cuentasByCodigo removidos —
  // SelectorCuentaContable maneja la selección y emite id+meta directamente.

  const crearYContabilizar = useMutation({
    mutationFn: async () => {
      const payload = {
        diario_id: diarioId,
        fecha,
        glosa: glosa || null,
        observaciones: observaciones || null,
        movimientos: lineas.map((l) => ({
          cuenta_id: l.cuenta_id,
          cuenta_codigo: l.cuenta_id ? undefined : l.cuenta_codigo || undefined,
          centro_costo_id: l.centro_costo_id,
          auxiliar_id: l.auxiliar_id,
          glosa: l.glosa || null,
          debe: l.debe,
          haber: l.haber,
        })),
      };
      const created = await api.post<{ data: { id: number; numero: number } }>('/api/erp/asientos', payload);
      const contab = await api.post<{ data: { numero: number; hash_integridad: string } }>(
        `/api/erp/asientos/${created.data.id}/contabilizar`
      );
      return contab.data;
    },
    onSuccess: (d) => {
      setResultado({
        ok: `Asiento N° ${d.numero} contabilizado correctamente.`,
        detalle: `Hash: ${d.hash_integridad.slice(0, 24)}…`,
      });
      setLineas([emptyLinea(), emptyLinea()]);
      setGlosa('');
      qc.invalidateQueries({ queryKey: ['asientos'] });
      qc.invalidateQueries({ queryKey: ['health'] });
      qc.invalidateQueries({ queryKey: ['diarios'] });
    },
    onError: (e) => {
      setResultado({
        ok: '',
        detalle: e instanceof ApiError ? e.message : 'Error de red',
      });
    },
  });

  const periodo = periodoResp?.data;
  const loadingCatalogos = !diarios.length || !cuentas.length;

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Nuevo asiento contable</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            {periodo ? (
              <>
                {new Date(periodo.anio, periodo.mes - 1, 1).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' })}
                {' · estado '}{periodo.estado}{' · N° se asigna al contabilizar'}
              </>
            ) : (
              'Cargando período…'
            )}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setLineas([emptyLinea(), emptyLinea()])}>
            Cancelar
          </Button>
          <Button
            variant="success"
            disabled={!balanced || !todasLineasConCuenta || crearYContabilizar.isPending || loadingCatalogos}
            onClick={() => crearYContabilizar.mutate()}
          >
            {crearYContabilizar.isPending ? (
              <Loader2 className="w-3 h-3 animate-spin" />
            ) : (
              <Check className="w-3 h-3" />
            )}
            Contabilizar
          </Button>
        </div>
      </div>

      {resultado && (
        <div
          className={`mb-4 p-3 rounded-md text-[12px] border ${
            resultado.ok
              ? 'bg-success-bg text-success border-success/30'
              : 'bg-danger-bg text-danger border-danger/30'
          }`}
        >
          <strong>{resultado.ok || 'Error:'}</strong>
          {resultado.detalle && <div className="mt-1 font-mono text-[11px] opacity-80">{resultado.detalle}</div>}
        </div>
      )}

      <div className="bg-white border border-line rounded-lg mb-4">
        {/* Plantillas/modelos de asiento */}
        <div className="flex items-center gap-2 p-[10px_16px] border-b border-line bg-[#F4F8FD]">
          <Bookmark className="w-3.5 h-3.5 text-azure" />
          <span className="text-[11px] font-semibold text-navy-800">Plantilla</span>
          <select
            className="px-[9px] py-[5px] text-[12px] border border-line-strong rounded-md bg-white min-w-[240px]"
            value={plantillaSel}
            onChange={(e) => aplicarPlantilla(e.target.value)}
          >
            <option value="">Cargar plantilla guardada…</option>
            {plantillas.map((p) => (
              <option key={p.id} value={p.id}>{p.nombre}</option>
            ))}
          </select>
          {plantillaSel && (
            <button
              onClick={() => {
                const p = plantillas.find((x) => String(x.id) === plantillaSel);
                if (p && confirm(`¿Eliminar la plantilla "${p.nombre}"?`)) eliminarPlantilla.mutate(p.id);
              }}
              className="text-ink-muted hover:text-danger p-1" title="Eliminar plantilla">
              <Trash2 className="w-3.5 h-3.5" />
            </button>
          )}
          <div className="flex-1" />
          <Button variant="secondary" onClick={() => setGuardarOpen(true)} disabled={!tieneLineasConCuenta}>
            <Save className="w-3 h-3" /> Guardar como plantilla
          </Button>
        </div>

        {/* Meta */}
        <div className="grid grid-cols-[180px_140px_140px_1fr] gap-3 p-[14px_16px] bg-[#FAFBFC] border-b border-line">
          <div>
            <div className="text-[10px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Diario</div>
            <select
              className="w-full px-[9px] py-[6px] text-[12px] border border-line-strong rounded-md bg-white"
              value={diarioId ?? ''}
              onChange={(e) => setDiarioId(Number(e.target.value))}
            >
              {diarios.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.codigo} — {d.nombre}
                </option>
              ))}
            </select>
          </div>
          <div>
            <div className="text-[10px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Fecha</div>
            <input
              type="date"
              className="w-full px-[9px] py-[6px] text-[12px] border border-line-strong rounded-md bg-white"
              value={fecha}
              onChange={(e) => setFecha(e.target.value)}
            />
          </div>
          <div>
            <div className="text-[10px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Moneda base</div>
            <input
              readOnly
              value="ARS"
              className="w-full px-[9px] py-[6px] text-[12px] border border-line-strong rounded-md bg-surface-hover"
            />
          </div>
          <div>
            <div className="text-[10px] font-semibold text-ink-muted uppercase tracking-wider mb-1">Glosa (concepto)</div>
            <input
              className="w-full px-[9px] py-[6px] text-[12px] border border-line-strong rounded-md bg-white"
              value={glosa}
              onChange={(e) => setGlosa(e.target.value)}
              placeholder="Descripción general del asiento"
            />
          </div>
        </div>

        {/* Addendum v1.15 Sprint M — Observaciones (texto libre detallado, opcional) */}
        <div>
          <div className="text-[10px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
            Observaciones (opcional)
          </div>
          <textarea
            className="w-full px-[9px] py-[6px] text-[12px] border border-line-strong rounded-md bg-white"
            value={observaciones}
            onChange={(e) => setObservaciones(e.target.value)}
            placeholder="Texto libre detallado. Aparece solo en el detalle del asiento."
            rows={2}
          />
        </div>

        {/* Editor de líneas */}
        <div className="overflow-x-auto">
          <table className="w-full border-collapse">
            <thead>
              <tr className="bg-surface-hover border-b border-line-strong text-[10px] uppercase tracking-wider text-navy-800 font-semibold">
                <th className="p-[8px_10px] text-center w-[30px]">#</th>
                <th className="p-[8px_10px] text-left w-[280px]">Cuenta</th>
                <th className="p-[8px_10px] text-left w-[150px]">Centro de costo</th>
                <th className="p-[8px_10px] text-left w-[200px]">Auxiliar</th>
                <th className="p-[8px_10px] text-left">Glosa línea</th>
                <th className="p-[8px_10px] text-right w-[120px]">Debe</th>
                <th className="p-[8px_10px] text-right w-[120px]">Haber</th>
                <th className="w-[32px]" />
              </tr>
            </thead>
            <tbody>
              {lineas.map((l, i) => {
                const cuenta = l.cuenta_id ? cuentas.find((c) => c.id === l.cuenta_id) : null;
                return (
                  <tr key={l.id} className="border-b border-line hover:bg-surface-hover">
                    <td className="text-center font-mono text-[11px] text-ink-muted w-[30px]">{i + 1}</td>
                    <td className="p-[6px_10px]">
                      {/* v1.15 Sprint M+: SelectorCuentaContable reemplaza el input texto libre — */}
                      {/* el operador SOLO puede elegir una cuenta del plan, jamás escribir libre. */}
                      <SelectorCuentaContable
                        value={l.cuenta_id}
                        onChange={(id, meta) => updateLinea(l.id, {
                          cuenta_id: id,
                          cuenta_codigo: meta?.codigo ?? '',
                        })}
                        soloImputables
                        placeholder="Código o nombre…"
                      />
                    </td>
                    <td className="p-[6px_10px]">
                      <select
                        className="w-full px-[6px] py-1 text-[12px] border border-transparent hover:border-line focus:outline-1 focus:outline-azure focus:bg-white rounded bg-transparent"
                        value={l.centro_costo_id ?? ''}
                        onChange={(e) => updateLinea(l.id, { centro_costo_id: e.target.value ? Number(e.target.value) : null })}
                      >
                        <option value="">—</option>
                        {ccs.map((c) => (
                          <option key={c.id} value={c.id}>
                            {c.codigo}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="p-[6px_10px]">
                      <select
                        className="w-full px-[6px] py-1 text-[12px] border border-transparent hover:border-line focus:outline-1 focus:outline-azure focus:bg-white rounded bg-transparent"
                        value={l.auxiliar_id ?? ''}
                        onChange={(e) => updateLinea(l.id, { auxiliar_id: e.target.value ? Number(e.target.value) : null })}
                        disabled={!cuenta?.admite_auxiliar}
                      >
                        <option value="">—</option>
                        {auxiliares.map((a) => (
                          <option key={a.id} value={a.id}>
                            {a.tipo.slice(0, 4)}: {a.nombre}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="p-[6px_10px]">
                      <input
                        className="w-full px-[6px] py-1 text-[12px] border border-transparent hover:border-line focus:outline-1 focus:outline-azure focus:border-azure focus:bg-white rounded bg-transparent"
                        value={l.glosa}
                        onChange={(e) => updateLinea(l.id, { glosa: e.target.value })}
                      />
                    </td>
                    <td className="p-[6px_10px] text-right">
                      <input
                        className="w-full px-[6px] py-1 text-[12px] text-right tabular font-medium border border-transparent hover:border-line focus:outline-1 focus:outline-azure focus:bg-white rounded bg-transparent"
                        value={l.debe ? l.debe.toString() : ''}
                        onChange={(e) => updateLinea(l.id, { debe: parseMoney(e.target.value), haber: 0 })}
                        placeholder="0"
                      />
                    </td>
                    <td className="p-[6px_10px] text-right">
                      <input
                        className="w-full px-[6px] py-1 text-[12px] text-right tabular font-medium border border-transparent hover:border-line focus:outline-1 focus:outline-azure focus:bg-white rounded bg-transparent"
                        value={l.haber ? l.haber.toString() : ''}
                        onChange={(e) => updateLinea(l.id, { haber: parseMoney(e.target.value), debe: 0 })}
                        placeholder="0"
                      />
                    </td>
                    <td className="px-1">
                      {lineas.length > 2 && (
                        <button
                          onClick={() => removeLinea(l.id)}
                          className="text-ink-muted hover:text-danger p-1"
                          aria-label="Borrar línea"
                        >
                          <Trash2 className="w-3 h-3" />
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
              <tr>
                <td
                  colSpan={8}
                  onClick={() => setLineas((prev) => [...prev, emptyLinea()])}
                  className="p-[8px_10px] text-[12px] text-azure font-medium cursor-pointer bg-surface-row border-t border-dashed border-line-strong hover:bg-[#EFF4FB]"
                >
                  <Plus className="w-3 h-3 inline mr-1" /> Agregar línea
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        {/* v1.15 Sprint M+: datalist eliminado — SelectorCuentaContable lo reemplaza con autocomplete real. */}

        {/* Footer */}
        <div className="p-[14px_16px] bg-surface-row border-t border-line grid grid-cols-[1fr_auto] gap-5 items-center">
          <div className="grid grid-cols-3 gap-[14px] max-w-[500px]">
            <div>
              <div className="text-[10px] uppercase tracking-wider text-ink-muted font-semibold">Total Debe</div>
              <div className="text-[15px] font-semibold text-navy-800 tabular mt-[2px]">{fmtMoney(totalDebe)}</div>
            </div>
            <div>
              <div className="text-[10px] uppercase tracking-wider text-ink-muted font-semibold">Total Haber</div>
              <div className="text-[15px] font-semibold text-navy-800 tabular mt-[2px]">{fmtMoney(totalHaber)}</div>
            </div>
            <div>
              <div className="text-[10px] uppercase tracking-wider text-ink-muted font-semibold">Diferencia</div>
              <div
                className={`text-[15px] font-semibold tabular mt-[2px] ${
                  balanced ? 'text-success' : 'text-danger'
                }`}
              >
                {fmtMoney(diff)}
              </div>
            </div>
          </div>
          <div>
            <span
              className={`inline-flex items-center gap-[5px] px-[10px] py-[5px] rounded-md text-[11px] font-semibold ${
                balanced ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger'
              }`}
            >
              <Check className="w-3 h-3" strokeWidth={3} />
              {balanced ? 'Partida doble OK' : 'Desbalanceado'}
            </span>
          </div>
        </div>
      </div>

      <div className="mt-[18px] p-[14px_18px] bg-[#EEF3F8] border border-[#D1DCE8] rounded-lg text-[12px] text-navy-700 leading-relaxed">
        <strong className="text-navy-800">Cómo funciona.</strong> Al contabilizar se crea el asiento en BORRADOR, se
        valida RN-1 (partida doble) a RN-10 (CC obligatorio) y se transiciona a CONTABILIZADO calculando hash SHA-256.
        Para corregir un contabilizado se usa anulación (genera asiento reversa automático).
        <br />
        <strong className="text-navy-800">Plantillas.</strong> Para asientos repetitivos (sueldos, alquiler) armá las
        líneas una vez y guardalas como plantilla. La próxima vez la seleccionás arriba y se cargan las cuentas (e
        importes sugeridos, editables). No crea el asiento — solo prellena el form.
      </div>

      {guardarOpen && (
        <GuardarPlantillaModal
          onClose={() => setGuardarOpen(false)}
          onSave={(nombre, descripcion) => guardarPlantilla.mutate({ nombre, descripcion })}
          saving={guardarPlantilla.isPending}
          lineasCount={lineas.filter((l) => l.cuenta_id).length}
        />
      )}
    </>
  );
}

function GuardarPlantillaModal({ onClose, onSave, saving, lineasCount }: {
  onClose: () => void;
  onSave: (nombre: string, descripcion: string) => void;
  saving: boolean;
  lineasCount: number;
}) {
  const [nombre, setNombre] = useState('');
  const [descripcion, setDescripcion] = useState('');
  return (
    <Modal open onClose={onClose} title="Guardar como plantilla" size="md" footer={
      <>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={nombre.trim().length < 2 || saving}
          onClick={() => onSave(nombre.trim(), descripcion.trim())}>
          {saving && <Loader2 className="w-3 h-3 animate-spin" />} Guardar
        </Button>
      </>
    }>
      <div className="space-y-2 text-[12px]">
        <div className="text-ink-muted">
          Se guardan las {lineasCount} línea{lineasCount === 1 ? '' : 's'} con cuenta (cuenta + centro de costo +
          auxiliar + glosa + importes sugeridos) + el diario y la glosa. Los importes son editables al aplicarla.
        </div>
        <div>
          <label className="block text-[11px] text-ink-muted mb-1">Nombre *</label>
          <input value={nombre} onChange={(e) => setNombre(e.target.value)}
            placeholder="Ej: Registración de sueldos mensual"
            className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
        </div>
        <div>
          <label className="block text-[11px] text-ink-muted mb-1">Descripción (opcional)</label>
          <input value={descripcion} onChange={(e) => setDescripcion(e.target.value)}
            className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
        </div>
      </div>
    </Modal>
  );
}
