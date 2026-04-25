import { useMemo, useState } from 'react';
import { Upload, ScrollText, Eye, Wand2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtMoney, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Importacion = {
  id: number;
  empresa_id: number;
  tipo: 'COMPRAS' | 'VENTAS';
  periodo_anio: number;
  periodo_mes: number;
  archivo_hash: string | null;
  archivo_url: string | null;
  total_filas: number;
  filas_nuevas: number;
  filas_match_erp: number;
  filas_match_distriapp: number;
  filas_conflicto: number;
  estado: 'PROCESANDO' | 'OK' | 'ERROR';
  error_detail: string | null;
  finished_at: string | null;
  created_at: string;
};

type Detalle = {
  id: number;
  importacion_id: number;
  nro_fila: number;
  fecha_cbte: string;
  tipo_cbte_afip: number;
  punto_venta: number;
  numero_desde: number;
  numero_hasta: number;
  cuit_contraparte: string | null;
  razon_social: string | null;
  imp_neto_gravado: number | string;
  imp_iva: number | string;
  imp_total: number | string;
  cae: string | null;
  estado_matching: 'NUEVO' | 'MATCH_ERP' | 'MATCH_DA' | 'CONFLICTO';
  factura_venta_id: number | null;
  factura_compra_id: number | null;
  conflicto_detalle: string | null;
};

type DetalleResp = { importacion: Importacion; filas: Paginator<Detalle> };

const ESTADO_BADGES: Record<Importacion['estado'], 'success' | 'danger' | 'warning'> = {
  OK: 'success', ERROR: 'danger', PROCESANDO: 'warning',
};

const MATCHING_BADGES: Record<Detalle['estado_matching'], 'success' | 'info' | 'warning' | 'danger'> = {
  NUEVO: 'warning', MATCH_ERP: 'success', MATCH_DA: 'info', CONFLICTO: 'danger',
};

export function LibroIvaImportarPage() {
  const [filtros, setFiltros] = useState({ tipo: '', periodo: '' });
  const [page, setPage] = useState(1);
  const [importarOpen, setImportarOpen] = useState(false);
  const [verImp, setVerImp] = useState<Importacion | null>(null);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (filtros.tipo) p.set('tipo', filtros.tipo);
    if (filtros.periodo) p.set('periodo', filtros.periodo);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [filtros, page]);

  const { data, isLoading, error } = useApi<Paginator<Importacion>>(
    ['libro-iva-importaciones', qs],
    `/api/erp/libro-iva/importaciones${qs ? `?${qs}` : ''}`
  );

  const cols: Column<Importacion>[] = [
    { key: 'id', header: '#', width: '70px',
      render: (r) => <code className="text-[11px]">{r.id}</code> },
    { key: 'tipo', header: 'Tipo', width: '110px',
      render: (r) => <Badge variant="default">{r.tipo}</Badge> },
    { key: 'periodo', header: 'Período', width: '120px',
      render: (r) => `${r.periodo_anio}/${String(r.periodo_mes).padStart(2, '0')}` },
    { key: 'estado', header: 'Estado', width: '120px',
      render: (r) => <Badge variant={ESTADO_BADGES[r.estado]}>{r.estado}</Badge> },
    { key: 'total_filas', header: 'Filas', align: 'right', width: '90px' },
    { key: 'filas_nuevas', header: 'Nuevas', align: 'right', width: '90px',
      render: (r) => r.filas_nuevas
        ? <Badge variant="warning">{r.filas_nuevas}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'filas_match_erp', header: 'Match ERP', align: 'right', width: '110px',
      render: (r) => r.filas_match_erp
        ? <Badge variant="success">{r.filas_match_erp}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'filas_match_distriapp', header: 'Match DA', align: 'right', width: '110px',
      render: (r) => r.filas_match_distriapp
        ? <Badge variant="info">{r.filas_match_distriapp}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'filas_conflicto', header: 'Conflicto', align: 'right', width: '100px',
      render: (r) => r.filas_conflicto
        ? <Badge variant="danger">{r.filas_conflicto}</Badge>
        : <span className="text-ink-muted">0</span> },
    { key: 'finished_at', header: 'Fecha', width: '120px',
      render: (r) => r.finished_at ? fmtDate(r.finished_at) : fmtDate(r.created_at) },
    { key: 'acciones', header: '', align: 'right', width: '80px',
      render: (r) => (
        <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setVerImp(r); }}>
          <Eye className="w-3 h-3" />
        </Button>
      ) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><ScrollText className="w-4 h-4 text-azure" /> Libro IVA — importar Excel ARCA</div>}
          actions={
            <Button variant="primary" onClick={() => setImportarOpen(true)}>
              <Upload className="w-3 h-3" /> Importar archivo
            </Button>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            Importa el archivo Excel descargado de "Libro IVA Digital" (AFIP) o ARCA.
            Cruza cada fila con facturas del ERP y de DistriApp por (tipo, pto venta, número, CUIT, total).
            Las filas que quedan en estado <Badge variant="warning">NUEVO</Badge> se pueden conciliar masivamente desde el detalle.
          </div>

          <div className="flex flex-wrap gap-3">
            <SelectField label="Tipo" value={filtros.tipo} placeholder="Todos"
              onChange={(e) => { setFiltros({ ...filtros, tipo: e.target.value }); setPage(1); }}
              options={[{ value: 'COMPRAS', label: 'COMPRAS' }, { value: 'VENTAS', label: 'VENTAS' }]}
              containerClassName="w-[160px]" />
            <Field label="Período (YYYY-MM)" value={filtros.periodo}
              onChange={(e) => { setFiltros({ ...filtros, periodo: e.target.value }); setPage(1); }}
              placeholder="2026-04" containerClassName="w-[180px]" />
          </div>

          {error && <FormError error={errorMessage(error)} />}

          <DataTable columns={cols} paginator={data} loading={isLoading}
            onPageChange={setPage} onRowClick={(r) => setVerImp(r)}
            empty="Aún no se importó ningún libro IVA" />
        </CardBody>
      </Card>

      {importarOpen && <ImportarModal onClose={() => setImportarOpen(false)} />}
      {verImp && <DetalleDrawer importacion={verImp} onClose={() => setVerImp(null)} />}
    </div>
  );
}

function ImportarModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-importaciones']);
  const [form, setForm] = useState({
    tipo: 'COMPRAS' as 'COMPRAS' | 'VENTAS',
    periodo: defaultPeriodo(),
    archivo: null as File | null,
  });

  const m = useApiMutation<Importacion, FormData>(
    (fd) => api.post('/api/erp/libro-iva/importar', fd),
    {
      onSuccess: () => {
        toast.success('Importación creada', 'Mirá el detalle para conciliar las filas nuevas');
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('Error al importar', errorMessage(e)),
    }
  );

  const submit = () => {
    if (!form.archivo) return;
    const fd = new FormData();
    fd.append('archivo', form.archivo);
    fd.append('tipo', form.tipo);
    fd.append('periodo', form.periodo);
    m.mutate(fd);
  };

  const valid = form.archivo && /^\d{4}-\d{2}$/.test(form.periodo);

  return (
    <Modal open onClose={onClose} title="Importar Libro IVA" size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!valid || m.isPending} onClick={submit}>
            {m.isPending ? 'Procesando…' : 'Importar'}
          </Button>
        </>
      }>
      <div className="space-y-3">
        <SelectField label="Tipo" required value={form.tipo}
          onChange={(e) => setForm({ ...form, tipo: e.target.value as 'COMPRAS' | 'VENTAS' })}
          options={[{ value: 'COMPRAS', label: 'COMPRAS' }, { value: 'VENTAS', label: 'VENTAS' }]}
          placeholder={null} />
        <Field label="Período" required value={form.periodo}
          onChange={(e) => setForm({ ...form, periodo: e.target.value })}
          placeholder="2026-04" hint="Formato YYYY-MM" />
        <div>
          <label className="block text-[11.5px] font-semibold text-ink-2 mb-1">
            Archivo Excel <span className="text-danger">*</span>
          </label>
          <input type="file" accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            onChange={(e) => setForm({ ...form, archivo: e.target.files?.[0] ?? null })}
            className="w-full text-[12px] file:mr-3 file:py-2 file:px-3 file:border-0 file:bg-azure file:text-white file:rounded-md file:cursor-pointer file:text-[12px] file:font-medium hover:file:bg-azure/90" />
          <div className="text-[11px] text-ink-muted mt-1">Hasta 30 MB · .xlsx/.xls/.csv</div>
        </div>
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function DetalleDrawer({ importacion, onClose }: { importacion: Importacion; onClose: () => void }) {
  const [page, setPage] = useState(1);
  const [estado, setEstado] = useState('');
  const toast = useToast();
  const invalidate = useInvalidate(['libro-iva-importaciones', 'libro-iva-detalle']);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (estado) p.set('estado', estado);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [estado, page]);

  const { data, isLoading } = useApi<DetalleResp>(
    ['libro-iva-detalle', importacion.id, qs],
    `/api/erp/libro-iva/importaciones/${importacion.id}/detalle${qs ? `?${qs}` : ''}`
  );

  const conciliar = useApiMutation(
    () => api.post(`/api/erp/libro-iva/importaciones/${importacion.id}/conciliar-masivo`),
    {
      onSuccess: () => {
        toast.success('Conciliación masiva ejecutada');
        invalidate();
      },
      onError: (e) => toast.error('Error al conciliar', errorMessage(e)),
    }
  );

  const cols: Column<Detalle>[] = [
    { key: 'nro_fila', header: '#', width: '70px',
      render: (r) => <code className="text-[11px]">{r.nro_fila}</code> },
    { key: 'fecha_cbte', header: 'Fecha', width: '95px',
      render: (r) => fmtDate(r.fecha_cbte) },
    { key: 'comprobante', header: 'Comprobante',
      render: (r) => `T${r.tipo_cbte_afip} ${String(r.punto_venta).padStart(4, '0')}-${String(r.numero_desde).padStart(8, '0')}${r.numero_hasta !== r.numero_desde ? ` a ${String(r.numero_hasta).padStart(8, '0')}` : ''}` },
    { key: 'razon_social', header: 'Contraparte',
      render: (r) => (
        <div>
          <div className="text-[12px]">{r.razon_social ?? '—'}</div>
          {r.cuit_contraparte && <div className="text-[10.5px] text-ink-muted">{r.cuit_contraparte}</div>}
        </div>
      ) },
    { key: 'imp_neto_gravado', header: 'Neto', align: 'right', width: '110px',
      render: (r) => fmtMoney(Number(r.imp_neto_gravado)) },
    { key: 'imp_iva', header: 'IVA', align: 'right', width: '110px',
      render: (r) => fmtMoney(Number(r.imp_iva)) },
    { key: 'imp_total', header: 'Total', align: 'right', width: '120px',
      render: (r) => fmtMoney(Number(r.imp_total)) },
    { key: 'cae', header: 'CAE', width: '140px',
      render: (r) => r.cae ? <code className="text-[10.5px]">{r.cae}</code> : '—' },
    { key: 'estado_matching', header: 'Matching', width: '120px',
      render: (r) => <Badge variant={MATCHING_BADGES[r.estado_matching]}>{r.estado_matching}</Badge> },
    { key: 'ref', header: 'Ref ERP/DA', width: '120px',
      render: (r) => r.factura_venta_id ? `FV #${r.factura_venta_id}`
        : r.factura_compra_id ? `FC #${r.factura_compra_id}`
        : '—' },
  ];

  return (
    <Modal open onClose={onClose}
      title={`Importación #${importacion.id} — ${importacion.tipo} ${importacion.periodo_anio}/${String(importacion.periodo_mes).padStart(2, '0')}`}
      size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}
    >
      <div className="space-y-3">
        <div className="grid grid-cols-5 gap-2 text-[12px]">
          <Stat label="Total filas" value={importacion.total_filas} />
          <Stat label="Nuevas" value={<Badge variant="warning">{importacion.filas_nuevas}</Badge>} />
          <Stat label="Match ERP" value={<Badge variant="success">{importacion.filas_match_erp}</Badge>} />
          <Stat label="Match DA" value={<Badge variant="info">{importacion.filas_match_distriapp}</Badge>} />
          <Stat label="Conflicto" value={<Badge variant="danger">{importacion.filas_conflicto}</Badge>} />
        </div>

        {importacion.error_detail && (
          <div className="border border-danger/30 bg-danger-bg/30 rounded-md p-2 text-[11.5px] text-danger whitespace-pre-wrap">
            {importacion.error_detail}
          </div>
        )}

        <div className="flex flex-wrap gap-3 items-end">
          <SelectField label="Filtrar por estado" value={estado} placeholder="Todos"
            onChange={(e) => { setEstado(e.target.value); setPage(1); }}
            options={[
              { value: 'NUEVO', label: 'NUEVO' },
              { value: 'MATCH_ERP', label: 'MATCH_ERP' },
              { value: 'MATCH_DA', label: 'MATCH_DA' },
              { value: 'CONFLICTO', label: 'CONFLICTO' },
            ]}
            containerClassName="w-[200px]" />
          <Button variant="outline" size="sm" disabled={conciliar.isPending}
            onClick={() => conciliar.mutate(undefined as unknown as void)}>
            <Wand2 className="w-3 h-3" /> {conciliar.isPending ? 'Conciliando…' : 'Conciliar masivo'}
          </Button>
        </div>

        <DataTable columns={cols} paginator={data?.filas} loading={isLoading}
          onPageChange={setPage} empty="Sin filas" />
      </div>
    </Modal>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="border border-line rounded-md p-2 bg-white">
      <div className="text-[10.5px] uppercase text-ink-muted">{label}</div>
      <div className="font-semibold tabular-nums">{value}</div>
    </div>
  );
}

function defaultPeriodo() {
  const d = new Date();
  d.setMonth(d.getMonth() - 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}
