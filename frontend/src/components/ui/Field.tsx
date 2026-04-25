import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

const baseInput =
  'w-full px-3 py-2 text-[12.5px] border border-line rounded-md bg-white text-ink ' +
  'focus:outline-none focus:border-azure focus:ring-1 focus:ring-azure/20 ' +
  'disabled:opacity-50 disabled:cursor-not-allowed disabled:bg-surface-hover';

const labelClass = 'block text-[11.5px] font-semibold text-ink-2 mb-1';
const errorClass = 'text-[11px] text-danger mt-1';
const hintClass = 'text-[11px] text-ink-muted mt-1';

type FieldShellProps = {
  label?: ReactNode;
  required?: boolean;
  error?: string | null;
  hint?: ReactNode;
  className?: string;
  children: ReactNode;
};

export function FieldShell({ label, required, error, hint, className, children }: FieldShellProps) {
  return (
    <div className={cn('block', className)}>
      {label && (
        <label className={labelClass}>
          {label}
          {required && <span className="text-danger ml-0.5">*</span>}
        </label>
      )}
      {children}
      {error && <div className={errorClass}>{error}</div>}
      {hint && !error && <div className={hintClass}>{hint}</div>}
    </div>
  );
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & {
  label?: ReactNode;
  error?: string | null;
  hint?: ReactNode;
  containerClassName?: string;
};

export function Field({ label, error, hint, required, containerClassName, className, ...props }: InputProps) {
  return (
    <FieldShell label={label} required={required} error={error} hint={hint} className={containerClassName}>
      <input
        {...props}
        className={cn(baseInput, error && 'border-danger focus:border-danger focus:ring-danger/20', className)}
      />
    </FieldShell>
  );
}

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & {
  label?: ReactNode;
  error?: string | null;
  hint?: ReactNode;
  containerClassName?: string;
  options: { value: string | number; label: string }[];
  /** Texto de la opción placeholder (vacía). null la oculta. */
  placeholder?: string | null;
};

export function SelectField({
  label,
  error,
  hint,
  required,
  containerClassName,
  className,
  options,
  placeholder = '—',
  ...props
}: SelectProps) {
  return (
    <FieldShell label={label} required={required} error={error} hint={hint} className={containerClassName}>
      <select
        {...props}
        className={cn(baseInput, error && 'border-danger focus:border-danger focus:ring-danger/20', className)}
      >
        {placeholder !== null && <option value="">{placeholder}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </FieldShell>
  );
}

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & {
  label?: ReactNode;
  error?: string | null;
  hint?: ReactNode;
  containerClassName?: string;
};

export function TextareaField({
  label,
  error,
  hint,
  required,
  containerClassName,
  className,
  rows = 3,
  ...props
}: TextareaProps) {
  return (
    <FieldShell label={label} required={required} error={error} hint={hint} className={containerClassName}>
      <textarea
        {...props}
        rows={rows}
        className={cn(baseInput, error && 'border-danger focus:border-danger focus:ring-danger/20', className)}
      />
    </FieldShell>
  );
}

/** Banner para mostrar un error general del backend (DomainException). */
export function FormError({ error }: { error: string | null | undefined }) {
  if (!error) return null;
  return (
    <div className="border border-danger/30 bg-danger-bg/40 text-danger text-[12px] rounded-md px-3 py-2">
      {error}
    </div>
  );
}
