import { useState } from 'react';
import { Coins, Download, FileText, ExternalLink } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column } from '@/components/ui/DataTable';
import { Field, FormError } from '@/components/ui/Field';
import { auth } from '@/lib/auth';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Retencion = {
  id: number;
  fecha_emision: string;
  cuit_retenido: string;
  proveedor_id: number;
  tipo_retencion: 'IVA' | 'GAN' | 'SUSS' | 'IIBB';
  regimen: string;
  base_imponible: number | string;
  alicuota: number | string;
  importe_retenido: number | string;
  nro_certificado: string;
  estado: 'EMITIDO' | 'ANULADO';
};

type ShowResp = {
  periodo: { id: number; impuesto: string; anio: number; mes: number };
  por_tipo: Record<string, { cantidad: number; total: number; detalle: Retencion[] }>;
  totales: { cantidad_total: number; monto_total: number };
};

const TIPOS = ['IVA', 'GAN', 'IIBB', 'SUSS'];

export function SicorePage() {
  const [periodoId, setPeriodoId] = useState('');

  const toast = useToast();
  const invalidate = useInvalidate(['sicore']);

  const { data, error, refetch } = useApi<ShowResp>(
    ['sicore', periodoId],
    `/api/erp/impuestos/sicore/${periodoId}`,
    { enabled: Boolean(periodoId) }
  );

  const generar = useApiMutation<Record<string, { path: string; hash: string; filas: number }>>(
    () => api.post(`/api/erp/impuestos/sicore/${periodoId}/generar`),
    {
      onSuccess: (res) => {
        const tipos = Object.keys(res ?? {});
        toast.success('SIRE generado', `Archivos: ${tipos.join(', ')}`);
        invalidate();
        refetch();
      },
      onError: (e) => toast.error('Error generando SIRE', errorMessage(e)),
    }
  );

  const descargar = (tipo: string) => {
    const url = `/api/erp/impuestos/sicore/${periodoId}/descargar?tipo=${tipo}`;
    const token = auth.getToken();
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.blob())
      .then((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `SIRE_${tipo}_${data?.periodo.anio}-${String(data?.periodo.mes).padStart(2, '0')}.txt`;
        a.click();
      })
      .catch(() => toast.error('No se pudo descargar', `tipo ${tipo}`));
  };

  const verCertificado = (retencionId: number) => {
    const token = auth.getToken();
    const win = window.open('', '_blank');
    fetch(`/api/erp/impuestos/sicore/certificados/${retencionId}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'text/html' },
    })
      .then((r) => r.text())
      .then((html) => {
        if (win) win.document.write(html);
      });
  };

  const [tab, setTab] = useState<'IVA' | 'GAN' | 'IIBB' | 'SUSS'>('IVA');
  const grupo = data?.por_tipo?.[tab];

  const columns: Column<Retencion>[] = [
    { key: 'fecha_emision', header: 'Fecha', width: '90px', render: (r) => fmtDate(r.fecha_emision) },
    { key: 'nro_certificado', header: 'Certificado', width: '180px',
      render: (r) => <code className="text-[11px]">{r.nro_certificado}</code> },
    { key: 'cuit_retenido', header: 'CUIT' },
    { key: 'regimen', header: 'Régimen', width: '90px' },
    { key: 'base_imponible', header: 'Base', align: 'right', width: '110px',
      render: (r) => fmtMoney(r.base_imponible) },
    { key: 'alicuota', header: 'Alic.', align: 'right', width: '70px',
      render: (r) => `${(Number(r.alicuota) * 100).toFixed(2)}%` },
    { key: 'importe_retenido', header: 'Importe', align: 'right', width: '110px',
      render: (r) => fmtMoney(r.importe_retenido) },
    { key: 'estado', header: 'Estado', width: '90px',
      render: (r) => <Badge variant={r.estado === 'EMITIDO' ? 'success' : 'danger'}>{r.estado}</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '100px',
      render: (r) => (
        <Button size="sm" variant="ghost" onClick={() => verCertificado(r.id)}>
          <ExternalLink className="w-3 h-3" /> Cert.
        </Button>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><Coins className="w-4 h-4 text-azure" /> SICORE / SIRE retenciones</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="flex flex-wrap gap-3 items-end">
            <Field label="ID Período fiscal SICORE" required type="number" value={periodoId}
              onChange={(e) => setPeriodoId(e.target.value)} placeholder="ej: 8"
              containerClassName="w-[200px]" />
            <Button variant="primary" disabled={!periodoId || generar.isPending}
              onClick={() => generar.mutate(undefined as unknown as void)}>
              <FileText className="w-3 h-3" /> {generar.isPending ? 'Generando…' : 'Generar SIRE'}
            </Button>
          </div>
          {error && <FormError error={errorMessage(error)} />}

          {data && (
            <div className="grid grid-cols-4 gap-3">
              {TIPOS.map((t) => {
                const g = data.por_tipo?.[t];
                return (
                  <div key={t} className="border border-line rounded-md p-3 bg-white">
                    <div className="flex items-center justify-between mb-1">
                      <Badge variant={g ? 'info' : 'neutral'}>{t}</Badge>
                      {g && <Button size="sm" variant="outline" onClick={() => descargar(t)}>
                        <Download className="w-3 h-3" />
                      </Button>}
                    </div>
                    <div className="text-[11px] text-ink-muted">{g?.cantidad ?? 0} certificados</div>
                    <div className="text-[14px] font-semibold tabular-nums">{fmtMoney(g?.total ?? 0)}</div>
                  </div>
                );
              })}
            </div>
          )}

          {data && (
            <>
              <div className="flex gap-2 mt-3">
                {TIPOS.map((t) => (
                  <Button key={t} size="sm" variant={tab === t ? 'primary' : 'outline'}
                    onClick={() => setTab(t as 'IVA' | 'GAN' | 'IIBB' | 'SUSS')}>
                    {t} ({data.por_tipo?.[t]?.cantidad ?? 0})
                  </Button>
                ))}
              </div>
              <DataTable columns={columns} rows={grupo?.detalle ?? []} empty={`Sin retenciones de ${tab}`} />
            </>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
