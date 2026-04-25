import { useMemo, useState } from 'react';
import { Users } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { useApi, errorMessage } from '@/hooks/useApi';

type Auxiliar = { id: number; codigo: string; nombre: string; cuit?: string };

type CcFila = {
  factura_id: number;
  tipo: string;
  pto_vta: number;
  numero: number;
  fecha_emision: string;
  fecha_vencimiento: string | null;
  imp_total: number | string;
  aplicado: number | string;
  saldo: number | string;
};

type CcResponse = {
  facturas: CcFila[];
  totales: { cantidad: number; saldo: number };
};

/**
 * Pantalla genérica de Cuenta Corriente. Usada por dos rutas:
 *   - /erp/cc-clientes     → tipo='Cliente', endpoint /reportes/cc-clientes
 *   - /erp/cc-proveedores  → tipo='Proveedor', endpoint /reportes/cc-proveedores
 */
export function CCPage({ kind }: { kind: 'clientes' | 'proveedores' }) {
  const tipoAux = kind === 'clientes' ? 'Cliente' : 'Proveedor';
  const titulo = kind === 'clientes' ? 'CC Clientes' : 'CC Proveedores';
  const param = kind === 'clientes' ? 'cliente_id' : 'proveedor_id';
  const endpoint = kind === 'clientes' ? '/api/erp/reportes/cc-clientes' : '/api/erp/reportes/cc-proveedores';

  const { data: auxiliares } = useApi<Auxiliar[]>(
    ['auxiliares', tipoAux],
    `/api/erp/auxiliares?tipo=${tipoAux}`
  );

  const [auxiliarId, setAuxiliarId] = useState('');
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10));

  const qs = useMemo(() => {
    if (!auxiliarId) return '';
    const p = new URLSearchParams();
    p.set(param, auxiliarId);
    if (fecha) p.set('fecha', fecha);
    return p.toString();
  }, [auxiliarId, fecha, param]);

  const { data, isLoading, error } = useApi<CcResponse>(
    ['cc', kind, auxiliarId, fecha],
    `${endpoint}?${qs}`,
    { enabled: Boolean(auxiliarId) }
  );

  const auxOpts = (auxiliares ?? []).map((a) => ({
    value: a.id,
    label: `${a.codigo} — ${a.nombre}`,
  }));
  const auxSel = (auxiliares ?? []).find((a) => String(a.id) === auxiliarId);

  const columns: Column<CcFila>[] = [
    { key: 'fecha_emision', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha_emision) },
    { key: 'comprobante', header: 'Comprobante',
      render: (r) => `${r.tipo} ${String(r.pto_vta).padStart(4, '0')}-${String(r.numero).padStart(8, '0')}` },
    { key: 'fecha_vencimiento', header: 'Vencimiento', width: '110px',
      render: (r) => r.fecha_vencimiento ? fmtDate(r.fecha_vencimiento) : '—' },
    { key: 'imp_total', header: 'Importe', align: 'right', width: '120px',
      render: (r) => fmtMoney(r.imp_total) },
    { key: 'aplicado', header: 'Aplicado', align: 'right', width: '120px',
      render: (r) => fmtMoney(r.aplicado) },
    { key: 'saldo', header: 'Saldo', align: 'right', width: '120px',
      render: (r) => <strong>{fmtMoney(r.saldo)}</strong> },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><Users className="w-4 h-4 text-azure" /> {titulo}</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <SelectField
              label={kind === 'clientes' ? 'Cliente' : 'Proveedor'}
              required
              value={auxiliarId}
              onChange={(e) => setAuxiliarId(e.target.value)}
              options={auxOpts}
              placeholder="Elegí…"
              containerClassName="w-[360px]"
            />
            <Field label="Fecha de corte" type="date" value={fecha}
              onChange={(e) => setFecha(e.target.value)}
              containerClassName="w-[150px]" />
          </div>

          {error && <FormError error={errorMessage(error)} />}

          {!auxiliarId ? (
            <div className="border border-dashed border-line rounded-md p-8 text-center text-ink-muted text-[12.5px]">
              Elegí un {kind === 'clientes' ? 'cliente' : 'proveedor'} para ver su cuenta corriente.
            </div>
          ) : (
            <>
              {data && (
                <div className="flex items-center gap-3 bg-surface-row border border-line rounded-md p-3">
                  <div className="flex-1 text-[12.5px]">
                    <div className="font-medium text-navy-800">{auxSel?.nombre}</div>
                    <div className="text-ink-muted text-[11.5px]">CUIT {auxSel?.cuit ?? '—'}</div>
                  </div>
                  <div className="text-right text-[12.5px]">
                    <div className="text-ink-muted">Saldo total</div>
                    <Badge variant={Number(data.totales.saldo) > 0 ? 'warning' : 'success'}>
                      {fmtMoney(data.totales.saldo)}
                    </Badge>
                  </div>
                  <div className="text-right text-[12.5px]">
                    <div className="text-ink-muted">Comprobantes pendientes</div>
                    <div className="font-semibold tabular-nums">{data.totales.cantidad}</div>
                  </div>
                </div>
              )}

              <DataTable
                columns={columns}
                rows={data?.facturas ?? []}
                loading={isLoading}
                empty="Sin saldo pendiente."
              />
            </>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
