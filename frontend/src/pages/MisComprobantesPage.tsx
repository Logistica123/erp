import { useMemo, useState } from 'react';
import { Download, Play, RefreshCw } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { DataTable, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type Run = {
  id: number;
  empresa_id: number;
  tipo: 'RECIBIDOS' | 'EMITIDOS';
  fecha_desde: string;
  fecha_hasta: string;
  estado: 'OK' | 'ERROR' | 'PENDIENTE' | 'EJECUTANDO';
  total_rows: number | null;
  nuevos: number | null;
  existentes: number | null;
  diff_json: unknown;
  error_detail: string | null;
  arca_run_id: string | null;
  iniciado_at: string;
  finalizado_at: string | null;
};

const ESTADOS = ['OK', 'ERROR', 'PENDIENTE', 'EJECUTANDO'];

function badgeFor(estado: Run['estado']) {
  switch (estado) {
    case 'OK': return 'success' as const;
    case 'ERROR': return 'danger' as const;
    case 'EJECUTANDO': return 'info' as const;
    default: return 'neutral' as const;
  }
}

export function MisComprobantesPage() {
  const [estado, setEstado] = useState('');
  const [page, setPage] = useState(1);
  const [ejecutarOpen, setEjecutarOpen] = useState(false);
  const [verRun, setVerRun] = useState<Run | null>(null);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (estado) p.set('estado', estado);
    if (page > 1) p.set('page', String(page));
    return p.toString();
  }, [estado, page]);

  const { data, isLoading, error, refetch } = useApi<Paginator<Run>>(
    ['mis-comprobantes-runs', qs],
    `/api/erp/mis-comprobantes/runs${qs ? `?${qs}` : ''}`
  );

  const columns: Column<Run>[] = [
    { key: 'id', header: '#', width: '70px',
      render: (r) => <code className="text-[11px]">{r.id}</code> },
    { key: 'tipo', header: 'Tipo', width: '110px',
      render: (r) => <Badge variant="default">{r.tipo}</Badge> },
    { key: 'rango', header: 'Rango',
      render: (r) => `${fmtDate(r.fecha_desde)} → ${fmtDate(r.fecha_hasta)}` },
    { key: 'estado', header: 'Estado', width: '120px',
      render: (r) => <Badge variant={badgeFor(r.estado)}>{r.estado}</Badge> },
    { key: 'total_rows', header: 'Filas', align: 'right', width: '80px',
      render: (r) => r.total_rows ?? '—' },
    { key: 'nuevos', header: 'Nuevos', align: 'right', width: '80px',
      render: (r) => r.nuevos ?? '—' },
    { key: 'existentes', header: 'Existentes', align: 'right', width: '90px',
      render: (r) => r.existentes ?? '—' },
    { key: 'iniciado_at', header: 'Iniciado', width: '140px',
      render: (r) => fmtDate(r.iniciado_at) },
    { key: 'arca_run_id', header: 'ARCA run', width: '110px',
      render: (r) => r.arca_run_id ? <code className="text-[10.5px]">{r.arca_run_id.slice(0, 12)}…</code> : '—' },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader
          title={<div className="flex items-center gap-2"><Download className="w-4 h-4 text-azure" /> Mis Comprobantes — corridas del scraper</div>}
          actions={
            <>
              <Button variant="outline" onClick={() => refetch()}>
                <RefreshCw className="w-3 h-3" /> Refrescar
              </Button>
              <Button variant="primary" onClick={() => setEjecutarOpen(true)}>
                <Play className="w-3 h-3" /> Ejecutar scraper
              </Button>
            </>
          }
        />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            RN-43: importa comprobantes recibidos / emitidos desde el portal Mis Comprobantes (AFIP) vía scraper Playwright.
            Cada corrida contabiliza filas nuevas vs existentes. Los nuevos quedan en una tabla auxiliar para conciliar contra facturas registradas.
          </div>

          <div className="flex flex-wrap gap-3">
            <SelectField label="Estado" value={estado} placeholder="Todos"
              onChange={(e) => { setEstado(e.target.value); setPage(1); }}
              containerClassName="w-[170px]"
              options={ESTADOS.map((s) => ({ value: s, label: s }))} />
          </div>

          {error && <FormError error={errorMessage(error)} />}

          <DataTable columns={columns} paginator={data} loading={isLoading}
            onPageChange={setPage} onRowClick={(r) => setVerRun(r)}
            empty="Aún no se ejecutó el scraper" />
        </CardBody>
      </Card>

      {ejecutarOpen && <EjecutarModal onClose={() => setEjecutarOpen(false)} />}
      {verRun && <DetalleRunModal run={verRun} onClose={() => setVerRun(null)} />}
    </div>
  );
}

function EjecutarModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate(['mis-comprobantes-runs']);
  const [form, setForm] = useState({
    desde: defaultDesde(),
    hasta: hoy(),
  });

  const m = useApiMutation<Run, Record<string, unknown>>(
    (vars) => api.post('/api/erp/mis-comprobantes/ejecutar', vars),
    {
      onSuccess: (run) => {
        toast.success('Scraper iniciado', `Run #${run.id} — el resultado aparece en la lista`);
        invalidate();
        onClose();
      },
      onError: (e) => toast.error('No se pudo iniciar', errorMessage(e)),
    }
  );

  return (
    <Modal open onClose={onClose} title="Ejecutar scraper Mis Comprobantes" size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="primary"
            disabled={!form.desde || !form.hasta || form.desde > form.hasta || m.isPending}
            onClick={() => m.mutate({ desde: form.desde, hasta: form.hasta })}>
            {m.isPending ? 'Iniciando…' : 'Iniciar'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="text-[12px] text-ink-2 bg-info-bg/30 border border-info/30 rounded-md p-3">
          El scraper recorre el portal AFIP "Mis Comprobantes". Puede tardar varios minutos.
          La corrida se registra y los nuevos comprobantes quedan disponibles para conciliar.
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Desde" required type="date" value={form.desde}
            onChange={(e) => setForm({ ...form, desde: e.target.value })} />
          <Field label="Hasta" required type="date" value={form.hasta}
            onChange={(e) => setForm({ ...form, hasta: e.target.value })} />
        </div>
        <FormError error={m.error ? errorMessage(m.error) : null} />
      </div>
    </Modal>
  );
}

function DetalleRunModal({ run, onClose }: { run: Run; onClose: () => void }) {
  return (
    <Modal open onClose={onClose} title={`Run #${run.id} — ${run.tipo}`} size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}
    >
      <div className="space-y-3 text-[12.5px]">
        <div className="grid grid-cols-2 gap-2">
          <Stat label="Estado" value={<Badge variant={badgeFor(run.estado)}>{run.estado}</Badge>} />
          <Stat label="Tipo" value={run.tipo} />
          <Stat label="Desde" value={fmtDate(run.fecha_desde)} />
          <Stat label="Hasta" value={fmtDate(run.fecha_hasta)} />
          <Stat label="Iniciado" value={run.iniciado_at} />
          <Stat label="Finalizado" value={run.finalizado_at ?? '—'} />
          <Stat label="Filas totales" value={run.total_rows ?? '—'} />
          <Stat label="Nuevos / Existentes" value={`${run.nuevos ?? 0} / ${run.existentes ?? 0}`} />
        </div>

        {run.arca_run_id && (
          <div>
            <div className="text-[10.5px] uppercase text-ink-muted">ARCA run id</div>
            <code className="text-[11px]">{run.arca_run_id}</code>
          </div>
        )}

        {run.error_detail && (
          <div className="border border-danger/30 bg-danger-bg/30 rounded-md p-2 text-[11.5px] text-danger whitespace-pre-wrap">
            {run.error_detail}
          </div>
        )}

        {run.diff_json !== null && run.diff_json !== undefined && (
          <details className="border border-line rounded-md">
            <summary className="px-3 py-2 cursor-pointer text-[12px] font-medium bg-bg-soft">
              Diff (raw)
            </summary>
            <pre className="text-[10.5px] p-3 max-h-[300px] overflow-auto bg-white">
              {JSON.stringify(run.diff_json, null, 2)}
            </pre>
          </details>
        )}
      </div>
    </Modal>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="text-[10.5px] uppercase text-ink-muted">{label}</div>
      <div className="font-medium tabular-nums">{value}</div>
    </div>
  );
}

function hoy() {
  return new Date().toISOString().slice(0, 10);
}

function defaultDesde() {
  const d = new Date();
  d.setDate(d.getDate() - 7);
  return d.toISOString().slice(0, 10);
}
