import { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, X, Check, AlertCircle } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { Badge } from '@/components/ui/Badge';

/**
 * ADDENDUM v1.15 Sprint M+ — Componente reutilizable para selección de
 * cuenta contable (combobox buscable autocomplete). Reemplaza:
 *   - El input texto libre del form Nuevo Asiento (raíz del bug HTTP 400).
 *   - El dropdown gigante del filtro Libro Mayor.
 *
 * Props:
 *   - value: id de la cuenta seleccionada (o null).
 *   - onChange: callback (id | null) — emite null al limpiar.
 *   - soloImputables: si true (default), solo cuentas imputables. El backend
 *     ya filtra esto, así que es informativo en el componente.
 *   - incluirInactivas: si true, incluye cuentas con activo=0 (badge "INACTIVA").
 *     Usar en contextos de consulta (Libro Mayor, Sumas y Saldos).
 *   - disabled, autoFocus, error: pass-through.
 *   - onMeta: callback opcional con la cuenta completa (admite_cc, admite_auxiliar)
 *     para que el caller pueda decidir mostrar columnas adicionales.
 */
export type CuentaOpcion = {
  id: number;
  codigo: string;
  nombre: string;
  activo: boolean;
  admite_cc: boolean;
  admite_auxiliar: boolean;
  tipo_auxiliar: string | null;
  moneda: string | null;
  label: string;
};

type Props = {
  value: number | null;
  onChange: (id: number | null, meta?: CuentaOpcion) => void;
  soloImputables?: boolean;
  incluirInactivas?: boolean;
  placeholder?: string;
  disabled?: boolean;
  autoFocus?: boolean;
  error?: string | null;
  className?: string;
};

