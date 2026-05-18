import { useState } from 'react';
import { Receipt, Pencil, Info } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { FormError } from '@/components/ui/Field';
import { SelectorCuentaContable } from '@/components/contabilidad/SelectorCuentaContable';
import { api, ApiError } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type MapeoRow = {
  id: number;
  concepto_csv: string;
  descripcion: string;
  cuenta_contable_id: number;
  cuenta_codigo: string;
  cuenta_nombre: string;
  cuenta_imputable: number;
  observaciones: string | null;
  activo: number;
  updated_at: string | null;
};

export function ConfiguracionIvaPage() {
  const { data: raw, isLoading, error } = useApi<MapeoRow[]>(
    ['iva-mapeo'],
    '/api/erp/contabilidad/iva-mapeo',
  );
  // El backend devuelve { ok, data, puede_editar }. useApi extrae .data.
  // Para puede_editar leemos del fetch a la raíz — workaround: usamos mi-permisos.
  const { data: permisos } = useApi<Array<{ codigo: string }>>(
    ['mi-permisos'],
    '/api/erp/mi-permisos',
  );
  const puedeEditar = !!permisos?.some((p) => p.codigo === 'contabilidad.iva_mapeo.editar');

  const rows = raw ?? [];
  const [editTarget, setEditTarget] = useState<MapeoRow | null>(null);

  const columns: Column<MapeoRow>[] = [
    { key: 'descripcion', header: 'Concepto AFIP',
      render: (r) => (
        <div>
          <div className="text-[12px] font-medium">{r.descripcion}</div>
          <div className="text-[10px] font-mono text-ink-muted">{r.concepto_csv}</div>
        </div>
      ),
    },
    { key: 'cuenta', header: 'Cuenta contable',
      render: (r) => (
        <div className="font-mono text-[11.5px]">
          <span className="font-semibold">{r.cuenta_codigo}</span>
          <span className="text-ink-muted ml-2">{r.cuenta_nombre}</span>
        </div>
      ),
    },
    { key: 'activo', header: 'Estado', width: '90px', align: 'center',
      render: (r) => r.activo
        ? <Badge variant="success">Activo</Badge>
        : <Badge variant="warning">Inactivo</Badge>,
    },
    ...(puedeEditar ? [{
      key: 'acc', header: '', width: '70px', align: 'center' as const,
      render: (r: MapeoRow) => (
        <Button size="sm" variant="ghost" onClick={() => setEditTarget(r)}>
          <Pencil className="w-3 h-3" />
        </Button>
      ),
    }] : []),
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2">
            <Receipt className="w-4 h-4 text-azure" />
            Configuración IVA — Mapeo de conceptos AFIP a cuentas contables
          </div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex items-start gap-2 bg-azure-soft/30 border border-azure-soft rounded p-3 text-[11.5px]">
            <Info className="w-3.5 h-3.5 text-azure mt-[2px] flex-shrink-0" />
            <div className="text-ink">
              Estos mapeos los usa el importador del Libro IVA Compras al generar
              los asientos contables. El generador lee cada concepto del CSV de AFIP
              (IVA por alícuota, percepciones, impuestos) y arma la línea del asiento
              con la cuenta acá configurada. Si falta un mapeo, las facturas con ese
              concepto no se podrán importar (error <code>MAPEO_IVA_FALTANTE</code>).
              {!puedeEditar && (
                <div className="mt-1 italic text-ink-muted">
                  Solo lectura. El permiso <code>contabilidad.iva_mapeo.editar</code> está
                  asignado a super_admin y contador.
                </div>
              )}
            </div>
          </div>

          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={columns} rows={rows} loading={isLoading}
            empty="Sin mapeos configurados. Aplicá el seed default." />
        </CardBody>
      </Card>

      {editTarget && (
        <EditarMapeoModal
          mapeo={editTarget}
          onClose={() => setEditTarget(null)}
        />
      )}
    </div>
  );
}

function EditarMapeoModal({ mapeo, onClose }: { mapeo: MapeoRow; onClose: () => void }) {
  const [cuentaId, setCuentaId] = useState<number | null>(mapeo.cuenta_contable_id);
  const [observaciones, setObservaciones] = useState(mapeo.observaciones ?? '');
  const toast = useToast();
  const invalidate = useInvalidate(['iva-mapeo']);

  const m = useApiMutation<unknown, { cuenta_contable_id: number; observaciones?: string }>(
    (body) => api.put(`/api/erp/contabilidad/iva-mapeo/${encodeURIComponent(mapeo.concepto_csv)}`, body),
    {
      onSuccess: () => {
        toast.success('Mapeo actualizado', mapeo.descripcion);
        invalidate();
        onClose();
      },
      onError: (e) => {
        if (e instanceof ApiError && e.status === 422) {
          const payload = e.payload as { error?: { code?: string; message?: string } };
          if (payload.error?.message) {
            toast.error(payload.error.code ?? 'Error', payload.error.message);
            return;
          }
        }
        toast.error('Error al actualizar', errorMessage(e));
      },
    },
  );

  return (
    <Modal open onClose={onClose} title={`Editar mapeo · ${mapeo.descripcion}`}>
      <div className="space-y-3 text-[12px]">
        <dl className="grid grid-cols-[140px_1fr] gap-y-1 gap-x-2 text-[11.5px] bg-azure-soft/30 rounded p-2">
          <dt className="text-ink-muted">Concepto CSV</dt>
          <dd className="font-mono">{mapeo.concepto_csv}</dd>
          <dt className="text-ink-muted">Cuenta actual</dt>
          <dd className="font-mono">{mapeo.cuenta_codigo} — {mapeo.cuenta_nombre}</dd>
        </dl>

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">
            Nueva cuenta contable <span className="text-danger">*</span>
          </label>
          <SelectorCuentaContable
            value={cuentaId}
            onChange={(id) => setCuentaId(id)}
            soloImputables
            placeholder="Buscar por código o nombre…"
          />
        </div>

        <div>
          <label className="block text-[11px] text-ink-muted mb-1">
            Observaciones (opcional)
          </label>
          <textarea
            rows={2}
            value={observaciones}
            onChange={(e) => setObservaciones(e.target.value)}
            maxLength={500}
            placeholder="Nota del cambio (ej: cuenta abierta el 2026-05-16 por pedido del contador)"
            className="w-full text-[12px] border border-azure-soft rounded px-2 py-1 focus:outline-none focus:border-azure"
          />
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <Button variant="outline" onClick={onClose} disabled={m.isPending}>Cancelar</Button>
          <Button
            variant="primary"
            disabled={!cuentaId || cuentaId === mapeo.cuenta_contable_id || m.isPending}
            onClick={() => m.mutate({
              cuenta_contable_id: cuentaId!,
              ...(observaciones.trim() ? { observaciones: observaciones.trim() } : {}),
            })}
          >
            Guardar
          </Button>
        </div>
      </div>
    </Modal>
  );
}
