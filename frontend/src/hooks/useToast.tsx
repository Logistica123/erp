import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { CheckCircle2, AlertCircle, Info, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

type Variant = 'success' | 'error' | 'info';

type Toast = {
  id: number;
  variant: Variant;
  title: string;
  message?: string;
  ttl: number;
};

type ToastApi = {
  show: (t: Omit<Toast, 'id' | 'ttl'> & { ttl?: number }) => void;
  success: (title: string, message?: string) => void;
  error: (title: string, message?: string) => void;
  info: (title: string, message?: string) => void;
};

const ToastCtx = createContext<ToastApi | null>(null);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const idRef = useRef(0);

  const remove = useCallback((id: number) => {
    setToasts((t) => t.filter((x) => x.id !== id));
  }, []);

  const show = useCallback(
    (t: Omit<Toast, 'id' | 'ttl'> & { ttl?: number }) => {
      const id = ++idRef.current;
      const toast: Toast = { id, ttl: 4000, ...t };
      setToasts((cur) => [...cur, toast]);
    },
    []
  );

  const api: ToastApi = {
    show,
    success: (title, message) => show({ variant: 'success', title, message }),
    error: (title, message) => show({ variant: 'error', title, message, ttl: 6000 }),
    info: (title, message) => show({ variant: 'info', title, message }),
  };

  return (
    <ToastCtx.Provider value={api}>
      {children}
      <div className="fixed top-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none">
        {toasts.map((t) => (
          <ToastItem key={t.id} toast={t} onClose={() => remove(t.id)} />
        ))}
      </div>
    </ToastCtx.Provider>
  );
}

function ToastItem({ toast, onClose }: { toast: Toast; onClose: () => void }) {
  useEffect(() => {
    const id = setTimeout(onClose, toast.ttl);
    return () => clearTimeout(id);
  }, [onClose, toast.ttl]);

  const Icon = toast.variant === 'success' ? CheckCircle2 : toast.variant === 'error' ? AlertCircle : Info;
  const variantClasses = {
    success: 'border-success/40 bg-white text-ink-2',
    error: 'border-danger/40 bg-white text-ink-2',
    info: 'border-line bg-white text-ink-2',
  }[toast.variant];
  const iconClass = {
    success: 'text-success',
    error: 'text-danger',
    info: 'text-azure',
  }[toast.variant];

  return (
    <div
      className={cn(
        'pointer-events-auto min-w-[280px] max-w-[400px] rounded-lg border shadow-lg px-3 py-2.5 flex items-start gap-2.5',
        variantClasses
      )}
    >
      <Icon className={cn('w-4 h-4 mt-0.5 shrink-0', iconClass)} strokeWidth={2.2} />
      <div className="flex-1 min-w-0">
        <div className="text-[12.5px] font-semibold text-navy-800 leading-tight">{toast.title}</div>
        {toast.message && <div className="text-[11.5px] text-ink-muted mt-1 leading-snug">{toast.message}</div>}
      </div>
      <button
        onClick={onClose}
        className="text-ink-muted hover:text-ink shrink-0 -mt-0.5 -mr-0.5"
        aria-label="Cerrar"
      >
        <X className="w-3.5 h-3.5" />
      </button>
    </div>
  );
}

export function useToast(): ToastApi {
  const ctx = useContext(ToastCtx);
  if (!ctx) {
    throw new Error('useToast debe usarse dentro de <ToastProvider>');
  }
  return ctx;
}
