import { useState } from 'react';
import { ScrollText, FileCheck, AlertCircle, Download, Hammer } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Field, FormError } from '@/components/ui/Field';
import { auth } from '@/lib/auth';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';
import { PeriodosFiscalesCard } from '@/components/impuestos/PeriodosFiscalesCard';

type LibroDetalle = {
  periodo: { id: number; anio: number; mes: number; estado: string };
  ventas: { cabecera: any; comprobantes: any[] };
  compras: { cabecera: any; comprobantes: any[] };
};

type Anomalia = {
  severidad: 'bloqueante' | 'warning';
  codigo: string;
  factura_id: number;
  descripcion: string;
};

type ValidacionResp = {
  ok: boolean;
  bloqueantes: number;
  warnings: number;
  anomalias: Anomalia[];
};

export function LibroIvaDigitalPage() {
  const [periodoId, setPeriodoId] = useState('');

  const { data, isLoading, error, refetch } = useApi<LibroDetalle>(
    ['libro-iva-digital', periodoId],
    `/api/erp/impuestos/libro-iva/${periodoId}`,
    { enabled: Boolean(periodoId) }
  );

  const [validacion, setValidacion] = useState<ValidacionResp | null>(null);
  const validar = useApiMutation<ValidacionResp>(
    () => api.post(`/api/erp/impuestos/libro-iva/${periodoId}/validar`),
    {
      onSuccess: (res) => setValidacion(res),
      onError: (e) => useToast().error('Error validando', errorMessage(e)),
    }
  );

  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-digital']);
  const armar = useApiMutation(
    () => api.post(`/api/erp/impuestos/libro-iva/${periodoId}/armar`),
    {
      onSuccess: () => {
        toast.success('Libro IVA armado');
        invalidate();
        refetch();
      },
      onError: (e) => toast.error('Error al armar', errorMessage(e)),
    }
  );

  const generar = useApiMutation(
    () => api.post(`/api/erp/impuestos/libro-iva/${periodoId}/generar-f8001`),
    {
      onSuccess: () => {
        toast.success('F.8001 generado', 'Descargá los TXT desde los botones');
        invalidate();
        refetch();
      },
      onError: (e) => toast.error('Error al generar F.8001', errorMessage(e)),
    }
  );

  const descargar = (tipo: 'ventas' | 'compras') => {
    const url = `/api/erp/impuestos/libro-iva/${periodoId}/descargar?tipo=${tipo}`;
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `F8001_${tipo}_${data?.periodo.anio}-${String(data?.periodo.mes).padStart(2, '0')}.txt`;
        a.click();
      })
      .catch(() => toast.error('No se pudo descargar'));
  };

  return (
    <div className="p-6 space-y-4">
      <PeriodosFiscalesCard
        impuesto="IVA"
        selectedId={periodoId}
        onSelect={(id) => setPeriodoId(String(id))}
        titulo="Períodos IVA — click para seleccionar"
      />

      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><ScrollText className="w-4 h-4 text-azure" /> Libro IVA Digital F.8001</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <Field label="ID Período seleccionado" required type="number" value={periodoId}
              readOnly
              hint="Elegí un período de la tabla de arriba"
              containerClassName="w-[180px]" />
            <Button variant="secondary" onClick={() => armar.mutate(undefined as unknown as void)}
              disabled={!periodoId || armar.isPending}>
              <Hammer className="w-3 h-3" /> Armar
            </Button>
            <Button variant="secondary" onClick={() => validar.mutate(undefined as unknown as void)}
              disabled={!periodoId || validar.isPending}>
              <FileCheck className="w-3 h-3" /> Validar
            </Button>
            <Button variant="primary" onClick={() => generar.mutate(undefined as unknown as void)}
              disabled={!periodoId || generar.isPending || !validacion?.ok}>
              {generar.isPending ? 'Generando…' : 'Generar F.8001'}
            </Button>
          </div>

          {error && <FormError error={errorMessage(error)} />}

          {validacion && (
            <ValidacionPanel v={validacion} />
          )}

          {data && (
            <div className="grid grid-cols-2 gap-3">
              <ResumenCabecera tipo="Ventas" cab={data.ventas.cabecera} />
              <ResumenCabecera tipo="Compras" cab={data.compras.cabecera} />
            </div>
          )}

          {data?.ventas?.cabecera?.archivo_f8001_path && (
            <div className="flex gap-2">
              <Button variant="outline" onClick={() => descargar('ventas')}>
                <Download className="w-3 h-3" /> Descargar F.8001 ventas
              </Button>
              <Button variant="outline" onClick={() => descargar('compras')}>
                <Download className="w-3 h-3" /> Descargar F.8001 compras
              </Button>
            </div>
          )}

          {data && (
            <ListadoComprobantes ventas={data.ventas.comprobantes} compras={data.compras.comprobantes} loading={isLoading} />
          )}
        </CardBody>
      </Card>
    </div>
  );
}

