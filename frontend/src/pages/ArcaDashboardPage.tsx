import { Link } from 'react-router-dom';
import { CloudCog, Search, ShieldCheck, Download, ArrowRight } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { DataTable, fmtDate, type Column, type Paginator } from '@/components/ui/DataTable';
import { useApi } from '@/hooks/useApi';

type Run = {
  id: number;
  tipo: 'RECIBIDOS' | 'EMITIDOS';
  fecha_desde: string;
  fecha_hasta: string;
  estado: 'OK' | 'ERROR' | 'PENDIENTE' | 'EJECUTANDO';
  total_rows: number | null;
  nuevos: number | null;
  existentes: number | null;
  error_detail: string | null;
  iniciado_at: string;
  finalizado_at: string | null;
};

const tools = [
  {
    to: '/erp/arca/padron',
    icon: Search,
    titulo: 'Padrón AFIP',
    desc: 'Consultá razón social, condición IVA y domicilio fiscal por CUIT.',
  },
  {
    to: '/erp/arca/constatacion',
    icon: ShieldCheck,
    titulo: 'Constatación de CAE',
    desc: 'Validá el CAE de un comprobante recibido contra AFIP (RN-42).',
  },
  {
    to: '/erp/arca/mis-comprobantes',
    icon: Download,
    titulo: 'Mis Comprobantes',
    desc: 'Disparar scraper de comprobantes recibidos / emitidos (RN-43).',
  },
];

function badgeFor(estado: Run['estado']) {
  switch (estado) {
    case 'OK': return 'success' as const;
    case 'ERROR': return 'danger' as const;
    case 'EJECUTANDO': return 'info' as const;
    default: return 'neutral' as const;
  }
}

export function ArcaDashboardPage() {
  const { data, isLoading } = useApi<Paginator<Run>>(
    ['mis-comprobantes-runs', 'dashboard'],
    '/api/erp/mis-comprobantes/runs?per_page=8'
  );

  const columns: Column<Run>[] = [
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
    { key: 'iniciado_at', header: 'Iniciado', width: '140px',
      render: (r) => fmtDate(r.iniciado_at) },
  ];

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><CloudCog className="w-4 h-4 text-azure" /> ARCA Gateway</div>
        } />
        <CardBody className="p-4 space-y-4">
          <div className="text-[12.5px] text-ink-2">
            Microservicio que centraliza la integración con AFIP (padrón, constatación, scraper Mis Comprobantes y emisión WSFEv1).
            Las llamadas se hacen vía el ERP — el frontend no contacta el gateway directamente.
          </div>

          <div className="grid grid-cols-3 gap-3">
            {tools.map((t) => {
              const Icon = t.icon;
              return (
                <Link key={t.to} to={t.to}
                  className="group border border-line rounded-md p-4 bg-white hover:border-azure hover:shadow-sm transition">
                  <div className="flex items-center gap-2 mb-1">
                    <Icon className="w-4 h-4 text-azure" />
                    <div className="font-semibold text-[13px]">{t.titulo}</div>
                  </div>
                  <div className="text-[11.5px] text-ink-muted mb-2">{t.desc}</div>
                  <div className="text-[11px] text-azure flex items-center gap-1 group-hover:translate-x-0.5 transition">
                    Abrir <ArrowRight className="w-3 h-3" />
                  </div>
                </Link>
              );
            })}
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title={<div className="text-[13px]">Últimas corridas de Mis Comprobantes</div>} />
        <CardBody className="p-4">
          <DataTable columns={columns} rows={data?.data ?? []} loading={isLoading}
            empty="Aún no se ejecutó el scraper" />
        </CardBody>
      </Card>
    </div>
  );
}
