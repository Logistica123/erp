import { cn } from '@/lib/cn';
import type { HTMLAttributes, ReactNode } from 'react';

export function Card({ className, ...p }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn('bg-white border border-line rounded-lg overflow-hidden mb-4', className)}
      {...p}
    />
  );
}

export function CardHeader({
  title,
  actions,
  className,
  children,
}: {
  title?: ReactNode;
  actions?: ReactNode;
  className?: string;
  children?: ReactNode;
}) {
  return (
    <div
      className={cn(
        'px-4 py-3 border-b border-line bg-[#FAFBFC]',
        title || actions ? 'flex items-center justify-between' : '',
        className
      )}
    >
      {title && <div className="text-[13px] font-semibold text-navy-800">{title}</div>}
      {actions && <div className="flex gap-[6px]">{actions}</div>}
      {children}
    </div>
  );
}

export function CardBody({ className, ...p }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('', className)} {...p} />;
}
