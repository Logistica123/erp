import { cn } from '@/lib/cn';
import type { HTMLAttributes } from 'react';

type Variant = 'success' | 'danger' | 'warning' | 'neutral' | 'info';

const styles: Record<Variant, string> = {
  success: 'bg-success-bg text-success',
  danger: 'bg-danger-bg text-danger',
  warning: 'bg-warning-bg text-warning',
  neutral: 'bg-slate-200 text-ink-2',
  info: 'bg-blue-100 text-blue-800',
};

export function Badge({
  variant = 'neutral',
  className,
  ...props
}: HTMLAttributes<HTMLSpanElement> & { variant?: Variant }) {
  return (
    <span
      className={cn(
        'inline-flex items-center px-2 py-[2px] rounded text-[10px] font-semibold tracking-wide uppercase',
        styles[variant],
        className
      )}
      {...props}
    />
  );
}
