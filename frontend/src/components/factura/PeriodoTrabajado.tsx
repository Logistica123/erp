import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CalendarRange, Pencil } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { api, ApiError } from '@/lib/api';
import { useToast } from '@/hooks/useToast';
import { errorMessage } from '@/hooks/useApi';

const FORMATO_REGEX = /^\d{4}-\d{2}(-Q[12])?$/;

/**
 * v1.27 — Celda inline-editable para periodo_trabajado_texto. Reusable
 * entre Facturas de Compra y Ventas.
 */
export function PeriodoTrabajadoCell({
  value, editable, endpointUrl, invalidateKeys,
}: {
  value: string | null | undefined;
  editable: boolean;
  endpointUrl: string; // PATCH endpoint para esa factura puntual
  invalidateKeys: Array<readonly unknown[]>;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value ?? '');
  const [savedFlash, setSavedFlash] = useState(false);
  const [invalid, setInvalid] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const toast = useToast();
  const queryClient = useQueryClient();

  useEffect(() => { if (editing) inputRef.current?.focus(); }, [editing]);

  const m = useMutation<unknown, ApiError, string | null>({
    mutationFn: (newValue) => api.patch(endpointUrl, { periodo_trabajado_texto: newValue }),
    onSuccess: () => {
      setSavedFlash(true);
      setTimeout(() => setSavedFlash(false), 1000);
      invalidateKeys.forEach((k) => queryClient.invalidateQueries({ queryKey: [...k] }));
    },
    onError: (e) => toast.error('No se pudo actualizar', errorMessage(e)),
  });

  if (!editable) {
    return value
      ? <code className="text-[11px]">{value}</code>
      : <span className="text-ink-muted">—</span>;
  }

  const commit = (raw: string) => {
    const t = raw.trim();
    if (t === (value ?? '')) { setEditing(false); setInvalid(false); return; }
    if (t !== '' && !FORMATO_REGEX.test(t)) { setInvalid(true); return; }
    setInvalid(false);
    m.mutate(t === '' ? null : t);
    setEditing(false);
  };

  if (editing) {
    return (
      <input
        ref={inputRef}
        type="text"
        value={draft}
        onChange={(e) => { setDraft(e.target.value); if (invalid) setInvalid(false); }}
        onClick={(e) => e.stopPropagation()}
        onKeyDown={(e) => {
          e.stopPropagation();
          if (e.key === 'Enter') commit(draft);
          else if (e.key === 'Escape') {
            setDraft(value ?? ''); setInvalid(false); setEditing(false);
          }
        }}
        onBlur={() => commit(draft)}
        placeholder="2026-05"
        title={invalid ? 'Formato: YYYY-MM o YYYY-MM-Q1/Q2' : 'Enter guarda · Escape cancela'}
        className={`w-[90px] text-[11px] border rounded px-1 py-0.5 ${
          invalid ? 'border-danger text-danger' : 'border-azure-soft focus:outline-none focus:border-azure'
        }`}
      />
    );
  }

  return (
    <button
      type="button"
      onClick={(e) => { e.stopPropagation(); setDraft(value ?? ''); setEditing(true); }}
      className={`group flex items-center gap-1 text-left w-full ${savedFlash ? 'bg-success-bg/40 transition' : ''}`}
      title="Click para editar"
    >
      {value
        ? <code className="text-[11px]">{value}</code>
        : <span className="text-ink-muted">—</span>}
      <Pencil className="w-2.5 h-2.5 text-ink-muted opacity-0 group-hover:opacity-100 transition" />
    </button>
  );
}

/**
 * v1.27 — Modal de edición masiva de período trabajado. Reusable entre
 * Compras y Ventas. Recibe el endpoint y las queryKeys a invalidar.
 */
