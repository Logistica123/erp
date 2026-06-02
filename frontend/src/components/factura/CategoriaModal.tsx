import { useState } from 'react';
import { Loader2, AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { useMutation } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { useToast } from '@/hooks/useToast';

// v1.37 — Modal de cambio de categoría FACTURA ⇄ EFECTIVO.
// Reusable para FacturacionPage (venta) y FacturasCompraPage (compra).
// El endpoint backend valida bloqueos (asiento contabilizado, recibos/OP
// imputados) y devuelve error 409 con code identificable.

type FacturaMin = {
  id: number;
  numero: number;
  letra?: string | null;
  tipo_codigo?: string;
  categoria?: 'FACTURA' | 'EFECTIVO';
};

export function CategoriaModal({ factura, tipo, puedeCrearEfectivo, onClose, onSuccess }: {
  factura: FacturaMin | null;
  tipo: 'venta' | 'compra';
  puedeCrearEfectivo: boolean;
  onClose: () => void;
  onSuccess: () => void;
}) {
  const toast = useToast();
  const [nueva, setNueva] = useState<'FACTURA' | 'EFECTIVO'>('FACTURA');
  const [motivo, setMotivo] = useState('');
  const [err, setErr] = useState<string | null>(null);

  const mut = useMutation({
    mutationFn: () =>
      api.patch(
        `/api/erp/facturas-${tipo}/${factura!.id}/categoria`,
        { categoria: nueva, motivo: motivo.trim() || null },
      ),
    onSuccess: () => {
      toast.success('Categoría actualizada',
        `Factura ${factura?.tipo_codigo ?? ''} ${factura?.numero} → ${nueva}`);
      setErr(null); setMotivo('');
      onSuccess();
    },
    onError: (e: ApiError) => setErr(e.message),
  });

  // Hooks-first; early return después.
  if (!factura) return null;

  const actual = factura.categoria ?? 'FACTURA';
  // Inicializar el destino al opuesto del actual cuando abre.
  if (mut.isIdle && nueva === actual) {
    setNueva(actual === 'FACTURA' ? 'EFECTIVO' : 'FACTURA');
  }

  const yendoAEfectivo = nueva === 'EFECTIVO';
  const sinPermisoEfectivo = yendoAEfectivo && !puedeCrearEfectivo;
  const sinCambio = nueva === actual;

  return (
    <Modal open onClose={onClose}
      title={`Cambiar categoría · factura ${factura.letra ?? ''} ${factura.tipo_codigo ?? ''} ${factura.numero}`}
      size="md">
      <div className="space-y-3 text-[12px]">
        <div className="bg-azure-soft/30 rounded p-2 text-[11.5px]">
          <div><span className="text-ink-muted">Categoría actual:</span> <strong>{actual}</strong></div>
          <div className="text-ink-muted mt-1">
            {actual === 'FACTURA'
              ? 'Operación con factura: aparece en Libro IVA / F.8001 / F.2002 y reportes fiscales.'
              : 'Operación EFECTIVO: NO aparece en Libro IVA ni reportes fiscales. Es solo de gestión interna.'}
          </div>
        </div>

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">Nueva categoría</label>
          <div className="flex gap-2">
            <label className={`flex-1 border rounded p-2 cursor-pointer ${
              nueva === 'FACTURA' ? 'border-azure bg-azure-soft/30' : 'border-line'
            }`}>
              <input type="radio" name="categoria" value="FACTURA"
                checked={nueva === 'FACTURA'}
                onChange={() => setNueva('FACTURA')}
                className="mr-1.5" />
              <strong>FACTURA</strong>
              <div className="text-[10.5px] text-ink-muted mt-1">Operación con factura, fiscal.</div>
            </label>
            <label className={`flex-1 border rounded p-2 cursor-pointer ${
              nueva === 'EFECTIVO' ? 'border-warning bg-warning-bg/40' : 'border-line'
            } ${sinPermisoEfectivo ? 'opacity-50 cursor-not-allowed' : ''}`}>
              <input type="radio" name="categoria" value="EFECTIVO"
                checked={nueva === 'EFECTIVO'}
                onChange={() => setNueva('EFECTIVO')}
                disabled={sinPermisoEfectivo}
                className="mr-1.5" />
              <strong>EFECTIVO</strong>
              <div className="text-[10.5px] text-ink-muted mt-1">Gestión interna, fuera del Libro IVA.</div>
            </label>
          </div>
          {sinPermisoEfectivo && (
            <div className="text-[10.5px] text-danger mt-1">
              No tenés permiso <code>facturas.crear_efectivo</code> para marcar como EFECTIVO.
            </div>
          )}
        </div>

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">Motivo (opcional, queda en audit log)</label>
          <textarea rows={2} value={motivo} onChange={(e) => setMotivo(e.target.value)}
            maxLength={500}
            placeholder="Ej: el contador identificó que esta operación es de gestión y no fiscal."
            className="w-full px-2 py-1 text-[12px] border border-azure-soft rounded focus:outline-none focus:border-azure" />
        </div>

        {yendoAEfectivo && (
          <div className="border border-warning/40 bg-warning-bg/40 rounded p-2 text-[11.5px] flex items-start gap-1.5">
            <AlertTriangle className="w-4 h-4 text-warning flex-shrink-0 mt-0.5" />
            <div>
              Al marcar como EFECTIVO la operación deja de aparecer en el Libro IVA y en los
              reportes fiscales (F.8001, F.2002). Solo afecta la gestión interna y el reporte
              de saldos consolidados.
            </div>
          </div>
        )}

        {err && (
          <div className="border border-danger/30 bg-danger-bg/20 rounded p-2 text-[11.5px] text-danger">
            {err}
          </div>
        )}

        <div className="flex justify-end gap-2 pt-2 border-t border-line">
          <Button variant="secondary" onClick={onClose} disabled={mut.isPending}>Cancelar</Button>
          <Button variant="primary"
            disabled={mut.isPending || sinCambio || sinPermisoEfectivo}
            onClick={() => { setErr(null); mut.mutate(); }}>
            {mut.isPending && <Loader2 className="w-3 h-3 animate-spin" />}
            Guardar cambio
          </Button>
        </div>
      </div>
    </Modal>
  );
}
