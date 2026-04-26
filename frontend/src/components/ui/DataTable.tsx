import { ChevronLeft, ChevronRight, Loader2, Search } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type Column<T> = {
  key: string;
  header: ReactNode;
  /** Render personalizado. Si no se pasa, usa `row[key]`. */
  render?: (row: T) => ReactNode;
  /** Alineación del contenido. Default: left. */
  align?: 'left' | 'right' | 'center';
  /** Ancho CSS opcional (`'120px'`, `'10ch'`, etc.). */
  width?: string;
  /** Clase CSS extra para la celda body. */
  cellClassName?: string;
};

export type Paginator<T> = {
  data: T[];
  current_page: number;
  per_page: number;
  last_page: number;
  total: number;
};

type Props<T> = {
  columns: Column<T>[];
  /** Pueden pasar el array directo o un paginator de Laravel. */
  rows?: T[];
  paginator?: Paginator<T>;
  loading?: boolean;
  empty?: ReactNode;
  /** Callback al click de fila. */
  onRowClick?: (row: T) => void;
  /** Texto del input de búsqueda; null lo oculta. */
  search?: { value: string; onChange: (v: string) => void; placeholder?: string } | null;
  /** Cambio de página (solo cuando se pasa `paginator`). */
  onPageChange?: (page: number) => void;
  /** Clase CSS extra del contenedor. */
  className?: string;
  /**
   * Tamaño de página para paginación client-side. Solo se aplica cuando se
   * pasan `rows` directos (no `paginator`) y `rows.length` supera el valor.
   * Default 50. Usar `null` para desactivar.
   */
  clientPageSize?: number | null;
};

/**
 * DataTable reusable para listados del ERP. Acepta arrays simples o
 * `paginator` con la forma estándar de Laravel.
 */
export function DataTable<T extends Record<string, unknown>>({
  columns,
  rows,
  paginator,
  loading,
  empty,
  onRowClick,
  search,
  onPageChange,
  className,
  clientPageSize = 50,
}: Props<T>) {
  const all = rows ?? paginator?.data ?? [];
  const useClientPaging = !paginator && clientPageSize !== null && all.length > clientPageSize;
  const [clientPage, setClientPage] = useState(1);
  // Reset a página 1 si cambia la lista de rows.
  useEffect(() => { setClientPage(1); }, [all.length]);
  const clientLastPage = useClientPaging ? Math.ceil(all.length / (clientPageSize ?? 50)) : 1;
  const data = useClientPaging
    ? all.slice((clientPage - 1) * (clientPageSize ?? 50), clientPage * (clientPageSize ?? 50))
    : all;

  return (
    <div className={cn('flex flex-col gap-3', className)}>
      {search && (
        <div className="relative">
          <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-ink-muted" />
          <input
            type="search"
            value={search.value}
            onChange={(e) => search.onChange(e.target.value)}
            placeholder={search.placeholder ?? 'Buscar…'}
            className="w-full pl-9 pr-3 py-2 text-[12.5px] border border-line rounded-md bg-white focus:outline-none focus:border-azure focus:ring-1 focus:ring-azure/20"
          />
        </div>
      )}

      <div className="overflow-x-auto border border-line rounded-lg bg-white">
        <table className="w-full text-[12.5px]">
          <thead>
            <tr className="bg-[#FAFBFC] text-ink-muted text-[11px] uppercase tracking-wide">
              {columns.map((c) => (
                <th
                  key={c.key}
                  className={cn(
                    'text-left font-semibold px-3 py-2.5 border-b border-line',
                    c.align === 'right' && 'text-right',
                    c.align === 'center' && 'text-center'
                  )}
                  style={c.width ? { width: c.width } : undefined}
                >
                  {c.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={columns.length} className="text-center py-10 text-ink-muted">
                  <Loader2 className="w-5 h-5 animate-spin inline-block mr-2" /> Cargando…
                </td>
              </tr>
            )}
            {!loading && data.length === 0 && (
              <tr>
                <td colSpan={columns.length} className="text-center py-10 text-ink-muted">
                  {empty ?? 'Sin resultados.'}
                </td>
              </tr>
            )}
            {!loading &&
              data.map((row, idx) => (
                <tr
                  key={(row as { id?: string | number }).id ?? idx}
                  className={cn(
                    'border-b border-line/60 last:border-b-0',
                    onRowClick && 'cursor-pointer hover:bg-surface-hover'
                  )}
                  onClick={onRowClick ? () => onRowClick(row) : undefined}
                >
                  {columns.map((c) => (
                    <td
                      key={c.key}
                      className={cn(
                        'px-3 py-2',
                        c.align === 'right' && 'text-right tabular-nums',
                        c.align === 'center' && 'text-center',
                        c.cellClassName
                      )}
                    >
                      {c.render ? c.render(row) : (row[c.key] as ReactNode) ?? ''}
                    </td>
                  ))}
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {useClientPaging && (
        <div className="flex items-center justify-between text-[11.5px] text-ink-muted px-1">
          <div>
            {all.length.toLocaleString('es-AR')} resultados · página {clientPage} de {clientLastPage}
          </div>
          <div className="flex gap-1">
            <button
              onClick={() => setClientPage((p) => Math.max(1, p - 1))}
              disabled={clientPage <= 1}
              className="p-1.5 rounded border border-line hover:bg-surface-hover disabled:opacity-40 disabled:cursor-not-allowed"
              aria-label="Anterior"
            >
              <ChevronLeft className="w-3.5 h-3.5" />
            </button>
            <button
              onClick={() => setClientPage((p) => Math.min(clientLastPage, p + 1))}
              disabled={clientPage >= clientLastPage}
              className="p-1.5 rounded border border-line hover:bg-surface-hover disabled:opacity-40 disabled:cursor-not-allowed"
              aria-label="Siguiente"
            >
              <ChevronRight className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      )}

      {paginator && paginator.last_page > 1 && onPageChange && (
        <div className="flex items-center justify-between text-[11.5px] text-ink-muted px-1">
          <div>
            {paginator.total.toLocaleString('es-AR')} resultados · página {paginator.current_page} de{' '}
            {paginator.last_page}
          </div>
          <div className="flex gap-1">
            <button
              onClick={() => onPageChange(paginator.current_page - 1)}
              disabled={paginator.current_page <= 1}
              className="p-1.5 rounded border border-line hover:bg-surface-hover disabled:opacity-40 disabled:cursor-not-allowed"
              aria-label="Anterior"
            >
              <ChevronLeft className="w-3.5 h-3.5" />
            </button>
            <button
              onClick={() => onPageChange(paginator.current_page + 1)}
              disabled={paginator.current_page >= paginator.last_page}
              className="p-1.5 rounded border border-line hover:bg-surface-hover disabled:opacity-40 disabled:cursor-not-allowed"
              aria-label="Siguiente"
            >
              <ChevronRight className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

/** Helper de formato de números (es-AR con 2 decimales). */
export function fmtMoney(n: number | string | null | undefined): string {
  if (n === null || n === undefined || n === '') return '—';
  const v = typeof n === 'string' ? parseFloat(n) : n;
  if (Number.isNaN(v)) return '—';
  return v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function fmtDate(d: string | null | undefined): string {
  if (!d) return '—';
  const date = new Date(d);
  if (Number.isNaN(date.getTime())) return d;
  return date.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