export function EditarPeriodoBulkModal({
  facturas, endpointUrl, invalidateKeys, onClose, onDone,
}: {
  facturas: Array<{ id: number; periodo_trabajado_texto?: string | null }>;
  endpointUrl: string;
  invalidateKeys: Array<readonly unknown[]>;
  onClose: () => void;
  onDone: () => void;
}) {
  const [valor, setValor] = useState('');
  const [errorFmt, setErrorFmt] = useState<string | null>(null);
  const toast = useToast();
  const queryClient = useQueryClient();

  const conPeriodo = new Map<string, number>();
  let vacios = 0;
  facturas.forEach((f) => {
    const v = f.periodo_trabajado_texto;
    if (!v) vacios++;
    else conPeriodo.set(v, (conPeriodo.get(v) ?? 0) + 1);
  });

  const m = useMutation<{ data: { updated: number } }, ApiError, { ids: number[]; periodo_trabajado_texto: string | null }>({
    mutationFn: (body) => api.patch(endpointUrl, body),
    onSuccess: (r) => {
      toast.success('Período actualizado',
        `${r.data.updated} factura${r.data.updated === 1 ? '' : 's'} con período "${valor || 'vacío'}"`);
      invalidateKeys.forEach((k) => queryClient.invalidateQueries({ queryKey: [...k] }));
      onDone();
    },
    onError: (e) => toast.error('No se pudo actualizar', errorMessage(e)),
  });

  const submit = () => {
    const t = valor.trim();
    if (t !== '' && !FORMATO_REGEX.test(t)) {
      setErrorFmt('Formato inválido. Usá YYYY-MM (ej: 2026-05) o YYYY-MM-Q1/Q2.');
      return;
    }
    setErrorFmt(null);
    m.mutate({
      ids: facturas.map((f) => f.id),
      periodo_trabajado_texto: t === '' ? null : t,
    });
  };

  return (
    <Modal open onClose={onClose} title="Asignar período trabajado">
      <div className="space-y-3 text-[12px]">
        <div className="text-ink-muted">
          Vas a actualizar <strong>{facturas.length}</strong> factura{facturas.length === 1 ? '' : 's'} seleccionada{facturas.length === 1 ? '' : 's'}.
        </div>

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">Período trabajado</label>
          <input
            type="text"
            value={valor}
            onChange={(e) => { setValor(e.target.value); if (errorFmt) setErrorFmt(null); }}
            placeholder="2026-05"
            className={`w-full text-[12px] border rounded px-2 py-1 focus:outline-none ${
              errorFmt ? 'border-danger' : 'border-azure-soft focus:border-azure'
            }`}
          />
          <div className="mt-1 text-[10.5px] text-ink-muted">
            Formato: <code>YYYY-MM</code> (ej: <code>2026-05</code>) o <code>YYYY-MM-Q1/Q2</code>.
            Dejar vacío borra el período.
          </div>
          {errorFmt && <div className="mt-1 text-[10.5px] text-danger">{errorFmt}</div>}
        </div>

        {(conPeriodo.size > 0 || vacios > 0) && (
          <div className="bg-azure-soft/30 rounded p-2 text-[11px] space-y-0.5">
            <div className="text-ink-muted mb-1">Período actual de las {facturas.length}:</div>
            {[...conPeriodo.entries()].slice(0, 5).map(([p, n]) => (
              <div key={p}>• {n} con <code>{p}</code></div>
            ))}
            {conPeriodo.size > 5 && <div className="text-ink-muted">… y {conPeriodo.size - 5} variantes más</div>}
            {vacios > 0 && <div>• {vacios} vacío{vacios === 1 ? '' : 's'}</div>}
          </div>
        )}

        <div className="bg-azure-soft/30 border border-azure-soft rounded p-2 text-[10.5px] text-ink">
          La acción es reversible. Cada cambio queda en audit log con el valor anterior.
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <Button variant="outline" onClick={onClose} disabled={m.isPending}>Cancelar</Button>
          <Button variant="primary" onClick={submit} disabled={m.isPending}>
            <CalendarRange className="w-3 h-3" /> Asignar {valor.trim() || 'vacío'} a {facturas.length}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
