import { useState } from 'react';
import { FileBarChart, Download, Wand2, Send, Hash } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, FormError } from '@/components/ui/Field';
import { auth } from '@/lib/auth';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type ExportLiber = {
  id: number; liquidacion_id: number; periodo: string;
  fecha_export: string; total_exportado: number | string;
  empleados_count: number; archivo_path: string; hash_sha256: string;
  enviado_a_liber: boolean; fecha_envio: string | null;
  generador?: { id: number; name: string };
};

export function LiberPage() {
  const [filtros, setFiltros] = useState({ periodo: '' });
  const [page, setPage] = useState(1);
  const [generarOpen, setGenerarOpen] = useState(false);

  const qs = (() => {
    const p = new URLSearchParams();
    if (filtros.periodo) p.set('periodo', filtros.periodo);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  })();
  const { data, isLoading, error } = useApi<Paginator<ExportLiber>>(
    ['sueldos-liber', qs],
    `/api/erp/sueldos/exports-liber${qs ? `?${qs}` : ''}`
  );

  const cols: Column<ExportLiber>[] = [
    { key: 'id', header: '#', width: '70px', render: (r) => <code>{r.id}</code> },
    { key: 'liquidacion_id', header: 'Liq.', width: '90px',
      render: (r) => <code>#{r.liquidacion_id}</code> },
    { key: 'periodo', header: 'Período', width: '95px' },
    { key: 'fecha_export', header: 'Generado', width: '140px',
      render: (r) => fmtDate(r.fecha_export) },
    { key: 'empleados_count', header: 'Emps', align: 'right', width: '70px' },
    { key: 'total_exportado', header: 'Total FORMAL', align: 'right', width: '140px',
      render: (r) => fmtMoney(Number(r.total_exportado)) },
    { key: 'hash', header: 'Hash', width: '110px',
      render: (r) => <code className="text-[10.5px]" title={r.hash_sha256}><Hash className="w-2.5 h-2.5 inline" /> {r.hash_sha256.slice(0, 8)}…</code> },
    { key: 'enviado', header: 'Enviado LIBER', width: '130px',
      render: (r) => r.enviado_a_liber
        ? <Badge variant="success">{r.fecha_envio ? fmtDate(r.fecha_envio) : 'SÍ'}</Badge>
        : <Badge variant="warning">PENDIENTE</Badge> },
    { key: 'acciones', header: '', align: 'right', width: '180px',
      render: (r) => (
        <div className="flex justify-end gap-1.5">
          <Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); descargar(r); }}>
            <Download className="w-3 h-3" /> XLSX
          </Button>
          {! r.enviado_a_liber && <MarcarEnviado id={r.id} />}
        </div>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><FileBarChart className="w-4 h-4 text-azure" /> Export LIBER (XLSX FORMAL)</div>}
          actions={
            <Button variant="primary" onClick={() => setGenerarOpen(true)}>
              <Wand2 className="w-3 h-3" /> Generar export
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            Genera el archivo XLSX con SOLO el componente FORMAL de una liquidación
            APROBADA o PAGADA. El archivo nunca incluye datos del componente EFECTIVO.
            Solo accesible con permiso <code className="text-[11px]">sueldos.export.liber</code>.
          </div>
          <div className="flex flex-wrap gap-3">
            <Field label="Filtrar por período" value={filtros.periodo} placeholder="YYYY-MM"
              onChange={(e) => { setFiltros({ periodo: e.target.value }); setPage(1); }}
              containerClassName="w-[180px]" />
          </div>
          {error && <FormError error={errorMessage(error)} />}
          <DataTable columns={cols} paginator={data} loading={isLoading}
            onPageChange={setPage} empty="Sin exports generados" />
        </CardBody>
      </Card>

      {generarOpen && <GenerarModal onClose={() => setGenerarOpen(false)} />}
    </div>
  );
}

function descargar(exp: ExportLiber) {
  const url = `/api/erp/sueldos/exports-liber/${exp.id}/descargar`;
  const token = auth.getToken();
  fetch(url, { headers: { Authorization: `Bearer ${token}` } })
    .then((r) => r.blob())
    .then((blob) => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `F931_${exp.periodo}_liq${exp.liquidacion_id}.xlsx`;
      a.click();
    });
}

function MarcarEnviado({ id }: { id: number }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-liber']);
  const m = useApiMutation(
    () => api.post(`/api/erp/sueldos/exports-liber/${id}/marcar-enviado`),
    {
      onSuccess: () => { toast.success('Marcado como enviado'); invalidate(); },
      onError: (e) => toast.error('No se pudo', errorMessage(e)),
    }
  );
  return (
    <Button size="sm" variant="ghost" disabled={m.isPending}
      onClick={(e) => { e.stopPropagation(); m.mutate(undefined as unknown as void); }}>
      <Send className="w-3 h-3" /> Enviado
    </Button>
  );
}

function GenerarModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['sueldos-liber']);
  const [liqId, setLiqId] = useState('');
  const m = useApiMutation<ExportLiber>(
    () => api.post(`/api/erp/sueldos/liquidaciones/${liqId}/export-liber`),
    {
      onSuccess: () => { toast.success('Export generado'); invalidate(); onClose(); },
      onError: (e) => toast.error('No se pudo generar', errorMessage(e)),
    }
  );
  return (
    <Modal open onClose={onClose} title="Generar export LIBER" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!liqId || m.isPending}
            onClick={() => m.mutate(undefined as unknown as void)}>
            {m.isPending ? 'Generando…' : 'Generar XLSX'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <Field label="ID liquidación" required type="number" value={liqId}
          onChange={(e) => setLiqId(e.target.value)}
          hint="Debe estar APROBADA o PAGADA. UK por liquidacion_id (no se regenera)." />
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}