function ValidacionPanel({ v }: { v: ValidacionResp }) {
  return (
    <div className={`border rounded-md p-3 text-[12.5px] ${
      v.ok ? 'border-success/40 bg-success-bg/30' : 'border-danger/40 bg-danger-bg/30'
    }`}>
      <div className="font-semibold mb-2 flex items-center gap-2">
        {v.ok ? <FileCheck className="w-4 h-4 text-success" /> : <AlertCircle className="w-4 h-4 text-danger" />}
        {v.ok
          ? 'Validación OK — listo para generar F.8001'
          : `${v.bloqueantes} bloqueantes · ${v.warnings} warnings`}
      </div>
      {v.anomalias.length > 0 && (
        <ul className="list-disc list-inside space-y-1 text-[11.5px]">
          {v.anomalias.map((a, i) => (
            <li key={i} className={a.severidad === 'bloqueante' ? 'text-danger' : 'text-warning'}>
              <strong>[{a.codigo}]</strong> {a.descripcion}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function ResumenCabecera({ tipo, cab }: { tipo: string; cab: any }) {
  if (!cab) {
    return (
      <div className="border border-dashed border-line rounded-md p-4 text-center text-ink-muted text-[12px]">
        Sin armar — apretá "Armar"
      </div>
    );
  }
  return (
    <div className="border border-line rounded-md p-3 bg-white">
      <div className="text-[11px] text-ink-muted uppercase tracking-wide mb-2">{tipo}</div>
      <div className="grid grid-cols-2 gap-2 text-[12px]">
        <Stat label="Comprobantes" value={cab.cantidad_comprobantes} />
        <Stat label="Total" value={fmtMoney(cab.total_facturado)} />
        <Stat label="Neto 21%" value={fmtMoney(cab.neto_gravado_21)} />
        <Stat label="IVA 21%" value={fmtMoney(cab.iva_21)} />
        <Stat label="Neto 10.5%" value={fmtMoney(cab.neto_gravado_10_5)} />
        <Stat label="IVA 10.5%" value={fmtMoney(cab.iva_10_5)} />
      </div>
      {cab.archivo_f8001_hash && (
        <div className="mt-2 text-[10.5px] text-ink-muted">
          <Badge variant="success">Generado</Badge> hash {cab.archivo_f8001_hash.slice(0, 12)}…
        </div>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="text-[10.5px] text-ink-muted">{label}</div>
      <div className="font-medium tabular-nums">{value}</div>
    </div>
  );
}

function ListadoComprobantes({ ventas, compras, loading }: { ventas: any[]; compras: any[]; loading: boolean }) {
  const [tab, setTab] = useState<'ventas' | 'compras'>('ventas');
  const rows = tab === 'ventas' ? ventas : compras;

  const columns: Column<any>[] = [
    { key: 'fecha_emision', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha_emision) },
    { key: 'comp', header: 'Comprobante',
      render: (r) => `${r.letra ?? ''} ${r.tipo_codigo} ${String(r.pto_vta).padStart(4, '0')}-${String(r.numero).padStart(8, '0')}` },
    { key: 'razon_social', header: tab === 'ventas' ? 'Cliente' : 'Proveedor' },
    { key: 'imp_neto_gravado', header: 'Neto', align: 'right', width: '110px',
      render: (r) => fmtMoney(r.imp_neto_gravado) },
    { key: 'imp_iva', header: 'IVA', align: 'right', width: '110px',
      render: (r) => fmtMoney(r.imp_iva) },
    { key: 'imp_total', header: 'Total', align: 'right', width: '120px',
      render: (r) => fmtMoney(r.imp_total) },
    { key: 'cae', header: 'CAE', width: '130px',
      render: (r) => r.cae ? <span className="text-[10.5px]">{r.cae}</span> : <Badge variant="danger">SIN CAE</Badge> },
  ];

  return (
    <div>
      <div className="flex gap-2 mb-2">
        <Button size="sm" variant={tab === 'ventas' ? 'primary' : 'outline'} onClick={() => setTab('ventas')}>
          Ventas ({ventas?.length ?? 0})
        </Button>
        <Button size="sm" variant={tab === 'compras' ? 'primary' : 'outline'} onClick={() => setTab('compras')}>
          Compras ({compras?.length ?? 0})
        </Button>
      </div>
      <DataTable columns={columns} rows={rows ?? []} loading={loading} empty={`Sin comprobantes de ${tab}`} />
    </div>
  );
}
