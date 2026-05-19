import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Pencil } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { useToast } from '@/hooks/useToast';
import { errorMessage } from '@/hooks/useApi';

/**
 * v1.40 — Celdas inline-editables para OP externa (texto) y fecha de pago
 * (date). Ambas usan el mismo endpoint PATCH /pago-info que acepta los dos
 * campos por separado.
 *
 * Patrón replicado de PeriodoTrabajadoCell (v1.27), generalizado para
 * funcionar con cualquier campo del modelo Factura de Compra que sea
 * editable inline sin restricciones de estado.
 */

type Common = {
  facturaId: number;
  editable: boolean;
  invalidateKeys: Array<readonly unknown[]>;
};

export function OpExternaCell({
  value, facturaId, editable, invalidateKeys,
}: Common & { value: string | null | undefined }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value ?? '');
  const [savedFlash, setSavedFlash] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const toast = useToast();
  const queryClient = useQueryClient();

  useEffect(() => { if (editing) inputRef.current?.focus(); }, [editing]);

  const m = useMutation<unknown, ApiError, string | null>({
    mutationFn: (v) => api.patch(`/api/erp/facturas-compra/${facturaId}/pago-info`, { op_externa: v }),
    onSuccess: () => {
      setSavedFlash(true);
      setTimeout(() => setSavedFlash(false), 1000);
      invalidateKeys.forEach((k) => queryClient.invalidateQueries({ queryKey: [...k] }));
    },
    onError: (e) => toast.error('No se pudo actualizar OP', errorMessage(e)),
  });

  if (!editable) {
    return value
      ? <code className="text-[11px]">{value}</code>
      : <span className="text-ink-muted">—</span>;
  }

  const commit = (raw: string) => {
    const t = raw.trim().slice(0, 50);
    if (t === (value ?? '')) { setEditing(false); return; }
    m.mutate(t === '' ? null : t);
    setEditing(false);
  };

  if (editing) {
    return (
      <input
        ref={inputRef}
        type="text"
        value={draft}
        maxLength={50}
        onChange={(e) => setDraft(e.target.value)}
        onClick={(e) => e.stopPropagation()}
        onKeyDown={(e) => {
          e.stopPropagation();
          if (e.key === 'Enter') commit(draft);
          else if (e.key === 'Escape') { setDraft(value ?? ''); setEditing(false); }
        }}
        onBlur={() => commit(draft)}
        placeholder="OP #..."
        title="Enter guarda · Escape cancela"
        className="w-[100px] text-[11px] border rounded px-1 py-0.5 border-azure-soft focus:outline-none focus:border-azure"
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

export function FechaPagoCell({
  value, facturaId, editable, invalidateKeys,
}: Common & { value: string | null | undefined }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value ?? '');
  const [savedFlash, setSavedFlash] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const toast = useToast();
  const queryClient = useQueryClient();

  useEffect(() => { if (editing) inputRef.current?.focus(); }, [editing]);

  const m = useMutation<unknown, ApiError, string | null>({
    mutationFn: (v) => api.patch(`/api/erp/facturas-compra/${facturaId}/pago-info`, { fecha_pago: v }),
    onSuccess: () => {
      setSavedFlash(true);
      setTimeout(() => setSavedFlash(false), 1000);
      invalidateKeys.forEach((k) => queryClient.invalidateQueries({ queryKey: [...k] }));
    },
    onError: (e) => toast.error('No se pudo actualizar fecha de pago', errorMessage(e)),
  });

  // Normaliza valor → solo YYYY-MM-DD (Eloquent puede mandar ISO 8601 con TZ).
  const valueIsoDate = value ? value.slice(0, 10) : '';

  if (!editable) {
    return valueIsoDate
      ? <code className="text-[11px]">{fmtDmy(valueIsoDate)}</code>
      : <span className="text-ink-muted">—</span>;
  }

  const commit = (raw: string) => {
    const t = raw.trim();
    if (t === valueIsoDate) { setEditing(false); return; }
    m.mutate(t === '' ? null : t);
    setEditing(false);
  };

  if (editing) {
    return (
      <input
        ref={inputRef}
        type="date"
        value={draft.slice(0, 10)}
        onChange={(e) => setDraft(e.target.value)}
        onClick={(e) => e.stopPropagation()}
        onKeyDown={(e) => {
          e.stopPropagation();
          if (e.key === 'Enter') commit(draft);
          else if (e.key === 'Escape') { setDraft(valueIsoDate); setEditing(false); }
        }}
        onBlur={() => commit(draft)}
        className="w-[130px] text-[11px] border rounded px-1 py-0.5 border-azure-soft focus:outline-none focus:border-azure"
      />
    );
  }

  return (
    <button
      type="button"
      onClick={(e) => { e.stopPropagation(); setDraft(valueIsoDate); setEditing(true); }}
      className={`group flex items-center gap-1 text-left w-full ${savedFlash ? 'bg-success-bg/40 transition' : ''}`}
      title="Click para editar"
    >
      {valueIsoDate
        ? <code className="text-[11px]">{fmtDmy(valueIsoDate)}</code>
        : <span className="text-ink-muted">—</span>}
      <Pencil className="w-2.5 h-2.5 text-ink-muted opacity-0 group-hover:opacity-100 transition" />
    </button>
  );
}

function fmtDmy(iso: string): string {
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : iso;
}
