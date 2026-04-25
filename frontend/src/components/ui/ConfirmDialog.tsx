import { Modal } from './Modal';
import { Button } from './Button';
import { AlertTriangle } from 'lucide-react';
import type { ReactNode } from 'react';

type Variant = 'danger' | 'primary';

type Props = {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  message: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  variant?: Variant;
  loading?: boolean;
};

/**
 * Diálogo de confirmación reusable. Para acciones destructivas (baja,
 * descartar) usar `variant="danger"` que pinta el botón rojo y agrega
 * ícono de advertencia.
 */
export function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  message,
  confirmLabel = 'Confirmar',
  cancelLabel = 'Cancelar',
  variant = 'primary',
  loading,
}: Props) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={loading}>
            {cancelLabel}
          </Button>
          <Button variant={variant === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} disabled={loading}>
            {loading ? 'Procesando…' : confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex gap-3">
        {variant === 'danger' && (
          <AlertTriangle className="w-5 h-5 text-danger shrink-0 mt-0.5" strokeWidth={2.2} />
        )}
        <div className="text-[12.5px] text-ink-2 leading-relaxed">{message}</div>
      </div>
    </Modal>
  );
}
