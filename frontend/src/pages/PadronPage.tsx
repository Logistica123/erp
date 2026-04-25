import { useState } from 'react';
import { Search, RefreshCw, Building2, MapPin, Briefcase } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Field, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApiMutation, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

type PadronCache = {
  cuit: string;
  alcance: string | null;
  razon_social: string | null;
  condicion_iva_afip: string | null;
  condicion_iva_id: number | null;
  estado_cuit: string | null;
  domicilio_fiscal: Record<string, unknown> | null;
  actividades: Array<Record<string, unknown>> | null;
  impuestos: Array<Record<string, unknown>> | null;
  consultado_at: string | null;
  ttl_dias: number | null;
};

export function PadronPage() {
  const [cuit, setCuit] = useState('');
  const [resp, setResp] = useState<PadronCache | null>(null);
  const toast = useToast();

  const consultar = useApiMutation<PadronCache, { cuit: string }>(
    (vars) => api.post('/api/erp/padrones/consultar', vars),
    {
      onSuccess: (d) => setResp(d),
      onError: (e) => toast.error('Error consultando padrón', errorMessage(e)),
    }
  );

  const refrescar = useApiMutation<PadronCache>(
    () => api.post(`/api/erp/padrones/refrescar/${cuit.replace(/[^0-9]/g, '')}`),
    {
      onSuccess: (d) => {
        setResp(d);
        toast.success('Padrón refrescado desde AFIP');
      },
      onError: (e) => toast.error('Error refrescando', errorMessage(e)),
    }
  );

  const submit = () => {
    const limpio = cuit.replace(/[^0-9]/g, '');
    if (limpio.length !== 11) {
      toast.error('CUIT inválido', 'Debe tener 11 dígitos');
      return;
    }
    consultar.mutate({ cuit: limpio });
  };

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><Search className="w-4 h-4 text-azure" /> Padrón AFIP</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12px] text-ink-muted">
            Consulta el padrón A5/A13 de AFIP. Si el CUIT está en cache (TTL configurado), devuelve el cache; si no, golpea AFIP.
            El botón "Refrescar" fuerza una nueva consulta a AFIP.
          </div>

          <div className="flex flex-wrap gap-3 items-end">
            <Field label="CUIT" required value={cuit} placeholder="20-12345678-9 o 20123456789"
              onChange={(e) => setCuit(e.target.value)} containerClassName="w-[260px]"
              onKeyDown={(e) => { if (e.key === 'Enter') submit(); }} />
            <Button variant="primary" onClick={submit} disabled={consultar.isPending}>
              <Search className="w-3 h-3" /> {consultar.isPending ? 'Consultando…' : 'Consultar'}
            </Button>
            {resp && (
              <Button variant="outline" onClick={() => refrescar.mutate(undefined as unknown as void)}
                disabled={refrescar.isPending}>
                <RefreshCw className={`w-3 h-3 ${refrescar.isPending ? 'animate-spin' : ''}`} /> Refrescar
              </Button>
            )}
          </div>

          {consultar.error && <FormError error={errorMessage(consultar.error)} />}

          {resp && <PadronDetalle p={resp} />}
        </CardBody>
      </Card>
    </div>
  );
}

function PadronDetalle({ p }: { p: PadronCache }) {
  const dom = p.domicilio_fiscal ?? {};
  return (
    <div className="border border-line rounded-md bg-white">
      <div className="p-4 border-b border-line">
        <div className="flex items-start justify-between gap-3">
          <div>
            <div className="text-[11px] text-ink-muted uppercase tracking-wide">{p.alcance ?? 'CUIT'}</div>
            <div className="text-[15px] font-semibold flex items-center gap-2">
              <Building2 className="w-4 h-4 text-azure" />
              {p.razon_social ?? '—'}
            </div>
            <div className="text-[12px] text-ink-2 mt-0.5">CUIT {p.cuit}</div>
          </div>
          <div className="flex flex-col items-end gap-1">
            {p.estado_cuit && (
              <Badge variant={p.estado_cuit === 'ACTIVO' ? 'success' : 'danger'}>{p.estado_cuit}</Badge>
            )}
            {p.condicion_iva_afip && <Badge variant="info">{p.condicion_iva_afip}</Badge>}
          </div>
        </div>
      </div>

      <div className="grid grid-cols-2 divide-x divide-line">
        <div className="p-4">
          <div className="text-[11px] uppercase text-ink-muted mb-2 flex items-center gap-1">
            <MapPin className="w-3 h-3" /> Domicilio fiscal
          </div>
          {Object.keys(dom).length === 0 ? (
            <div className="text-[12px] text-ink-muted">Sin datos</div>
          ) : (
            <dl className="text-[12px] space-y-1">
              {Object.entries(dom).map(([k, v]) => (
                <div key={k} className="flex gap-2">
                  <dt className="text-ink-muted min-w-[100px]">{k}</dt>
                  <dd className="font-medium">{String(v ?? '—')}</dd>
                </div>
              ))}
            </dl>
          )}
        </div>
        <div className="p-4">
          <div className="text-[11px] uppercase text-ink-muted mb-2 flex items-center gap-1">
            <Briefcase className="w-3 h-3" /> Actividades
          </div>
          {!p.actividades || p.actividades.length === 0 ? (
            <div className="text-[12px] text-ink-muted">Sin actividades registradas</div>
          ) : (
            <ul className="text-[12px] space-y-1">
              {p.actividades.map((a, i) => (
                <li key={i} className="flex gap-2">
                  <code className="text-[11px] bg-bg-soft px-1.5 rounded">{String(a.codigo ?? a['codigo'] ?? '—')}</code>
                  <span>{String(a.descripcion ?? a['descripcion'] ?? '')}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      {p.impuestos && p.impuestos.length > 0 && (
        <div className="p-4 border-t border-line">
          <div className="text-[11px] uppercase text-ink-muted mb-2">Impuestos</div>
          <div className="flex flex-wrap gap-1.5">
            {p.impuestos.map((imp, i) => (
              <Badge key={i} variant="neutral">
                {String(imp.descripcion ?? imp.idImpuesto ?? imp['idImpuesto'] ?? `imp ${i}`)}
              </Badge>
            ))}
          </div>
        </div>
      )}

      <div className="px-4 py-2 border-t border-line text-[10.5px] text-ink-muted bg-[#FAFBFC] flex justify-between">
        <span>Consultado: {p.consultado_at ?? '—'}</span>
        <span>TTL: {p.ttl_dias ?? '—'} días</span>
      </div>
    </div>
  );
}
