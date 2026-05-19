import { useState, useEffect, useRef } from 'react';

/**
 * v1.38 — Input numérico que acepta tanto `.` como `,` como separador
 * decimal y mantiene el valor como string mientras el usuario está editando
 * (no convierte a Number en cada keystroke).
 *
 * Bugs que reemplaza del `<Field type="number">` usado antes:
 * - "Si escribís ',02' se borra el punto decimal" — `+","` → NaN → 0.
 * - "Si tocás el . del teclado numérico se borra el contenido" — algunos
 *   layouts mandan "," que `type="number"` rechaza silente.
 *
 * Estrategia: input `type="text"` con `inputMode="decimal"` (teclado
 * numérico en mobile) + regex permisiva durante edición + commit al blur
 * convirtiendo a número.
 */
type Props = {
  label?: string;
  value: number;
  onChange: (n: number) => void;
  /** Hint inferior. */
  hint?: string;
  /** Marca el borde de rojo + tooltip. */
  error?: string | null;
  containerClassName?: string;
  placeholder?: string;
  disabled?: boolean;
  /** Auto-foco. */
  autoFocus?: boolean;
};

export function DecimalField({
  label, value, onChange, hint, error,
  containerClassName, placeholder, disabled, autoFocus,
}: Props) {
  const [draft, setDraft] = useState<string>(formatNum(value));
  const focusing = useRef(false);

  // Sincronizar el draft si el value externo cambia (ej: cálculo automático)
  // pero solo cuando el input NO está foco — sino se borra lo que está escribiendo.
  useEffect(() => {
    if (!focusing.current) setDraft(formatNum(value));
  }, [value]);

  const onInputChange = (raw: string) => {
    // Aceptar dígitos + un único separador (. o ,) + opcional signo `-` al inicio.
    // No commitear todavía — solo limpiar caracteres inválidos.
    const cleaned = raw
      .replace(/[^\d.,\-]/g, '')
      .replace(/(?!^)-/g, '');         // `-` solo al inicio
    // Permitir solo un separador (el primero que aparezca).
    const m = cleaned.match(/^(-?\d*)([.,]?)(\d*)/);
    const normalized = m ? `${m[1]}${m[2]}${m[3]}` : cleaned;
    setDraft(normalized);
  };

  const commit = () => {
    focusing.current = false;
    // Reemplazar `,` por `.` para que parseFloat funcione.
    const n = parseFloat(draft.replace(',', '.'));
    if (Number.isNaN(n)) {
      onChange(0);
      setDraft('0');
    } else {
      onChange(n);
      setDraft(formatNum(n));
    }
  };

  return (
    <div className={containerClassName}>
      {label && (
        <label className="block text-[11px] font-medium text-ink-muted mb-1">{label}</label>
      )}
      <input
        type="text"
        inputMode="decimal"
        value={draft}
        onChange={(e) => onInputChange(e.target.value)}
        onFocus={() => { focusing.current = true; }}
        onBlur={commit}
        onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); }}
        placeholder={placeholder ?? '0,00'}
        disabled={disabled}
        autoFocus={autoFocus}
        className={`w-full text-[12px] border rounded px-2 py-1 focus:outline-none ${
          error ? 'border-danger focus:border-danger' : 'border-azure-soft focus:border-azure'
        } ${disabled ? 'bg-surface-row text-ink-muted' : ''}`}
        title={error ?? undefined}
      />
      {error && <div className="mt-0.5 text-[10.5px] text-danger">{error}</div>}
      {hint && !error && <div className="mt-0.5 text-[10.5px] text-ink-muted">{hint}</div>}
    </div>
  );
}

function formatNum(n: number): string {
  if (n === 0 || Number.isNaN(n)) return '0';
  // Sin separadores de miles para no confundir parseo posterior. Si tiene
  // decimales, hasta 2; sino entero limpio.
  return Number.isInteger(n) ? String(n) : n.toFixed(2);
}
