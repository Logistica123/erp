import { useMemo, useState } from 'react';
import { Check, Loader2, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { fmtMoney } from '@/lib/cn';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

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

  const cuentasByCodigo = useMemo(() => {
    const m = new Map<string, Cuenta>();
    for (const c of cuentas) m.set(c.codigo, c);
    return m;
  }, [cuentas]);

  const [diarioId, setDiarioId] = useState<number | null>(null);
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10));
  const [glosa, setGlosa] = useState('');
  const [observaciones, setObservaciones] = useState(''); // v1.15 Sprint M
  const [lineas, setLineas] = useState<Linea[]>([emptyLinea(), emptyLinea()]);
  const [resultado, setResultado] = useState<{ ok: string; detalle?: string } | null>(null);

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

  function updateLinea(id: string, patch: Partial<Linea>) {
    setLineas((prev) => prev.map((l) => (l.id === id ? { ...l, ...patch } : l)));
  }

  function removeLinea(id: string) {
    setLineas((prev) => (prev.length > 2 ? prev.filter((l) => l.id !== id) : prev));
  }

  function onCuentaChange(id: string, codigo: string) {
    const c = cuentasByCodigo.get(codigo);
    updateLinea(id, { cuenta_codigo: codigo, cuenta_id: c?.id ?? null });
  }

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
            disabled={!balanced || crearYContabilizar.isPending || loadingCatalogos}
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
                const cuenta = l.cuenta_id ? cuentas.find((c) => c.id === l.cuenta_id) : cuentasByCodigo.get(l.cuenta_codigo);
                return (
                  <tr key={l.id} className="border-b border-line hover:bg-surface-hover">
                    <td className="text-center font-mono text-[11px] text-ink-muted w-[30px]">{i + 1}</td>
                    <td className="p-[6px_10px]">
                      <input
                        list="cuentas-list"
                        className="w-full px-[6px] py-1 text-[12px] border border-transparent hover:border-line focus:outline-1 focus:outline-azure focus:border-azure focus:bg-white rounded bg-transparent"
                        value={l.cuenta_codigo}
                        onChange={(e) => onCuentaChange(l.id, e.target.value)}
                        placeholder="Código o nombre"
                      />
                      {cuenta && (
                        <div className="text-[10px] text-ink-muted mt-[2px] font-mono pl-1">{cuenta.nombre}</div>
                      )}
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

        {/* Datalist para autocompletado de cuentas */}
        <datalist id="cuentas-list">
          {cuentas.map((c) => (
            <option key={c.id} value={c.codigo}>
              {c.nombre}
            </option>
          ))}
        </datalist>

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
      </div>
    </>
  );
}
