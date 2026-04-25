import { cn } from '@/lib/cn';
import type { ButtonHTMLAttributes } from 'react';

type Variant = 'primary' | 'secondary' | 'success' | 'danger' | 'outline' | 'ghost';
type Size = 'sm' | 'md';

const variants: Record<Variant, string> = {
  primary: 'bg-navy-700 text-white border-navy-700 hover:bg-navy-600',
  secondary: 'bg-white text-ink-2 border-line-strong hover:bg-surface-hover',
  success: 'bg-success text-white border-success hover:opacity-90',
  danger: 'bg-danger text-white border-danger hover:opacity-90',
  outline: 'bg-transparent text-ink-2 border-line-strong hover:bg-surface-hover',
  ghost: 'bg-transparent text-ink-2 border-transparent hover:bg-surface-hover',
};

const sizes: Record<Size, string> = {
  sm: 'px-[9px] py-1 text-[11px]',
  md: 'px-[14px] py-[7px] text-[12px]',
};

export function Button({
  variant = 'primary',
  size = 'md',
  className,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: Size }) {
  return (
    <button
      className={cn(
        'inline-flex items-center gap-[6px] rounded-md border font-medium transition-colors',
        'disabled:opacity-50 disabled:cursor-not-allowed',
        variants[variant],
        sizes[size],
        className
      )}
      {...props}
    />
  );
}
