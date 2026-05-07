import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { UseQueryOptions, UseMutationOptions } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';

/**
 * Wrapper de useQuery con la signatura unificada del backend Laravel ERP.
 *
 * Convención: el backend devuelve `{ok:true, data:T}` o `{ok:false, error:{code,message}}`.
 * Este hook desempaqueta `data` automáticamente y propaga errores como `ApiError`.
 *
 * Uso:
 *   const { data: bienes, isLoading, error } = useApi(['af','bienes',{q}],
 *     `/api/erp/af/bienes?q=${q}`);
 */
export function useApi<T>(
  key: readonly unknown[],
  path: string,
  options?: Omit<UseQueryOptions<T, ApiError>, 'queryKey' | 'queryFn'>
) {
  return useQuery<T, ApiError>({
    queryKey: key,
    queryFn: async ({ signal }) => {
      const resp = await api.get<{ ok?: boolean; data: T; error?: { code: string; message: string } }>(
        path,
        signal
      );
      // Convención: el backend devuelve {ok, data} en endpoints nuevos. Algunos
      // endpoints viejos solo devuelven {data}. Solo tratamos como error si ok
      // viene explícitamente false.
      if (resp.ok === false) {
        throw new ApiError(409, resp, resp.error?.message ?? 'Error del backend');
      }
      return resp.data;
    },
    ...options,
  });
}

/**
 * Mutación con resolución de errores DomainException del backend.
 *
 * Devuelve `{ mutate, mutateAsync, isPending, error }`. El error es ApiError
 * con `payload.error.code` y `payload.error.message` cuando el backend lanza
 * DomainException (HTTP 409).
 *
 * Uso:
 *   const crear = useApiMutation<Bien, AltaBienPayload>(
 *     (datos) => api.post('/api/erp/af/bienes', datos),
 *     { onSuccess: () => qc.invalidateQueries({ queryKey: ['af','bienes'] }) }
 *   );
 *   crear.mutate({ ... });
 */
export function useApiMutation<TData, TVars = unknown>(
  fn: (vars: TVars) => Promise<{ ok: boolean; data: TData; error?: { code: string; message: string } }>,
  options?: Omit<UseMutationOptions<TData, ApiError, TVars>, 'mutationFn'>
) {
  return useMutation<TData, ApiError, TVars>({
    mutationFn: async (vars) => {
      const resp = await fn(vars);
      if (resp.ok === false) {
        throw new ApiError(409, resp, resp.error?.message ?? 'Error del backend');
      }
      return resp.data;
    },
    ...options,
  });
}

/**
 * Devuelve un helper que invalida queries por prefijo de key.
 * Útil para refrescar listados tras una mutación.
 */
export function useInvalidate(...keys: readonly unknown[][]) {
  const qc = useQueryClient();
  return () => keys.forEach((k) => qc.invalidateQueries({ queryKey: k }));
}

/** Extrae código y mensaje de DomainException del backend o un error de red. */
export function errorMessage(e: unknown): string {
  if (e instanceof ApiError) {
    const payload = e.payload as { error?: { message?: string } } | undefined;
    return payload?.error?.message ?? e.message;
  }
  if (e instanceof Error) return e.message;
  return 'Error desconocido';
}

export function errorCode(e: unknown): string | null {
  if (e instanceof ApiError) {
    const payload = e.payload as { error?: { code?: string } } | undefined;
    return payload?.error?.code ?? null;
  }
  return null;
}
