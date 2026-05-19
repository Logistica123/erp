import { useNavigate } from 'react-router-dom';
import { Receipt, ArrowLeft } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { FacturaVentaForm } from '@/components/forms/FacturaVentaForm';

/**
 * v1.17 — Carga manual factura de venta (página standalone).
 * v1.39 — refactor: el form se extrajo a `FacturaVentaForm` para poder
 * embeberlo también en el wizard de importación PDF batch.
 */
export function FacturaVentaManualPage() {
  const navigate = useNavigate();

  return (
    <div className="p-6 max-w-4xl space-y-4">
      <div className="flex items-center gap-2 text-[13px] text-ink-muted">
        <button onClick={() => navigate('/erp/facturas-venta')} className="hover:text-ink-2 flex items-center gap-1">
          <ArrowLeft className="w-3 h-3" /> Facturación
        </button>
        <span>›</span>
        <span className="text-ink-2 font-medium">Nueva manual</span>
      </div>

      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2">
            <Receipt className="w-4 h-4 text-azure" /> Nueva factura de venta (carga manual)
          </div>
        } />
        <CardBody className="p-4">
          <FacturaVentaForm
            onSuccess={() => navigate('/erp/facturas-venta')}
            extraActions={
              <Button variant="secondary" onClick={() => navigate('/erp/facturas-venta')}>
                Cancelar
              </Button>
            }
          />
        </CardBody>
      </Card>
    </div>
  );
}