export function SelectorCuentaContable({
  value, onChange, soloImputables = true, incluirInactivas = false,
  placeholder = 'Buscar por código o nombre…',
  disabled, autoFocus, error, className,
}: Props) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [highlight, setHighlight] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  // Cargar la cuenta seleccionada (para mostrar su label cuando no hay query).
  const { data: actual } = useQuery<{ data: CuentaOpcion } | { data: null }>({
    queryKey: ['cuenta-imputable', value],
    queryFn: async () => {
      if (!value) return { data: null };
      // Buscar la cuenta por id usando GET con q vacía + filtro por id no se soporta
      // todavía; alternativa: fetch del show endpoint.
      const r = await api.get<{ data: { id: number; codigo: string; nombre: string; activo: boolean; admite_cc: boolean; admite_auxiliar: boolean; tipo_auxiliar: string | null; moneda: string | null } }>(`/api/erp/cuentas/${value}`);
      return {
        data: r.data ? {
          id: r.data.id, codigo: r.data.codigo, nombre: r.data.nombre,
          activo: r.data.activo, admite_cc: r.data.admite_cc, admite_auxiliar: r.data.admite_auxiliar,
          tipo_auxiliar: r.data.tipo_auxiliar, moneda: r.data.moneda,
          label: `${r.data.codigo} — ${r.data.nombre}`,
        } : null,
      };
    },
    enabled: !!value,
    staleTime: 60_000,
  });

  // Búsqueda. Debounce simple via key del useQuery (TanStack dedupea queries iguales).
  const queryDebounced = useDebouncedValue(query, 200);
  const { data: results, isFetching } = useQuery<{ ok: boolean; data: CuentaOpcion[] }>({
    queryKey: ['cuentas-imputables', queryDebounced, soloImputables, incluirInactivas],
    queryFn: () => {
      const params = new URLSearchParams();
      if (queryDebounced) params.set('q', queryDebounced);
      if (incluirInactivas) params.set('incluir_inactivas', '1');
      params.set('limit', '20');
      return api.get(`/api/erp/cuentas/imputables?${params.toString()}`);
    },
    enabled: open,
    staleTime: 30_000,
  });

  const items = useMemo(() => results?.data ?? [], [results]);

  // Cerrar al click afuera.
  useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [open]);

  // Resetear highlight cuando cambian los items.
  useEffect(() => { setHighlight(0); }, [items.length]);

  const displayValue = open
    ? query
    : (actual?.data?.label ?? '');

  const seleccionar = (op: CuentaOpcion) => {
    onChange(op.id, op);
    setQuery('');
    setOpen(false);
    inputRef.current?.blur();
  };

  const limpiar = () => {
    onChange(null);
    setQuery('');
    inputRef.current?.focus();
  };

  const onKey = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setOpen(true);
      setHighlight((h) => Math.min(h + 1, items.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setHighlight((h) => Math.max(h - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (open && items[highlight]) seleccionar(items[highlight]);
    } else if (e.key === 'Escape') {
      setOpen(false);
      setQuery('');
    }
  };

  return (
    <div ref={containerRef} className={`relative ${className ?? ''}`}>
      <div className={`flex items-center gap-1 border rounded-md bg-white ${
        error ? 'border-danger' : 'border-line-strong'
      } ${disabled ? 'opacity-60 bg-surface-row' : 'focus-within:border-azure'}`}>
        <input
          ref={inputRef}
          type="text"
          value={displayValue}
          onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKey}
          placeholder={placeholder}
          disabled={disabled}
          autoFocus={autoFocus}
          className="flex-1 px-[8px] py-[5px] text-[12px] bg-transparent outline-none"
        />
        {value && !disabled && (
          <button type="button" onClick={limpiar}
            className="p-1 text-ink-muted hover:text-danger" title="Limpiar">
            <X className="w-3 h-3" />
          </button>
        )}
        <ChevronDown className="w-3 h-3 mr-2 text-ink-muted pointer-events-none" />
      </div>

      {error && (
        <div className="text-[10.5px] text-danger mt-[2px] flex items-center gap-1">
          <AlertCircle className="w-3 h-3" /> {error}
        </div>
      )}

      {open && !disabled && (
        <div className="absolute z-30 mt-1 w-full max-w-[500px] bg-white border border-line-strong rounded-md shadow-lg max-h-[320px] overflow-y-auto">
          {isFetching && items.length === 0 && (
            <div className="px-3 py-2 text-[11.5px] text-ink-muted italic">Buscando…</div>
          )}
          {!isFetching && items.length === 0 && (
            <div className="px-3 py-2 text-[11.5px] text-ink-muted italic">
              {queryDebounced ? `Sin resultados para "${queryDebounced}"` : 'Escribí para buscar por código o nombre.'}
            </div>
          )}
          {items.map((op, idx) => (
            <button key={op.id} type="button"
              onMouseEnter={() => setHighlight(idx)}
              onClick={() => seleccionar(op)}
              className={`w-full text-left px-3 py-1.5 text-[12px] border-b border-line/60 last:border-0 ${
                idx === highlight ? 'bg-azure/10' : 'hover:bg-surface-hover'
              }`}>
              <div className="flex items-center gap-2">
                <code className="text-[11px] text-azure font-semibold">{op.codigo}</code>
                <span className="flex-1 truncate">{op.nombre}</span>
                {!op.activo && <Badge variant="warning">INACTIVA</Badge>}
                {op.id === value && <Check className="w-3 h-3 text-success" />}
              </div>
              {(op.admite_cc || op.admite_auxiliar || op.moneda) && (
                <div className="text-[10px] text-ink-muted mt-0.5 flex gap-2">
                  {op.moneda && <span>{op.moneda}</span>}
                  {op.admite_cc && <span>req. CC</span>}
                  {op.admite_auxiliar && <span>req. Auxiliar ({op.tipo_auxiliar ?? '*'})</span>}
                </div>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

/** Hook helper de debounce — local al componente para no agregar dependencias. */
function useDebouncedValue<T>(value: T, ms: number): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}
