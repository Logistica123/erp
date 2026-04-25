import { useState } from 'react';
import { TrendingUp } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, type Column } from '@/components/ui/DataTable';
import { Field, FormError } from '@/components/ui/Field';
import { useApi, errorMessage } from '@/hooks/useApi';

type AgingFila = {
  auxiliar_id: number;
  nombre: string;
  cuit: string;
  corriente: number;
  rango_1_30: number;
  rango_31_60: number;
  rango_61_90: number;
  rango_91_plus: number;
  total: number;
  cantidad_facturas: number;
};

type AgingResp = {
  tipo: 'clientes' | 'proveedores';
  fecha_corte: string;
  por_auxiliar: AgingFila[];
  totales: {
    corriente: number;
    rango_1_30: number;
    rango_31_60: number;
    rango_61_90: number;
    rango_91_plus: number;
    total: number;
  };
};

export function AgingPage() {
  const [tipo, setTipo] = useState<'clientes' | 'proveedores'>('clientes');
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10));

  const { data, isLoading, error } = useApi<AgingResp>(
    ['aging', tipo, fecha],
    `/api/erp/reportes/aging?tipo=${tipo}&fecha=${fecha}`
  );

  const columns: Column<AgingFila>[] = [
    { key: 'nombre', header: tipo === 'clientes' ? 'Cliente' : 'Proveedor',
      render: (r) => <div>
        <div className="text-[12.5px]">{r.nombre}</div>
        <div className="text-[10.5px] text-ink-muted">CUIT {r.cuit}</div>
      </div> },
    { key: 'cantidad_facturas', header: 'Cant.', align: 'center', width: '70px' },
    { key: 'corriente', header: 'Corriente', align: 'right',
      render: (r) => <span className="text-success">{fmtMoney(r.corriente)}</span> },
    { key: 'rango_1_30', header: '1-30', align: 'right', render: (r) => fmtMoney(r.rango_1_30) },
    { key: 'rango_31_60', header: '31-60', align: 'right',
      render: (r) => <span className="text-warning">{fmtMoney(r.rango_31_60)}</span> },
    { key: 'rango_61_90', header: '61-90', align: 'right',
      render: (r) => <span className="text-warning">{fmtMoney(r.rango_61_90)}</span> },
    { key: 'rango_91_plus', header: '+90', align: 'right',
      render: (r) => <span className="text-danger font-semibold">{fmtMoney(r.rango_91_plus)}</span> },
    { key: 'total', header: 'Total', align: 'right',
      render: (r) => <strong>{fmtMoney(r.total)}</strong> },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><TrendingUp className="w-4 h-4 text-azure" /> Aging — Antigüedad de saldos</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <div className="flex gap-2">
              <Button variant={tipo === 'clientes' ? 'primary' : 'outline'}
                onClick={() => setTipo('clientes')}>Clientes</Button>
              <Button variant={tipo === 'proveedores' ? 'primary' : 'outline'}
                onClick={() => setTipo('proveedores')}>Proveedores</Button>
            </div>
            <Field label="Fecha de corte" type="date" value={fecha}
              onChange={(e) => setFecha(e.target.value)}
              containerClassName="w-[150px]" />
          </div>
          {error && <FormError error={errorMessage(error)} />}

          {data && (
            <div className="grid grid-cols-6 gap-2">
              <KPI label="Corriente" value={data.totales.corriente} color="text-success" />
              <KPI label="1-30 días" value={data.totales.rango_1_30} />
              <KPI label="31-60 días" value={data.totales.rango_31_60} color="text-warning" />
              <KPI label="61-90 días" value={data.totales.rango_61_90} color="text-warning" />
              <KPI label="+90 días" value={data.totales.rango_91_plus} color="text-danger" />
              <KPI label="TOTAL" value={data.totales.total} color="text-navy-800 font-bold" />
            </div>
          )}

          <DataTable columns={columns} rows={data?.por_auxiliar ?? []} loading={isLoading}
            empty="Sin saldos pendientes." />
        </CardBody>
      </Card>
    </div>
  );
}

function KPI({ label, value, color }: { label: string; value: number; color?: string }) {
  return (
    <div className="border border-line rounded-md p-2 bg-white">
      <div className="text-[10.5px] text-ink-muted uppercase tracking-wide">{label}</div>
      <div className={`text-[13.5px] tabular-nums ${color ?? ''}`}>{fmtMoney(value)}</div>
    </div>
  );
}
