import { Construction } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';

/**
 * Pantalla placeholder mientras se implementa la real.
 * Indica el módulo y el endpoint backend que ya está disponible para
 * orientar la siguiente iteración.
 */
export function PlaceholderPage({
  title,
  modulo,
  endpoint,
  bloque,
}: {
  title: string;
  modulo: string;
  endpoint?: string;
  bloque?: string;
}) {
  return (
    <div className="p-6 max-w-3xl">
      <Card>
        <CardHeader title={title} />
        <CardBody className="px-5 py-6">
          <div className="flex items-start gap-4">
            <Construction className="w-10 h-10 text-azure shrink-0" strokeWidth={1.6} />
            <div className="space-y-2">
              <p className="text-[13px] text-ink-2">
                Pantalla del módulo <span className="font-semibold">{modulo}</span> pendiente de
                implementación.
              </p>
              {endpoint && (
                <p className="text-[12px] text-ink-muted">
                  Backend disponible en <code className="bg-surface-row px-1.5 py-0.5 rounded">{endpoint}</code>.
                </p>
              )}
              {bloque && (
                <p className="text-[11.5px] text-ink-muted">
                  Se entregará en el bloque{' '}
                  <span className="font-semibold text-azure">{bloque}</span>.
                </p>
              )}
            </div>
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
