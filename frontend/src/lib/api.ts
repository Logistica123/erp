import { auth } from './auth';

export class ApiError extends Error {
  status: number;
  payload: unknown;

  constructor(status: number, payload: unknown, message: string) {
    super(message);
    this.status = status;
    this.payload = payload;
  }
}

async function request<T>(
  path: string,
  opts: { method?: string; body?: unknown; signal?: AbortSignal } = {}
): Promise<T> {
  const token = auth.getToken();
  const headers: Record<string, string> = {
    Accept: 'application/json',
  };
  const isFormData = typeof FormData !== 'undefined' && opts.body instanceof FormData;
  if (opts.body !== undefined && !isFormData) {
    headers['Content-Type'] = 'application/json';
  }
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const res = await fetch(path, {
    method: opts.method ?? 'GET',
    headers,
    body: opts.body === undefined
      ? undefined
      : isFormData
        ? (opts.body as FormData)
        : JSON.stringify(opts.body),
    signal: opts.signal,
  });

  const isJson = res.headers.get('content-type')?.includes('application/json');
  const payload = isJson ? await res.json() : await res.text();

  if (!res.ok) {
    if (res.status === 401) {
      auth.logout();
    }
    // Extraer el mensaje más útil del payload:
    //   1. error.message (formato ERP custom: { ok:false, error:{ code, message }})
    //   2. errors[campo][0] (Laravel validation 422 — devuelve detalle por campo)
    //   3. message (Laravel default)
    //   4. fallback "HTTP {status}"
    let msg: string | null = null;
    if (isJson && typeof payload === 'object' && payload !== null) {
      const p = payload as Record<string, unknown>;
      if (p.error && typeof p.error === 'object') {
        const err = p.error as Record<string, unknown>;
        if (typeof err.message === 'string') msg = err.message;
      }
      if (!msg && p.errors && typeof p.errors === 'object') {
        const detalles: string[] = [];
        for (const [campo, errs] of Object.entries(p.errors as Record<string, unknown>)) {
          if (Array.isArray(errs) && errs.length > 0 && typeof errs[0] === 'string') {
            detalles.push(`${campo}: ${errs[0]}`);
          }
        }
        if (detalles.length) msg = detalles.join(' · ');
      }
      if (!msg && typeof p.message === 'string') msg = p.message;
    }
    msg = msg ?? `HTTP ${res.status}`;
    throw new ApiError(res.status, payload, msg);
  }

  return payload as T;
}

export const api = {
  get: <T>(path: string, signal?: AbortSignal) => request<T>(path, { signal }),
  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
  put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
  delete: <T>(path: string, body?: unknown) => request<T>(path, { method: 'DELETE', body }),
};
