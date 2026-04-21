import { useMemo, useState } from 'react';
import { ChevronDown, ChevronRight, Download, Loader2, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { api } from '@/lib/api';
import { useQuery } from '@tanstack/react-query';

type Cuenta = {
  id: number;
  codigo: string;
  codigo_padre_id: number | null;
  nivel: 1 | 2 | 3 | 4;
  nombre: string;
  tipo: string;
  imputable: boolean;
  moneda: 'ARS' | 'USD' | null;
  admite_cc: boolean;
  admite_auxiliar: boolean;
  etiqueta_cierre: string | null;
  activo: boolean;
  hijos: Cuenta[];
};

type Resp = {
  data: Cuenta[];
  meta: { total: number; imputables: number };
};

/** Aplana el árbol respetando el estado de expansión. */
function flatten(nodes: Cuenta[], expanded: Set<number>, out: Cuenta[] = []): Cuenta[] {
  for (const n of nodes) {
    out.push(n);
    if (n.hijos?.length && expanded.has(n.id)) {
      flatten(n.hijos, expanded, out);
    }
  }
  return out;
}

function collectIdsUpToLevel(nodes: Cuenta[], maxLevel: number, out: Set<number> = new Set()): Set<number> {
  for (const n of nodes) {
    if (n.nivel <= maxLevel && n.hijos?.length) {
      out.add(n.id);
      collectIdsUpToLevel(n.hijos, maxLevel, out);
    }
  }
  return out;
}

function levelClasses(c: Cuenta, alt: boolean): string {
  switch (c.nivel) {
    case 1:
      return 'bg-navy-700 text-white font-semibold';
    case 2:
      return 'bg-steel text-white font-medium';
    case 3:
      return 'bg-[#DFE8F2] text-navy-800 font-medium';
    default:
      return `${alt ? 'bg-surface-row' : ''} text-ink-2 hover:bg-surface-hover`;
  }
}

function codeColor(nivel: number): string {
  if (nivel === 1) return 'text-[#9FB5D1]';
  if (nivel === 2) return 'text-[#C5D3E3]';
  return 'text-navy-700';
}

function indentClass(nivel: 1 | 2 | 3 | 4): string {
  return { 1: '', 2: 'pl-5', 3: 'pl-[30px]', 4: 'pl-[44px]' }[nivel];
}

export function PlanCuentasPage() {
  const { data, isLoading, error } = useQuery<Resp>({
    queryKey: ['cuentas', 'tree'],
    queryFn: () => api.get<Resp>('/api/erp/cuentas?tree=true'),
  });

  const [expanded, setExpanded] = useState<Set<number>>(new Set());
  const [query, setQuery] = useState('');

  // Primera carga: expandir niveles 1 y 2 (rubros y subrubros) por default.
  useMemo(() => {
    if (data && expanded.size === 0) {
      setExpanded(collectIdsUpToLevel(data.data, 2));
    }
  }, [data, expanded.size]);

  const filas = useMemo(() => {
    if (!data) return [];
    const all = flatten(data.data, expanded);
    if (!query.trim()) return all;
    const q = query.toLowerCase();
    return all.filter((c) => c.codigo.toLowerCase().includes(q) || c.nombre.toLowerCase().includes(q));
  }, [data, expanded, query]);

  function toggle(id: number) {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Plan de Cuentas</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            {data ? (
              <>
                {data.meta.total} cuentas · {data.meta.imputables} imputables · Estructura RT 8/9 FACPCE
              </>
            ) : (
              'Cargando…'
            )}
          </p>
        </div>
        <div className="flex gap-2">
          <input
            className="px-[9px] py-[6px] text-[12px] border border-line-strong rounded-md bg-white w-[280px]"
            placeholder="Buscar por código o nombre…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          <Button variant="secondary">
            <Download className="w-3 h-3" /> Exportar
          </Button>
          <Button variant="primary">
            <Plus className="w-3 h-3" /> Nueva cuenta
          </Button>
        </div>
      </div>

      {error && (
        <div className="mb-4 p-3 bg-danger-bg text-danger border border-danger/20 rounded-md text-[12px]">
          Error cargando cuentas: {error instanceof Error ? error.message : 'desconocido'}
        </div>
      )}

      <Card>
        <CardBody>
          {/* Header columnas */}
          <div
            className="grid border-b border-line-strong bg-surface-hover text-[10px] uppercase tracking-wider font-semibold text-navy-800"
            style={{ gridTemplateColumns: '120px 1fr 80px 80px 60px 60px 140px' }}
          >
            <div className="px-[10px] py-[7px]">Código</div>
            <div className="px-[10px] py-[7px]">Cuenta</div>
            <div className="px-[10px] py-[7px] text-right">Moneda</div>
            <div className="px-[10px] py-[7px] text-right">CC</div>
            <div className="px-[10px] py-[7px]">Aux.</div>
            <div className="px-[10px] py-[7px]">Impu.</div>
            <div className="px-[10px] py-[7px]">Etiqueta cierre</div>
          </div>

          {isLoading && (
            <div className="flex items-center justify-center py-10 text-ink-muted">
              <Loader2 className="w-4 h-4 animate-spin mr-2" /> Cargando cuentas…
            </div>
          )}

          {filas.map((c, i) => {
            const hasHijos = c.hijos?.length > 0;
            const isExpanded = expanded.has(c.id);
            return (
              <div
                key={c.id}
                className={`grid border-b border-line text-[12px] items-center ${levelClasses(c, i % 2 === 1)}`}
                style={{ gridTemplateColumns: '120px 1fr 80px 80px 60px 60px 140px' }}
              >
                <div className={`px-[10px] py-[7px] font-mono text-[11px] ${codeColor(c.nivel)}`}>{c.codigo}</div>
                <div className={`px-[10px] py-[7px] ${indentClass(c.nivel)} flex items-center`}>
                  {hasHijos ? (
                    <button
                      onClick={() => toggle(c.id)}
                      className="mr-1 opacity-70 hover:opacity-100 cursor-pointer"
                      aria-label={isExpanded ? 'Colapsar' : 'Expandir'}
                    >
                      {isExpanded ? (
                        <ChevronDown className="w-[10px] h-[10px] inline" />
                      ) : (
                        <ChevronRight className="w-[10px] h-[10px] inline" />
                      )}
                    </button>
                  ) : (
                    <span className="mr-1 w-[10px] inline-block" />
                  )}
                  {c.nombre}
                </div>
                <div className="px-[10px] py-[7px] text-right">
                  {c.moneda && <Badge variant={c.moneda === 'USD' ? 'info' : 'neutral'}>{c.moneda}</Badge>}
                </div>
                <div className="px-[10px] py-[7px] text-right">
                  {c.admite_cc && <Badge variant="success">SI</Badge>}
                </div>
                <div className="px-[10px] py-[7px]">
                  {c.admite_auxiliar ? 'SI' : c.nivel === 4 ? '—' : ''}
                </div>
                <div className="px-[10px] py-[7px]">
                  {c.imputable && <Badge variant="success">SI</Badge>}
                </div>
                <div className={`px-[10px] py-[7px] font-mono text-[11px] ${codeColor(c.nivel)}`}>
                  {c.etiqueta_cierre ?? ''}
                </div>
              </div>
            );
          })}

          {data && filas.length === 0 && !isLoading && (
            <div className="py-10 text-center text-ink-muted text-[12px]">
              {query ? 'No hay cuentas que coincidan con la búsqueda.' : 'Sin cuentas.'}
            </div>
          )}
        </CardBody>
      </Card>

      <div className="p-[14px_18px] bg-[#EEF3F8] border border-[#D1DCE8] rounded-lg text-[12px] text-navy-700 leading-relaxed">
        <strong className="text-navy-800">Estructura jerárquica.</strong> Niveles 1–3 son agrupadoras (no admiten
        asientos). Nivel 4 es imputable. La columna <strong>Etiqueta cierre</strong> vincula cada cuenta al pipeline
        actual de cierres contables diarios para auto-imputar movimientos bancarios al importar extractos.
      </div>
    </>
  );
}
